<?php

namespace App\Console\Commands;

use App\Notifications\CadanganGagalNotification;
use App\Support\CadanganTerenkripsi;
use App\Support\NotifikasiOperator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * CadangkanBasisData — cadangan basis data terjadwal (mysqldump).
 *
 * MENGAPA COMMAND INI ADA
 * -----------------------
 * Seluruh nilai keuangan koperasi (jurnal, buku besar, saldo, PII anggota)
 * hanya hidup di satu basis data MySQL tanpa replika. Tanpa cadangan
 * terjadwal, satu kegagalan disk atau `DELETE` yang salah berarti kehilangan
 * permanen — dan seluruh laporan PSAK/neraca tidak dapat direkonstruksi
 * karena jurnal adalah satu-satunya sumber nilai.
 *
 * PERILAKU
 * --------
 *   1. `mysqldump --single-transaction --routines` → gzip → enkripsi
 *      XChaCha20-Poly1305 (libsodium) menjadi `.sql.gz.enc` di
 *      `storage/app/backups`. Opsi `--single-transaction` membuat dump
 *      konsisten (InnoDB) TANPA mengunci tabel, sehingga teller tetap bisa
 *      bertransaksi selama pencadangan. Plaintext tidak pernah menyentuh disk.
 *   2. Bila `--salinan-tujuan` diisi, berkas yang sudah terenkripsi disalin
 *      ke lokasi kedua (disk lain/USB/host cadangan). Kegagalan menyalin
 *      membuat command GAGAL agar operator tidak mengira salinan offsite ada.
 *   3. Berkas berumur lebih dari `--simpan-hari` dihapus (retensi).
 *   4. Kegagalan dicatat sebagai CRITICAL di log DAN dikirim sebagai
 *      notifikasi ke pemegang `pengurus.operasional`, karena berkas log tidak
 *      pernah dibaca siapa pun pada saat kejadian; command mengembalikan exit
 *      code non-nol sehingga scheduler/operator dapat mendeteksinya.
 *
 * PENGEMBALIAN DATA
 * -----------------
 *   php artisan db:buka-cadangan storage/app/backups/koperasi_syariah-YYYYmmdd-His.sql.gz.enc
 *   gunzip -c koperasi_syariah-YYYYmmdd-His.sql.gz |
 *     mysql -u root -p koperasi_syariah
 *
 * Enkripsi dapat dimatikan dengan `BACKUP_ENKRIPSI=false` (tidak disarankan).
 */
class CadangkanBasisData extends Command
{
    protected $signature = 'db:cadangkan
                            {--simpan-hari=14 : Jumlah hari retensi berkas cadangan}
                            {--tujuan= : Direktori tujuan (default dari config/cadangan.php)}
                            {--salinan-tujuan= : Direktori salinan offsite (opsional, disk/USB/host lain)}
                            {--lewati-bila-hari-ini-ada : Lewati bila cadangan untuk tanggal hari ini sudah ada di direktori tujuan}';

    protected $description = 'Membuat cadangan basis data (mysqldump + gzip) dan membersihkan cadangan kedaluwarsa';

    public function handle(): int
    {
        $config = config('database.connections.'.config('database.default'));

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->error('Command ini hanya mendukung driver MySQL (driver aktif: '.($config['driver'] ?? 'tidak diketahui').').');

            return self::FAILURE;
        }

        $tujuan = $this->option('tujuan') ?: (string) config('cadangan.direktori', storage_path('app/backups'));

        if (! $this->siapkanDirektori($tujuan, 'Direktori cadangan')) {
            return self::FAILURE;
        }

        $enkripsi = CadanganTerenkripsi::aktif();

        // Jadwal berjalan tiap jam (bukan hanya 01:00) agar hari yang terlewat
        // — misalnya komputer mati pukul 01:00 — dikejar pada jam berikutnya.
        // Tanpa penyaring ini, cadangan akan dibuat berulang setiap jam.
        if ($this->option('lewati-bila-hari-ini-ada') && $this->sudahAdaCadanganHariIni($tujuan)) {
            $this->info('Cadangan untuk hari ini sudah ada — pembuatan dilewati.');

            return self::SUCCESS;
        }

        try {
            $kunci = $enkripsi ? CadanganTerenkripsi::kunci() : null;
        } catch (Throwable $e) {
            $this->gagalkan('Enkripsi cadangan tidak dapat disiapkan: '.$e->getMessage());

            return self::FAILURE;
        }

        $namaBerkas = sprintf(
            '%s-%s.sql.gz%s',
            $config['database'],
            now()->format('Ymd-His'),
            $enkripsi ? '.enc' : ''
        );
        $jalur = $tujuan.DIRECTORY_SEPARATOR.$namaBerkas;

        $dump = $this->lokasiMysqldump();

        if ($dump === null) {
            $this->gagalkan('mysqldump tidak ditemukan. Setel DB_DUMP_BINARY pada .env.');

            return self::FAILURE;
        }

