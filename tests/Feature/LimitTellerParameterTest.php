<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PARAMETER MODUL TELLER — LIMIT SETOR & TARIK TUNAI (ZERO-HARDCODING).
 *
 * Mengunci syarat penerimaan permintaan "parameter modul untuk mengatur limit
 * teller":
 *   1. Kolom parameter tersedia di `kebijakan_koperasis`:
 *      limit_penarikan_teller, limit_setoran_teller, teller_max_cash_limit,
 *      minimum_transaksi_teller.
 *   2. Form kebijakan Pengurus menampilkan & menyimpan keempat parameter ini.
 *   3. Resolver terpusat (Setting / KebijakanKoperasi) mengembalikan nilai
 *      parameter yang sedang berlaku.
 *   4. Perubahan parameter langsung memengaruhi logika limit transaksi teller
 *      (HierarkiRole) tanpa mengubah kode inti.
 */
class LimitTellerParameterTest extends TestCase
{
    use DatabaseTransactions;

    private const DEFAULT_LIMIT_TARIK = 5000000.00;

    private const DEFAULT_LIMIT_SETOR = 50000000.00;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pengurus = User::firstOrCreate(
            ['email' => 'limit.teller.pengurus@test.local'],
            [
                'name' => 'Uji Limit Teller',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        Setting::flush();
        HierarkiRole::flush();
    }

    /** Payload minimal agar endpoint kebijakan lolos validasi field wajib. */
    private function payloadDasar(array $tambahan = []): array
    {
        return array_merge([
            'limit_manajer' => '50.000.000',
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_rate' => 0.1,
            'tazir_grace_period_days' => 7,
            'tazir_default_method' => 'AUTODEBET',
            'mandatory_principal_savings' => '100.000',
            'mandatory_monthly_savings' => '20.000',
            'minimum_pembukaan_rekening' => '10.000',
            'maksimum_pembukaan_rekening' => '50.000.000',
            'nama_koperasi' => 'Koperasi Uji',
        ], $tambahan);
    }

    /* =====================================================================
     | 1. SKEMA & RESOLVER PARAMETER
     |===================================================================== */

    public function test_kolom_parameter_limit_teller_tersedia_di_tabel_kebijakan(): void
    {
        foreach ([
            'limit_penarikan_teller',
            'limit_setoran_teller',
            'teller_max_cash_limit',
            'minimum_transaksi_teller',
        ] as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('kebijakan_koperasis', $kolom),
                "Kolom {$kolom} harus ada di kebijakan_koperasis."
            );
        }
    }

    public function test_setting_mengembalikan_default_saat_kebijakan_belum_diatur(): void
    {
        KebijakanKoperasi::query()->delete();
        Setting::flush();

        $this->assertSame(self::DEFAULT_LIMIT_TARIK, Setting::limitPenarikanTeller());
        $this->assertSame(self::DEFAULT_LIMIT_SETOR, Setting::limitSetoranTeller());
    }

    public function test_setting_mengikuti_nilai_kebijakan_yang_sedang_berlaku(): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $berlakuTarik = $kebijakan->limit_penarikan_teller;
        $berlakuSetor = $kebijakan->limit_setoran_teller;

        Setting::flush();

