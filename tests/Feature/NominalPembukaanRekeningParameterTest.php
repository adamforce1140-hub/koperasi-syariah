<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Coa;
use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Models\User;
use App\Services\AutoJournalingService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PARAMETER DINAMIS NOMINAL PEMBUKAAN REKENING — pengujian menyeluruh.
 *
 * Mengunci syarat penerimaan permintaan:
 *   1. Parameter tersimpan di `kebijakan_koperasis` (minimum_pembukaan_rekening,
 *      maksimum_pembukaan_rekening) + jejak `rekening.nominal_pembukaan`.
 *   2. Validasi terpusat di `App\Support\Setting` / `KebijakanKoperasi` —
 *      decimal presisi 2, batas min/max DINAMIS, pesan error spesifik.
 *   3. Semua jalur pembukaan rekening memakai batas dinamis (tanpa hardcode).
 *   4. Tidak ada regresi pada alur lama (kolom nullable + backfill).
 *   5. Fleksibilitas: batas dapat diubah tanpa modifikasi kode inti.
 */
class NominalPembukaanRekeningParameterTest extends TestCase
{
    use DatabaseTransactions;

    private const MIN_DEFAULT = 10000.00;

    private const MAKS_DEFAULT = 50000000.00;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pengurus = User::firstOrCreate(
            ['email' => 'nominal.pembukaan.pengurus@test.local'],
            [
                'name' => 'Uji Nominal Pembukaan',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        Setting::flush();
    }

    private function sebagai(string $peran): User
    {
        return User::firstOrCreate(
            ['email' => "nominal.pembukaan.{$peran}@test.local"],
            [
                'name' => 'Uji '.ucfirst($peran),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ]
        );
    }

    /** Set batas parameter dan segarkan cache Setting. */
    private function tetapkanBatas(float $minimum, float $maksimum): void
    {
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $kebijakan->minimum_pembukaan_rekening = $minimum;
        $kebijakan->maksimum_pembukaan_rekening = $maksimum;
        $kebijakan->save();

        Setting::flush();
    }

    /* =====================================================================
     | 1. SKEMA & PERSISTENSI PARAMETER
     |===================================================================== */

    public function test_kolom_parameter_tersedia_di_tabel_kebijakan(): void
    {
        $this->assertTrue(
            Schema::hasColumn('kebijakan_koperasis', 'minimum_pembukaan_rekening'),
            'Kolom minimum_pembukaan_rekening harus ada di kebijakan_koperasis.'
        );
        $this->assertTrue(
            Schema::hasColumn('kebijakan_koperasis', 'maksimum_pembukaan_rekening'),
            'Kolom maksimum_pembukaan_rekening harus ada di kebijakan_koperasis.'
        );
        $this->assertTrue(
            Schema::hasColumn('rekening', 'nominal_pembukaan'),
            'Kolom jejak nominal_pembukaan harus ada di tabel rekening.'
        );
    }

    public function test_kolom_jejak_memakai_decimal_15_2(): void
    {
        $kolom = collect(DB::select('SHOW COLUMNS FROM rekening WHERE Field = ?', ['nominal_pembukaan']))->first();

        $this->assertNotNull($kolom, 'Kolom nominal_pembukaan tidak ditemukan.');
        $this->assertStringContainsString('decimal(15,2)', strtolower((string) $kolom->Type));
    }

    /* =====================================================================
     | 2. RESOLVER PARAMETER TERPUSAT (Setting + KebijakanKoperasi)
     |===================================================================== */

    public function test_setting_mengembalikan_default_saat_kebijakan_belum_diatur(): void
    {
        // Kolom parameter NOT NULL di level skema, sehingga jalur "belum diatur"
        // diuji dengan menghapus baris kebijakan (kondisi seed pertama) di dalam
        // transaksi test — bukan dengan menegaskan konstanta terhadap data nyata.
        KebijakanKoperasi::query()->delete();

        Setting::flush();

        $this->assertSame(self::MIN_DEFAULT, Setting::minimumPembukaanRekening());
        $this->assertSame(self::MAKS_DEFAULT, Setting::maksimumPembukaanRekening());

        $batas = Setting::batasPembukaanRekening();
        $this->assertSame(self::MIN_DEFAULT, $batas['minimum']);
        $this->assertSame(self::MAKS_DEFAULT, $batas['maksimum']);
    }

    public function test_setting_mengikuti_nilai_kebijakan_yang_sedang_berlaku(): void
    {
        // Kekokohan terhadap data nyata: nilai yang tersimpan harus dibaca apa
        // adanya (bukan ditimpa default), apa pun nilai yang diatur operator.
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;
        $berlakuMin = $kebijakan->minimum_pembukaan_rekening;
        $berlakuMaks = $kebijakan->maksimum_pembukaan_rekening;

        Setting::flush();

        $this->assertSame(
            (float) ($berlakuMin ?? self::MIN_DEFAULT),
            Setting::minimumPembukaanRekening()
        );
        $this->assertSame(
            (float) ($berlakuMaks ?? self::MAKS_DEFAULT),
            Setting::maksimumPembukaanRekening()
        );
    }

    public function test_kebijakan_mendelegasikan_resolver_ke_setting(): void
    {
        $this->tetapkanBatas(25000, 75000000);

        $this->assertSame(25000.0, KebijakanKoperasi::minimumPembukaanRekening());
        $this->assertSame(75000000.0, KebijakanKoperasi::maksimumPembukaanRekening());
        $this->assertSame(
            ['minimum' => 25000.0, 'maksimum' => 75000000.0],
            KebijakanKoperasi::batasPembukaanRekening()
        );
    }

    public function test_batas_maksimum_dipaksa_tidak_lebih_kecil_dari_minimum(): void
    {
        // Data tidak konsisten (maks < min) tidak boleh menghasilkan rentang negatif.
        $this->tetapkanBatas(50000, 10000);

        $batas = KebijakanKoperasi::batasPembukaanRekening();
        $this->assertSame(50000.0, $batas['minimum']);
        $this->assertSame(50000.0, $batas['maksimum'], 'Maksimum harus dinaikkan minimal setara minimum.');
    }

    /* =====================================================================
     | 3. VALIDASI & PESAN ERROR SPESIFIK
     |===================================================================== */

    public function test_aturan_validasi_dibangun_dinamis_dari_parameter(): void
    {
        $this->tetapkanBatas(20000, 90000000);

        $this->assertSame(
            ['required', 'numeric', 'min:20000', 'max:90000000'],
            KebijakanKoperasi::aturanNominalPembukaan()
        );
        $this->assertSame(
            ['nullable', 'numeric', 'min:20000', 'max:90000000'],
            KebijakanKoperasi::aturanNominalPembukaan(false)
        );
    }

    public function test_validator_menolak_nilai_kosong_dengan_pesan_wajib_diisi(): void
    {
        $pesan = KebijakanKoperasi::validasiNominalPembukaan(null);

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('wajib diisi', $pesan);
    }

    public function test_validator_menolak_tipe_data_bukan_angka(): void
    {
        $pesan = KebijakanKoperasi::validasiNominalPembukaan('seratus ribu');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('harus berupa angka', $pesan);
    }

    public function test_validator_menolak_di_bawah_batas_minimum_dengan_konteks_spesifik(): void
    {
        $this->tetapkanBatas(50000, 10000000);

        $pesan = KebijakanKoperasi::validasiNominalPembukaan(25000);

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('batas minimum', $pesan);
        $this->assertStringContainsString('50.000,00', $pesan, 'Pesan harus memuat nilai minimum aktual.');
    }

    public function test_validator_menolak_di_atas_batas_maksimum_dengan_konteks_spesifik(): void
    {
        $this->tetapkanBatas(10000, 2000000);

        $pesan = KebijakanKoperasi::validasiNominalPembukaan(50000000);

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('batas maksimum', $pesan);
        $this->assertStringContainsString('2.000.000,00', $pesan, 'Pesan harus memuat nilai maksimum aktual.');
    }

    public function test_validator_menerima_nilai_dalam_rentang(): void
    {
        $this->tetapkanBatas(10000, 5000000);

        $this->assertNull(KebijakanKoperasi::validasiNominalPembukaan(10000), 'Batas bawah inklusif.');
        $this->assertNull(KebijakanKoperasi::validasiNominalPembukaan(5000000), 'Batas atas inklusif.');
        $this->assertNull(KebijakanKoperasi::validasiNominalPembukaan(2500000));
    }

    /* =====================================================================
     | 4. FLEKSIBILITAS — BATAS DIUBAH TANPA MODIFIKASI KODE INTI
     |===================================================================== */

    public function test_perubahan_parameter_langsung_berlaku_tanpa_modifikasi_kode(): void
    {
        $this->tetapkanBatas(10000, 50000000);

        // Sebelum perubahan: nilai 300.000.000 ditolak.
        $this->assertNotNull(KebijakanKoperasi::validasiNominalPembukaan(300000000));

        // Pengurus mengubah parameter melalui antarmuka — cukup update data.
        $this->tetapkanBatas(5000, 500000000);

        // Sesudah perubahan: nilai yang sama diterima, aturan validasi ikut menyesuaikan.
        $this->assertNull(KebijakanKoperasi::validasiNominalPembukaan(300000000));
        $this->assertSame(
            ['required', 'numeric', 'min:5000', 'max:500000000'],
            KebijakanKoperasi::aturanNominalPembukaan()
        );
    }

    public function test_pembaruan_via_endpoint_pengurus_mengubah_parameter_dan_cache(): void
    {
        $this->tetapkanBatas(10000, 50000000);

        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), [
                'limit_manajer' => '50.000.000',
                'tazir_mode' => 'FLAT_HARIAN',
                'tazir_rate' => 0.1,
                'tazir_grace_period_days' => 7,
                'tazir_default_method' => 'AUTODEBET',
                'mandatory_principal_savings' => '100.000',
                'mandatory_monthly_savings' => '20.000',
                'minimum_pembukaan_rekening' => '15.000',
                'maksimum_pembukaan_rekening' => '80.000.000',
                'nama_koperasi' => 'Koperasi Uji',
            ]);

