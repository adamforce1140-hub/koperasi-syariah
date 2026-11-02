<?php

namespace Tests\Feature;

use App\Models\Coa;
use App\Models\User;
use App\Models\ZakatTransaksi;
use App\Services\ZakatService;
use App\Support\Coa as CoaKode;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-07 (Z-06) — Pemilihan Kas/Bank pada penerimaan & penyaluran dana zakat.
 *
 * Sebelum perbaikan, form penerimaan & penyaluran tidak menyediakan input
 * `kode_kas` sehingga ZakatService selalu memakai default Kas Teller
 * (1-1001) — setoran/penyaluran lewat bank tercatat keliru.
 *
 * Test ini mengunci:
 *  1. Form menyediakan dropdown `kode_kas` berisi Kas Teller (1-1001) & Bank (1-1002)
 *     pada KEDUA form (penerimaan dan penyaluran).
 *  2. Penerimaan via bank mendebet akun Bank (bukan Kas Teller).
 *  3. Penyaluran via bank mengkredit akun Bank (bukan Kas Teller).
 *  4. Validasi menolak akun kas non-likuid (Brankas 1-1003), nonaktif, & tak dikenal.
 *  5. Tanpa `kode_kas` (kompatibilitas mundur) tetap memakai Kas Teller.
 */
class ZakatKasPilihanTest extends TestCase
{
    use DatabaseTransactions;

    private ZakatService $zakat;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zakat = app(ZakatService::class);
        $this->prefix = 'ZKTKAS'.strtoupper(substr(uniqid(), -6));

