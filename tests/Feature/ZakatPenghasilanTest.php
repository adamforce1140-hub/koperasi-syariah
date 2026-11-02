<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-05 (temuan Z-03) — Utilitas hisab ZAKAT PENGHASILAN (profesi) anggota.
 *
 * Mengunci perilaku:
 *  - Zakat penghasilan = kadar (`zakat_rate`, default 2,5%) × penghasilan neto,
 *    dengan neto = bruto − kebutuhan pokok.
 *  - Hanya wajib bila neto mencapai NISHAB BULANAN (nishab maal 85 gram emas
 *    dibagi 12 bulan) — nol hardcoding, seluruhnya dapat dikonfigurasi.
 *  - Kebutuhan pokok tidak dapat melebihi penghasilan (neto minimal nol).
 *  - Endpoint `POST /zakat/hitung-penghasilan` tersedia sebagai PRA-ISI form
 *    penerimaan; posting tetap manual oleh Pengurus.
 */
class ZakatPenghasilanTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // Pulihkan cache parameter kebijakan agar tidak mencemari test lain.
        Setting::flush();

        parent::tearDown();
    }

    private function pengelola(): User
    {
        return User::updateOrCreate(
            ['email' => 'pengurus.zakat.penghasilan@test.local'],
            [
                'name' => 'Pengurus Zakat Penghasilan',
                'password' => Hash::make('rahasia'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /** Nishab bulanan yang diharapkan = nishab maal ÷ 12. */
    private function nishabBulanan(): float
    {
        return round(Setting::zakatNisabNominal() / 12, 2);
    }

    public function test_default_nishab_bulanan_seperduabelas_nishab_maal(): void
    {
        $hasil = Setting::hitungZakatPenghasilan(0);

        $this->assertSame($this->nishabBulanan(), $hasil['nishab_bulanan']);
        $this->assertSame(2.50, $hasil['kadar_persen']);
    }

    public function test_penghasilan_neto_di_bawah_nishab_bulanan_tidak_wajib(): void
    {
        $nishab = $this->nishabBulanan();
        $hasil = Setting::hitungZakatPenghasilan($nishab - 1);

        $this->assertFalse($hasil['kena_zakat']);
        $this->assertFalse($hasil['nishab_terpenuhi']);
        $this->assertSame(0.0, $hasil['zakat']);
    }

    /**
     * Kriteria penerimaan R-05: hasil 2,5% dari (penghasilan − kebutuhan pokok)
     * yang telah mencapai nishab bulanan.
     */
    public function test_penghasilan_neto_mencapai_nishab_dihitung_2_5_persen(): void
    {
        $neto = $this->nishabBulanan();
        $hasil = Setting::hitungZakatPenghasilan($neto);

        $this->assertTrue($hasil['kena_zakat']);
        $this->assertTrue($hasil['nishab_terpenuhi']);
        $this->assertSame(round($neto * 0.025, 2), $hasil['zakat']);
    }

    public function test_kebutuhan_pokok_mengurangi_penghasilan_neto(): void
    {
        $nishab = $this->nishabBulanan();
        $bruto = $nishab + 5_000_000;
        $kebutuhan = 5_000_000;

        $hasil = Setting::hitungZakatPenghasilan($bruto, $kebutuhan);

        $this->assertSame($nishab, $hasil['penghasilan_neto']);
        $this->assertSame(round($nishab * 0.025, 2), $hasil['zakat']);
    }

    public function test_kebutuhan_pokok_melebihi_bruto_neto_nol_dan_tidak_wajib(): void
    {
        $hasil = Setting::hitungZakatPenghasilan(1_000_000, 2_000_000);

        $this->assertSame(0.0, $hasil['penghasilan_neto']);
        $this->assertFalse($hasil['kena_zakat']);
        $this->assertSame(0.0, $hasil['zakat']);
    }

    public function test_endpoint_hitung_penghasilan_mengembalikan_hasil(): void
    {
        $nishab = $this->nishabBulanan();
        $bruto = $nishab + 10_000_000;
        $pokok = 10_000_000;

        $resp = $this->actingAs($this->pengelola())
            ->postJson(route('zakat.hitung.penghasilan'), [
                'bruto' => $bruto,
                'kebutuhan_pokok' => $pokok,
            ])
            ->assertOk()
            ->assertJson([
                'sukses' => true,
                'kena_zakat' => true,
                'nishab_bulanan' => $nishab,
                'penghasilan_neto' => $nishab,
                'nishab_terpenuhi' => true,
            ]);

        $this->assertEquals(round($nishab * 0.025, 2), $resp->json('zakat'));
    }

    public function test_endpoint_hitung_penghasilan_validasi_input(): void
    {
        $this->actingAs($this->pengelola())
            ->postJson(route('zakat.hitung.penghasilan'), ['kebutuhan_pokok' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bruto']);
    }
}
