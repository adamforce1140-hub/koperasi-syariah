<?php

namespace App\Services;

use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Penjalan pengujian otomatis (PHPUnit) dari dalam aplikasi.
 *
 * Fitur "Riwayat Pengujian" pada dasbor Pengurus memanggil layanan ini untuk
 * mengeksekusi `php artisan test` secara terisolasi (subproses), lalu
 * menerjemahkan keluarannya menjadi ringkasan terstruktur yang ditampilkan
 * sebagai tabel di UI dan disimpan ke tabel `test_runs`.
 *
 * KEPUTUSAN DESAIN PENTING
 * ------------------------
 * 1. MENGIKUTI JALUR RESMI. Sama seperti `scripts/run-tests.ps1`, layanan ini
 *    menjalankan `php artisan optimize:clear` SEBELUM `php artisan test` pada
 *    setiap sesi (lihat docs/PANDUAN-PENGUJIAN.md). Tanpa itu, cache
 *    konfigurasi yang tertinggal membuat suite gagal massal (419 CSRF) —
 *    dan penjaga di `tests/CreatesApplication.php` akan menolaknya.
 * 2. DATABASE. Pengujian berjalan pada database aplikasi seperti konvensi
 *    proyek (phpunit.xml tidak menimpa DB_DATABASE). Setiap test memakai
 *    `DatabaseTransactions`, sehingga perubahan data di-rollback dan data
 *    aplikasi tidak berubah permanen.
 * 3. AMAN DARI SUNTIKAN PERINTAH. Nama modul & target uji divalidasi dengan
 *    pola ketat sebelum diteruskan ke baris perintah; proses dijalankan tanpa
 *    shell sehingga tidak ada masukan bebas yang bisa dieksekusi.
 */
class TestRunnerService
{
    /**
     * Kode keluar semu bila subproses gagal dimulai (proc_open gagal/timeout).
     */
    private const EXIT_GAGAL_MULAI = -1;