        if (
            ! Coa::findByKode(CoaKode::DANA_ZAKAT)
            || ! Coa::findByKode(CoaKode::KAS_TELLER)
            || ! Coa::findByKode(CoaKode::BANK_KOPERASI)
        ) {
            $this->markTestSkipped('CoA dana zakat / kas teller / bank belum ter-seed.');
        }
    }

    protected function tearDown(): void
    {
        Setting::flush();

        parent::tearDown();
    }

    /* ─────────────────────────── HELPER ─────────────────────────── */

    private function pengelola(): User
    {
        return User::firstOrCreate(
            ['email' => 'pengurus.zakat.kas@test.local'],
            [
                'name' => 'Pengelola Zakat Kas Uji',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
            ]
        );
    }

    private function saldo(string $dana): float
    {
        return $this->zakat->ringkasan($dana)['saldoAkhir'];
    }

    /** Selisih mutasi debet/kredit sebuah akun CoA antara sebelum & sesudah aksi. */
    private function deltaJurnal(string $kodeAkun, callable $aksi): array
    {
        $coa = Coa::findByKode($kodeAkun);
        $debet = (float) $coa->totalDebet(now()->toDateString());
        $kredit = (float) $coa->totalKredit(now()->toDateString());

        $aksi();

        return [
            'debet' => round((float) $coa->fresh()->totalDebet(now()->toDateString()) - $debet, 2),
            'kredit' => round((float) $coa->fresh()->totalKredit(now()->toDateString()) - $kredit, 2),
        ];
    }

    /* ─────────────── 1. DROPDOWN TERSEDIA DI KEDUA FORM ─────────────── */

    public function test_form_penerimaan_dan_penyaluran_menyediakan_pilihan_kas_bank(): void
    {
        $html = $this->actingAs($this->pengelola())
            ->get(route('zakat.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="kode_kas"', $html, 'Form wajib menyediakan input kode_kas.');
        $this->assertStringContainsString(CoaKode::KAS_TELLER, $html, 'Dropdown wajib memuat Kas Teller (1-1001).');
        $this->assertStringContainsString(CoaKode::BANK_KOPERASI, $html, 'Dropdown wajib memuat Bank (1-1002).');

        // Form penerimaan + form penyaluran → dua input kode_kas.
        $this->assertSame(
            2,
            substr_count($html, 'name="kode_kas"'),
            'Dropdown kode_kas wajib ada pada form penerimaan DAN form penyaluran.'
        );
    }

    /* ─────────────── 2. PENERIMAAN VIA BANK ─────────────── */

    public function test_penerimaan_via_bank_mendebet_akun_bank_bukan_kas_teller(): void
    {
        $tellerDebetSebelum = (float) Coa::findByKode(CoaKode::KAS_TELLER)->totalDebet(now()->toDateString());

        $deltaBank = $this->deltaJurnal(CoaKode::BANK_KOPERASI, function () {
            $this->actingAs($this->pengelola())
                ->post(route('zakat.store'), [
                    'dana' => 'zakat',
                    'kategori' => 'zakat_maal',
                    'pihak' => $this->prefix.' Muzakki Bank',
                    'jumlah' => 500000.00,
                    'tanggal' => now()->toDateString(),
                    'kode_kas' => CoaKode::BANK_KOPERASI,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        });

        $this->assertSame(
            Setting::pembulatanNominal(500000.00),
            $deltaBank['debet'],
            'Penerimaan via Bank harus MENDebet akun Bank (1-1002).'
        );
        $this->assertSame(0.0, $deltaBank['kredit']);

        $tellerDebetSesudah = (float) Coa::findByKode(CoaKode::KAS_TELLER)->totalDebet(now()->toDateString());
        $this->assertSame(
            $tellerDebetSebelum,
            $tellerDebetSesudah,
            'Penerimaan via Bank tidak boleh menyentuh Kas Teller (1-1001).'
        );
    }

    /* ─────────────── 3. PENYALURAN VIA BANK ─────────────── */

    public function test_penyaluran_via_bank_mengkredit_akun_bank(): void
    {
        // Siapkan saldo dana zakat terlebih dahulu (lewat bank).
        $this->zakat->terima([
            'dana' => 'zakat',
            'kategori' => 'zakat_maal',
            'pihak' => $this->prefix.' Muzakki Sumber',
            'jumlah' => 900000.00,
            'kode_kas' => CoaKode::BANK_KOPERASI,
        ], $this->pengelola()->id);

        $deltaBank = $this->deltaJurnal(CoaKode::BANK_KOPERASI, function () {
            $this->actingAs($this->pengelola())
                ->post(route('zakat.salurkan'), [
                    'dana' => 'zakat',
                    'kategori' => 'fakir',
                    'pihak' => $this->prefix.' Mustahik Bank',
                    'jumlah' => 300000.00,
                    'tanggal' => now()->toDateString(),
                    'kode_kas' => CoaKode::BANK_KOPERASI,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        });

        $this->assertSame(
            300000.00,
            $deltaBank['kredit'],
            'Penyaluran via Bank harus MENGkredit akun Bank (1-1002).'
        );
    }

    /* ─────────────── 4. VALIDASI AKUN KAS ─────────────── */

    public function test_validasi_menolak_akun_kas_non_likuid(): void
    {
        // 1-1003 Kas Brankas ada & aktif, tetapi BUKAN sumber pembayaran langsung.
        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'zakat',
                'kategori' => 'zakat_maal',
                'pihak' => $this->prefix.' Brankas Ditolak',
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
                'kode_kas' => CoaKode::KAS_BRANKAS,
            ])
            ->assertSessionHasErrors('kode_kas');

        $this->assertNull(
            ZakatTransaksi::where('pihak', $this->prefix.' Brankas Ditolak')->first(),
            'Kas Brankas bukan sumber pembayaran sah — transaksi tidak boleh tercatat.'
        );
    }

    public function test_validasi_menolak_akun_kas_nonaktif(): void
    {
        $bank = Coa::where('kode_akun', CoaKode::BANK_KOPERASI)->first();
        $bank->is_active = false;
        $bank->save();

        $this->actingAs($this->pengelola())
            ->post(route('zakat.store'), [
                'dana' => 'zakat',
                'kategori' => 'zakat_maal',
                'pihak' => $this->prefix.' Bank Nonaktif',
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
                'kode_kas' => CoaKode::BANK_KOPERASI,
            ])
            ->assertSessionHasErrors('kode_kas');

        $this->assertNull(
            ZakatTransaksi::where('pihak', $this->prefix.' Bank Nonaktif')->first(),
            'Akun kas nonaktif tidak boleh dipakai — transaksi tidak boleh tercatat.'
        );
    }

    public function test_validasi_menolak_akun_kas_tidak_dikenal(): void
    {
        $this->actingAs($this->pengelola())
            ->post(route('zakat.salurkan'), [
                'dana' => 'zakat',
                'kategori' => 'fakir',
                'pihak' => $this->prefix.' Kas Tak Dikenal',
                'jumlah' => 100000.00,
                'tanggal' => now()->toDateString(),
                'kode_kas' => '9-9999',
            ])
            ->assertSessionHasErrors('kode_kas');
    }

    /* ─────────────── 5. KOMPATIBILITAS MUNDUR ─────────────── */

    public function test_tanpa_kode_kas_default_ke_kas_teller(): void
    {
        $deltaTeller = $this->deltaJurnal(CoaKode::KAS_TELLER, function () {
            $this->actingAs($this->pengelola())
                ->post(route('zakat.store'), [
                    'dana' => 'infak_sedekah',
                    'kategori' => 'kegiatan_sosial',
                    'pihak' => $this->prefix.' Munfiq Default',
                    'jumlah' => 75000.00,
                    'tanggal' => now()->toDateString(),
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        });

        $this->assertSame(
            Setting::pembulatanNominal(75000.00),
            $deltaTeller['debet'],
            'Tanpa kode_kas, penerimaan harus tetap default ke Kas Teller (kompatibilitas mundur).'
        );
    }
}
