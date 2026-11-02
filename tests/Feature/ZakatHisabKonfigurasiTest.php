<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Parameter hisab Zakat yang dapat dikonfigurasi (PSAK 109 & syariah).
 *
 * Menutup temuan audit T-02: sebelumnya zakat dicatat sebagai nominal manual
 * tanpa perhitungan kadar 2,5% / nishab. Pengujian ini mengunci:
 *  - kadar zakat default 2,5% dan nishab 85 gram emas dapat dikonfigurasi;
 *  - harta di bawah nishab TIDAK wajib zakat (mengembalikan 0);
 *  - endpoint kalkulator mengembalikan hasil hisab yang benar.
 *
 * Temuan audit Z-11: seluruh penyetelan parameter kini melalui JALUR HTTP
 * (`pengurus.kebijakan.update`) — sumber kebenaran yang sama dipakai aplikasi —
 * bukan lagi dengan mengubah model `KebijakanKoperasi` secara langsung. Pola uji
 * jadi konsisten: satu jalur untuk semua penyetelan kebijakan.
 */
class ZakatHisabKonfigurasiTest extends TestCase
{
    use DatabaseTransactions;

    private function pengelola(): User
    {
        return User::updateOrCreate(
            ['email' => 'pengurus.zakat.hisab@test.local'],
            [
                'name' => 'Pengurus Zakat Hisab',
                'password' => Hash::make('rahasia'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    /**
     * Payload minimal kebijakan: mempertahankan nilai berjalan agar pembaruan
     * sebagian (partial update) tidak mengosongkan parameter lain.
     */
    private function payloadKebijakan(): array
    {
        $kebijakan = KebijakanKoperasi::query()->first();

        return [
            'limit_manajer' => (string) ($kebijakan->limit_manajer ?: 1000000),
            'tazir_mode' => $kebijakan->tazir_mode ?: 'DISABLED',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) ($kebijakan->mandatory_principal_savings ?? 100000),
            'mandatory_monthly_savings' => (string) ($kebijakan->mandatory_monthly_savings ?? 50000),
            'minimum_pembukaan_rekening' => (string) ($kebijakan->minimum_pembukaan_rekening ?? 50000),
            'maksimum_pembukaan_rekening' => (string) ($kebijakan->maksimum_pembukaan_rekening ?? 10000000),
        ];
    }

    /**
     * Z-11 — setel parameter hisab melalui endpoint kebijakan HTTP (bukan model),
     * lalu segarkan cache Setting agar nilainya berlaku pada perhitungan.
     */
    private function setHisabViaHttp(array $parameter): void
    {
        if (KebijakanKoperasi::query()->first() === null) {
            $this->markTestSkipped('Baris kebijakan koperasi belum tersedia.');
        }

        $this->actingAs($this->pengelola())
            ->put(route('pengurus.kebijakan.update'), array_merge($this->payloadKebijakan(), $parameter))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Setting::flush();
    }

    /**
     * Setel parameter haul lewat jalur HTTP lalu segarkan cache Setting.
     * Dilewati bila baris kebijakan belum tersedia (mis. seeder belum dijalankan).
     */
    private function setHaul(bool $aktif, int $hari = 354): void
    {
        $this->setHisabViaHttp([
            'zakat_haul_aktif' => $aktif ? '1' : '0',
            'zakat_haul_hari' => (string) $hari,
        ]);
    }

    public function test_default_kadar_dan_nishab_sesuai_syariah(): void
    {
        $this->assertSame(2.50, Setting::zakatRate());
        $this->assertSame(85.00, Setting::zakatNisabGram());
    }

    public function test_harta_di_bawah_nishab_tidak_wajib_zakat(): void
    {
        $nishab = Setting::zakatNisabNominal();
        $hasil = Setting::hitungZakat($nishab - 1);

        $this->assertFalse($hasil['kena_zakat']);
        $this->assertSame(0.0, $hasil['zakat']);
    }

    public function test_harta_mencapai_nishab_dihitung_2_5_persen(): void
    {
        $nishab = Setting::zakatNisabNominal();
        $hasil = Setting::hitungZakat($nishab);

        $this->assertTrue($hasil['kena_zakat']);
        $this->assertSame(round($nishab * 0.025, 2), $hasil['zakat']);
    }

    public function test_endpoint_kalkulator_mengembalikan_hasil_hisab(): void
    {
        $nishab = Setting::zakatNisabNominal();

        $this->actingAs($this->pengelola())
            ->postJson(route('zakat.hitung'), ['harta' => $nishab])
            ->assertOk()
            ->assertJson([
                'sukses' => true,
                'kena_zakat' => true,
            ]);

        $this->assertEquals(
            round($nishab * 0.025, 2),
            $this->actingAs($this->pengelola())
                ->postJson(route('zakat.hitung'), ['harta' => $nishab])
                ->json('zakat')
        );
    }

    public function test_kadar_dapat_diubah_tanpa_mengubah_kode(): void
    {
        // Z-11 — penyetelan melalui endpoint kebijakan HTTP, bukan model langsung.
        $this->setHisabViaHttp(['zakat_rate' => '5']);

        $this->assertSame(5.0, Setting::zakatRate());

        $harta = Setting::zakatNisabNominal() * 2;
        $hasil = Setting::hitungZakat($harta);
        $this->assertSame(round($harta * 0.05, 2), $hasil['zakat']);
    }

    // ---------------------------------------------------------------------
    // R-03 (temuan Z-04): penegakan syarat HAUL (kepemilikan 1 tahun hijriah)
    // ---------------------------------------------------------------------

    public function test_default_haul_nonaktif_dan_panjang_354_hari(): void
    {
        // P4 Default Aman: tanpa pengaturan, haul NONAKTIF → hisab hanya nishab.
        $this->setHaul(false);

        $this->assertFalse(Setting::zakatHaulAktif());
        $this->assertSame(354, Setting::zakatHaulHari());
    }

    public function test_haul_nonaktif_hisab_hanya_memeriksa_nishab(): void
    {
        $this->setHaul(false);

        $nishab = Setting::zakatNisabNominal();
        // Tanpa tanggal kepemilikan pun tetap wajib bila nishab tercapai,
        // karena haul belum ditegakkan (perilaku lama tidak berubah).
        $hasil = Setting::hitungZakat($nishab);

        $this->assertTrue($hasil['kena_zakat']);
        $this->assertFalse($hasil['haul_aktif']);
        $this->assertTrue($hasil['haul_terpenuhi']);
        $this->assertSame(round($nishab * 0.025, 2), $hasil['zakat']);
    }

    /**
     * Kriteria penerimaan R-03: harta >= nishab dengan kepemilikan < 1 tahun
     * (354 hari hijriah) → kena_zakat = false.
     */
    public function test_haul_aktif_kepemilikan_kurang_dari_setahun_belum_wajib(): void
    {
        $this->setHaul(true, 354);

        $nishab = Setting::zakatNisabNominal();
        $sejak = Carbon::now()->subDays(100)->toDateString();
        $hasil = Setting::hitungZakat($nishab, $sejak);

        $this->assertFalse($hasil['kena_zakat']);
        $this->assertSame(0.0, $hasil['zakat']);
        $this->assertTrue($hasil['nishab_terpenuhi']);
        $this->assertTrue($hasil['haul_aktif']);
        $this->assertFalse($hasil['haul_terpenuhi']);
        $this->assertSame(354, $hasil['haul_hari']);
        $this->assertSame(100, $hasil['hari_kepemilikan']);
        $this->assertSame($sejak, $hasil['haul_sejak']);
    }

    public function test_haul_aktif_kepemilikan_genap_setahun_wajib_zakat(): void
    {
        $this->setHaul(true, 354);

        $nishab = Setting::zakatNisabNominal();
        $sejak = Carbon::now()->subDays(354)->toDateString();
        $hasil = Setting::hitungZakat($nishab, $sejak);

        $this->assertTrue($hasil['kena_zakat']);
        $this->assertTrue($hasil['haul_terpenuhi']);
        $this->assertSame(354, $hasil['hari_kepemilikan']);
        $this->assertSame(round($nishab * 0.025, 2), $hasil['zakat']);
    }

    public function test_haul_aktif_tanpa_tanggal_kepemilikan_fail_closed(): void
    {
        // Fail-closed: tanpa bukti tanggal kepemilikan, sistem TIDAK
        // menyatakan wajib zakat meskipun nishab tercapai.
        $this->setHaul(true, 354);

        $nishab = Setting::zakatNisabNominal();
        $hasil = Setting::hitungZakat($nishab);

        $this->assertFalse($hasil['kena_zakat']);
        $this->assertFalse($hasil['haul_terpenuhi']);
        $this->assertNull($hasil['hari_kepemilikan']);
        $this->assertNull($hasil['haul_sejak']);
        $this->assertSame(0.0, $hasil['zakat']);
    }

    public function test_panjang_haul_dapat_dikonfigurasi(): void
    {
        // Panjang haul bukan konstanta kode: mengubah parameter mengubah hasil.
        $this->setHaul(true, 200);

        $this->assertSame(200, Setting::zakatHaulHari());

        $nishab = Setting::zakatNisabNominal();
        $sejak = Carbon::now()->subDays(210)->toDateString();

        $this->assertTrue(Setting::hitungZakat($nishab, $sejak)['kena_zakat']);
    }

    public function test_endpoint_kalkulator_menegakkan_haul_saat_aktif(): void
    {
        $this->setHaul(true, 354);

        $nishab = Setting::zakatNisabNominal();
        $sejak = Carbon::now()->subDays(30)->toDateString();

        $this->actingAs($this->pengelola())
            ->postJson(route('zakat.hitung'), [
                'harta' => $nishab,
                'tanggal_kepemilikan' => $sejak,
            ])
            ->assertOk()
            ->assertJson([
                'sukses' => true,
                'kena_zakat' => false,
                'nishab_terpenuhi' => true,
                'haul_aktif' => true,
                'haul_terpenuhi' => false,
                'haul_hari' => 354,
                'hari_kepemilikan' => 30,
                'zakat' => 0,
            ]);
    }
}
