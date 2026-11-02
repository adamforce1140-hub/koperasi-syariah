<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\LaporanKeuanganLog;
use App\Models\User;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RBAC + audit log modul Laporan Keuangan.
 *
 * Menguji enam hal:
 *   1. Matriks hak akses per peran (langkah 1).
 *   2. Setiap aktivitas tercatat lengkap: identitas, peran, aksi, timestamp,
 *      perubahan data (langkah 2).
 *   3. Peran hanya bisa mengakses sesuai wewenang; akses tidak sah ditolak
 *      DAN tetap tercatat (langkah 3 & 4). Modul audit laporan — termasuk
 *      verifikasi integritas & ekspor log — eksklusif DPS (pengawas
 *      independen), sesuai pemisahan tugas.
 *   4. Laporan audit terfilter peran / rentang waktu / jenis aktivitas
 *      (langkah 5).
 *   5. Integritas tabel log: append-only, dan hash chain mendeteksi
 *      penyuntingan maupun penghapusan baris (langkah 6).
 */
class LaporanKeuanganRbacTest extends TestCase
{
    use DatabaseTransactions;

    /** Peran yang benar-benar ada di ENUM users.role. */
    private const SEMUA_PERAN = ['admin', 'teller', 'manajer', 'pengurus', 'anggota', 'ao', 'dps', 'super_admin'];

    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SEMUA_PERAN as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "rbac.{$peran}@test.local"],
                [
                    'name' => 'Uji '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                ]
            );
        }
    }

    private function sebagai(string $peran): self
    {
        $this->actingAs($this->pengguna[$peran]);

        return $this;
    }

    private function logTerakhir(array $where = []): ?LaporanKeuanganLog
    {
        return LaporanKeuanganLog::where($where)->orderByDesc('id')->first();
    }

    // ═════════════════════════════════════════════════════════════════════
    // LANGKAH 1 — Matriks hak akses
    // ═════════════════════════════════════════════════════════════════════

    public function test_matriks_menetapkan_hak_lihat_unduh_edit_hapus_ekspor_untuk_setiap_peran(): void
    {
        $matriks = LaporanKeuanganPermission::matriksLengkap();

        // Setiap peran pada ENUM harus punya entri eksplisit, tidak boleh
        // mengandalkan fallback — kalau peran baru ditambahkan ke ENUM tanpa
        // dipetakan, test ini gagal.
        foreach (self::SEMUA_PERAN as $peran) {
            $this->assertArrayHasKey(
                $peran,
                $matriks,
                "Peran '{$peran}' belum dipetakan di matriks RBAC Laporan Keuangan."
            );
        }

        // Pengurus: pengelola data akuntansi — tetapi hak menulisnya TIDAK lagi
        // menempel pada modul laporan. Modul laporan hanya-baca; wewenang menulis
        // dipindahkan ke modul tersendiri 'penyesuaian_jurnal' (R19/B11).
        //
        // Laporan Operasional sengaja TIDAK termasuk: rutenya dijaga
        // `izin:laporan.manajer` yang hanya memuat peran Manajer, sehingga hak
        // Pengurus di sana tidak akan pernah dapat dipakai (A5).
        foreach ([
            LaporanKeuanganPermission::MODUL_NERACA,
            LaporanKeuanganPermission::MODUL_BUKU_BESAR,
            LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101,
        ] as $modul) {
            foreach (['lihat', 'unduh', 'ekspor'] as $hak) {
                $this->assertTrue(
                    LaporanKeuanganPermission::boleh('pengurus', $modul, $hak),
                    "Pengurus seharusnya boleh {$hak} pada {$modul}."
                );
            }

            // Hak menulis tidak menempel di modul laporan — untuk peran mana pun.
            $this->assertFalse(
                LaporanKeuanganPermission::boleh('pengurus', $modul, LaporanKeuanganPermission::EDIT),
                "Pengurus TIDAK boleh 'edit' lewat modul laporan {$modul} (pemisahan tugas)."
            );
            $this->assertFalse(
                LaporanKeuanganPermission::boleh('pengurus', $modul, LaporanKeuanganPermission::HAPUS),
                "Pengurus TIDAK boleh 'hapus' lewat modul laporan {$modul} (pemisahan tugas)."
            );
        }

        // Hak mati tidak boleh dicantumkan: matriks harus sejalan dengan izin
        // rute. `manajer.laporan.index` hanya terbuka bagi peran Manajer.
        $this->assertSame(
            [],
            LaporanKeuanganPermission::hak('pengurus', LaporanKeuanganPermission::MODUL_LAPORAN_MANAJER),
            'Pengurus tidak boleh diberi hak pada Laporan Operasional — rutenya khusus Manajer.'
        );

        // Admin sistem tidak memegang izin laporan mana pun pada
        // `config/izin.php`. Matriks harus kosong agar halaman audit tidak
        // menjanjikan akses yang setiap kali dicoba berakhir 403 (A5).
        foreach (LaporanKeuanganPermission::MODUL as $modul) {
            $this->assertSame(
                [],
                LaporanKeuanganPermission::hak('admin', $modul),
                "Admin tidak boleh diberi hak apa pun pada modul {$modul} — tidak ada izin rutenya."
            );
        }
        $this->assertFalse(
            LaporanKeuanganPermission::punyaAksesLaporan('admin'),
            'Admin tidak punya akses laporan keuangan.'
        );

        // Super Admin (puncak administratif): seluruh modul laporan yang
        // rutenya terbuka untuknya WAJIB tercantum di matriks — termasuk
        // Laporan Operasional (`izin:laporan.manajer`) dan Penyesuaian Jurnal
        // (`ckpn.posting`, `dana.cadangan`) yang kini memuat super_admin.
        // Matriks kosong sementara rutenya terbuka akan membuat halaman audit
        // menjanjikan akses yang tidak ada, dan sebaliknya middleware
        // `laporan.akses` memblokir rute yang izinnya sudah diberikan.
        $this->assertSame(
            [
                LaporanKeuanganPermission::LIHAT,
                LaporanKeuanganPermission::UNDUH,
                LaporanKeuanganPermission::EKSPOR,
            ],
            LaporanKeuanganPermission::hak('super_admin', LaporanKeuanganPermission::MODUL_LAPORAN_MANAJER),
            'Super Admin memegang baca penuh atas Laporan Operasional (tanpa hak tulis).'
        );
        $this->assertSame(
            [
                LaporanKeuanganPermission::LIHAT,
                LaporanKeuanganPermission::EDIT,
                LaporanKeuanganPermission::HAPUS,
            ],
            LaporanKeuanganPermission::hak('super_admin', LaporanKeuanganPermission::MODUL_PENYESUAIAN),
            'Super Admin memegang modul Penyesuaian Jurnal sebagaimana Pengurus.'
        );

        // Modul Audit TETAP eksklusif DPS — Super Admin tidak boleh mengaudit
        // dirinya sendiri (pemisahan tugas).
        $this->assertSame(
            [],
            LaporanKeuanganPermission::hak('super_admin', LaporanKeuanganPermission::MODUL_AUDIT),
            'Modul Audit eksklusif DPS — Super Admin tidak boleh dicantumkan.'
        );

        // Modul laporan harus bersifat hanya-baca bagi SEMUA peran...
        foreach (LaporanKeuanganPermission::modulLaporan() as $modul) {
            $this->assertTrue(
                LaporanKeuanganPermission::modulHanyaBaca($modul),
                "Modul laporan {$modul} seharusnya hanya-baca (tanpa hak edit/hapus)."
            );
            $this->assertSame(
                [],
                LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::EDIT),
                "Tidak satu pun peran boleh mengubah data lewat modul laporan {$modul}."
            );
            $this->assertSame(
                [],
                LaporanKeuanganPermission::peranYangBoleh($modul, LaporanKeuanganPermission::HAPUS),
                "Tidak satu pun peran boleh menghapus data lewat modul laporan {$modul}."
            );
        }

        // ...dan wewenang menulis terkumpul pada modul penyesuaian, yang
        // dipegang Pengurus dan Super Admin (puncak administratif). Keduanya
        // pemegang izin rute `ckpn.posting` / `dana.cadangan`; peran lain
        // tidak boleh ikut tercantum.
        $this->assertSame(
            ['super_admin', 'pengurus'],
            LaporanKeuanganPermission::peranYangBoleh(
                LaporanKeuanganPermission::MODUL_PENYESUAIAN,
                LaporanKeuanganPermission::EDIT
            ),
            'Hanya Pengurus dan Super Admin yang boleh memposting penyesuaian jurnal.'
        );
        $this->assertSame(
            ['super_admin', 'pengurus'],
            LaporanKeuanganPermission::peranYangBoleh(
                LaporanKeuanganPermission::MODUL_PENYESUAIAN,
                LaporanKeuanganPermission::HAPUS
            ),
            'Hanya Pengurus dan Super Admin yang boleh menganulir penyesuaian jurnal.'
        );

        // Teller & AO tidak punya akses apa pun ke laporan keuangan.
        foreach (['teller', 'ao'] as $peran) {
            $this->assertFalse(
                LaporanKeuanganPermission::punyaAksesLaporan($peran),
                "Peran '{$peran}' seharusnya tidak punya akses laporan keuangan."
            );
        }

        // DPS hanya boleh melihat, dan hanya pada Neraca & Buku Besar.
        $this->assertTrue(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_NERACA, 'lihat'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_NERACA, 'ekspor'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_NERACA, 'edit'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_NERACA, 'hapus'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('dps', LaporanKeuanganPermission::MODUL_LAPORAN_MANAJER, 'lihat'));

        // Manajer boleh lihat & ekspor Neraca, tapi tidak boleh mengubah.
        $this->assertTrue(LaporanKeuanganPermission::boleh('manajer', LaporanKeuanganPermission::MODUL_NERACA, 'ekspor'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('manajer', LaporanKeuanganPermission::MODUL_NERACA, 'edit'));

        // Peran tak dikenal / null ditolak (fail-closed).
        $this->assertFalse(LaporanKeuanganPermission::boleh(null, LaporanKeuanganPermission::MODUL_NERACA, 'lihat'));
        $this->assertFalse(LaporanKeuanganPermission::boleh('peran_karangan', LaporanKeuanganPermission::MODUL_NERACA, 'lihat'));
        // Peran anggota tidak mengenal modul laporan keuangan (fail-closed).
        $this->assertFalse(LaporanKeuanganPermission::boleh('anggota', LaporanKeuanganPermission::MODUL_NERACA, 'lihat'));
        // Nilai lama 'nasabah' bukan nama peran pada ENUM users.role.
        $this->assertFalse(LaporanKeuanganPermission::boleh('nasabah', LaporanKeuanganPermission::MODUL_NERACA, 'lihat'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // LANGKAH 3 — Penegakan akses per peran
    // ═════════════════════════════════════════════════════════════════════

    public function test_peran_yang_berwenang_dapat_membuka_neraca(): void
    {
        foreach (['pengurus', 'manajer', 'dps'] as $peran) {
            $this->sebagai($peran)->get(route('laporan.neraca'))->assertOk();
        }
    }

    public function test_peran_tanpa_wewenang_ditolak_403_pada_neraca(): void
    {
        foreach (['teller', 'ao', 'anggota'] as $peran) {
            $this->sebagai($peran)->get(route('laporan.neraca'))->assertForbidden();
        }
    }

    public function test_peran_yang_berwenang_dapat_membuka_buku_besar(): void
    {
        foreach (['pengurus', 'manajer', 'dps'] as $peran) {
            $this->sebagai($peran)->get(route('laporan.buku-besar'))->assertOk();
        }
    }

    public function test_peran_tanpa_wewenang_ditolak_403_pada_buku_besar(): void
    {
        foreach (['teller', 'ao', 'anggota'] as $peran) {
            $this->sebagai($peran)->get(route('laporan.buku-besar'))->assertForbidden();
        }
    }

    public function test_laporan_manajer_hanya_untuk_manajer(): void
    {
        // Dua lapis berbeda bekerja di sini, dan keduanya dikunci test ini:
        //   · WEWENANG — matriks memberi Manajer hak lihat/unduh/ekspor pada
        //     modul Laporan Operasional, dan itulah yang dibaca middleware
        //     `laporan.akses:laporan_manajer` untuk meloloskan rutenya.
        //   · KETERSEDIAAN — `ManajerController::FITUR_DISEMBUNYIKAN` memuat
        //     'laporan', sehingga halamannya dialihkan ke dasbor.
        // Hak di matriks TIDAK dicabut karena yang menyembunyikan halaman
        // adalah keputusan fitur, bukan pencabutan wewenang. Mencabutnya akan
        // membuat rute berbalik menjadi 403 (bukan redirect) dan menutup jalan
        // menghidupkan kembali halaman tanpa menyentuh RBAC.
        foreach ([
            LaporanKeuanganPermission::LIHAT,
            LaporanKeuanganPermission::UNDUH,
            LaporanKeuanganPermission::EKSPOR,
        ] as $hak) {
            $this->assertTrue(
                LaporanKeuanganPermission::boleh('manajer', LaporanKeuanganPermission::MODUL_LAPORAN_MANAJER, $hak),
                "Matriks harus tetap memberi Manajer hak '{$hak}' pada Laporan Operasional."
            );
        }

        // Akses langsung via URL diarahkan ulang dengan aman ke dashboard.
        $this->sebagai('manajer')
            ->get(route('manajer.laporan.index'))
            ->assertRedirect(route('dashboard.manajer'));

        foreach (['teller', 'ao', 'dps', 'anggota'] as $peran) {
            $this->sebagai($peran)->get(route('manajer.laporan.index'))->assertForbidden();
        }
    }

    public function test_pengurus_ditolak_membuka_laporan_manajer_dan_penolakan_tercatat(): void
    {
        // Rute `manajer.laporan.index` dijaga `izin:laporan.manajer` yang hanya
        // memuat peran Manajer, jadi Pengurus ditolak. Matriks pun tidak lagi
        // mencantumkan hak Pengurus di modul ini (A5) agar tidak menjanjikan
        // akses yang mustahil. Yang dipastikan di sini: penolakan itu tetap
        // tercatat (tidak lolos audit).
        $sebelum = LaporanKeuanganLog::count();

        $this->sebagai('pengurus')->get(route('manajer.laporan.index'))->assertForbidden();

        $this->assertSame($sebelum + 1, LaporanKeuanganLog::count());
        $this->assertSame('laporan_manajer', $this->logTerakhir()?->modul);
    }

    public function test_ekspor_neraca_menuntut_hak_ekspor_bukan_sekadar_hak_lihat(): void
    {
        // DPS hanya punya hak lihat — tidak boleh mengekspor.
        $this->sebagai('dps')
            ->get(route('laporan.neraca', ['export' => 'excel']))
            ->assertForbidden();

        $log = $this->logTerakhir();
        $this->assertNotNull($log);
        $this->assertSame('ekspor', $log->aktivitas);
        $this->assertSame('dps', $log->role);
        $this->assertFalse($log->berhasil);
        $this->assertSame(403, $log->status_http);

        // Manajer & Pengurus punya hak ekspor.
        foreach (['manajer', 'pengurus'] as $peran) {
            $this->sebagai($peran)
                ->get(route('laporan.neraca', ['export' => 'excel']))
                ->assertOk();
        }
    }

    public function test_dps_tidak_boleh_membuka_buku_besar_akun_non_ekuitas(): void
    {
        // Pembatasan per-akun yang sudah ada harus tetap berlaku di bawah RBAC baru.
        $this->sebagai('dps')
            ->get(route('laporan.buku-besar', ['kode_akun' => '1-1001']))
            ->assertForbidden();

        $log = $this->logTerakhir();
        $this->assertNotNull($log);
        $this->assertSame('dps', $log->role);
        $this->assertSame('buku_besar', $log->modul);
        $this->assertFalse($log->berhasil);
    }

    public function test_mengubah_parameter_export_tidak_bisa_menembus_rbac(): void
    {
        // Upaya mengekspor dengan menambahkan parameter lain tetap dinilai
        // sebagai aksi 'ekspor' dan tetap ditolak untuk peran tanpa hak itu.
        $this->sebagai('dps')
            ->get(route('laporan.neraca', ['export' => 'excel', 'mode' => 'akhir_bulan', 'tanggal' => now()->toDateString()]))
            ->assertForbidden();

        $this->assertSame('ekspor', $this->logTerakhir()?->aktivitas);
    }

    // ═════════════════════════════════════════════════════════════════════
    // LANGKAH 2 & 4 — Kelengkapan isi log
    // ═════════════════════════════════════════════════════════════════════

    public function test_aktivitas_berhasil_tercatat_lengkap(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $log = $this->logTerakhir();

        $this->assertNotNull($log, 'Aktivitas berhasil tidak tercatat sama sekali.');
        $this->assertSame($this->pengguna['manajer']->id, $log->user_id, 'Identitas pengguna tidak tercatat.');
        $this->assertSame('Uji Manajer', $log->user_nama);
        $this->assertSame('manajer', $log->role, 'Peran yang digunakan tidak tercatat.');
        $this->assertSame('neraca', $log->modul);
        $this->assertSame('lihat', $log->aktivitas, 'Jenis aktivitas tidak tercatat.');
        $this->assertNotNull($log->created_at, 'Timestamp akses tidak tercatat.');
        $this->assertTrue($log->berhasil);
        $this->assertSame(200, $log->status_http);
        $this->assertSame('laporan.neraca', $log->route);
        $this->assertSame('GET', $log->metode);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->hash, 'Baris log tidak disegel hash.');
    }

    public function test_parameter_permintaan_tersimpan_sebagai_konteks_data(): void
    {
        $this->sebagai('pengurus')->get(route('laporan.neraca', [
            'mode' => 'akhir_bulan',
            'tanggal' => '2026-01-31',
        ]));

        $log = $this->logTerakhir();

        $this->assertIsArray($log->parameter);
        $this->assertSame('akhir_bulan', $log->parameter['mode']);
        $this->assertSame('2026-01-31', $log->parameter['tanggal']);
        $this->assertStringContainsString('akhir_bulan', $log->deskripsi);
    }

    public function test_akses_tidak_sah_ditolak_dan_tetap_tercatat(): void
    {
        $sebelum = LaporanKeuanganLog::count();

        $this->sebagai('teller')->get(route('laporan.neraca'))->assertForbidden();

        $this->assertSame($sebelum + 1, LaporanKeuanganLog::count(), 'Percobaan akses tidak sah lolos dari audit.');

        $log = $this->logTerakhir();
        $this->assertSame('teller', $log->role);
        $this->assertSame($this->pengguna['teller']->id, $log->user_id);
        $this->assertSame('lihat', $log->aktivitas);
        $this->assertFalse($log->berhasil);
        $this->assertSame(403, $log->status_http);
        $this->assertStringContainsString('DITOLAK', $log->deskripsi);
    }

    public function test_setiap_jenis_aktivitas_yang_mungkin_terjadi_terwakili_di_log(): void
    {
        // lihat — Manajer membuka Neraca
        $this->sebagai('manajer')->get(route('laporan.neraca'))->assertOk();
        // ekspor — Manajer mengekspor Neraca
        $this->sebagai('manajer')->get(route('laporan.neraca', ['export' => 'excel']))->assertOk();
        // lihat — Manajer membuka Buku Besar
        $this->sebagai('manajer')->get(route('laporan.buku-besar'))->assertOk();
        // lihat — Manajer membuka Laporan Keuangan Syariah (PSAK 101).
        // Catatan: halaman 'Laporan Operasional' (manajer.laporan.index) kini
        // disembunyikan dari Manajer, jadi aktivitas 'lihat' diambil dari
        // halaman laporan lain yang masih boleh dibuka Manajer.
        $this->sebagai('manajer')->get(route('laporan.psak101'))->assertOk();
        // edit — melalui endpoint tulis modul (alokasi SHU oleh Pengurus)
        $this->sebagai('pengurus')->post(route('pengurus.cadangan.alokasi'), [
            'nominal' => 1000000,
            'keterangan' => 'Uji audit RBAC',
        ]);

        $aktivitas = LaporanKeuanganLog::query()->pluck('aktivitas')->unique()->values()->all();

        foreach (['lihat', 'ekspor', 'edit'] as $aksi) {
            $this->assertContains($aksi, $aktivitas, "Aktivitas '{$aksi}' tidak pernah tercatat di log.");
        }

        $this->assertGreaterThanOrEqual(5, LaporanKeuanganLog::count(), 'Ada aktivitas yang tidak tercatat.');
    }

    /**
     * Penegakan hak 'hapus' & pemisahan tugas.
     *
     * Modul Laporan Keuangan tidak punya endpoint hapus — tidak ada data laporan
     * yang boleh dihapus — sehingga hak ini tidak dapat diuji lewat rute yang
     * ada. Supaya penegakannya tetap terbukti bekerja (bukan sekadar tertulis
     * di matriks), rute uji dipasang lokal di dalam test memakai alias
     * middleware produksi yang sama persis.
     *
     * Dua hal dibuktikan sekaligus:
     *   - pada modul LAPORAN, hak hapus ditolak bahkan untuk Pengurus
     *     (laporan bersifat hanya-baca — inti pemisahan tugas R19/B11);
     *   - pada modul PENYESUAIAN, Pengurus lolos sedangkan Manajer ditolak.
     */
    public function test_hak_hapus_ditegakkan_dan_tercatat(): void
    {
        // Modul LAPORAN: hanya-baca → siapa pun ditolak, termasuk Pengurus.
        Route::middleware(['web', 'auth', 'laporan.akses:neraca'])
            ->delete('/uji-rbac/hapus-neraca', fn () => response()->noContent());

        $this->sebagai('pengurus')->delete('/uji-rbac/hapus-neraca')->assertForbidden();

        $log = $this->logTerakhir();
        $this->assertNotNull($log, 'Percobaan hapus tidak tercatat.');
        $this->assertSame('hapus', $log->aktivitas);
        $this->assertSame('pengurus', $log->role);
        $this->assertFalse($log->berhasil);
        $this->assertSame(403, $log->status_http);

        // Modul PENYESUAIAN: Manajer tidak berwenang → ditolak & tercatat.
        Route::middleware(['web', 'auth', 'laporan.akses:penyesuaian_jurnal'])
            ->delete('/uji-rbac/hapus-penyesuaian', fn () => response()->noContent());

        $this->sebagai('manajer')->delete('/uji-rbac/hapus-penyesuaian')->assertForbidden();

        $log = $this->logTerakhir();
        $this->assertSame('hapus', $log->aktivitas);
        $this->assertSame('manajer', $log->role);
        $this->assertFalse($log->berhasil);
        $this->assertSame(403, $log->status_http);

        // ...sedangkan Pengurus berwenang → lolos gerbang RBAC, tetap tercatat.
        $this->sebagai('pengurus')->delete('/uji-rbac/hapus-penyesuaian')->assertNoContent();

        $log = $this->logTerakhir();
        $this->assertSame('hapus', $log->aktivitas);
        $this->assertSame('pengurus', $log->role);
        $this->assertTrue($log->berhasil);
    }

    public function test_perubahan_data_tercatat_saat_data_laporan_diubah(): void
    {
        // Pastikan saldo Ikhtisar SHU mencukupi supaya jalur pencatatan
        // perubahan data benar-benar dapat diuji. Penyesuaian ini dibatalkan
        // otomatis oleh DatabaseTransactions.
        $coaShu = Coa::findByKode('3-9000');
        $this->assertNotNull($coaShu, 'Akun Ikhtisar SHU (3-9000) tidak ditemukan di CoA.');

        $kekurangan = 10000000 - $coaShu->saldoBerjalan();
        if ($kekurangan > 0) {
            $coaShu->update(['saldo_awal' => $coaShu->saldo_awal + $kekurangan]);
        }

        // Alokasi SHU kini juga dijaga guard profit/loss: ditolak bila posisi
        // keuangan berjalan merugi (pendapatan − beban < 0). Agar jalur
        // pencatatan perubahan data tetap dapat diuji, posisi disiapkan untung
        // dengan menambahkan pendapatan uji pada akun Pendapatan pertama.
        $coaPendapatan = Coa::where('kategori', 'Pendapatan')->where('is_active', true)->orderBy('kode_akun')->first();
        $this->assertNotNull($coaPendapatan, 'Tidak ada akun Pendapatan aktif untuk menyiapkan kondisi untung.');

        $labaBerjalan = Coa::labaBersihBerjalan();
        if ($labaBerjalan < 0) {
            JurnalDetail::create([
                'jurnal_id' => Jurnal::create([
                    'no_jurnal' => 'UJI-RBAC-'.uniqid(),
                    'tanggal_jurnal' => now()->toDateString(),
                    'jenis_jurnal' => 'Jurnal Umum',
                    'sumber' => 'UJI RBAC',
                    'keterangan' => 'Menyiapkan posisi untung untuk uji pencatatan',
                    'total_debet' => 0,
                    'total_kredit' => abs($labaBerjalan) + 1000000,
                    'is_balance' => true,
                    'status' => 'Posted',
                    'posted_at' => now(),
                ])->id,
                'coa_id' => $coaPendapatan->id,
                'baris' => 1,
                'debet' => 0,
                'kredit' => abs($labaBerjalan) + 1000000,
                'keterangan' => 'Menyiapkan posisi untung untuk uji pencatatan',
            ]);
        }

        $this->sebagai('pengurus')->post(route('pengurus.cadangan.alokasi'), [
            'nominal' => 2500000,
            'keterangan' => 'Uji pencatatan perubahan data',
        ]);

        // Percobaan alokasi harus tercatat oleh middleware...
        $this->assertNotNull(
            LaporanKeuanganLog::where('route', 'pengurus.cadangan.alokasi')->first(),
            'Percobaan alokasi SHU tidak tercatat sama sekali.'
        );

        // ...dan dampak perubahannya dicatat oleh controller.
        $log = LaporanKeuanganLog::query()
            ->where('aktivitas', 'edit')
            ->whereNotNull('perubahan_data')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, 'Perubahan data hasil alokasi SHU tidak tercatat.');

        $perubahan = $log->perubahan_data;
        $this->assertIsArray($perubahan);
        $this->assertArrayHasKey('akun_debit', $perubahan);
        $this->assertArrayHasKey('akun_kredit', $perubahan);
        $this->assertSame('3-9000', $perubahan['akun_debit']['kode']);
        $this->assertSame('3-2001', $perubahan['akun_kredit']['kode']);
        $this->assertSame(2500000.0, (float) $perubahan['akun_debit']['nominal']);
        $this->assertArrayHasKey('saldo_cadangan_sebelum', $perubahan);
        $this->assertArrayHasKey('saldo_cadangan_sesudah', $perubahan);
        $this->assertSame(
            2500000.0,
            round($perubahan['saldo_cadangan_sesudah'] - $perubahan['saldo_cadangan_sebelum'], 2),
            'Selisih saldo cadangan tidak sama dengan nominal alokasi yang dicatat.'
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // LANGKAH 5 — Laporan audit terintegrasi & filter
    // ═════════════════════════════════════════════════════════════════════

    public function test_halaman_audit_dapat_dibuka_peran_berwenang_dan_ditolak_untuk_lainnya(): void
    {
        // PEMISAHAN TUGAS: modul audit eksklusif pengawas independen (DPS).
        foreach (['dps'] as $peran) {
            $this->sebagai($peran)->get(route('audit.laporan.index'))->assertOk();
        }

        foreach (['teller', 'ao', 'anggota', 'super_admin', 'admin', 'pengurus', 'manajer'] as $peran) {
            $this->sebagai($peran)->get(route('audit.laporan.index'))->assertForbidden();
        }
    }

    public function test_halaman_audit_menampilkan_riwayat_aktivitas(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $this->sebagai('dps')
            ->get(route('audit.laporan.index'))
            ->assertOk()
            ->assertSee('Audit Laporan Keuangan')
            ->assertSee('Uji Manajer')
            ->assertSee('Neraca');
    }

    public function test_filter_berdasarkan_peran(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('pengurus')->get(route('laporan.buku-besar'));

        $respon = $this->sebagai('dps')
            ->get(route('audit.laporan.index', ['peran' => 'manajer']))
            ->assertOk();

        $logs = $respon->viewData('logs');

        $this->assertGreaterThan(0, $logs->count(), 'Filter peran tidak mengembalikan baris apa pun.');
        foreach ($logs as $log) {
            $this->assertSame('manajer', $log->role, 'Filter peran membocorkan baris peran lain.');
        }
    }

    public function test_filter_berdasarkan_jenis_aktivitas(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('manajer')->get(route('laporan.neraca', ['export' => 'excel']));

        $logs = $this->sebagai('dps')
            ->get(route('audit.laporan.index', ['aktivitas' => 'ekspor']))
            ->assertOk()
            ->viewData('logs');

        $this->assertGreaterThan(0, $logs->count());
        foreach ($logs as $log) {
            $this->assertSame('ekspor', $log->aktivitas);
        }
    }

    public function test_filter_berdasarkan_rentang_waktu(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        // Rentang yang tidak memuat aktivitas hari ini
        $kosong = $this->sebagai('dps')
            ->get(route('audit.laporan.index', [
                'dari' => now()->subYear()->startOfYear()->toDateString(),
                'sampai' => now()->subYear()->endOfYear()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('logs');

        $this->assertSame(0, $kosong->total(), 'Filter rentang waktu tidak menyaring apa pun.');

        // Rentang yang memuat aktivitas hari ini
        $terisi = $this->sebagai('dps')
            ->get(route('audit.laporan.index', [
                'dari' => now()->subDay()->toDateString(),
                'sampai' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('logs');

        $this->assertGreaterThan(0, $terisi->total());
    }

    public function test_filter_gabungan_peran_aktivitas_dan_status(): void
    {
        $this->sebagai('dps')->get(route('laporan.neraca', ['export' => 'excel'])); // ditolak
        $this->sebagai('manajer')->get(route('laporan.neraca', ['export' => 'excel'])); // berhasil

        $logs = $this->sebagai('dps')
            ->get(route('audit.laporan.index', [
                'peran' => 'dps',
                'aktivitas' => 'ekspor',
                'status' => 'ditolak',
            ]))
            ->assertOk()
            ->viewData('logs');

        $this->assertGreaterThan(0, $logs->count());
        foreach ($logs as $log) {
            $this->assertSame('dps', $log->role);
            $this->assertSame('ekspor', $log->aktivitas);
            $this->assertFalse($log->berhasil);
        }
    }

    public function test_ekspor_log_csv_menghasilkan_berkas_terunduh(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $respon = $this->sebagai('dps')->get(route('audit.laporan.ekspor'));

        $respon->assertOk();
        $this->assertStringContainsString('text/csv', $respon->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $respon->headers->get('Content-Disposition'));

        $isi = $respon->streamedContent();
        $this->assertStringContainsString('Peran', $isi);
        $this->assertStringContainsString('Neraca', $isi);
    }

    public function test_peran_tanpa_hak_ekspor_tidak_bisa_mengunduh_log(): void
    {
        // Matriks: hanya DPS yang berwenang membuka modul audit laporan.
        foreach (['super_admin', 'admin', 'pengurus', 'manajer'] as $peran) {
            $this->sebagai($peran)->get(route('audit.laporan.ekspor'))->assertForbidden();
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // LANGKAH 6 — Integritas log
    // ═════════════════════════════════════════════════════════════════════

    public function test_rantai_hash_utuh_pada_kondisi_normal(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('pengurus')->get(route('laporan.buku-besar'));
        $this->sebagai('dps')->get(route('laporan.neraca'));

        $hasil = LaporanKeuanganLog::verifyChain();

        $this->assertTrue($hasil['utuh'], 'Rantai hash tidak utuh: '.($hasil['alasan'] ?? '-'));
        $this->assertGreaterThanOrEqual(3, $hasil['diperiksa']);
        $this->assertNull($hasil['rusak_di']);
    }

    public function test_hash_setiap_baris_menyambung_ke_baris_sebelumnya(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('manajer')->get(route('laporan.buku-besar'));

        $logs = LaporanKeuanganLog::orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $logs->count());

        foreach ($logs as $i => $log) {
            $this->assertSame($log->hitungHash(), $log->hash, "Hash baris #{$log->id} tidak cocok dengan isinya.");

            if ($i === 0) {
                continue;
            }

            $sebelumnya = $logs[$i - 1];
            $this->assertSame($sebelumnya->id, $log->hash_sebelumnya_id, "Baris #{$log->id} tidak merujuk baris sebelumnya.");
            $this->assertSame($sebelumnya->hash, $log->hash_sebelumnya, "Rantai hash putus di baris #{$log->id}.");
        }
    }

    public function test_penyuntingan_langsung_di_database_terdeteksi(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('pengurus')->get(route('laporan.buku-besar'));

        $korban = LaporanKeuanganLog::orderBy('id')->first();

        $this->assertTrue(LaporanKeuanganLog::verifyChain()['utuh']);

        // Menyunting langsung lewat query builder — meniru pihak yang mencoba
        // memalsukan log tanpa melewati model.
        DB::table('laporan_keuangan_logs')
            ->where('id', $korban->id)
            ->update(['deskripsi' => 'Diubah diam-diam']);

        $hasil = LaporanKeuanganLog::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penyuntingan langsung di database tidak terdeteksi.');
        $this->assertSame($korban->id, $hasil['rusak_di']);
        $this->assertStringContainsString('disunting', $hasil['alasan']);
    }

    public function test_penghapusan_baris_di_tengah_rantai_terdeteksi(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));
        $this->sebagai('manajer')->get(route('laporan.buku-besar'));
        $this->sebagai('manajer')->get(route('laporan.buku-besar', ['kode_akun' => '2-1003']));

        $idTengah = LaporanKeuanganLog::orderBy('id')->skip(1)->value('id');

        DB::table('laporan_keuangan_logs')->where('id', $idTengah)->delete();

        $hasil = LaporanKeuanganLog::verifyChain();

        $this->assertFalse($hasil['utuh'], 'Penghapusan baris di tengah rantai tidak terdeteksi.');
        $this->assertStringContainsString('terputus', $hasil['alasan']);
    }

    public function test_model_menolak_penyuntingan_dan_penghapusan(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $log = $this->logTerakhir();

        try {
            $log->deskripsi = 'Coba ubah';
            $log->save();
            $this->fail('Model mengizinkan penyuntingan baris log.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('Model mengizinkan penghapusan baris log.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Baris harus masih ada dan masih utuh
        $this->assertNotNull(LaporanKeuanganLog::find($log->id));
        $this->assertTrue(LaporanKeuanganLog::verifyChain()['utuh']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Anti-regresi: kanonikalisasi kolom JSON dalam rantai hash
    //
    // MySQL tidak menyimpan urutan kunci objek JSON apa adanya — ia
    // mengurutkannya sendiri menurut panjang lalu abjad. Sebelum
    // stringUntukHash() menormalkan urutan kunci, baris yang punya parameter
    // banyak kunci dianggap "disunting" oleh verifyChain() padahal isinya
    // masih asli.
    // ─────────────────────────────────────────────────────────────────────

    public function test_hash_tidak_berubah_walau_urutan_kunci_parameter_berbeda(): void
    {
        $urutSatu = LaporanKeuanganLog::catat([
            'user_nama' => 'Uji Kanonik',
            'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
            'aktivitas' => LaporanKeuanganPermission::LIHAT,
            'deskripsi' => 'Uji urutan kunci A',
            'parameter' => ['peran' => 'anggota', 'modul' => null, 'per_halaman' => '50'],
            'status_http' => 200,
            'berhasil' => true,
        ]);

        $urutDua = LaporanKeuanganLog::catat([
            'user_nama' => 'Uji Kanonik',
            'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
            'aktivitas' => LaporanKeuanganPermission::LIHAT,
            'deskripsi' => 'Uji urutan kunci B',
            'parameter' => ['per_halaman' => '50', 'modul' => null, 'peran' => 'anggota'],
            'status_http' => 200,
            'berhasil' => true,
        ]);

        // String kanonik hanya boleh berbeda pada field yang memang berbeda
        // (id & deskripsi) — urutan kunci parameter tidak boleh ikut berpengaruh.
        $kanonikSatu = explode('|', $urutSatu->stringUntukHash());
        $kanonikDua = explode('|', $urutDua->stringUntukHash());

        $this->assertSame($kanonikSatu[9], $kanonikDua[9],
            'Urutan kunci parameter masih memengaruhi string kanonik.');
    }

    public function test_baris_berparameter_banyak_kunci_tetap_utuh_setelah_dibaca_ulang_dari_database(): void
    {
        LaporanKeuanganLog::catat([
            'user_nama' => 'Uji Kanonik',
            'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
            'aktivitas' => LaporanKeuanganPermission::LIHAT,
            'deskripsi' => 'Uji parameter multi-kunci',
            'parameter' => [
                'status' => null,
                'peran' => 'anggota',
                'modul' => null,
                'aktivitas' => null,
                'per_halaman' => '50',
                'cari' => null,
                'dari' => null,
                'sampai' => null,
            ],
            'perubahan_data' => ['sebelum' => ['b' => 2, 'a' => 1], 'sesudah' => ['a' => 1, 'b' => 2]],
            'status_http' => 200,
            'berhasil' => true,
        ]);

        // Ambil ulang dari database: di sinilah urutan kunci JSON sudah
        // diubah MySQL, sehingga baris ini jadi ujian sebenarnya.
        $log = LaporanKeuanganLog::orderByDesc('id')->first();

        $this->assertIsArray($log->parameter);
        $this->assertSame($log->hitungHash(), $log->hash,
            'Hash baris berparameter multi-kunci tidak cocok setelah dibaca ulang.');

        $hasil = LaporanKeuanganLog::verifyChain();
        $this->assertTrue($hasil['utuh'], 'Rantai hash tidak utuh: '.($hasil['alasan'] ?? '-'));
    }

    public function test_kolom_hash_memakai_kolasi_biner_sepanjang_64_karakter(): void
    {
        LaporanKeuanganLog::catat([
            'user_nama' => 'Uji Skema',
            'modul' => LaporanKeuanganPermission::MODUL_AUDIT,
            'aktivitas' => LaporanKeuanganPermission::LIHAT,
            'deskripsi' => 'Uji skema kolom hash',
            'status_http' => 200,
            'berhasil' => true,
        ]);

        foreach (['hash', 'hash_sebelumnya'] as $kolom) {
            $meta = collect(DB::select('SHOW FULL COLUMNS FROM laporan_keuangan_logs'))
                ->firstWhere('Field', $kolom);

            $this->assertNotNull($meta, "Kolom {$kolom} tidak ditemukan.");
            $this->assertStringStartsWith('char(64)', $meta->Type, "Kolom {$kolom} bukan char(64).");
            $this->assertSame('utf8mb4_bin', $meta->Collation,
                "Kolom {$kolom} tidak memakai kolasi biner.");
        }

        // Perbandingan hash harus peka huruf besar/kecil. Dengan kolasi
        // utf8mb4_unicode_ci, dua hash yang hanya beda kapitalisasi dianggap sama.
        $hash = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
        $this->assertSame(
            0,
            DB::table('laporan_keuangan_logs')->where('hash', strtoupper($hash))->count(),
            'Kolasi kolom hash masih tidak peka huruf besar/kecil.'
        );
    }

    public function test_penyegaran_rantai_bersifat_idempoten(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $this->artisan('laporan-keuangan:segarkan-rantai --force')->assertSuccessful();
        $this->artisan('laporan-keuangan:segarkan-rantai --force')->assertSuccessful();

        $this->assertTrue(LaporanKeuanganLog::verifyChain()['utuh']);
    }

    public function test_identitas_peran_tersalin_sehingga_jejak_audit_tetap_utuh(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $log = $this->logTerakhir();
        $this->assertSame('manajer', $log->role);

        // Setelah peran pengguna berubah, jejak lama tidak boleh ikut berubah.
        DB::table('users')->where('id', $this->pengguna['manajer']->id)->update(['role' => 'pengurus']);

        $ulang = LaporanKeuanganLog::find($log->id);
        $this->assertSame('manajer', $ulang->role, 'Peran pada jejak audit ikut berubah — jejak tidak lagi dapat dipercaya.');
        $this->assertTrue(LaporanKeuanganLog::verifyChain()['utuh']);
    }

    public function test_verifikasi_integritas_melalui_http_mengembalikan_status_utuh(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $this->sebagai('dps')
            ->getJson(route('audit.laporan.verifikasi'))
            ->assertOk()
            ->assertJson(['utuh' => true]);
    }

    public function test_verifikasi_integritas_melaporkan_kerusakan_melalui_http(): void
    {
        $this->sebagai('manajer')->get(route('laporan.neraca'));

        $korban = LaporanKeuanganLog::orderBy('id')->first();
        DB::table('laporan_keuangan_logs')
            ->where('id', $korban->id)
            ->update(['user_nama' => 'Penyusup']);

        $this->sebagai('dps')
            ->getJson(route('audit.laporan.verifikasi'))
            ->assertOk()
            ->assertJson(['utuh' => false, 'rusak_di' => $korban->id]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Pengunjung belum login
    // ═════════════════════════════════════════════════════════════════════

    public function test_pengunjung_belum_login_diarahkan_ke_halaman_masuk(): void
    {
        foreach ([
            route('laporan.neraca'),
            route('laporan.buku-besar'),
            route('audit.laporan.index'),
            route('audit.laporan.ekspor'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }
}