    /**
     * Modul yang tersedia untuk diuji dari UI.
     *
     * `berkas`   : daftar berkas uji (relatif dari root proyek).
     * `deskripsi`: teks bantuan untuk UI.
     */
    public const MODUL = [
        'simpanan_wajib' => [
            'label' => 'Simpanan Wajib',
            'deskripsi' => 'Jatuh tempo otomatis, tagihan, auto-debit, penarikan, konfigurasi dinamis.',
            'berkas' => [
                'tests/Feature/JatuhTempoSimpananWajibOtomatisTest.php',
                'tests/Feature/MandatorySavingsSimpananWajibTest.php',
                'tests/Feature/MandatorySavingsPenangananDinamisTest.php',
                'tests/Feature/SimpananWajibKonfigurasiDinamisTest.php',
                'tests/Feature/SimpananWajibPenarikanTest.php',
                'tests/Feature/ValidasiKepatuhanSimpananWajibTest.php',
                'tests/Feature/TellerSimpananWajibTest.php',
                'tests/Feature/ProcessWajibDuesJobTest.php',
            ],
        ],
        'simpanan_umum' => [
            'label' => 'Simpanan Pokok & Sukarela',
            'deskripsi' => 'Simpanan Pokok, Sukarela, deposito, dan aktivasi setoran awal.',
            'berkas' => [
                'tests/Feature/AktivasiSetoranAwalTest.php',
                'tests/Feature/BundlingSetoranAwalTest.php',
                'tests/Feature/DepositoSyariahTest.php',
                'tests/Feature/ReversalDepositoDanNomorSequenceTest.php',
                'tests/Feature/SetorTunaiAnggotaTest.php',
            ],
        ],
        'pembiayaan' => [
            'label' => 'Pembiayaan & Angsuran',
            'deskripsi' => 'Pengajuan, approval berjenjang, pencairan, angsuran, CKPN.',
            'berkas' => [
                'tests/Feature/DisbursementPencairanPembiayaanTest.php',
                'tests/Feature/AngsuranSisaKewajibanTest.php',
                'tests/Feature/BayarAngsuranFormTest.php',
                'tests/Feature/CkpnPsak71Test.php',
                'tests/Feature/PemisahanTugasPembiayaanTest.php',
            ],
        ],
        'zakat' => [
            'label' => 'Zakat (PSAK 109)',
            'deskripsi' => 'Penghasilan, hisab, porsi amil, pihak relasi, laporan CALK.',
            'berkas' => [
                'tests/Feature/ZakatPenghasilanTest.php',
                'tests/Feature/ZakatHisabKonfigurasiTest.php',
                'tests/Feature/ZakatAmilPorsiTest.php',
                'tests/Feature/ZakatPsak109Test.php',
                'tests/Feature/ZakatLaporanCalkTest.php',
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan & Akuntansi (PSAK)',
            'deskripsi' => 'Jurnal, buku besar, neraca, SHU, laporan PSAK 101/108.',
            'berkas' => [
                'tests/Feature/JurnalOrphanDanBalanceTest.php',
                'tests/Feature/BukuBesarAkunTest.php',
                'tests/Feature/LaporanPsak101Test.php',
                'tests/Feature/LaporanKeuanganSyariahTest.php',
                'tests/Feature/Psak108PemisahanMarginDanaKebajikanTest.php',
                'tests/Feature/SaldoEkuitasIkhtisarShuTest.php',
            ],
        ],
        'rbac_keamanan' => [
            'label' => 'RBAC & Keamanan',
            'deskripsi' => 'Hak akses per peran, keamanan header, PII, sesi, password.',
            'berkas' => [
                'tests/Feature/MatriksHakAksesAuditTest.php',
                'tests/Feature/RbacHierarkiRoleTest.php',
                'tests/Feature/KeamananHeaderTest.php',
                'tests/Feature/KeamananPiiNikTest.php',
                'tests/Feature/PasswordKuatTest.php',
                'tests/Feature/NavigasiWewenangPeranTest.php',
            ],
        ],
        'semua' => [
            'label' => 'Seluruh Suite (Unit + Feature)',
            'deskripsi' => 'Menjalankan semua pengujian. Memakan waktu paling lama.',
            'berkas' => null,
        ],
    ];

    /**
     * Jalankan pengujian untuk modul tertentu, simpan ke `test_runs`, kembalikan
     * baris yang tersimpan.
     *
     * @param  string  $modul  Kunci modul (lihat self::MODUL).
     * @param  User  $pelaksana  Pengurus yang memicu pengujian.
     * @param  string|null  $tanggalUji  Tanggal pengujian 'Y-m-d' (default hari ini).
     * @param  string|null  $targetUji  Target kustom (opsional): jalur di dalam tests/.
     */
    public function jalankan(string $modul, User $pelaksana, ?string $tanggalUji = null, ?string $targetUji = null): TestRun
    {
        if (! array_key_exists($modul, self::MODUL)) {
            throw new RuntimeException("Modul '{$modul}' tidak dikenal.");
        }

        $definisi = self::MODUL[$modul];
        $tanggal = $tanggalUji ?: now()->toDateString();

        $argumen = $this->susunPerintah($modul, $targetUji, $targetDipakai);

        // Baris dibuat lebih dahulu (status "berjalan") agar hasil tetap terekam
        // meski koneksi peramban terputus pada modul yang berat.
        $run = TestRun::create([
            'modul' => $definisi['label'],
            'target_uji' => $targetDipakai,
            'tanggal_uji' => $tanggal,
            'status' => TestRun::STATUS_RUNNING,
            'dijalankan_oleh' => $pelaksana->id,
            'nama_pelaksana' => $pelaksana->name,
        ]);

        [$exitCode, $keluaran, $durasiMs] = $this->eksekusi($argumen);

        $ringkasan = $this->ringkasKeluaran($keluaran, $exitCode);

        $run->update([
            'status' => $ringkasan['status'],
            'exit_code' => (string) $exitCode,
            'jumlah_tes' => $ringkasan['jumlah_tes'],
            'lulus' => $ringkasan['lulus'],
            'gagal' => $ringkasan['gagal'],
            'dilewati' => $ringkasan['dilewati'],
            'assertions' => $ringkasan['assertions'],
            'durasi_ms' => $durasiMs,
            'detail' => $ringkasan['detail'],
            'cuplikan_keluaran' => mb_substr($keluaran, -8000),
        ]);

        return $run->refresh();
    }

    /**
     * Susun daftar argumen baris perintah (tanpa shell) untuk `php artisan test`.
     *
     * @param  string|null  $targetUji  Target kustom; divalidasi pola ketat.
     * @param  string|null  $targetDipakai  Diisi target yang benar-benar dipakai.
     */
    private function susunPerintah(string $modul, ?string $targetUji, ?string &$targetDipakai): array
    {
        $argumen = [$this->phpBinary(), 'artisan', 'test', '--without-tty'];

        $targetUji = $this->sanitasiTarget($targetUji);

        if ($targetUji !== null) {
            $argumen[] = $targetUji;
            $targetDipakai = $targetUji;

            return $argumen;
        }

        $berkas = self::MODUL[$modul]['berkas'] ?? null;

        if (! empty($berkas)) {
            foreach ($berkas as $file) {
                $argumen[] = $file;
            }
            $targetDipakai = count($berkas) === 1
                ? $berkas[0]
                : count($berkas).' berkas uji '.(self::MODUL[$modul]['label'] ?? $modul);
        } else {
            $targetDipakai = 'Seluruh suite';
        }

        return $argumen;
    }

    /**
     * Validasi target uji kustom agar tidak ada masukan bebas yang bisa
     * disalahgunakan. Hanya menerima jalur relatif di dalam `tests/`.
     */
    private function sanitasiTarget(?string $target): ?string
    {
        if ($target === null || trim($target) === '') {
            return null;
        }

        $target = trim(str_replace('\\', '/', $target));

        $pola = '#^tests/[A-Za-z0-9_./-]+$#';

        if (! preg_match($pola, $target) || str_contains($target, '..')) {
            throw new RuntimeException(
                'Target uji tidak sah. Gunakan jalur di dalam folder tests/ '
                .'(contoh: tests/Feature/NamaTest.php).'
            );
        }

        return $target;
    }

    /**
     * Eksekusi subproses dengan environment terisolasi.
     *
     * @return array{0:int,1:string,2:int} [exitCode, gabungan stdout+stderr, durasi ms]
     */
    private function eksekusi(array $perintah): array
    {
        /*
         | Sesuai prosedur wajib (docs/PANDUAN-PENGUJIAN.md), cache dibersihkan
         | lebih dahulu. Kegagalan pembersihan tidak menggagalkan pengujian,
         | tetapi dicatat pada keluaran agar terlihat di UI.
         */
        $pra = $this->jalankanProses([$this->phpBinary(), 'artisan', 'optimize:clear'], 120);

        $mulai = microtime(true);
        [$exitCode, $keluaran] = $this->jalankanProses($perintah, 1800);
        $durasi = (int) round((microtime(true) - $mulai) * 1000);

        if ($exitCode === self::EXIT_GAGAL_MULAI) {
            return [
                self::EXIT_GAGAL_MULAI,
                "[PRA-UJI] optimize:clear (exit {$pra[0]})\n{$pra[1]}\n\n"
                    .'Gagal menjalankan proses pengujian: '.$keluaran,
                $durasi,
            ];
        }

        $keluaran = "[PRA-UJI] optimize:clear (exit {$pra[0]})\n"
            .mb_substr($pra[1], -2000)
            ."\n\n[PENGUJIAN]\n".$keluaran;

        return [$exitCode, $keluaran, $durasi];
    }

    /**
     * Jalankan perintah TANPA shell dan tangkap keluaran lewat berkas di
     * direktori sementara yang dapat ditulis.
     *
     * MENGAPA TIDAK MEMAKAI `Symfony\Component\Process\Process`
     * --------------------------------------------------------
     * Di Windows, Process memakai `Pipes\WindowsPipes` yang membuat berkas
     * sementara lewat `sys_get_temp_dir()` (WindowsPipes.php:50). Nilai itu
     * diambil dari proses INDUK (Apache/mod_php), yang di lingkungan Laragon
     * sering menunjuk ke `C:\Windows` — tidak dapat ditulis oleh akun layanan
     * web. Akibatnya subproses selalu gagal start:
     *   "A temporary file could not be opened to write the process output:
     *    fopen(C:\Windows\sf_proc_00.out.lock): Failed to open stream: Permission denied".
     * `sys_get_temp_dir()` di-cache per-proses sehingga `putenv`/`ini_set`
     * tidak dapat diandalkan untuk mengubahnya. Karena itu keluaran ditangkap
     * langsung dengan `proc_open()` + deskriptor berkas di `storage/app/tmp`,
     * sehingga tidak bergantung pada temp dir sistem.
     *
     * @return array{0:int,1:string} [exitCode, gabungan stdout+stderr]
     */
    private function jalankanProses(array $perintah, int $timeout): array
    {
        $dir = $this->direktoriTemp();
        $out = $dir.DIRECTORY_SEPARATOR.'uji_out_'.bin2hex(random_bytes(6)).'.log';
        $err = $dir.DIRECTORY_SEPARATOR.'uji_err_'.bin2hex(random_bytes(6)).'.log';

        $deskriptor = [
            0 => ['file', $this->perangkatNul(), 'r'],
            1 => ['file', $out, 'w'],
            2 => ['file', $err, 'w'],
        ];

        $proses = @proc_open(
            $perintah,
            $deskriptor,
            $pipa,
            base_path(),
            $this->environmentSubproses($dir)
        );

        if (! is_resource($proses)) {
            @unlink($out);
            @unlink($err);

            return [self::EXIT_GAGAL_MULAI, 'Gagal memulai proses (proc_open).'];
        }

        $mulai = microtime(true);
        $exitCode = self::EXIT_GAGAL_MULAI;

        while (true) {
            $status = proc_get_status($proses);

            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }

            if ($timeout > 0 && (microtime(true) - $mulai) > $timeout) {
                proc_terminate($proses, 9);
                $exitCode = self::EXIT_GAGAL_MULAI;
                break;
            }

            usleep(200000); // 200 ms
        }

        proc_close($proses);

        $keluaran = trim(
            (is_file($out) ? (string) file_get_contents($out) : '')
            ."\n"
            .(is_file($err) ? (string) file_get_contents($err) : '')
        );

        @unlink($out);
        @unlink($err);

        return [(int) $exitCode, $keluaran];
    }

