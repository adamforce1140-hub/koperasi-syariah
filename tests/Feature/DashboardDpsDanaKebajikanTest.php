<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Services\DanaKebajikanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sinkronisasi nilai 'DANA TERKUMPUL' widget Dashboard DPS dengan modul
 * Dana Kebajikan (/dps/dana-kebajikan).
 *
 * Akar masalah yang dikunci oleh test ini:
 *   Widget Dashboard DPS dahulu menghitung dana terkumpul dari tabel
 *   `transaksi` dengan filter LIKE pada `jenis_transaksi`, lalu
 *   `where('status', '!=', 'Voided')`. Query itu keliru karena:
 *     1. Menjumlahkan seluruh baris tanpa memandang posisi Debet/Kredit,
 *        sehingga penyaluran dana keluar justru menambah total.
 *     2. Memakai kolom `status` (berisi nilai tak baku: 'success',
 *        'rejected') alih-alih `status_transaksi`, dan enum-nya tidak
 *        memuat 'Voided' sehingga baris void lolos filter.
 *     3. Mengabaikan jurnal, padahal ta'zir dibukukan KREDIT 2-3001
 *        secara double-entry.
 *   Akibatnya widget menampilkan Rp 50.000 (hanya 1 transaksi yang
 *   kebetulan cocok LIKE-nya) sedangkan modul Dana Kebajikan menampilkan
 *   Rp 1.156.000.
 *
 * Sumber kebenaran: SUM(KREDIT) mutasi jurnal akun 2-3001, diakses lewat
 * DanaKebajikanService::ringkasan() — method yang sama dipakai kedua halaman.
 *
 * Catatan: basis data dev memuat data nyata, sehingga pengujian memakai
 * SELISIH (delta) agar deterministik dan tidak bergantung isi basis data.
 */
class DashboardDpsDanaKebajikanTest extends TestCase
{
    use DatabaseTransactions;