        $perintah = [
            $dump,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            // Kredensial lewat MYSQL_PWD (env) agar tidak tampil pada daftar proses.
            '--single-transaction',
            '--quick',
            '--routines',
            '--default-character-set=utf8mb4',
            '--no-tablespaces',
            $config['database'],
        ];

        $berkas = null;
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        if ($deflate === false) {
            $this->gagalkan('Kompresor gzip tidak tersedia pada PHP ini.');

            return self::FAILURE;
        }

        try {
            $berkas = fopen($jalur, 'wb');

            if ($berkas === false) {
                throw new \RuntimeException("Berkas cadangan tidak dapat ditulis: {$jalur}");
            }

            $state = null;

            if ($enkripsi) {
                [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($kunci);
                fwrite($berkas, $header);
            }

            // Aliran bertahap: stdout mysqldump → deflate → (opsional) enkripsi
            // → berkas. Isi basis data tidak pernah ditampung utuh di memori
            // dan plaintext tidak pernah menyentuh disk.
            $tulis = function (string $data) use ($berkas, &$state): void {
                if ($data === '') {
                    return;
                }

                if ($state !== null) {
                    // Panjang plaintext ditulis eksplisit: fread() dapat
                    // mengembalikan potongan lebih pendek dari yang diminta,
                    // sehingga pembaca tidak boleh menebak batas potongan.
                    if (fwrite($berkas, pack('N', strlen($data))) === false) {
                        throw new \RuntimeException('Berkas cadangan tidak dapat ditulis (disk penuh?).');
                    }

                    $data = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $data,
                        '',
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                    );
                }

                if (fwrite($berkas, $data) === false) {
                    throw new \RuntimeException('Berkas cadangan tidak dapat ditulis (disk penuh?).');
                }
            };

            $adaKeluaran = false;

            $proses = new Process($perintah, base_path(), [
                'MYSQL_PWD' => (string) ($config['password'] ?? ''),
            ]);
            $proses->setTimeout(1800);

            $proses->start(function ($tipe, $baris) use ($tulis, $deflate, &$adaKeluaran) {
                if ($tipe !== Process::OUT || $baris === '') {
                    return;
                }

                $adaKeluaran = true;
                $padat = deflate_add($deflate, $baris, ZLIB_NO_FLUSH);

                if ($padat === false) {
                    throw new \RuntimeException('Kompresi cadangan gagal.');
                }

                $tulis($padat);
            });

            $proses->wait();

            if (! $proses->isSuccessful()) {
                throw new \RuntimeException(trim((string) $proses->getErrorOutput()) ?: 'mysqldump gagal tanpa keluaran.');
            }

            if (! $adaKeluaran) {
                throw new \RuntimeException('Keluaran mysqldump kosong — cadangan dibatalkan.');
            }

            $sisa = deflate_add($deflate, '', ZLIB_FINISH);

            if ($sisa === false) {
                throw new \RuntimeException('Kompresi cadangan tidak dapat diselesaikan.');
            }

            $tulis($sisa);

            if ($state !== null) {
                fwrite($berkas, pack('N', 0));
                fwrite($berkas, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    '',
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                ));
            }

            fclose($berkas);
            $berkas = null;
            @chmod($jalur, 0600);