    /**
     * Direktori sementara aplikasi yang pasti dapat ditulis.
     */
    private function direktoriTemp(): string
    {
        $dir = storage_path('app/tmp');

        try {
            File::ensureDirectoryExists($dir, 0775, true);
        } catch (Throwable) {
            // abaikan; dicek di bawah.
        }

        if (! is_dir($dir) || ! is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }

        return rtrim($dir, '/\\');
    }

    /**
     * Environment untuk subproses: lingkungan saat ini + TMP/TEMP/TMPDIR ke
     * direktori yang dapat ditulis (dipakai PHP anak, mis. `artisan test`).
     */
    private function environmentSubproses(string $dir): array
    {
        $env = getenv();

        if (! is_array($env)) {
            $env = [];
        }

        $env['TMP'] = $dir;
        $env['TEMP'] = $dir;
        $env['TMPDIR'] = $dir;

        /*
         | PENTING — jangan warisi APP_ENV dari proses web.
         |
         | Saat melayani permintaan HTTP, Laravel memuat `.env` dan mengekspor
         | APP_ENV=local ke lingkungan proses (via putenv). Bila nilai itu
         | diteruskan sebagai variabel lingkungan NYATA ke subproses PHPUnit,
         | ia akan MENANG atas `<env name="APP_ENV" value="testing"/>` di
         | phpunit.xml (yang secara bawaan tidak memaksa). Akibatnya suite
         | berjalan sebagai `local`: middleware CSRF aktif → semua POST uji
         | gagal dengan HTTP 419. Karena itu APP_ENV dipaksa `testing` di sini.
         */
        $env['APP_ENV'] = 'testing';

        return $env;
    }