        $this->assertSame(
            (float) ($berlakuTarik ?? self::DEFAULT_LIMIT_TARIK),
            Setting::limitPenarikanTeller()
        );
        $this->assertSame(
            (float) ($berlakuSetor ?? self::DEFAULT_LIMIT_SETOR),
            Setting::limitSetoranTeller()
        );
    }

    /* =====================================================================
     | 2. PERSISTENSI VIA FORM KEBIJAKAN PENGURUS
     |===================================================================== */

    public function test_endpoint_pengurus_menyimpan_parameter_limit_teller(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->payloadDasar([
                'limit_penarikan_teller' => '7.500.000',
                'limit_setoran_teller' => '60.000.000',
                'teller_max_cash_limit' => '30.000.000',
                'minimum_transaksi_teller' => '5.000',
            ]));

        $respons->assertRedirect();
        $respons->assertSessionHasNoErrors();

        $kebijakan = KebijakanKoperasi::getSetting();

        $this->assertSame(7500000.0, (float) $kebijakan->limit_penarikan_teller);
        $this->assertSame(60000000.0, (float) $kebijakan->limit_setoran_teller);
        $this->assertSame(30000000.0, (float) $kebijakan->teller_max_cash_limit);
        $this->assertSame(5000.0, (float) $kebijakan->minimum_transaksi_teller);
    }

    public function test_endpoint_pengurus_menolak_limit_negatif(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->payloadDasar([
                'limit_penarikan_teller' => '-1.000.000',
            ]));

        $respons->assertSessionHasErrors('limit_penarikan_teller');
    }

    public function test_endpoint_pengurus_menolak_limit_bukan_angka(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->payloadDasar([
                'limit_setoran_teller' => 'seratus juta',
            ]));

        $respons->assertSessionHasErrors('limit_setoran_teller');
    }

    /* =====================================================================
     | 2b. DUA LIMIT PEMBIAYAAN DISIMPAN TERPISAH
     |===================================================================== */

    public function test_limit_manajer_dan_limit_pembiayaan_manajer_disimpan_terpisah(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->payloadDasar([
                'limit_manajer' => '25.000.000',
                'limit_pembiayaan_manajer' => '250.000.000',
            ]));

        $respons->assertRedirect();
        $respons->assertSessionHasNoErrors();

        $kebijakan = KebijakanKoperasi::getSetting();

        $this->assertSame(25000000.0, (float) $kebijakan->limit_manajer);
        $this->assertSame(
            250000000.0,
            (float) $kebijakan->limit_pembiayaan_manajer,
            'Limit pembiayaan skala besar (Pengurus) tidak boleh tertimpa nilai limit Manajer.'
        );
    }

    public function test_fallback_limit_manajer_memakai_plafon_pembiayaan_bukan_limit_beban(): void
    {
        // Form mengirim `limit_manajer` kosong: fallback wajib memakai plafon
        // pembiayaan (50 juta), BUKAN limit approval beban (1 juta).
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->payloadDasar([
                'limit_manajer' => '',
            ]));

        $respons->assertRedirect();

        $kebijakan = KebijakanKoperasi::getSetting();

        $this->assertSame(
            Setting::limitManajerPembiayaan(),
            (float) $kebijakan->limit_manajer,
            'Fallback limit Manajer harus plafon pembiayaan, bukan limit approval beban.'
        );
    }

    public function test_halaman_kebijakan_menampilkan_field_limit_pembiayaan_manajer(): void
    {
        $respons = $this->actingAs($this->pengurus)->get(route('pengurus.kebijakan.index'));

        $respons->assertOk();
        $respons->assertSee('name="limit_pembiayaan_manajer"', false);
    }

    /* =====================================================================
     | 3. FORM MENAMPILKAN FIELD PARAMETER
     |===================================================================== */

    public function test_halaman_kebijakan_menampilkan_field_limit_teller(): void
    {
        $respons = $this->actingAs($this->pengurus)->get(route('pengurus.kebijakan.index'));

        $respons->assertOk();
        $respons->assertSee('name="limit_penarikan_teller"', false);
        $respons->assertSee('name="limit_setoran_teller"', false);
        $respons->assertSee('name="teller_max_cash_limit"', false);
        $respons->assertSee('name="minimum_transaksi_teller"', false);
    }

    public function test_halaman_kebijakan_menampilkan_ambang_approval_teller_berjenjang(): void
    {
        $respons = $this->actingAs($this->pengurus)->get(route('pengurus.kebijakan.index'));

        $respons->assertOk();

        // Section & kotak readonly ambang approval (pola seperti "Limit Pengurus").
        $respons->assertSee('Limit Kas &amp; Otorisasi Transaksi Teller', false);
        $respons->assertSee('id="ambang_penarikan_teller"', false);
        $respons->assertSee('id="ambang_setoran_teller"', false);

        // Angka ambang mengikuti nilai limit yang berlaku saat ini.
        $nilaiTarik = number_format((float) ($kebijakan = KebijakanKoperasi::getSetting())->limit_penarikan_teller
            ?? Setting::limitPenarikanTeller(), 0, ',', '.');
        $respons->assertSee('> Rp '.$nilaiTarik, false);

        // Skrip live binding tersedia (kotak ambang mengikuti input limit).
        $respons->assertSee('ambang_penarikan_teller', false);
        $respons->assertSee('ambang_setoran_teller', false);
    }

    /* =====================================================================
     | 4. EFEK PARAMETER TERHADAP LOGIKA LIMIT TRANSAKSI TELLER
     |===================================================================== */

    public function test_perubahan_limit_penarikan_berlaku_pada_logika_teller(): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->limit_penarikan_teller = 3_000_000;
        $kebijakan->save();

        Setting::flush();
        HierarkiRole::flush();

        $this->assertSame(
            3000000.0,
            HierarkiRole::limitPerTransaksi('teller', 'tarik_tunai'),
            'Limit penarikan teller harus mengikuti parameter kebijakan.'
        );

        // Pengurus menaikkan limit → langsung berlaku tanpa ubah kode.
        $kebijakan->limit_penarikan_teller = 9_000_000;
        $kebijakan->save();

        Setting::flush();
        HierarkiRole::flush();

        $this->assertSame(9000000.0, HierarkiRole::limitPerTransaksi('teller', 'tarik_tunai'));
    }

    public function test_perubahan_limit_setoran_berlaku_pada_logika_teller(): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->limit_setoran_teller = 25_000_000;
        $kebijakan->save();

        Setting::flush();
        HierarkiRole::flush();

        $this->assertSame(
            25000000.0,
            HierarkiRole::limitPerTransaksi('teller', 'setor_tunai'),
            'Limit setoran teller harus mengikuti parameter kebijakan.'
        );
    }
}