        $respons->assertRedirect();
        $respons->assertSessionHasNoErrors();

        $this->assertSame(15000.0, KebijakanKoperasi::minimumPembukaanRekening());
        $this->assertSame(80000000.0, KebijakanKoperasi::maksimumPembukaanRekening());
    }

    public function test_endpoint_pengurus_menolak_maksimum_lebih_kecil_dari_minimum(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), [
                'limit_manajer' => '50.000.000',
                'tazir_mode' => 'FLAT_HARIAN',
                'tazir_rate' => 0.1,
                'tazir_grace_period_days' => 7,
                'tazir_default_method' => 'AUTODEBET',
                'mandatory_principal_savings' => '100.000',
                'mandatory_monthly_savings' => '20.000',
                'minimum_pembukaan_rekening' => '90.000.000',
                'maksimum_pembukaan_rekening' => '10.000.000',
            ]);

        $respons->assertSessionHasErrors('maksimum_pembukaan_rekening');
    }

    /* =====================================================================
     | 5. JEJAK NOMINAL PEMBUKAAN (REKENING)
     |===================================================================== */

    public function test_rekening_menyimpan_jejak_nominal_pembukaan_terpisah_dari_saldo(): void
    {
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        $rekening = Rekening::create([
            'no_rekening' => 'UJI-NP-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => 150000,
            'nominal_pembukaan' => 100000,
            'tanggal_buka' => today()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        $rekening->refresh();

        $this->assertSame('100000.00', (string) $rekening->nominal_pembukaan);
        $this->assertSame('150000.00', (string) $rekening->saldo);

        // Saldo berjalan berubah → jejak nominal pembukaan TIDAK ikut berubah.
        $rekening->update(['saldo' => 250000]);

        $this->assertSame('100000.00', (string) $rekening->fresh()->nominal_pembukaan);
    }

    public function test_nominal_pembukaan_cast_ke_decimal_dua(): void
    {
        $rekening = new Rekening(['nominal_pembukaan' => 1234.567]);

        $this->assertSame('1234.57', $rekening->nominal_pembukaan);
    }

    /* =====================================================================
     | 6. INTEGRASI ALUR PEMBUKAAN REKENING (END-TO-END)
     |===================================================================== */

    public function test_api_pembuatan_rekening_menolak_nominal_di_bawah_batas_minimum(): void
    {
        $this->actingAs($this->sebagai('teller'));
        $this->tetapkanBatas(50000, 10000000);
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        $respons = $this->postJson('/api/rekening', [
            'no_rekening' => 'API-NP-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'tanggal_buka' => today()->toDateString(),
            'nominal_pembukaan' => 10000,
        ]);

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('nominal_pembukaan');
    }

    public function test_api_pembuatan_rekening_menerima_nominal_dalam_rentang(): void
    {
        $this->actingAs($this->sebagai('teller'));
        $this->tetapkanBatas(10000, 10000000);
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        $noRekening = 'API-NP-'.strtoupper(substr(uniqid(), -6));

        $respons = $this->postJson('/api/rekening', [
            'no_rekening' => $noRekening,
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'tanggal_buka' => today()->toDateString(),
            'nominal_pembukaan' => 100000,
        ]);

        $respons->assertStatus(201);

        $this->assertDatabaseHas('rekening', [
            'no_rekening' => $noRekening,
            'nominal_pembukaan' => 100000.00,
        ]);
    }

    public function test_api_pembuatan_rekening_menolak_ketika_nominal_dan_saldo_kosong(): void
    {
        $this->actingAs($this->sebagai('teller'));
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        $respons = $this->postJson('/api/rekening', [
            'no_rekening' => 'API-NP-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'tanggal_buka' => today()->toDateString(),
        ]);

        $respons->assertStatus(422);
        $respons->assertJsonValidationErrors('nominal_pembukaan');
    }

    public function test_api_pembuatan_rekening_memakai_saldo_sebagai_fallback_jejak_nominal(): void
    {
        $this->actingAs($this->sebagai('teller'));
        $this->tetapkanBatas(10000, 10000000);
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        $noRekening = 'API-FB-'.strtoupper(substr(uniqid(), -6));

        $respons = $this->postJson('/api/rekening', [
            'no_rekening' => $noRekening,
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'tanggal_buka' => today()->toDateString(),
            'saldo' => 250000,
        ]);

        $respons->assertStatus(201);

        $this->assertDatabaseHas('rekening', [
            'no_rekening' => $noRekening,
            'nominal_pembukaan' => 250000.00,
        ]);
    }

    /* =====================================================================
     | 7. INTEGRASI COA — PENGELOMPOKAN BERDASARKAN JENIS AKUN
     |===================================================================== */

    public function test_pemetaan_akun_coa_per_jenis_simpanan_tersedia(): void
    {
        foreach (['Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela', 'Simpanan Berjangka'] as $jenis) {
            $kode = JenisSimpanan::coaKode($jenis);

            $this->assertNotNull($kode, "Jenis simpanan \"{$jenis}\" harus terpetakan ke akun COA.");
            $this->assertMatchesRegularExpression(
                '/^\d-\d{4}$/',
                (string) $kode,
                "Kode COA untuk \"{$jenis}\" harus berformat N-NNNN."
            );
        }
    }

    public function test_akun_legacy_untuk_kolom_rekening_akun_id_resolved_dinamis(): void
    {
        foreach (['Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela'] as $jenis) {
            $this->assertNotNull(
                JenisSimpanan::akunLegacyId($jenis),
                "Jenis \"{$jenis}\" harus punya akun legacy untuk kolom rekening.akun_id."
            );
        }
    }

    public function test_jurnal_setoran_terbentuk_dengan_nominal_pembukaan(): void
    {
        $this->tetapkanBatas(10000, 50000000);

        $jenis = 'Simpanan Sukarela';
        $anggota = $this->anggotaBaru();

        if (! JenisSimpanan::coaKode($jenis) || ! JenisSimpanan::akunLegacyId($jenis)) {
            $this->markTestSkipped('Pemetaan COA untuk '.$jenis.' belum tersedia.');
        }

        $kodeCoa = JenisSimpanan::coaKode($jenis);
        $nominal = 250000.0;

        $jurnal = app(AutoJournalingService::class)->jurnalSetorSimpanan(
            $nominal,
            'Uji nominal pembukaan rekening',
            $jenis,
            1,
            null,
            null,
            $anggota->id
        );

        $this->assertNotNull($jurnal, 'Jurnal setoran harus terbentuk untuk nominal pembukaan yang valid.');
        $this->assertSame(
            $nominal,
            (float) $jurnal->total_debet,
            'Total debet jurnal harus sama persis dengan nominal pembukaan (presisi 2 desimal, tanpa pembulatan yang merugikan).'
        );
        $this->assertSame(
            $nominal,
            (float) $jurnal->total_kredit,
            'Total kredit jurnal harus sama persis dengan nominal pembukaan.'
        );
        $this->assertSame(
            $nominal,
            (float) $jurnal->detail()->where('coa_id', Coa::where('kode_akun', $kodeCoa)->value('id'))->value('kredit'),
            'Sisi kredit harus diarahkan ke akun COA hasil pengelompokan jenis simpanan.'
        );
    }

    /** Helper: anggota uji berprefix unik agar tidak bertabrakan dengan data dev. */
    private function anggotaBaru(): Anggota
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return Anggota::create([
            'no_anggota' => 'A-NP-'.$suffix,
            'nik' => '99'.random_int(10000000000000, 99999999999999),
            'nama_lengkap' => 'Anggota Uji NP '.$suffix,
            'jenis_kelamin' => 'Laki-laki',
            'alamat' => 'Jl. Pengujian No. 1',
            'kota' => 'Bandung',
            'provinsi' => 'Jawa Barat',
        ]);
    }

    /* =====================================================================
     | 8. REGRESI — REKENING LAMA TETAP VALID
     |===================================================================== */

    public function test_rekening_lama_tanpa_nominal_pembukaan_tidak_menyebabkan_error(): void
    {
        if (! Rekening::query()->exists()) {
            $this->markTestSkipped('Belum ada data rekening untuk uji regresi.');
        }

        // Akses kolom tidak boleh meledak (nullable + cast decimal).
        $rekening = Rekening::query()->first();

        $this->assertTrue(
            $rekening->nominal_pembukaan === null || is_string($rekening->nominal_pembukaan),
            'Kolom jejak harus null atau string decimal.'
        );
    }

    public function test_backfill_mengisi_jejak_rekening_lama_dari_saldo(): void
    {
        $anggota = Anggota::query()->first();

        if (! $anggota) {
            $this->markTestSkipped('Data anggota belum tersedia pada basis data uji.');
        }

        // Simulasi kondisi: kolom jejak kosong → migrasi mengisinya dari saldo.
        $rekening = Rekening::create([
            'no_rekening' => 'UJI-BF-'.strtoupper(substr(uniqid(), -6)),
            'anggota_id' => $anggota->id,
            'akun_id' => 1,
            'jenis_rekening' => 'Simpanan Sukarela',
            'akad' => 'Wadiah',
            'saldo' => 75000,
            'tanggal_buka' => today()->toDateString(),
            'status_rekening' => 'Aktif',
        ]);

        DB::table('rekening')->where('id', $rekening->id)->update(['nominal_pembukaan' => null]);
        DB::table('rekening')->whereNull('nominal_pembukaan')->update(['nominal_pembukaan' => DB::raw('saldo')]);

        $this->assertSame('75000.00', (string) $rekening->fresh()->nominal_pembukaan);
    }

    public function test_aturan_validasi_lama_hardcode_tidak_lagi_digunakan(): void
    {
        // Batas lama `min:1000` (CIF) / `min:1000000` (teller) harus sudah digantikan
        // oleh parameter. Bila parameter minimum diubah, aturan ikut berubah.
        $this->tetapkanBatas(3000000, 50000000);

        $aturan = KebijakanKoperasi::aturanNominalPembukaan();

        $this->assertContains('min:3000000', $aturan, 'Batas minimum harus bersumber dari parameter, bukan hardcode.');
        $this->assertNotContains('min:1000', $aturan);
        $this->assertNotContains('min:1000000', $aturan);
    }

    public function test_pesan_validasi_memuat_label_konteks_yang_dapat_disesuaikan(): void
    {
        $this->tetapkanBatas(10000, 1000000);

        $pesan = KebijakanKoperasi::pesanValidasiNominalPembukaan('Setoran awal');

        $this->assertStringContainsString('Setoran awal', $pesan['required']);
        $this->assertStringContainsString('Setoran awal', $pesan['min']);
        $this->assertStringContainsString('batas minimum', $pesan['min']);
    }
}
