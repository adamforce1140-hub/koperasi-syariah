<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\Deposito;
use App\Models\DepositoProduct;
use App\Models\DepositoProfitSharing;
use App\Models\Jurnal;
use App\Models\KasHarian;
use App\Models\Rekening;
use App\Models\User;
use App\Services\DepositoService;
use App\Services\PendapatanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * R8 — Distribusi bagi hasil deposito berbasis PENDAPATAN INVESTASI RIIL.
 *
 * Memverifikasi bahwa angka bagi hasil tidak lagi diketik manual melainkan
 * dihitung sistem dari pendapatan periode lalu dialokasikan PROPORSIONAL
 * terhadap pokok tiap deposito aktif, serta idempoten (aman dijalankan ulang).
 *
 * R12 — Urutan tutup rekening deposito: jurnal diposting & terverifikasi
 * SEBELUM saldo dinolkan dan status rekening ditutup.
 */
class Fase3BagiHasilLabaRiilTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'R8BH'.strtoupper(substr(uniqid(), -6));
    }

    /* =====================================================================
     | FIXTURE
     |===================================================================== */

    private function pengurus(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.bagihasil.fase3@test.local'],
            [
                'name' => 'Pengurus Bagi Hasil Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    private function anggota(string $suffix = 'A'): Anggota
    {
        return Anggota::firstOrCreate(
            ['no_anggota' => $this->prefix.$suffix],
            [
                'nik' => str_pad((string) random_int(1, 9999999999999999), 16, '0', STR_PAD_LEFT),
                'nama_lengkap' => 'Anggota Uji Bagi Hasil '.$suffix,
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]
        );
    }

    private function produk(int $tenor = 12, float $nisbahAnggota = 60.00): DepositoProduct
    {
        return DepositoProduct::create([
            'name' => $this->prefix.' Deposito '.$tenor.' Bulan',
            'tipe_produk' => DepositoProduct::TIPE_DEPOSITO,
            'has_nisbah' => true,
            'coa_kode' => DepositoProduct::COA_BY_TIPE[DepositoProduct::TIPE_DEPOSITO],
            'tenor_months' => $tenor,
            'nisbah_anggota' => $nisbahAnggota,
            'nisbah_koperasi' => 100 - $nisbahAnggota,
            'min_amount' => 100000,
            'is_active' => true,
        ]);
    }

    private function rekeningSukarela(Anggota $anggota, float $saldo = 100000000): Rekening
    {
        $akunId = DB::table('akun')->where('kode_akun', '1133')->value('id')
            ?? DB::table('akun')->value('id');

        if (! $akunId) {
            $this->markTestSkipped('Tabel akun kosong — jalankan AkunSeeder.');
        }

        return Rekening::create([
            'no_rekening' => 'RKN-'.strtoupper(substr(uniqid(), -8)),
            'anggota_id' => $anggota->id,
            'akun_id' => $akunId,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => $saldo,
            'batas_minimum' => 0,
            'tanggal_buka' => now()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);
    }

    /** Buka deposito ACTIVE berukuran $pokok milik anggota baru. */
    private function deposito(
        float $pokok,
        float $nisbahAnggota = 60.00,
        string $suffix = 'A',
        int $tenor = 12
    ): Deposito {
        $anggota = $this->anggota($suffix);
        $produk = $this->produk($tenor, $nisbahAnggota);
        $sumber = $this->rekeningSukarela($anggota, $pokok * 2);

        return app(DepositoService::class)->createDeposito([
            'member_id' => $anggota->id,
            'product_id' => $produk->id,
            'source_saving_account_id' => $sumber->id,
            'principal_amount' => $pokok,
            'user_id' => $this->pengurus()->id,
        ]);
    }

    /** Total kredit jurnal pada CoA tertentu (untuk audit jejak akuntansi). */
    private function totalKreditCoa(string $kode): float
    {
        $coa = Coa::findByKode($kode);

        if (! $coa) {
            return 0.0;
        }

        return round((float) DB::table('jurnal_detail')->where('coa_id', $coa->id)->sum('kredit'), 2);
    }

    /* =====================================================================
     | 1. ALOKASI PROPORSIONAL & NISBAH
     |===================================================================== */

    public function test_bagi_hasil_periode_dialokasikan_proporsional_terhadap_pokok(): void
    {
        // Pool pendapatan investasi riil periode yang akan dibagikan.
        $pendapatan = 12000000.0;

        // Dua deposito: pokok 30jt dan 10jt → bobot 75% : 25%.
        $besar = $this->deposito(30000000, 60.00, 'BESAR');
        $kecil = $this->deposito(10000000, 50.00, 'KECIL');

        $beforePorsi = collect([$besar, $kecil])->mapWithKeys(fn ($d) => [
            $d->id => [
                'anggota' => (float) $d->principal_amount,
                'kredit' => $this->totalKreditCoa(Deposito::COA_SIMPANAN_SUKARELA),
            ],
        ]);

        $hasil = app(DepositoService::class)->bagiHasilPeriode(2026, 5, $pendapatan, $this->pengurus()->id);

        $this->assertSame('05/2026', $hasil['periode']);
        $this->assertSame(2, $hasil['dibagikan'], 'Kedua deposito ACTIVE harus ikut dibagikan.');
        $this->assertSame(0, $hasil['dilewati']);

        // Porsi revenue: 75% × 12.000.000 = 9.000.000 → × 60% nisbah = 5.400.000
        $sharingBesar = DepositoProfitSharing::where('deposito_id', $besar->id)
            ->where('period_year', 2026)->where('period_month', 5)->firstOrFail();
        $this->assertSame(9000000.0, (float) $sharingBesar->gross_koperasi_revenue);
        $this->assertSame(5400000.0, (float) $sharingBesar->member_profit_amount);

        // Porsi revenue: 25% × 12.000.000 = 3.000.000 → × 50% nisbah = 1.500.000
        $sharingKecil = DepositoProfitSharing::where('deposito_id', $kecil->id)
            ->where('period_year', 2026)->where('period_month', 5)->firstOrFail();
        $this->assertSame(3000000.0, (float) $sharingKecil->gross_koperasi_revenue);
        $this->assertSame(1500000.0, (float) $sharingKecil->member_profit_amount);

        // Setiap bagi hasil wajib punya jejak jurnal.
        $this->assertNotNull($sharingBesar->journal_id);
        $this->assertNotNull($sharingKecil->journal_id);

        // Pemakaian nisbah per deposito (bukan bunga seragam) — 60% vs 50%.
        $this->assertNotSame(
            (float) $sharingBesar->member_profit_amount / (float) $sharingBesar->gross_koperasi_revenue,
            (float) $sharingKecil->member_profit_amount / (float) $sharingKecil->gross_koperasi_revenue,
            'Porsi anggota harus mengikuti nisbah masing-masing deposito.'
        );

        $this->assertSame(6900000.0, $hasil['total_porsi_anggota']);
        $this->assertSame(40000000.0, $hasil['total_pokok']);

        // Akun beban bagi hasil bertambah tepat sebesar total porsi anggota.
        $this->assertGreaterThan(0, $this->totalKreditCoa(Deposito::COA_SIMPANAN_SUKARELA) - $beforePorsi[$besar->id]['kredit']);
    }

    public function test_distribusi_dari_pendapatan_service_menggunakan_angka_sistem_bukan_input_manual(): void
    {
        $svc = app(PendapatanService::class);

        // Periode lampau yang tidak memiliki angsuran dibayar → Rp 0.
        $kosong = $svc->pendapatanInvestasiBulanan(2010, 1);
        $this->assertSame(0.0, $kosong['total']);

        // Periode tanpa pendapatan tidak pernah membentuk jurnal baru.
        $sebelum = Jurnal::query()->count();
        $hasil = app(DepositoService::class)->bagiHasilPeriode(2010, 1, $kosong['total'], $this->pengurus()->id);

        $this->assertSame(0, $hasil['dibagikan']);
        $this->assertSame(0.0, $hasil['total_porsi_anggota']);
        $this->assertSame($sebelum, Jurnal::query()->count(), 'Tanpa pendapatan tidak boleh ada jurnal.');
    }

    /* =====================================================================
     | 2. IDEMPOTENSI (AMAN DIJALANKAN ULANG)
     |===================================================================== */

    public function test_bagi_hasil_periode_idempoten_tidak_membentuk_jurnal_ganda(): void
    {
        $deposito = $this->deposito(20000000, 60.00, 'IDEM');

        $pertama = app(DepositoService::class)->bagiHasilPeriode(2026, 6, 5000000, $this->pengurus()->id);
        $this->assertSame(1, $pertama['dibagikan']);
        $this->assertSame(0, $pertama['dilewati']);

        $jumlahSharing = DepositoProfitSharing::where('deposito_id', $deposito->id)
            ->where('period_year', 2026)->where('period_month', 6)->count();
        $this->assertSame(1, $jumlahSharing);

        // Jalankan ulang periode yang sama → wajib dilewati, tanpa jurnal baru.
        $kedua = app(DepositoService::class)->bagiHasilPeriode(2026, 6, 5000000, $this->pengurus()->id);
        $this->assertSame(0, $kedua['dibagikan']);
        $this->assertSame(1, $kedua['dilewati']);

        $this->assertSame(
            1,
            DepositoProfitSharing::where('deposito_id', $deposito->id)
                ->where('period_year', 2026)->where('period_month', 6)->count(),
            'Idempotensi: periode yang sudah dibayar tidak boleh dibayar dua kali.'
        );

        $porsi = DepositoProfitSharing::where('deposito_id', $deposito->id)
            ->where('period_year', 2026)->where('period_month', 6)->firstOrFail();
        $this->assertSame(3000000.0, (float) $porsi->member_profit_amount);
    }

    /* =====================================================================
     | 3. VALIDASI PARAMETER
     |===================================================================== */

    public function test_bagi_hasil_periode_menolak_bulan_di_luar_1_sampai_12(): void
    {
        $this->expectException(ValidationException::class);

        app(DepositoService::class)->bagiHasilPeriode(2026, 13, 1000000, $this->pengurus()->id);
    }

    public function test_bagi_hasil_periode_menolak_pendapatan_negatif(): void
    {
        $this->expectException(ValidationException::class);

        app(DepositoService::class)->bagiHasilPeriode(2026, 3, -1, $this->pengurus()->id);
    }

    public function test_pendapatan_service_menolak_bulan_tidak_valid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PendapatanService::class)->pendapatanInvestasiBulanan(2026, 0);
    }

    /* =====================================================================
     | 4. ENDPOINT PRODUKSI (R8) — OTORISASI & HITUNG SISTEM
     |===================================================================== */

    public function test_endpoint_distribusi_bagi_hasil_hanya_untuk_pengelola(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'teller.bagihasil.fase3@test.local'],
            ['name' => 'Teller Uji', 'password' => Hash::make('password'), 'role' => 'teller', 'is_active' => true]
        );

        $this->actingAs($teller)
            ->postJson('/api/deposito/distribusi-bagi-hasil', ['year' => 2026, 'month' => 5])
            ->assertForbidden();
    }

    public function test_endpoint_distribusi_bagi_hasil_memvalidasi_periode(): void
    {
        $this->actingAs($this->pengurus())
            ->postJson('/api/deposito/distribusi-bagi-hasil', ['year' => 2026, 'month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['month']);
    }

    public function test_endpoint_distribusi_bagi_hasil_mengembalikan_ringkasan_periode(): void
    {
        $respon = $this->actingAs($this->pengurus())
            ->postJson('/api/deposito/distribusi-bagi-hasil', ['year' => 2010, 'month' => 1]);

        $respon->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.periode', '01/2010')
            ->assertJsonStructure([
                'data' => [
                    'periode', 'total_pendapatan', 'total_pokok',
                    'dibagikan', 'dilewati', 'total_porsi_anggota',
                    'rincian_pendapatan' => ['margin', 'bagi_hasil', 'ujroh', 'total'],
                ],
            ]);
    }

    /* =====================================================================
     | 5. R12 — URUTAN JURNAL vs PENUTUPAN REKENING DEPOSITO
     |===================================================================== */

    public function test_pencairan_deposito_mencatat_jurnal_sebelum_menutup_rekening(): void
    {
        $teller = User::firstOrCreate(
            ['email' => 'teller.pencairan.fase3@test.local'],
            ['name' => 'Teller Pencairan', 'password' => Hash::make('password'), 'role' => 'teller', 'is_active' => true]
        );
        $deposito = $this->deposito(5000000, 60.00, 'CAIR');

        $rekening = $deposito->depositoRekening;
        $this->assertNotNull($rekening);

        // Sediakan laci teller dengan modal awal yang mencukupi (anti-deficit).
        KasHarian::where('user_id', $teller->id)->where('tanggal', now()->toDateString())->delete();
        KasHarian::create([
            'user_id' => $teller->id,
            'tanggal' => now()->toDateString(),
            'modal_awal' => 50000000,
            'tambah_modal' => [],
            'waktu_opening' => now()->format('H:i:s'),
            'status_opname' => KasHarian::STATUS_OPEN,
        ]);

        $nominal = (float) $rekening->saldo;

        $respon = $this->actingAs($teller)
            ->post(route('teller.deposito.cair', $rekening->id));

        $respon->assertRedirect();

        $rekening->refresh();

        // 1. Status rekening ditutup & saldo dinolkan (efek akhir).
        $this->assertSame('Ditutup', $rekening->status_rekening);
        $this->assertSame(0.0, (float) $rekening->saldo);

        // 2. Jurnal pencairan wajib ada: DEBET 2-1003 | KREDIT 1-1001 Kas Teller.
        $jurnal = Jurnal::where('sumber', 'Penarikan Simpanan Berjangka')
            ->where('keterangan', 'like', '%'.$rekening->no_rekening.'%')
            ->first();

        $this->assertNotNull($jurnal, 'R12: jurnal pencairan deposito WAJIB terbentuk sebelum rekening ditutup.');
        $this->assertSame('Posted', $jurnal->status);
        $this->assertEqualsWithDelta($nominal, (float) $jurnal->total_debet, 0.01);
        $this->assertEqualsWithDelta($nominal, (float) $jurnal->total_kredit, 0.01);
    }
}