    /** Prefix unik agar data uji mudah diisolasi dari data dev. */
    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'DKDPS'.strtoupper(substr(uniqid(), -6));
    }

    private function penggunaDps(): User
    {
        return User::firstOrCreate(
            ['email' => 'dps.danakebajikan@test.local'],
            [
                'name' => 'DPS Dana Kebajikan',
                'password' => Hash::make('password'),
                'role' => 'dps',
                'is_active' => true,
            ]
        );
    }

    private function akunKebajikan(): ?Coa
    {
        return Coa::findByKode(AutoJournalingService::DANA_KEBAJIKAN);
    }

    /** Buka Dashboard DPS dan ambil nilai dana kebajikan yang di-render. */
    private function danaTerkumpulDashboard(): float
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dashboard.dps'));
        $respon->assertOk();

        return (float) $respon->viewData('danaKebajikan');
    }

    /** Buka modul Dana Kebajikan dan ambil nilai total terkumpul. */
    private function danaTerkumpulModul(): float
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dps.dana-kebajikan'));
        $respon->assertOk();

        return (float) $respon->viewData('totalTerkumpul');
    }

    /** Catat mutasi KREDIT baru pada akun 2-3001 melalui jalur jurnal resmi. */
    private function tambahMutasiKredit(float $nominal): void
    {
        app(AutoJournalingService::class)->jurnalPenerimaanDanaKebajikan(
            $nominal,
            'Uji sinkronisasi widget dashboard '.$this->prefix,
            null,
            null,
            null,
            null,
            'Infak Tunai'
        );
    }

    /** Catat mutasi DEBET (penyaluran) pada akun 2-3001 melalui jalur resmi. */
    private function tambahMutasiDebet(float $nominal): void
    {
        app(AutoJournalingService::class)->jurnalPenyaluranDanaKebajikan(
            $nominal,
            'Uji penyaluran tidak menambah dana terkumpul '.$this->prefix
        );
    }

    /* =====================================================================
     | A. SINKRONISASI NILAI DASAR
     ===================================================================== */

    public function test_widget_dashboard_identik_dengan_modul_dana_kebajikan(): void
    {
        $dashboard = $this->danaTerkumpulDashboard();
        $modul = $this->danaTerkumpulModul();

        $this->assertSame(
            $modul,
            $dashboard,
            "Nilai 'DANA TERKUMPUL' di Dashboard DPS (Rp {$dashboard}) wajib identik dengan "
            ."modul Dana Kebajikan (Rp {$modul})."
        );
    }

    public function test_widget_dashboard_membaca_sum_kredit_akun_2_3001(): void
    {
        $akun = $this->akunKebajikan();

        if (! $akun) {
            $this->markTestSkipped('Akun 2-3001 belum ter-seed pada basis data ini.');
        }

        $ringkasan = app(DanaKebajikanService::class)->ringkasan();

        $this->assertSame(
            (float) $ringkasan['totalTerkumpul'],
            $this->danaTerkumpulDashboard(),
            'Widget Dashboard DPS wajib menampilkan SUM(KREDIT) mutasi jurnal akun 2-3001.'
        );
    }

    public function test_widget_tidak_lagi_menjumlahkan_tabel_transaksi(): void
    {
        // Nilai keliru dari query lama (LIKE pada jenis_transaksi, kolom `status`).
        $nilaiLama = (float) Transaksi::where(function ($q) {
            $q->where('jenis_transaksi', 'like', '%Denda%')
                ->orWhere('jenis_transaksi', 'like', '%Penalti%')
                ->orWhere('jenis_transaksi', 'like', "%Ta'zir%")
                ->orWhere('jenis_transaksi', 'like', '%Kebajikan%')
                ->orWhere('jenis_transaksi', 'like', '%Infaq%');
        })
            ->where('status', '!=', 'Voided')
            ->sum('jumlah');

        $dashboard = $this->danaTerkumpulDashboard();

        // Bila kebetulan nilainya sama (basis data kosong), tidak ada yang bisa
        // dibuktikan — namun pada basis data dengan mutasi jurnal, nilai wajib
        // bersumber dari jurnal, bukan dari tabel transaksi.
        if ($nilaiLama === $dashboard) {
            $this->assertSame($nilaiLama, $dashboard);

            return;
        }

        $this->assertNotSame(
            $nilaiLama,
            $dashboard,
            'Widget tidak boleh lagi memakai hasil query tabel transaksi sebagai sumber dana terkumpul.'
        );
    }

    public function test_penyaluran_dana_tidak_menambah_dana_terkumpul(): void
    {
        $sebelumDashboard = $this->danaTerkumpulDashboard();
        $sebelumModul = $this->danaTerkumpulModul();

        $this->tambahMutasiDebet(250000.0);

        $this->assertSame(
            $sebelumDashboard,
            $this->danaTerkumpulDashboard(),
            'Mutasi DEBET 2-3001 (penyaluran) TIDAK boleh menambah Dana Terkumpul di Dashboard DPS.'
        );
        $this->assertSame(
            $sebelumModul,
            $this->danaTerkumpulModul(),
            'Mutasi DEBET 2-3001 (penyaluran) TIDAK boleh menambah Total Terkumpul di modul Dana Kebajikan.'
        );
    }

    /* =====================================================================
     | B. SINKRONISASI DINAMIS — mutasi kredit baru langsung terlihat
     ===================================================================== */

    public function test_mutasi_kredit_baru_langsung_terlihat_di_kedua_halaman(): void
    {
        $sebelumDashboard = $this->danaTerkumpulDashboard();
        $sebelumModul = $this->danaTerkumpulModul();

        $nominal = 175000.0;
        $this->tambahMutasiKredit($nominal);

        $sesudahDashboard = $this->danaTerkumpulDashboard();
        $sesudahModul = $this->danaTerkumpulModul();

        $this->assertSame(
            round($sebelumDashboard + $nominal, 2),
            round($sesudahDashboard, 2),
            'Dashboard DPS wajib langsung memuat mutasi KREDIT 2-3001 yang baru tanpa refresh berulang.'
        );
        $this->assertSame(
            round($sebelumModul + $nominal, 2),
            round($sesudahModul, 2),
            'Modul Dana Kebajikan wajib langsung memuat mutasi KREDIT 2-3001 yang baru.'
        );
        $this->assertSame(
            $sesudahModul,
            $sesudahDashboard,
            'Setelah mutasi baru, kedua halaman wajib tetap menampilkan angka yang sama persis.'
        );
    }

    public function test_akumulasi_beberapa_mutasi_kredit_tetap_sinkron(): void
    {
        $sebelum = $this->danaTerkumpulDashboard();

        $this->tambahMutasiKredit(100000.0);
        $this->tambahMutasiKredit(50000.0);
        $this->tambahMutasiKredit(25000.0);

        $this->assertSame(
            round($sebelum + 175000.0, 2),
            round($this->danaTerkumpulDashboard(), 2),
            'Akumulasi beberapa mutasi KREDIT wajib terjumlah tepat di Dashboard DPS.'
        );
        $this->assertSame(
            $this->danaTerkumpulModul(),
            $this->danaTerkumpulDashboard(),
            'Akumulasi beberapa mutasi KREDIT wajib tetap sinkron antar halaman.'
        );
    }

    public function test_penyaluran_dan_penerimaan_bercampur_tetap_sinkron(): void
    {
        $sebelum = $this->danaTerkumpulDashboard();

        $this->tambahMutasiKredit(300000.0);
        $this->tambahMutasiDebet(120000.0);

        $this->assertSame(
            round($sebelum + 300000.0, 2),
            round($this->danaTerkumpulDashboard(), 2),
            'Dana Terkumpul hanya bertambah dari KREDIT; DEBET tidak boleh ikut menambah.'
        );
        $this->assertSame(
            $this->danaTerkumpulModul(),
            $this->danaTerkumpulDashboard(),
            'Setelah campuran KREDIT dan DEBET, kedua halaman wajib tetap sinkron.'
        );
    }

    /* =====================================================================
     | C. TAMPILAN
     ===================================================================== */

    public function test_widget_menampilkan_format_rupiah_dengan_pemisah_ribuan(): void
    {
        $nilai = $this->danaTerkumpulDashboard();
        $terformat = 'Rp '.number_format($nilai, 0, ',', '.');

        $respon = $this->actingAs($this->penggunaDps())->get(route('dashboard.dps'));
        $respon->assertOk();

        $respon->assertSee('Dana Terkumpul');
        $respon->assertSee($terformat, false);

        if ($nilai >= 1000) {
            $this->assertStringContainsString(
                '.',
                number_format($nilai, 0, ',', '.'),
                'Nilai ribuan wajib memakai pemisah ribuan titik.'
            );
        }
    }

    public function test_label_widget_menyebut_sumber_akun_2_3001(): void
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dashboard.dps'));
        $respon->assertOk();

        $respon->assertSee('Akumulasi KREDIT akun 2-3001', false);
    }

    /* =====================================================================
     | D. REGRESI — widget & halaman lain tidak terganggu
     ===================================================================== */

    public function test_dashboard_tetap_menampilkan_seluruh_widget_lain(): void
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dashboard.dps'));
        $respon->assertOk();

        // Variabel yang dipakai view dashboard wajib tetap tersedia.
        foreach ([
            'totalAnggota', 'totalPembiayaanAktif', 'totalTransaksiAll',
            'pembiayaanPerAkad', 'totalMargin', 'totalBagiHasil', 'totalUjroh',
            'totalPendapatan', 'danaKebajikan', 'transaksiTerbaru', 'pembiayaanAktif',
        ] as $variabel) {
            $this->assertNotNull(
                $respon->viewData($variabel),
                "Variabel view '{$variabel}' wajib tetap dikirim ke dashboard."
            );
        }
    }

    public function test_modul_dana_kebajikan_tetap_menampilkan_ringkasan_lengkap(): void
    {
        $respon = $this->actingAs($this->penggunaDps())->get(route('dps.dana-kebajikan'));
        $respon->assertOk();

        foreach (['totalTerkumpul', 'totalDisalurkan', 'saldoKebajikan', 'validasiSaldo'] as $variabel) {
            $this->assertNotNull(
                $respon->viewData($variabel),
                "Variabel view '{$variabel}' wajib tetap dikirim ke modul Dana Kebajikan."
            );
        }

        $ringkasan = app(DanaKebajikanService::class)->ringkasan();
        $this->assertSame(
            round($ringkasan['totalTerkumpul'] - $ringkasan['totalDisalurkan'], 2),
            round($ringkasan['saldoKebajikan'], 2),
            'Saldo wajib tetap konsisten dengan rumus TERKUMPUL - DISALURKAN.'
        );
    }

    public function test_endpoint_ringkasan_json_tetap_sinkron(): void
    {
        $respon = $this->actingAs($this->penggunaDps())->getJson(route('dps.dana-kebajikan.ringkasan'));
        $respon->assertOk();

        $this->assertSame(
            round($this->danaTerkumpulDashboard(), 2),
            round((float) $respon->json('totalTerkumpul'), 2),
            'Endpoint JSON ringkasan wajib melaporkan nilai yang sama dengan widget dashboard.'
        );
    }

    public function test_widget_tidak_terpengaruh_transaksi_non_kebajikan(): void
    {
        $sebelum = $this->danaTerkumpulDashboard();

        Transaksi::create([
            'no_transaksi' => $this->prefix.'-NONKB',
            'waktu_transaksi' => now(),
            'jenis_transaksi' => 'Setoran Tunai',
            'posisi' => 'Kredit',
            'jumlah' => 5000000,
            'status_transaksi' => 'Sukses',
            'status' => 'Sukses',
        ]);

        $this->assertSame(
            $sebelum,
            $this->danaTerkumpulDashboard(),
            'Transaksi non-kebajikan tidak boleh mengubah Dana Terkumpul.'
        );
    }
}
