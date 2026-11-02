<?php

namespace Tests\Feature;

use App\Http\Controllers\DenyutController;
use App\Models\Anggota;
use App\Models\Jurnal;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Models\User;
use App\Observers\DenyutObserver;
use App\Services\DenyutMonitor;
use App\Support\DenyutData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regresi mesin sinkronisasi real-time ("Denyut").
 *
 * AKAR MASALAH YANG DIKUNCI
 * -------------------------
 * Aplikasi ini dirender di server (berbasis sesi) tanpa WebSocket/Pusher
 * (`BROADCAST_DRIVER=log`) dan tanpa queue asinkron (`QUEUE_CONNECTION=sync`).
 * Tidak ada jalur "push" server → peramban; sebelum mesin denyut ada, angka
 * dashboard hanya berubah setelah pengguna menekan refresh (F5).
 *
 * Mesin denyut menutup celah itu dengan tiga lapis:
 *   1. Buku versi data `DenyutData` (cache) — dinaikkan tiap ada mutasi model
 *      lewat `DenyutObserver`.
 *   2. Endpoint `GET /denyut` — mengembalikan sidik jari perubahan TANPA
 *      menyentuh database.
 *   3. Endpoint `.../ringkas` per dashboard — mengirim HANYA potongan HTML
 *      yang terdampak, sehingga klien memperbarui tampilan tanpa reload penuh.
 *
 * Test ini memverifikasi ketiga lapis tersebut beserta kasus tepinya.
 *
 * Catatan: test memakai `DatabaseTransactions` (data dev tidak diubah) dan
 * `CACHE_DRIVER=array` (lihat phpunit.xml) sehingga buku versi terisolasi per
 * proses uji.
 */
class DenyutSinkronisasiRealtimeTest extends TestCase
{
    use DatabaseTransactions;