    /**
     * Perangkat "null" sesuai platform.
     */
    private function perangkatNul(): string
    {
        return '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null';
    }

    /**
     * Terjemahkan keluaran PHPUnit menjadi ringkasan terstruktur.
     *
     * Mendukung dua format keluaran:
     *   • Ringkas Laravel (`Tests:  30 passed (137 assertions)`).
     *   • PHPUnit standar (`OK (30 tests, 137 assertions)` dan
     *     `Tests: 30, Assertions: 137, Failures: 1`).
     */
    private function ringkasKeluaran(string $keluaran, int $exitCode): array
    {
        $ringkas = [
            'status' => $exitCode === 0 ? TestRun::STATUS_PASSED : TestRun::STATUS_FAILED,
            'jumlah_tes' => 0,
            'lulus' => 0,
            'gagal' => 0,
            'dilewati' => 0,
            'assertions' => 0,
            'detail' => [],
        ];

        // 1) Ringkas Laravel: "Tests:  30 passed (137 assertions)".
        if (preg_match(
            '/Tests:\s+([\d,]+)\s+passed(?:\s*\(([\d,]+)\s+assertions?\))?/i',
            $keluaran,
            $m1
        )) {
            $ringkas['lulus'] = $this->angka($m1[1]);
            $ringkas['jumlah_tes'] = $ringkas['lulus'];
            $ringkas['assertions'] = isset($m1[2]) ? $this->angka($m1[2]) : 0;
        }

        // 2) Baris agregat PHPUnit: "Tests: 30, Assertions: 137, Failures: 1".
        if (preg_match('/Tests:\s*([\d,]+)/i', $keluaran, $m2) && $ringkas['jumlah_tes'] === 0) {
            $ringkas['jumlah_tes'] = $this->angka($m2[1]);
        }
        if (preg_match('/Assertions:\s*([\d,]+)/i', $keluaran, $m3) && $ringkas['assertions'] === 0) {
            $ringkas['assertions'] = $this->angka($m3[1]);
        }
        if (preg_match('/Failures:\s*([\d,]+)/i', $keluaran, $m4)) {
            $ringkas['gagal'] += $this->angka($m4[1]);
        }
        if (preg_match('/Errors:\s*([\d,]+)/i', $keluaran, $m5)) {
            $ringkas['gagal'] += $this->angka($m5[1]);
        }
        if (preg_match('/Skipped:\s*([\d,]+)/i', $keluaran, $m6)) {
            $ringkas['dilewati'] = $this->angka($m6[1]);
        }

        // 3) Baris "OK (30 tests, 137 assertions)".
        if (preg_match('/OK\s*\(([\d,]+)\s+tests?,\s*([\d,]+)\s+assertions?\)/i', $keluaran, $m7)) {
            $ringkas['jumlah_tes'] = $this->angka($m7[1]);
            $ringkas['lulus'] = $ringkas['jumlah_tes'];
            $ringkas['assertions'] = $this->angka($m7[2]);
        }

        // 4) Fallback agregat bila tidak ada baris ringkas sama sekali.
        if ($ringkas['jumlah_tes'] === 0) {
            preg_match_all('/\bPASSED\b/i', $keluaran, $pass);
            preg_match_all('/\bFAILED\b/i', $keluaran, $fail);
            $ringkas['lulus'] = count($pass[0] ?? []);
            $ringkas['gagal'] = max($ringkas['gagal'], count($fail[0] ?? []));
            $ringkas['jumlah_tes'] = $ringkas['lulus'] + $ringkas['gagal'];
        }

        if ($ringkas['lulus'] === 0 && $ringkas['jumlah_tes'] > 0) {
            $ringkas['lulus'] = max(0, $ringkas['jumlah_tes'] - $ringkas['gagal'] - $ringkas['dilewati']);
        }

        $ringkas['detail'] = $this->parsDetail($keluaran);

        // 5) Tentukan status akhir.
        if ($exitCode === 0 && $ringkas['gagal'] === 0) {
            $ringkas['status'] = TestRun::STATUS_PASSED;
        } elseif ($ringkas['jumlah_tes'] === 0 && $exitCode !== 0) {
            $ringkas['status'] = TestRun::STATUS_ERROR;
        } else {
            $ringkas['status'] = TestRun::STATUS_FAILED;
        }

        return $ringkas;
    }

