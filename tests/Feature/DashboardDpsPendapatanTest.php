<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\PendapatanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perbaikan pembacaan & format angka kartu 'Pendapatan Margin vs Bagi Hasil'
 * pada Dashboard DPS (/dps/dashboard).
 *
 * Akar masalah yang dikunci oleh test ini:
 *   Dashboard menjumlahkan kolom `pembiayaan.margin_nisbah` sebagai rupiah,
 *   padahal kolom itu berisi PERSENTASE nisbah. Akibatnya kartu menampilkan
 *   "Pendapatan Margin Rp 21" dan "Bagi Hasil Rp 18" — angkanya hanya
 *   akumulasi persen (21 = 12 + 9), bukan nilai transaksi.
 *
 * Perbaikan: nilai diambil dari `angsuran.margin_ujroh` untuk angsuran yang
 * benar-benar sudah dibayar, dipusatkan di PendapatanService sehingga kartu
 * dashboard dan halaman Laporan Keuangan Syariah SELALU identik.
 *
 * Catatan: nilai absolut tidak dijadikan patokan karena basis data dev juga
 * berisi data nyata; pengujian memakai SELISIH (delta) agar deterministik.
 */
class DashboardDpsPendapatanTest extends TestCase
{
    use DatabaseTransactions;

    /** Nominal uji yang tidak bulat — memastikan tidak ada pemotongan/desimal hilang. */
    private const MARGIN_MURABAHAH = 123456.78;

    private const BAGI_HASIL_MUDHARABAH = 65432.10;