            $ukuran = File::size($jalur);
        } catch (Throwable $e) {
            if (is_resource($berkas)) {
                fclose($berkas);
            }

            File::delete($jalur);

            Log::critical('Cadangan basis data GAGAL — data belum aman.', [
                'database' => $config['database'],
                'error' => $e->getMessage(),
            ]);

            $this->gagalkan('Cadangan gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Cadangan dibuat: %s (%.1f KB)%s',
            $jalur,
            $ukuran / 1024,
            $enkripsi ? ' [terenkripsi]' : ''
        ));

        // Salinan offsite: menyalin berkas yang SUDAH terenkripsi apa adanya,
        // sehingga kunci yang sama dapat memulihkannya. Kegagalan di sini
        // membuat command GAGAL — cadangan yang hanya ada di satu disk tidak
        // melindungi dari kerusakan disk itu sendiri.
        // Opsi `--salinan-tujuan` menang; bila tidak dikirim (mis. dari jadwal
        // tiap jam), pakai `BACKUP_SALINAN_DIREKTORI` di .env.
        $salinan = $this->option('salinan-tujuan') ?: config('cadangan.salinan_direktori');

        if (is_string($salinan) && $salinan !== '') {
            if (! $this->siapkanDirektori($salinan, 'Direktori salinan offsite')) {
                return self::FAILURE;
            }

            $tujuanSalinan = rtrim($salinan, '\\/').DIRECTORY_SEPARATOR.$namaBerkas;

            try {
                if (! File::copy($jalur, $tujuanSalinan)) {
                    throw new \RuntimeException("Berkas tidak dapat disalin ke {$tujuanSalinan}");
                }

                @chmod($tujuanSalinan, 0600);
            } catch (Throwable $e) {
                File::delete($tujuanSalinan);

                Log::critical('Salinan offsite cadangan GAGAL — cadangan hanya ada di satu lokasi.', [
                    'berkas' => $namaBerkas,
                    'tujuan' => $salinan,
                    'error' => $e->getMessage(),
                ]);

                $this->gagalkan('Cadangan lokal berhasil, tetapi salinan offsite GAGAL: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->info('Salinan offsite dibuat: '.$tujuanSalinan);
        }

        Log::info('Cadangan basis data berhasil.', [
            'berkas' => $namaBerkas,
            'ukuran' => $ukuran,
            'database' => $config['database'],
            'terenkripsi' => $enkripsi,
        ]);

        $this->bersihkanCadanganLama($tujuan, (int) $this->option('simpan-hari'));

        return self::SUCCESS;
    }

    /**
     * Pastikan direktori tersedia; bila gagal, laporkan ke operator.
     *
     * `File::makeDirectory()` MELEMPAR exception (bukan mengembalikan false)
     * ketika salah satu segmen jalur adalah berkas biasa atau induknya tidak
     * dapat diakses. Tanpa penangkap ini, kegagalan paling awal justru lolos
     * sebagai stack trace tanpa notifikasi ke pengurus.
     */
    private function siapkanDirektori(string $direktori, string $sebutan): bool
    {
        if (File::isDirectory($direktori)) {
            return true;
        }

        try {
            File::makeDirectory($direktori, 0700, true);
        } catch (Throwable $e) {
            $this->gagalkan("{$sebutan} tidak dapat dibuat: {$direktori} ({$e->getMessage()})");

            return false;
        }

        if (! File::isDirectory($direktori)) {
            $this->gagalkan("{$sebutan} tidak dapat dibuat: {$direktori}");

            return false;
        }

        return true;
    }

    /**
     * Laporkan kegagalan pencadangan ke layar, log, dan kotak masuk pengurus.
     *
     * Setiap kegagalan dikirim (tanpa pembatas per jam): penyebabnya bisa
     * berbeda-beda, dan kegagalan berikutnya sering menjadi akibat dari
     * kegagalan sebelumnya (misalnya disk penuh).
     */
    private function gagalkan(string $alasan): void
    {
        $this->error($alasan);

        NotifikasiOperator::kirim(
            CadanganGagalNotification::dari($alasan),
            'pengurus.operasional'
        );
    }

    /**
     * Apakah direktori tujuan sudah memuat cadangan bertanggal hari ini?
     *
     * Tanggal dibaca dari nama berkas (`<database>-YYYYmmdd-His.sql.gz[.enc]`)
     * yang dibuat memakai zona waktu aplikasi, bukan dari waktu modifikasi
     * berkas, agar hasilnya tidak bergeser bila zona waktu server berbeda.
     */
    private function sudahAdaCadanganHariIni(string $tujuan): bool
    {
        $pola = '/-'.now()->format('Ymd').'-\d{6}\.sql\.gz(\.enc)?$/';

        foreach (File::glob($tujuan.DIRECTORY_SEPARATOR.'*.sql.gz*') as $berkas) {
            if (preg_match($pola, basename($berkas)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hapus berkas cadangan yang lebih tua dari batas retensi.
     */
    private function bersihkanCadanganLama(string $tujuan, int $hari): void
    {
        if ($hari <= 0) {
            return;
        }

        $batas = now()->subDays($hari)->getTimestamp();
        $terhapus = 0;

        // Cadangan lama (.sql.gz) dan baru (.sql.gz.enc) sama-sama dibersihkan
        // agar berkas tak terenkripsi dari versi sebelumnya tidak menumpuk.
        $kandidat = array_merge(
            File::glob($tujuan.DIRECTORY_SEPARATOR.'*.sql.gz'),
            File::glob($tujuan.DIRECTORY_SEPARATOR.'*.sql.gz.enc')
        );

        foreach ($kandidat as $berkas) {
            if (File::lastModified($berkas) < $batas) {
                File::delete($berkas);
                $terhapus++;
            }
        }

        if ($terhapus > 0) {
            $this->line("{$terhapus} berkas cadangan kedaluwarsa (> {$hari} hari) dihapus.");
        }
    }

    /**
     * Tentukan biner mysqldump: env DB_DUMP_BINARY, lalu PATH, lalu Laragon.
     */
    private function lokasiMysqldump(): ?string
    {
        $kandidat = array_filter([
            env('DB_DUMP_BINARY'),
            'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe',
        ]);

        foreach ($kandidat as $jalur) {
            if (is_file($jalur)) {
                return $jalur;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $jalur = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.'mysqldump.exe';

            if (is_file($jalur)) {
                return $jalur;
            }
        }

        return null;
    }
}