    /** Ambang target yang diminta: setiap pembaruan tercermin < 2 detik. */
    private const TENGGAT_MS = 2000;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('transaksi') || ! Schema::hasTable('anggota')) {
            $this->markTestSkipped('Tabel inti belum tersedia.');
        }

        $this->prefix = 'DENYUT'.strtoupper(substr(uniqid(), -6));

        DenyutData::reset();
    }

    /* =====================================================================
     | Lapis 1 — Buku versi `DenyutData`
     ===================================================================== */

    public function test_bump_menaikkan_versi_secara_monoton(): void
    {
        $awal = DenyutData::versi(DenyutData::TRANSAKSI);

        $satu = DenyutData::bump(DenyutData::TRANSAKSI);
        $dua = DenyutData::bump(DenyutData::TRANSAKSI);

        $this->assertSame($awal + 1, $satu, 'bump pertama harus menaikkan tepat satu.');
        $this->assertSame($awal + 2, $dua, 'bump kedua harus terus naik.');
        $this->assertGreaterThan($satu, $dua, 'Versi tidak pernah menurun.');
    }

    public function test_tanda_berubah_ketika_ada_domain_yang_dinaikkan(): void
    {
        $sebelum = DenyutData::peta()['tanda'];

        DenyutData::bump(DenyutData::JURNAL);

        $sesudah = DenyutData::peta()['tanda'];

        $this->assertNotSame(
            $sebelum,
            $sesudah,
            'Sidik jari gabungan harus berubah begitu satu domain berubah.'
        );
    }

    public function test_tanda_stabil_dan_tidak_terpengaruh_urutan_iterasi(): void
    {
        $versi = [
            DenyutData::JURNAL => 7,
            DenyutData::TRANSAKSI => 3,
            DenyutData::ANGGOTA => 11,
        ];

        $urutAsli = DenyutData::tanda($versi);
        $urutAcak = DenyutData::tanda(array_reverse($versi, true));

        $this->assertSame(
            $urutAsli,
            $urutAcak,
            'Dua proses dengan urutan berbeda harus menghasilkan tanda identik (ksort).'
        );

        // Data yang sama harus menghasilkan tanda yang sama secara konsisten.
        $this->assertSame($urutAsli, DenyutData::tanda($versi));
    }

    public function test_domain_yang_belum_disentuh_bernilai_nol(): void
    {
        Cache::forget('denyut:v1:'.DenyutData::DEPOSITO);

        $this->assertSame(
            0,
            DenyutData::versi(DenyutData::DEPOSITO),
            'Klien baru harus melihat versi 0 agar tidak salah mendeteksi perubahan.'
        );
    }

    public function test_peta_memuat_seluruh_domain_sah(): void
    {
        $peta = DenyutData::peta();

        $this->assertArrayHasKey('tanda', $peta);
        $this->assertArrayHasKey('versi', $peta);
        $this->assertSame(
            DenyutData::domain(),
            array_keys($peta['versi']),
            'Peta wajib memuat tepat seluruh domain yang sah.'
        );

        foreach ($peta['versi'] as $nilai) {
            $this->assertIsInt($nilai, 'Nilai versi harus berupa integer.');
        }

        $this->assertSame(32, strlen($peta['tanda']), 'Tanda dipotong 32 karakter.');
    }

    public function test_reset_mengembalikan_seluruh_domain_ke_nol(): void
    {
        foreach (DenyutData::domain() as $domain) {
            DenyutData::bump($domain);
        }

        DenyutData::reset();

        foreach (DenyutData::domain() as $domain) {
            $this->assertSame(
                0,
                DenyutData::versi($domain),
                "Domain {$domain} harus kembali ke 0 setelah reset."
            );
        }
    }

    /* =====================================================================
     | Lapis 2 — DenyutObserver menandai perubahan pada SEMUA jalur tulis
     ===================================================================== */

    public function test_observer_memeta_kan_model_ke_domain_yang_benar(): void
    {
        $peta = DenyutObserver::petaModel();

        $this->assertContains(DenyutData::TRANSAKSI, $peta[Transaksi::class]);
        $this->assertContains(DenyutData::REKENING, $peta[Rekening::class]);
        $this->assertContains(DenyutData::ANGGOTA, $peta[Anggota::class]);
        $this->assertContains(DenyutData::JURNAL, $peta[Jurnal::class]);

        // Satu model boleh memengaruhi beberapa domain sekaligus.
        $this->assertSame(
            [DenyutData::PEMBIAYAAN, DenyutData::APPROVAL],
            array_values(array_unique($peta[PengajuanPembiayaan::class])),
            'Pengajuan pembiayaan memengaruhi portofolio sekaligus antrean approval.'
        );
    }

    public function test_menyimpan_transaksi_menaikkan_domain_transaksi(): void
    {
        $sebelum = DenyutData::versi(DenyutData::TRANSAKSI);

        Transaksi::create([
            'no_transaksi' => $this->prefix.'-T1',
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Kredit',
            'jumlah' => 100000,
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
        ]);

        $this->assertGreaterThan(
            $sebelum,
            DenyutData::versi(DenyutData::TRANSAKSI),
            'INSERT transaksi harus menaikkan versi domain transaksi lewat observer.'
        );
    }

    public function test_menghapus_anggota_menaikkan_domain_anggota(): void
    {
        $anggota = $this->anggotaUji();

        $sebelum = DenyutData::versi(DenyutData::ANGGOTA);

        $anggota->delete();

        $this->assertGreaterThan(
            $sebelum,
            DenyutData::versi(DenyutData::ANGGOTA),
            'DELETE juga merupakan perubahan data dan harus menaikkan versi.'
        );
    }

    public function test_observer_menaikkan_semua_domain_untuk_model_multi_domain(): void
    {
        $vPembiayaan = DenyutData::versi(DenyutData::PEMBIAYAAN);
        $vApproval = DenyutData::versi(DenyutData::APPROVAL);

        PengajuanPembiayaan::create([
            'no_pengajuan' => $this->prefix.'-PP',
            'anggota_id' => $this->anggotaUji()->id,
            'jenis_akad' => 'Murabahah',
            'jumlah_pengajuan' => 5000000,
            'total_pembiayaan' => 5500000,
            'tenor' => 12,
            'tujuan' => 'Modal usaha',
            'status' => 'Menunggu',
        ]);

        $this->assertGreaterThan($vPembiayaan, DenyutData::versi(DenyutData::PEMBIAYAAN));
        $this->assertGreaterThan($vApproval, DenyutData::versi(DenyutData::APPROVAL));
    }

    public function test_model_di_luar_peta_tidak_mengubah_versi(): void
    {
        $sebelum = DenyutData::peta()['tanda'];

        // `User` bukan bagian domain dashboard — tidak boleh memicu denyut.
        User::firstOrCreate(
            ['email' => "denyut.ignored.{$this->prefix}@test.local"],
            [
                'name' => 'Denyut Ignored',
                'password' => Hash::make('password'),
                'role' => 'teller',
                'is_active' => true,
            ]
        );

        $this->assertSame(
            $sebelum,
            DenyutData::peta()['tanda'],
            'Mutasi model non-domain tidak boleh memicu pembaruan dashboard.'
        );
    }

    /* =====================================================================
     | Lapis 3 — Endpoint /denyut (snapshot & long polling)
     ===================================================================== */

    public function test_endpoint_denyut_mengembalikan_snapshot_tanpa_database(): void
    {
        $pengguna = $this->pengguna('teller');

        $respons = $this->actingAs($pengguna)->getJson(route('denyut'));

        $respons->assertOk();
        $respons->assertJsonStructure([
            'tanda',
            'versi',
            'detak_aktif',
            'detak_idle',
            'transportasi' => ['sse', 'tunggu'],
            'waktu_server',
        ]);

        $this->assertSame(
            DenyutData::peta()['tanda'],
            $respons->json('tanda'),
            'Snapshot harus mencerminkan tanda versi terkini.'
        );
    }

    public function test_snapshot_menyimpan_header_cache_no_cache_untuk_selalu_segar(): void
    {
        $pengguna = $this->pengguna('teller');

        $respons = $this->actingAs($pengguna)->getJson(route('denyut'));

        $cacheControl = $respons->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', (string) $cacheControl);
        $this->assertStringContainsString('must-revalidate', (string) $cacheControl);
        $this->assertNotNull($respons->headers->get('ETag'), 'Snapshot menyediakan ETag untuk kondisional GET.');
    }

    public function test_endpoint_denyut_menolak_pengguna_belum_login(): void
    {
        $respons = $this->get(route('denyut'));

        $respons->assertRedirect(route('login'));
    }

    public function test_snapshot_tidak_membuka_database(): void
    {
        // Pasang pendengar kueri: snapshot WAJIB bebas kueri (hanya baca cache),
        // karena endpoint ini dipanggil berkali-kali per menit oleh setiap tab.
        $jumlahKueri = 0;
        DB::listen(function () use (&$jumlahKueri) {
            $jumlahKueri++;
        });

        app(DenyutController::class)->snapshot();

        $this->assertSame(
            0,
            $jumlahKueri,
            'Snapshot denyut harus tidak menyentuh database sama sekali.'
        );
    }

    /* =====================================================================
     | Kasus tepi — degradasi & transportasi
     ===================================================================== */

    public function test_long_polling_berakhir_sopan_saat_tidak_ada_perubahan(): void
    {
        $pengguna = $this->pengguna('teller');

        // Batas waktu dipersingkat agar test cepat; tidak ada mutasi selama menunggu.
        config(['denyut.maks_tunggu' => 1, 'denyut.jeda_tunggu' => 0.05]);

        $tandaAwal = DenyutData::peta()['tanda'];

        $mulai = microtime(true);

        $respons = $this->actingAs($pengguna)
            ->getJson(route('denyut', ['tunggu' => 1, 'tanda' => $tandaAwal]));

        $respons->assertOk();
        $respons->assertJsonStructure(['berubah', 'tanda', 'versi']);

        // Tidak ada perubahan → harus membalas `berubah = false` dan tidak menggantung.
        $this->assertFalse($respons->json('berubah'), 'Tanpa mutasi, long polling melaporkan tidak ada perubahan.');
        $this->assertSame($tandaAwal, $respons->json('tanda'));

        $this->assertLessThan(
            3.0,
            microtime(true) - $mulai,
            'Long polling harus menghormati batas waktu, bukan menggantung tanpa batas.'
        );
    }

    public function test_long_polling_membalas_segera_setelah_ada_perubahan(): void
    {
        $pengguna = $this->pengguna('teller');

        // Batas waktu panjang; satu-satunya cara test selesai cepat adalah
        // long polling benar-benar mendeteksi perubahan dan membalas segera.
        config(['denyut.maks_tunggu' => 10, 'denyut.jeda_tunggu' => 0.05]);

        $tandaAwal = DenyutData::peta()['tanda'];

        $mulai = microtime(true);

        // Mutasi data yang terjadi sebelum permintaan diproses.
        Transaksi::create([
            'no_transaksi' => $this->prefix.'-LP',
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Kredit',
            'jumlah' => 5000,
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
        ]);

        $respons = $this->actingAs($pengguna)
            ->getJson(route('denyut', ['tunggu' => 1, 'tanda' => $tandaAwal]));

        $respons->assertOk();
        $this->assertTrue($respons->json('berubah'), 'Long polling harus melaporkan perubahan.');
        $this->assertLessThan(
            self::TENGGAT_MS / 1000,
            microtime(true) - $mulai,
            'Perubahan harus terdeteksi jauh di bawah 2 detik.'
        );
    }

    public function test_sse_dan_long_polling_dapat_dinonaktifkan_lewat_konfigurasi(): void
    {
        $pengguna = $this->pengguna('teller');

        config(['denyut.sse' => false, 'denyut.tunggu' => false]);

        // Meski diminta, permintaan stream jatuh aman ke snapshot JSON.
        $respons = $this->actingAs($pengguna)->getJson(route('denyut', ['stream' => 1]));

        $respons->assertOk();
        $respons->assertJsonStructure(['tanda', 'versi']);

        // Snapshot tetap melaporkan transportasi nonaktif agar klien memakai polling.
        $this->assertFalse($respons->json('transportasi.sse'));
        $this->assertFalse($respons->json('transportasi.tunggu'));
    }

    public function test_snapshot_selalu_membawa_status_alur_cepat_untuk_deteksi_klien(): void
    {
        $pengguna = $this->pengguna('teller');

        // Alur cepat AKTIF → klien boleh memakai SSE/long polling.
        config(['denyut.sse' => true, 'denyut.tunggu' => true]);
        $aktif = $this->actingAs($pengguna)->getJson(route('denyut'));
        $aktif->assertOk();
        $this->assertTrue($aktif->json('transportasi.sse'));
        $this->assertTrue($aktif->json('transportasi.tunggu'));

        // Alur cepat DIMATIKAN lewat env → klien harus melihatnya di respons
        // JSON berikutnya tanpa perlu perubahan kode apa pun.
        config(['denyut.sse' => false, 'denyut.tunggu' => false]);
        $mati = $this->actingAs($pengguna)->getJson(route('denyut'));
        $mati->assertOk();
        $this->assertFalse($mati->json('transportasi.sse'));
        $this->assertFalse($mati->json('transportasi.tunggu'));
    }

    public function test_alur_cepat_hanya_lewat_env_sset_ke_atribut_html(): void
    {
        $pengguna = $this->pengguna('teller');

        // Kondisi bawaan: alur cepat dinyalakan lewat env.
        config(['denyut.sse' => true, 'denyut.tunggu' => false]);

        $html = $this->actingAs($pengguna)->get(route('dashboard.teller'))->content();

        $this->assertStringContainsString('data-denyut-sse="1"', $html);
        $this->assertStringContainsString('data-denyut-tunggu="0"', $html);
        $this->assertStringContainsString('data-denyut-alur-cepat="1"', $html);

        // Alur cepat dimatikan sepenuhnya lewat env → atribut ikut berubah
        // tanpa satu pun perubahan kode sumber.
        config(['denyut.sse' => false, 'denyut.tunggu' => false]);

        $html = $this->actingAs($pengguna)->get(route('dashboard.teller'))->content();

        $this->assertStringContainsString('data-denyut-sse="0"', $html);
        $this->assertStringContainsString('data-denyut-tunggu="0"', $html);
        $this->assertStringContainsString('data-denyut-alur-cepat="0"', $html);
    }

    public function test_permintaan_stream_jatuh_ke_snapshot_saat_sse_dimatikan_env(): void
    {
        $pengguna = $this->pengguna('teller');

        config(['denyut.sse' => false]);

        // Klien yang masih memegang sambungan SSE lama meminta `stream=1`;
        // server harus membalas JSON biasa (bukan menggantung) supaya klien
        // dapat membaca `transportasi` lalu beralih ke polling.
        $respons = $this->actingAs($pengguna)->getJson(route('denyut', ['stream' => 1]));

        $respons->assertOk();
        $respons->assertJsonStructure(['tanda', 'versi', 'transportasi' => ['sse', 'tunggu']]);
        $this->assertFalse($respons->json('transportasi.sse'));
        $this->assertStringNotContainsString(
            'text/event-stream',
            (string) $respons->headers->get('Content-Type')
        );
    }

    public function test_halaman_memuat_penjaga_transisi_alur_cepat(): void
    {
        $html = $this->actingAs($this->pengguna('teller'))
            ->get(route('dashboard.teller'))
            ->content();

        // Skrip klien harus memuat logika deteksi otomatis → turun ke polling.
        $this->assertStringContainsString('terapkanTransportasi', $html);
        $this->assertStringContainsString('jadwalkanPengawas', $html);
        $this->assertStringContainsString('hentikanSse', $html);
        $this->assertStringContainsString('denyut:transportasi', $html);
    }

    public function test_latensi_snapshot_jauh_di_bawah_target_dua_detik(): void
    {
        $pengguna = $this->pengguna('teller');
        $this->actingAs($pengguna);

        $kontroler = app(DenyutController::class);

        $mulai = microtime(true);
        for ($i = 0; $i < 5; $i++) {
            $kontroler->snapshot();
        }
        $rataMs = ((microtime(true) - $mulai) / 5) * 1000;

        $this->assertLessThan(
            self::TENGGAT_MS,
            $rataMs,
            "Rata-rata latensi snapshot {$rataMs} ms melewati target < 2 detik."
        );
    }

    /* =====================================================================
     | Endpoint ringkas per dashboard — potongan HTML real-time
     ===================================================================== */

    public function test_ringkas_teller_mengembalikan_potongan_html_yang_tepat(): void
    {
        $respons = $this->actingAs($this->pengguna('teller'))
            ->getJson(route('dashboard.teller.ringkas'));

        $respons->assertOk();
        $respons->assertJsonStructure(['html' => ['stats', 'transaksi'], 'waktu']);
        $this->assertNotSame('', trim($respons->json('html.stats')));
        $this->assertStringContainsString('no-store', (string) $respons->headers->get('Cache-Control'));
    }

    public function test_ringkas_manajer_mengembalikan_lima_potongan(): void
    {
        $respons = $this->actingAs($this->pengguna('manajer'))
            ->getJson(route('dashboard.manajer.ringkas'));

        $respons->assertOk();

        foreach (['stats', 'approval', 'pembiayaan', 'keputusan', 'beban'] as $kunci) {
            $this->assertArrayHasKey($kunci, $respons->json('html'), "Potongan '{$kunci}' wajib ada.");
        }
    }

    public function test_ringkas_pengurus_mengembalikan_delapan_potongan(): void
    {
        $respons = $this->actingAs($this->pengguna('pengurus'))
            ->getJson(route('dashboard.pengurus.ringkas'));

        $respons->assertOk();

        foreach (['stats', 'hold', 'validasi', 'riwayat', 'pengajuan', 'transaksi', 'jurnal', 'limit'] as $kunci) {
            $this->assertArrayHasKey($kunci, $respons->json('html'), "Potongan '{$kunci}' wajib ada.");
        }
    }

    public function test_ringkas_ao_mengembalikan_tiga_potongan(): void
    {
        $respons = $this->actingAs($this->pengguna('ao'))
            ->getJson(route('dashboard.ao.ringkas'));

        $respons->assertOk();

        foreach (['stats', 'menunggu', 'riwayat'] as $kunci) {
            $this->assertArrayHasKey($kunci, $respons->json('html'), "Potongan '{$kunci}' wajib ada.");
        }
    }

    public function test_ringkas_dps_mengembalikan_enam_potongan(): void
    {
        $respons = $this->actingAs($this->pengguna('dps'))
            ->getJson(route('dashboard.dps.ringkas'));

        $respons->assertOk();

        foreach (['stats', 'akad', 'pendapatan', 'kebajikan', 'transaksi', 'pembiayaan'] as $kunci) {
            $this->assertArrayHasKey($kunci, $respons->json('html'), "Potongan '{$kunci}' wajib ada.");
        }
    }

    public function test_ringkas_nasabah_mengembalikan_tiga_potongan(): void
    {
        $anggota = $this->anggotaUji();
        $anggota->update(['user_id' => ($pengguna = $this->pengguna('anggota'))->id]);

        $respons = $this->actingAs($pengguna)
            ->getJson(route('nasabah.dashboard.ringkas'));

        $respons->assertOk();

        foreach (['metrik', 'angsuran', 'transaksi'] as $kunci) {
            $this->assertArrayHasKey($kunci, $respons->json('html'), "Potongan '{$kunci}' wajib ada.");
        }
    }

    public function test_ringkas_menolak_role_yang_tidak_berhak(): void
    {
        // Teller tidak boleh membaca potongan dashboard manajer.
        $this->actingAs($this->pengguna('teller'))
            ->getJson(route('dashboard.manajer.ringkas'))
            ->assertForbidden();
    }

    public function test_ringkas_latensi_di_bawah_target_dua_detik(): void
    {
        $pengguna = $this->pengguna('teller');
        $this->actingAs($pengguna);

        $mulai = microtime(true);

        $this->getJson(route('dashboard.teller.ringkas'))->assertOk();

        $ms = (microtime(true) - $mulai) * 1000;

        $this->assertLessThan(
            self::TENGGAT_MS,
            $ms,
            "Latensi endpoint ringkas {$ms} ms melewati target < 2 detik."
        );
    }

    public function test_potongan_html_berubah_setelah_data_mutasi(): void
    {
        $pengguna = $this->pengguna('teller');
        $this->actingAs($pengguna);

        $sebelum = $this->getJson(route('dashboard.teller.ringkas'))->json('html.transaksi');
        $tandaSebelum = DenyutData::peta()['tanda'];

        Transaksi::create([
            'no_transaksi' => $this->prefix.'-RT',
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Kredit',
            'jumlah' => 777000,
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
            'user_id' => $pengguna->id,
        ]);

        $this->assertNotSame(
            $tandaSebelum,
            DenyutData::peta()['tanda'],
            'Mutasi harus mengubah tanda sehingga klien tahu perlu memuat ulang potongan.'
        );

        $sesudah = $this->getJson(route('dashboard.teller.ringkas'))->json('html.transaksi');

        $this->assertNotSame(
            $sebelum,
            $sesudah,
            'Potongan HTML tabel transaksi harus ikut berubah — inilah bukti sinkronisasi.'
        );
    }

    /* =====================================================================
     | Integrasi — observer → tanda → klien
     ===================================================================== */

    public function test_alur_integrasi_observer_sampai_sidik_jari_klien(): void
    {
        $pengguna = $this->pengguna('teller');
        $this->actingAs($pengguna);

        $sebelum = $this->getJson(route('denyut'))->json('tanda');

        // Mutasi jurnal lewat model (jalur tulis mana pun tetap terpantau).
        Jurnal::create([
            'no_jurnal' => 'DENYUT-'.strtoupper(substr(uniqid(), -8)),
            'tanggal_jurnal' => now()->toDateString(),
            'jenis_jurnal' => 'Jurnal Umum',
            'sumber' => 'Uji Denyut',
            'keterangan' => 'Integrasi real-time',
            'status' => 'Posted',
            'user_id' => $pengguna->id,
        ]);

        $sesudah = $this->getJson(route('denyut'))->json('tanda');

        $this->assertNotSame($sebelum, $sesudah, 'Sidik jari klien harus berubah setelah mutasi.');
    }

    /* =====================================================================
     | Lapis 4 — Pemantauan & degradasi anggun (DenyutMonitor)
     ===================================================================== */

    public function test_self_test_kesehatan_menyatakan_sehat_pada_cache_yang_hidup(): void
    {
        $kesehatan = DenyutMonitor::kesehatan();

        $this->assertTrue($kesehatan['sehat'], 'Cache uji harus sehat: '.$kesehatan['pesan']);
        $this->assertSame(1, $kesehatan['versi_terbaca'], 'Nilai tulis–baca harus kembali utuh.');
        $this->assertGreaterThanOrEqual(0, $kesehatan['latensi_ms']);
    }

    public function test_self_test_tidak_mengganggu_versi_domain_bisnis(): void
    {
        DenyutData::reset();

        DenyutMonitor::kesehatan();

        foreach (DenyutData::domain() as $domain) {
            $this->assertSame(
                0,
                DenyutData::versi($domain),
                "Self-test TIDAK BOLEH menaikkan versi domain '{$domain}'."
            );
        }
    }

    public function test_self_test_membersihkan_kunci_ujinya(): void
    {
        DenyutMonitor::kesehatan();

        $this->assertNull(
            Cache::get('denyut:selftest'),
            'Kunci uji harus dibersihkan agar tidak menumpuk di penyimpanan cache.'
        );
    }

    public function test_ringkasan_memuat_tanda_versi_dan_kesehatan(): void
    {
        DenyutData::bump(DenyutData::TRANSAKSI, DenyutData::JURNAL);

        $ringkasan = DenyutMonitor::ringkasan();

        $this->assertSame(32, strlen($ringkasan['tanda']));
        $this->assertSame(2, $ringkasan['total_mutasi']);
        $this->assertTrue($ringkasan['kesehatan']['sehat']);
        $this->assertArrayHasKey('sse', $ringkasan['transportasi']);
        $this->assertArrayHasKey('tunggu', $ringkasan['transportasi']);
    }

    public function test_perintah_artisan_denyut_kesehatan_berjalan_dan_menyatakan_sehat(): void
    {
        $this->artisan('denyut:kesehatan')
            ->expectsOutputToContain('SEHAT')
            ->assertExitCode(0);
    }

    public function test_perintah_artisan_rinci_menampilkan_seluruh_domain(): void
    {
        $this->artisan('denyut:kesehatan --rinci')
            ->expectsOutputToContain('transaksi')
            ->assertExitCode(0);
    }

    public function test_bump_tidak_melempar_saat_cache_bermasalah(): void
    {
        // Simulasi cache yang selalu gagal: kegagalan pemantauan TIDAK BOLEH
        // menggagalkan transaksi keuangan yang sedang berjalan.
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache mati'));
        Cache::shouldReceive('add')->andReturn(true);

        $hasil = DenyutData::bump(DenyutData::TRANSAKSI);

        $this->assertSame(0, $hasil, 'bump() harus mengembalikan nilai aman saat cache gagal.');
    }

    public function test_versi_tidak_melempar_saat_cache_bermasalah(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache mati'));
        Cache::shouldReceive('add')->andReturn(true);

        $this->assertSame(0, DenyutData::versi(DenyutData::TRANSAKSI));
    }

    public function test_kegagalan_cache_tercatat_pada_kanal_denyut(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with(DenyutMonitor::KANAL)
            ->andReturn($logger = \Mockery::mock());
        $logger->shouldReceive('warning')->once();

        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache mati'));
        Cache::shouldReceive('add')->andReturn(true);

        DenyutData::bump(DenyutData::TRANSAKSI);
    }

    public function test_catat_menelan_throwable_tanpa_menghentikan_pemanggil(): void
    {
        // Bahkan bila cache untuk kendali anti-banjir gagal, catat() tetap aman.
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache mati'));

        DenyutMonitor::catat('uji', new \RuntimeException('contoh'));

        $this->assertTrue(true, 'catat() tidak boleh melempar.');
    }

    /* =====================================================================
     | Helper
     ===================================================================== */

    private function pengguna(string $role): User
    {
        return User::firstOrCreate(
            ['email' => "denyut.{$role}.{$this->prefix}@test.local"],
            [
                'name' => 'Denyut '.ucfirst($role),
                'password' => Hash::make('password'),
                'role' => $role,
                'is_active' => true,
            ]
        );
    }

    private function anggotaUji(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'A'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Denyut',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Denyut',
                'kota' => 'Bandung',
                'provinsi' => 'Jawa Barat',
                'status_anggota' => Anggota::STATUS_ACTIVE,
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }
}