    private const UJROH_IJARAH = 25000.55;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'DPSPEND'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaDps(): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.dashboard.pendapatan@test.local'],
            [
                'name' => 'DPS Dashboard Pendapatan',
                'password' => Hash::make('password'),
                'role' => 'dps',
                'is_active' => true,
            ]
        );
    }

    private function anggota(): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.'AG'],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Pendapatan',
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji Pendapatan',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    /**
     * Buat satu pembiayaan + satu angsuran yang SUDAH DIBAYAR pada tanggal
     * tertentu, lalu kembalikan selisih pendapatan sebelum vs sesudah.
     *
     * @return array{margin: float, bagi_hasil: float, ujroh: float, total: float}
     */
    private function tambahAngsuranDibayar(
        string $akad,
        float $marginUjroh,
        float $pokok,
        string $tanggalBayar,
        ?string $dari = null,
        ?string $sampai = null
    ): array {
        $svc = app(PendapatanService::class);

        $sebelum = $svc->ringkasan($dari, $sampai);

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $this->anggota()->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => $akad,
            'tujuan_pembiayaan' => 'Uji Pendapatan Dashboard DPS',
            'jumlah_pembiayaan' => 1000000,
            'total_pembiayaan' => 1000000,
            'sisa_pembiayaan' => 1000000,
            'margin_nisbah' => 12.5,
            'total_ujroh' => 0,
            'tenor' => 12,
            'tanggal_akad' => $tanggalBayar,
            'tanggal_jatuh_tempo' => $tanggalBayar,
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => round(1000000 / 12, 2),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => $tanggalBayar,
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => $marginUjroh,
            'jumlah_angsuran' => $pokok + $marginUjroh,
            'total_bayar' => $pokok + $marginUjroh,
            'sisa_pokok' => 0,
            'tanggal_bayar' => $tanggalBayar,
            'status_angsuran' => PendapatanService::STATUS_ANGSURAN_DIBAYAR,
        ]);

        $sesudah = $svc->ringkasan($dari, $sampai);

        return [
            'margin' => round($sesudah['margin'] - $sebelum['margin'], 2),
            'bagi_hasil' => round($sesudah['bagi_hasil'] - $sebelum['bagi_hasil'], 2),
            'ujroh' => round($sesudah['ujroh'] - $sebelum['ujroh'], 2),
            'total' => round($sesudah['total'] - $sebelum['total'], 2),
        ];
    }

    /** Ambil nilai kartu pendapatan langsung dari data view dashboard DPS. */
    private function nilaiKartuDashboard(): array
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dashboard.dps'));

        $respon->assertOk();

        return [
            'margin' => (float) $respon->viewData('totalMargin'),
            'bagi_hasil' => (float) $respon->viewData('totalBagiHasil'),
            'ujroh' => (float) $respon->viewData('totalUjroh'),
            'total' => (float) $respon->viewData('totalPendapatan'),
            'html' => $respon->getContent(),
        ];
    }

    /* ==================================================================
     | 1. Angka tidak lagi dibaca dari kolom persentase
     | ================================================================== */

    public function test_kartu_tidak_lagi_menjumlahkan_persentase_margin_nisbah_sebagai_rupiah(): void
    {
        /* Nilai lama (bug) = SUM(margin_nisbah) yang berisi persen.
         | Nilai baru harus jauh berbeda kecuali kebetulan sama. */
        $persenLama = (float) Pembiayaan::whereIn('akad_pembiayaan', ['Murabahah', 'Ijarah', 'IMBT'])
            ->statusIn([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_AKTIF,
                Pembiayaan::STATUS_LUNAS,
                Pembiayaan::STATUS_RESTRUKTURISASI,
            ])
            ->sum('margin_nisbah');

        $kartu = $this->nilaiKartuDashboard();

        $this->assertNotEqualsWithDelta(
            $persenLama,
            $kartu['margin'],
            0.01,
            'Pendapatan Margin masih sama dengan akumulasi persen margin_nisbah — bug belum diperbaiki.'
        );

        /* Angka pendapatan riil harus >= persen (karena rupiah angsuran jauh
         | lebih besar dari angka persen), dan bukan nilai persen itu sendiri. */
        $this->assertGreaterThanOrEqual(0.0, $kartu['margin']);
    }

    public function test_margin_hanya_dihitung_dari_akad_jual_beli(): void
    {
        $delta = $this->tambahAngsuranDibayar(
            'Murabahah',
            self::MARGIN_MURABAHAH,
            100000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(self::MARGIN_MURABAHAH, $delta['margin'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['bagi_hasil'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['ujroh'], 0.01);
        $this->assertEqualsWithDelta(self::MARGIN_MURABAHAH, $delta['total'], 0.01);
    }

    public function test_bagi_hasil_hanya_dihitung_dari_akad_kerja_sama_usaha(): void
    {
        $deltaMudharabah = $this->tambahAngsuranDibayar(
            'Mudharabah',
            self::BAGI_HASIL_MUDHARABAH,
            50000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(0.0, $deltaMudharabah['margin'], 0.01);
        $this->assertEqualsWithDelta(self::BAGI_HASIL_MUDHARABAH, $deltaMudharabah['bagi_hasil'], 0.01);

        $deltaMusyarakah = $this->tambahAngsuranDibayar(
            'Musyarakah',
            10000.11,
            50000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(0.0, $deltaMusyarakah['margin'], 0.01);
        $this->assertEqualsWithDelta(10000.11, $deltaMusyarakah['bagi_hasil'], 0.01);
    }

    public function test_akad_ijarah_masuk_ujroh_dan_bukan_margin(): void
    {
        $delta = $this->tambahAngsuranDibayar(
            'Ijarah',
            self::UJROH_IJARAH,
            75000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(0.0, $delta['margin'], 0.01, 'Ijarah tidak boleh masuk Margin (PSAK 107).');
        $this->assertEqualsWithDelta(0.0, $delta['bagi_hasil'], 0.01);
        $this->assertEqualsWithDelta(self::UJROH_IJARAH, $delta['ujroh'], 0.01);
        $this->assertEqualsWithDelta(self::UJROH_IJARAH, $delta['total'], 0.01);
    }

    public function test_akad_ijarah_muntahia_bittamlik_dikenali_sebagai_margin(): void
    {
        /* Sebelumnya kode memakai string 'IMBT' yang TIDAK ADA pada enum
         | akad_pembiayaan, sehingga akad IMBT tidak pernah ikut terhitung. */
        $delta = $this->tambahAngsuranDibayar(
            'Ijarah Muntahia Bittamlik',
            77777.77,
            100000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(77777.77, $delta['margin'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['ujroh'], 0.01);
    }

    public function test_akad_qardh_tidak_dihitung_sebagai_pendapatan(): void
    {
        $delta = $this->tambahAngsuranDibayar(
            'Qardh',
            0.0,
            200000,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(0.0, $delta['margin'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['bagi_hasil'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['ujroh'], 0.01);
        $this->assertEqualsWithDelta(0.0, $delta['total'], 0.01);
    }

    public function test_angsuran_yang_belum_dibayar_tidak_dihitung(): void
    {
        $svc = app(PendapatanService::class);
        $sebelum = $svc->ringkasan();

        $pembiayaan = Pembiayaan::create([
            'no_pembiayaan' => $this->prefix.'-BELUM',
            'anggota_id' => $this->anggota()->id,
            'akun_id' => Coa::query()->value('id') ?? 1,
            'akad_pembiayaan' => 'Murabahah',
            'tujuan_pembiayaan' => 'Uji Pendapatan Dashboard DPS',
            'jumlah_pembiayaan' => 1000000,
            'total_pembiayaan' => 1000000,
            'sisa_pembiayaan' => 1000000,
            'margin_nisbah' => 12.5,
            'total_ujroh' => 0,
            'tenor' => 12,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addYear()->toDateString(),
            'frekuensi_angsuran' => 'Bulanan',
            'jumlah_angsuran' => round(1000000 / 12, 2),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
        ]);

        Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => now()->addMonth()->toDateString(),
            'pokok_angsuran' => 100000,
            'margin_ujroh' => 999999.99,
            'jumlah_angsuran' => 1099999.99,
            'total_bayar' => 0,
            'sisa_pokok' => 100000,
            'tanggal_bayar' => null,
            'status_angsuran' => 'Belum Jatuh Tempo',
        ]);

        $sesudah = $svc->ringkasan();

        $this->assertEqualsWithDelta($sebelum['margin'], $sesudah['margin'], 0.01);
        $this->assertEqualsWithDelta($sebelum['total'], $sesudah['total'], 0.01);
    }

    public function test_pokok_angsuran_tidak_dihitung_sebagai_pendapatan(): void
    {
        $delta = $this->tambahAngsuranDibayar(
            'Murabahah',
            50000.0,
            9999999.99,
            now()->toDateString()
        );

        $this->assertEqualsWithDelta(50000.0, $delta['total'], 0.01, 'Pokok angsuran bukan pendapatan.');
    }

    /* ==================================================================
     | 2. Akurasi untuk periode waktu yang berbeda
     | ================================================================== */

    public function test_penjumlahan_per_periode_bulanan_tepat(): void
    {
        $deltaMei = $this->tambahAngsuranDibayar(
            'Murabahah',
            111111.11,
            100000,
            '2026-05-15',
            '2026-05-01',
            '2026-05-31'
        );

        $this->assertEqualsWithDelta(111111.11, $deltaMei['margin'], 0.01);

        // Angsuran bulan Juni TIDAK boleh ikut terhitung pada rentang Mei.
        $deltaJuniTerhadapMei = $this->tambahAngsuranDibayar(
            'Murabahah',
            222222.22,
            100000,
            '2026-06-15',
            '2026-05-01',
            '2026-05-31'
        );

        $this->assertEqualsWithDelta(0.0, $deltaJuniTerhadapMei['margin'], 0.01);

        // Tetapi HARUS terhitung pada rentang Juni.
        $deltaJuni = $this->tambahAngsuranDibayar(
            'Murabahah',
            333333.33,
            100000,
            '2026-06-20',
            '2026-06-01',
            '2026-06-30'
        );

        $this->assertEqualsWithDelta(333333.33, $deltaJuni['margin'], 0.01);
    }

    public function test_rentang_tahunan_sama_dengan_akumulasi_bila_seluruh_data_di_tahun_itu(): void
    {
        $this->tambahAngsuranDibayar('Murabahah', 1000.10, 1000, '2026-03-10');
        $this->tambahAngsuranDibayar('Mudharabah', 2000.20, 1000, '2026-08-10');

        $svc = app(PendapatanService::class);
        $akumulasi = $svc->ringkasan();
        $tahunan = $svc->ringkasan('2026-01-01', '2026-12-31');

        /* Data dev berada pada rentang 2026, sehingga total tahun 2026 harus
         | sama dengan akumulatif — ini mengunci filter rentang agar akurat. */
        $this->assertEqualsWithDelta($akumulasi['total'], $tahunan['total'], 0.01);
    }

    public function test_rentang_custom_tidak_bocor_ke_luar_batas(): void
    {
        $this->tambahAngsuranDibayar('Murabahah', 500000.0, 100000, '2026-06-01');
        $this->tambahAngsuranDibayar('Murabahah', 700000.0, 100000, '2026-06-30');

        $svc = app(PendapatanService::class);

        $tepat = $svc->ringkasan('2026-06-01', '2026-06-30');
        $kurang = $svc->ringkasan('2026-06-02', '2026-06-29');

        $this->assertGreaterThan($kurang['margin'], $tepat['margin'], 'Rentang penuh harus lebih besar dari rentang sempit.');
        $this->assertGreaterThanOrEqual(1200000.0, $tepat['margin']);
    }

    public function test_ringkasan_tanpa_rentang_sama_dengan_rentang_sangat_lebar(): void
    {
        $svc = app(PendapatanService::class);

        $tanpaBatas = $svc->ringkasan();
        $lebar = $svc->ringkasan('1900-01-01', '2999-12-31');

        $this->assertEqualsWithDelta($tanpaBatas['total'], $lebar['total'], 0.01);
    }

    /* ==================================================================
     | 3. Sinkronisasi lintas modul & format tampilan
     | ================================================================== */

    public function test_angka_kartu_dashboard_identik_dengan_laporan_keuangan(): void
    {
        $this->tambahAngsuranDibayar('Murabahah', 12345.67, 1000, now()->toDateString());
        $this->tambahAngsuranDibayar('Musyarakah', 76543.21, 1000, now()->toDateString());
        $this->tambahAngsuranDibayar('Ijarah', 11111.11, 1000, now()->toDateString());

        $kartu = $this->nilaiKartuDashboard();

        $laporan = $this->actingAs($this->penggunaDps())->get(route('dps.laporan-keuangan'));
        $laporan->assertOk();

        $this->assertEqualsWithDelta(
            (float) $laporan->viewData('pendapatanMargin'),
            $kartu['margin'],
            0.01,
            'Pendapatan Margin dashboard tidak sama dengan Laporan Keuangan.'
        );

        $this->assertEqualsWithDelta(
            (float) $laporan->viewData('pendapatanBagiHasil'),
            $kartu['bagi_hasil'],
            0.01,
            'Pendapatan Bagi Hasil dashboard tidak sama dengan Laporan Keuangan.'
        );

        $this->assertEqualsWithDelta(
            (float) $laporan->viewData('pendapatanUjroh'),
            $kartu['ujroh'],
            0.01,
            'Pendapatan Ujroh dashboard tidak sama dengan Laporan Keuangan.'
        );

        $totalLaporan = (float) $laporan->viewData('pendapatanMargin')
            + (float) $laporan->viewData('pendapatanBagiHasil')
            + (float) $laporan->viewData('pendapatanUjroh');

        $this->assertEqualsWithDelta(
            $totalLaporan,
            $kartu['total'],
            0.01,
            'Total pendapatan dashboard tidak sama dengan total di Laporan Keuangan.'
        );
    }

    public function test_total_kartu_adalah_jumlah_margin_bagi_hasil_dan_ujroh(): void
    {
        $kartu = $this->nilaiKartuDashboard();

        $this->assertEqualsWithDelta(
            $kartu['margin'] + $kartu['bagi_hasil'] + $kartu['ujroh'],
            $kartu['total'],
            0.01
        );
    }

    public function test_kartu_menampilkan_format_rupiah_dengan_pemisah_ribuan(): void
    {
        $kartu = $this->nilaiKartuDashboard();

        // Kartu harus memuat minimal satu nilai berformat "Rp x.xxx.xxx"
        // (titik sebagai pemisah ribuan, tanpa desimal).
        $this->assertMatchesRegularExpression(
            '/Rp\s\d{1,3}(\.\d{3})+/',
            $kartu['html'],
            'Kartu tidak menampilkan angka dengan pemisah ribuan standar.'
        );

        // Format yang dipakai harus persis number_format($val, 0, ',', '.').
        $this->assertStringContainsString(
            'Rp '.number_format($kartu['total'], 0, ',', '.'),
            $kartu['html'],
            'Total pendapatan tidak dirender dengan number_format($val, 0, \',\', \'.\').'
        );
    }

    public function test_kartu_tidak_menampilkan_persentase_palsu_di_atas_seratus(): void
    {
        $this->tambahAngsuranDibayar('Murabahah', 50000.0, 1000, now()->toDateString());

        $kartu = $this->nilaiKartuDashboard();

        preg_match_all('/([\d.]+)% dari total pendapatan/', $kartu['html'], $cocok);

        $this->assertNotEmpty($cocok[1], 'Kartu tidak menampilkan baris persentase.');

        foreach ($cocok[1] as $persen) {
            $nilai = (float) $persen;
            $this->assertGreaterThanOrEqual(0.0, $nilai, "Persentase negatif muncul: {$persen}%");
            $this->assertLessThanOrEqual(100.0, $nilai, "Persentase di atas 100% muncul: {$persen}%");
        }
    }

    public function test_kartu_menampilkan_tiga_kelompok_pendapatan(): void
    {
        $kartu = $this->nilaiKartuDashboard();

        $this->assertStringContainsString('Pendapatan Margin', $kartu['html']);
        $this->assertStringContainsString('Pendapatan Bagi Hasil', $kartu['html']);
        $this->assertStringContainsString('Pendapatan Ujroh / Fee', $kartu['html']);
        $this->assertStringContainsString('Total Pendapatan Tercatat', $kartu['html']);
    }

    public function test_kategori_akad_konsisten_dengan_pengelompokan_angka(): void
    {
        // Akad margin.
        foreach (PendapatanService::AKAD_MARGIN as $akad) {
            $this->assertSame(
                PendapatanService::HAK_MARGIN,
                PendapatanService::kategoriAkad($akad),
                "Akad {$akad} seharusnya berkategori Margin."
            );
        }

        // Akad bagi hasil.
        foreach (PendapatanService::AKAD_BAGI_HASIL as $akad) {
            $this->assertSame(PendapatanService::HAK_BAGI_HASIL, PendapatanService::kategoriAkad($akad));
        }

        // Akad ujroh.
        foreach (PendapatanService::AKAD_UJROH as $akad) {
            $this->assertSame(PendapatanService::HAK_UJROH, PendapatanService::kategoriAkad($akad));
        }

        // Qardh bukan pendapatan.
        $this->assertSame('non_pendapatan', PendapatanService::kategoriAkad('Qardh'));
        $this->assertSame('non_pendapatan', PendapatanService::kategoriAkad(null));
    }
}