    /**
     * Ambil baris-baris hasil per-tes bila keluaran memuatnya (format verbose).
     */
    private function parsDetail(string $keluaran): array
    {
        $detail = [];

        foreach (preg_split('/\r?\n/', $keluaran) as $baris) {
            if (preg_match('/^\s*(PASS|FAIL|WARN|SKIP)\s+(.+)$/', $baris, $m)) {
                $detail[] = [
                    'status' => strtoupper($m[1]),
                    'nama' => trim($m[2]),
                    'pesan' => null,
                ];
            }
        }

        return array_slice($detail, 0, 300);
    }

    private function angka(string $nilai): int
    {
        return (int) str_replace(',', '', $nilai);
    }

    /**
     * Lokasi biner PHP CLI yang dipakai untuk subproses.
     *
     * Di bawah Apache (mod_php) PHP_BINARY dapat menunjuk ke biner web server
     * (mis. httpd.exe), bukan PHP — menjalankannya akan gagal. Karena itu
     * binari CLI pada direktori instalasi PHP (PHP_BINDIR) diutamakan.
     */
    private function phpBinary(): string
    {
        $bindir = rtrim((string) PHP_BINDIR, '/\\');

        if ($bindir !== '') {
            foreach (['php.exe', 'php'] as $nama) {
                $kandidat = $bindir.DIRECTORY_SEPARATOR.$nama;

                if (is_file($kandidat)) {
                    return $kandidat;
                }
            }
        }

        // Cadangan: PHP_BINARY bila memang biner PHP (bukan CGI/Apache).
        if (PHP_BINARY && is_file(PHP_BINARY)) {
            $nama = strtolower(basename(PHP_BINARY));

            if (str_starts_with($nama, 'php') && ! str_contains($nama, 'cgi')) {
                return PHP_BINARY;
            }
        }

        return 'php';
    }
}
