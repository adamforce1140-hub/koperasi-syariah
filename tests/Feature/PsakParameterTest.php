<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\Coa as CoaModel;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\User;
use App\Services\CkpnService;
use App\Support\Coa;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PARAMETER PSAK & MESIN CKPN — pengujian fungsionalitas.
 *
 * Memastikan parameter yang dahulu berupa angka keras (rate deposito 8%,
 * nisbah 60%, margin 12%, ambang AML 100 juta, pembulatan & toleransi jurnal,
 * serta tarif penyisihan CKPN) benar-benar:
 *   1. Terdaftar pada SATU sumber nilai (Setting::DEFAULTS).
 *   2. Dapat diubah dari UI Pengurus tanpa menyentuh kode inti.
 *   3. Dijaga konsistensinya (CKPN wajib menaik seiring memburuknya
 *      kolektibilitas — PSAK 101/105/107).
 *   4. Benar-benar mengubah perilaku sistem (perhitungan CKPN, jurnal,
 *      pembulatan) — bukan sekadar tersimpan.
 */
class PsakParameterTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('kebijakan_koperasis')) {
            $this->markTestSkipped('Tabel kebijakan_koperasis belum tersedia.');
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'psak.pengurus@test.local'],
            [
                'name' => 'Uji PSAK Pengurus',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );

        Setting::flush();
    }

    /** Baris kebijakan yang dipakai pengujian (dibuat bila belum ada). */
    private function kebijakan(): KebijakanKoperasi
    {
        return KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
    }

    private function ckpn(): CkpnService
    {
        return app(CkpnService::class);
    }

    /**
     * Muat data form Master Settings secara lengkap agar pembaruan parameter
     * dapat diuji lewat rute sungguhan.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function muatan(array $override = []): array
    {
        $kebijakan = $this->kebijakan();

        return array_merge([
            'limit_manajer' => (string) $kebijakan->limit_manajer,
            'tazir_mode' => $kebijakan->tazir_mode ?: 'FLAT_HARIAN',
            'tazir_rate' => (string) ($kebijakan->tazir_rate ?? 0),
            'tazir_grace_period_days' => (string) ($kebijakan->tazir_grace_period_days ?? 0),
            'tazir_default_method' => $kebijakan->tazir_default_method ?: 'TUNAI',
            'mandatory_principal_savings' => (string) $kebijakan->mandatory_principal_savings,
            'mandatory_monthly_savings' => (string) $kebijakan->mandatory_monthly_savings,
            'minimum_pembukaan_rekening' => (string) $kebijakan->minimum_pembukaan_rekening,
            'maksimum_pembukaan_rekening' => (string) $kebijakan->maksimum_pembukaan_rekening,
            'nama_koperasi' => (string) $kebijakan->nama_koperasi,
            'shu_pct_cadangan_modal' => (string) Setting::get('shu_pct_cadangan_modal', 30),
            'shu_pct_jasa_anggota' => (string) Setting::get('shu_pct_jasa_anggota', 40),
            'shu_pct_jasa_modal' => (string) Setting::get('shu_pct_jasa_modal', 20),
            'shu_pct_dana_pendidikan' => (string) Setting::get('shu_pct_dana_pendidikan', 10),
            'limit_approval_beban' => (string) (int) Setting::limitApprovalBeban(),
            'metode_perhitungan_shu' => Setting::metodePerhitunganShu(),
            'kolektibilitas_kol1_hari' => (string) Setting::ambangKolektibilitas()['kol1'],
            'kolektibilitas_kol2_hari' => (string) Setting::ambangKolektibilitas()['kol2'],
            'kolektibilitas_kol3_hari' => (string) Setting::ambangKolektibilitas()['kol3'],
            'kolektibilitas_kol4_hari' => (string) Setting::ambangKolektibilitas()['kol4'],
        ], $override);
    }

    /**
     * Pembiayaan berjalan untuk pengujian CKPN.
     *
     * `sisa_pembiayaan` diisi sebesar TOTAL kewajiban akad (pokok + margin),
     * meniru perilaku jalur pencairan sungguhan (lihat DisbursementService /
     * PengajuanPembiayaanIntegController). Dengan begitu basis CKPN — piutang
     * POKOK bruto = `sisa_pembiayaan` − margin ditangguhkan — sama dengan
     * `$sisa` (pokok) sebagaimana diharapkan pengujian.
     */
    private function pembiayaan(float $sisa, ?string $kolektibilitas = null): Pembiayaan
    {
        $margin = round($sisa * 0.1, 2);

        return Pembiayaan::create([
            'no_pembiayaan' => 'UJI-PSAK-'.uniqid(),
            'anggota_id' => Anggota::query()->value('id') ?? 1,
            'akun_id' => DB::table('akun')->value('id') ?? 1,
            'akad_pembiayaan' => Pembiayaan::AKAD_MURABAHAH,
            'tujuan_pembiayaan' => 'Uji parameter PSAK (CKPN)',
            'jumlah_pembiayaan' => $sisa,
            'margin_nisbah' => 10,
            'total_ujroh' => $margin,
            'total_pembiayaan' => round($sisa + $margin, 2),
            'jumlah_angsuran' => round(($sisa + $margin) / 12, 2),
            'tenor' => 12,
            'frekuensi_angsuran' => 'Bulanan',
            'sisa_pembiayaan' => round($sisa + $margin, 2),
            'status_pembiayaan' => Pembiayaan::STATUS_AKTIF,
            'status_kolektibilitas' => $kolektibilitas,
            'tanggal_akad' => now()->toDateString(),
            'tanggal_jatuh_tempo' => now()->addMonths(12)->toDateString(),
            'tgl_pencairan' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Angsuran yang sudah melewati jatuh tempo sekian hari, memuat SELURUH
     * sisa pokok pembiayaan (jadwal angsuran penuh belum terbentuk pada fixture
     * ini, sehingga basis CKPN = pokok kontraktual pembiayaan).
     */
    private function angsuranTerlambat(Pembiayaan $pembiayaan, int $hari): Angsuran
    {
        $pokok = (float) $pembiayaan->jumlah_pembiayaan;

        return Angsuran::create([
            'pembiayaan_id' => $pembiayaan->id,
            'angsuran_ke' => 1,
            'tanggal_jatuh_tempo' => Carbon::today()->subDays($hari)->toDateString(),
            'pokok_angsuran' => $pokok,
            'margin_ujroh' => 0,
            'jumlah_angsuran' => $pokok,
            'total_bayar' => $pokok,
            'sisa_pokok' => $pokok,
            'status_angsuran' => 'Terlambat',
        ]);
    }

    /** Baris rincian CKPN milik satu pembiayaan. */
    private function barisCkpn(int $pembiayaanId): ?array
    {
        return collect($this->ckpn()->rincian())->firstWhere('pembiayaan_id', $pembiayaanId);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. SATU SUMBER NILAI — parameter PSAK terdaftar di Setting
     ══════════════════════════════════════════════════════════════════ */

    public function test_nilai_default_psak_terdaftar_pada_satu_sumber(): void
    {
        $harapan = [
            'deposito_rate_pa' => 0.08,
            'nisbah_default_persen' => 60.00,
            'margin_default_persen' => 12.00,
            'aml_threshold' => 100000000.00,
            'pembulatan_desimal' => 0,
            'toleransi_selisih_jurnal' => 0.01,
            // Pola KSP syariah (Kemenkop UKM): Lancar 0–10 hari dengan
            // cadangan 0,5 %, lalu menaik 10 / 50 / 75 / 100 %.
            'kolektibilitas_kol1_hari' => 10,
            'ckpn_persen_kol1' => 0.50,
            'ckpn_persen_kol2' => 10.00,
            'ckpn_persen_kol3' => 50.00,
            'ckpn_persen_kol4' => 75.00,
            'ckpn_persen_kol5' => 100.00,
            'financing_late_fee_per_day' => 0.00,
            // Metode partisipasi SHU — satu-satunya nilai sah 'SRH'.
            'metode_perhitungan_shu' => 'SRH',
        ];

        foreach ($harapan as $key => $nilai) {
            $this->assertArrayHasKey($key, Setting::DEFAULTS, "Parameter {$key} harus terdaftar di Setting::DEFAULTS.");
            $this->assertSame($nilai, Setting::DEFAULTS[$key], "Nilai default {$key} tidak sesuai standar.");
        }
    }

    public function test_persentase_ckpn_default_menaik_seiring_memburuknya_kolektibilitas(): void
    {
        $persen = Setting::persenCkpn();

        $this->assertSame(['kol1', 'kol2', 'kol3', 'kol4', 'kol5'], array_keys($persen));
        $this->assertLessThan($persen['kol2'], $persen['kol1']);
        $this->assertLessThan($persen['kol3'], $persen['kol2']);
        $this->assertLessThan($persen['kol4'], $persen['kol3']);
        $this->assertLessThan($persen['kol5'], $persen['kol4']);
    }

    public function test_kode_kolektibilitas_tak_dikenal_dianggap_lancar(): void
    {
        $this->assertSame(Setting::persenCkpn()['kol1'], Setting::persenCkpnKolektibilitas('entah-apa'));
        $this->assertSame(Setting::persenCkpn()['kol1'], Setting::persenCkpnKolektibilitas(null));
        $this->assertSame(Setting::persenCkpn()['kol3'], Setting::persenCkpnKolektibilitas('KOL3'));
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. PARAMETER BENAR-BENAR MENGUBAH PERILAKU SISTEM
     ══════════════════════════════════════════════════════════════════ */

    public function test_rate_deposito_mempertahankan_presisi_empat_desimal(): void
    {
        // Cacat yang dijaga di sini: pembulatan 2 desimal akan mengubah
        // 6,75% p.a. (0,0675) menjadi 7% tanpa peringatan apa pun.
        $kebijakan = $this->kebijakan();
        $kebijakan->deposito_rate_pa = 0.0675;
        $kebijakan->save();
        Setting::flush();

        $this->assertEqualsWithDelta(0.0675, Setting::depositoRatePa(), 1e-9);
    }

    public function test_pembulatan_nominal_mengikuti_parameter_kebijakan(): void
    {
        $kebijakan = $this->kebijakan();

        // Default: satuan rupiah penuh.
        $this->assertSame(1235.0, Setting::pembulatanNominal(1234.56));

        // Koperasi yang membutuhkan presisi sen cukup mengubah satu parameter.
        $kebijakan->pembulatan_desimal = 2;
        $kebijakan->save();
        Setting::flush();

        $this->assertSame(1234.56, Setting::pembulatanNominal(1234.56));

        // Dan parameter dibatasi pada rentang yang sah (0-4 desimal).
        $kebijakan->pembulatan_desimal = 9;
        $kebijakan->save();
        Setting::flush();

        $this->assertSame(4, Setting::pembulatanDesimal());
    }

    public function test_persentase_ckpn_baru_langsung_mengubah_perhitungan_penyisihan(): void
    {
        $pembiayaan = $this->pembiayaan(10000000.0, 'kol3');

        $awal = $this->barisCkpn($pembiayaan->id);
        $this->assertNotNull($awal, 'Pembiayaan berjalan harus masuk rincian CKPN.');
        $this->assertSame('kol3', $awal['kolektibilitas']);
        // Tarif default mengikuti parameter CKPN terbaru (kol3 = 50 %).
        $this->assertEqualsWithDelta(50.0, $awal['persen'], 1e-9);
        $this->assertEqualsWithDelta(5000000.0, $awal['ckpn'], 0.01);

        // Tarif penyisihan diubah pengurus → perhitungan ikut berubah tanpa deploy.
        $kebijakan = $this->kebijakan();
        $kebijakan->ckpn_persen_kol3 = 20.00;
        $kebijakan->save();
        Setting::flush();

        $baru = $this->barisCkpn($pembiayaan->id);
        $this->assertEqualsWithDelta(20.0, $baru['persen'], 1e-9);
        $this->assertEqualsWithDelta(2000000.0, $baru['ckpn'], 0.01);
    }

    public function test_kolektibilitas_dihitung_dari_tunggakan_nyata_bila_kolom_kosong(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->kolektibilitas_kol2_hari = 30;
        $kebijakan->kolektibilitas_kol3_hari = 90;
        $kebijakan->kolektibilitas_kol4_hari = 180;
        $kebijakan->save();
        Setting::flush();

        $pembiayaan = $this->pembiayaan(1000000.0, null);
        $this->angsuranTerlambat($pembiayaan, 100);

        $this->assertSame(100, $this->ckpn()->hariTunggakan($pembiayaan));
        $this->assertSame('kol4', $this->ckpn()->kolektibilitasKode($pembiayaan));

        $baris = $this->barisCkpn($pembiayaan->id);
        // Kol4 memakai tarif default terbaru (75 %).
        $this->assertEqualsWithDelta(75.0, $baris['persen'], 1e-9);
        $this->assertEqualsWithDelta(750000.0, $baris['ckpn'], 0.01);
    }

    public function test_pembiayaan_tanpa_tunggakan_dianggap_lancar(): void
    {
        $pembiayaan = $this->pembiayaan(2000000.0, null);

        $this->assertSame(0, $this->ckpn()->hariTunggakan($pembiayaan));
        $this->assertSame('kol1', $this->ckpn()->kolektibilitasKode($pembiayaan));

        $baris = $this->barisCkpn($pembiayaan->id);
        $this->assertEqualsWithDelta(0.5, $baris['persen'], 1e-9);
        $this->assertEqualsWithDelta(10000.0, $baris['ckpn'], 0.01);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. JURNAL CKPN — akun kontra-aset 1-2100 & beban 5-4001
     ══════════════════════════════════════════════════════════════════ */

    public function test_penyesuaian_ckpn_membentuk_jurnal_dan_menyamakan_saldo(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->pembulatan_desimal = 2;   // agar saldo target dapat dicapai persis
        $kebijakan->save();
        Setting::flush();

        $pembiayaan = $this->pembiayaan(1000000.0, 'kol5');
        $service = $this->ckpn();

        $this->assertGreaterThan(0, $service->selisih(), 'Penyisihan belum pernah dicatat → harus ada kekurangan.');

        $jurnal = $service->catatPenyesuaian(null, $this->pengurus->id);

        $this->assertNotNull($jurnal, 'Penyesuaian CKPN harus membentuk jurnal.');
        $this->assertSame('Penyesuaian CKPN', $jurnal->sumber);
        $this->assertSame('Posted', $jurnal->status);

        $baris = $jurnal->detail->mapWithKeys(fn ($d) => [
            CoaModel::find($d->coa_id)->kode_akun => $d,
        ]);

        $this->assertTrue($baris->has(Coa::BEBAN_CKPN_PEMBIAYAAN), 'Beban CKPN 5-4001 harus didebet.');
        $this->assertTrue($baris->has(Coa::CKPN_PIUTANG_PEMBIAYAAN), 'Penyisihan 1-2100 harus dikredit.');

        $this->assertGreaterThan(0, (float) $baris[Coa::BEBAN_CKPN_PEMBIAYAAN]->debet);
        $this->assertSame(0.0, (float) $baris[Coa::BEBAN_CKPN_PEMBIAYAAN]->kredit);
        $this->assertGreaterThan(0, (float) $baris[Coa::CKPN_PIUTANG_PEMBIAYAAN]->kredit);
        $this->assertSame(0.0, (float) $baris[Coa::CKPN_PIUTANG_PEMBIAYAAN]->debet);

        // Saldo tercatat kini sama dengan target → penyisihan sudah memadai.
        $this->assertSame(0.0, $service->selisih());
        $this->assertNull(
            $service->catatPenyesuaian(null, $this->pengurus->id),
            'Penyesuaian kedua tidak boleh membuat jurnal baru (sudah sesuai target).'
        );

        $this->assertNotNull($this->barisCkpn($pembiayaan->id));
    }

    public function test_pemulihan_ckpn_dibukukan_terbalik_saat_target_menurun(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->pembulatan_desimal = 2;
        $kebijakan->save();
        Setting::flush();

        $pembiayaan = $this->pembiayaan(1000000.0, 'kol5');
        $service = $this->ckpn();

        $service->catatPenyesuaian(null, $this->pengurus->id);
        $this->assertSame(0.0, $service->selisih());

        // Piutang lunas → penyisihan yang seharusnya menurun (PSAK 71: pemulihan).
        $pembiayaan->update(['sisa_pembiayaan' => 0]);
        $this->assertLessThan(0, $service->selisih());

        $jurnal = $service->catatPenyesuaian(null, $this->pengurus->id);

        $this->assertNotNull($jurnal);
        $this->assertSame('Pemulihan CKPN piutang pembiayaan', $jurnal->keterangan);

        $baris = $jurnal->detail->mapWithKeys(fn ($d) => [
            CoaModel::find($d->coa_id)->kode_akun => $d,
        ]);

        // Posisi dibalik: penyisihan (kontra-aset) didebet, beban dikredit.
        $this->assertGreaterThan(0, (float) $baris[Coa::CKPN_PIUTANG_PEMBIAYAAN]->debet);
        $this->assertGreaterThan(0, (float) $baris[Coa::BEBAN_CKPN_PEMBIAYAAN]->kredit);
        $this->assertSame(0.0, $service->selisih());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  4. PEMBARUAN DARI UI PENGURUS — tanpa menyentuh kode inti
     ══════════════════════════════════════════════════════════════════ */

    public function test_update_kebijakan_menyimpan_seluruh_parameter_psak_baru(): void
    {
        $akunTazir = CoaModel::query()->where('is_active', true)->orderBy('kode_akun')->first();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'deposito_rate_pa' => '0.0675',
                'nisbah_default_persen' => '65',
                'margin_default_persen' => '13.5',
                'aml_threshold' => '250.000.000',
                'pembulatan_desimal' => '2',
                'toleransi_selisih_jurnal' => '0.05',
                'ckpn_persen_kol1' => '1',
                'ckpn_persen_kol2' => '6',
                'ckpn_persen_kol3' => '16',
                'ckpn_persen_kol4' => '51',
                'ckpn_persen_kol5' => '100',
                'akun_tazir_id' => (string) $akunTazir->id,
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertEqualsWithDelta(0.0675, Setting::depositoRatePa(), 1e-9);
        $this->assertSame(65.0, Setting::nisbahDefaultPersen());
        $this->assertSame(13.5, Setting::marginDefaultPersen());
        $this->assertSame(250000000.0, Setting::amlThreshold());
        $this->assertSame(2, Setting::pembulatanDesimal());
        $this->assertSame(0.05, Setting::toleransiSelisihJurnal());
        $this->assertSame(
            ['kol1' => 1.0, 'kol2' => 6.0, 'kol3' => 16.0, 'kol4' => 51.0, 'kol5' => 100.0],
            Setting::persenCkpn()
        );
        $this->assertSame((int) $akunTazir->id, Setting::akunTazirId());
    }

    public function test_update_kebijakan_menolak_persentase_ckpn_yang_tidak_menaik(): void
    {
        $sebelum = Setting::persenCkpn();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'ckpn_persen_kol1' => '10',
                'ckpn_persen_kol2' => '5',   // terbalik
            ]))
            ->assertSessionHasErrors(['ckpn_persen_kol1']);

        Setting::flush();

        // Nilai lama tidak boleh ikut berubah saat validasi gagal.
        $this->assertSame($sebelum, Setting::persenCkpn());
    }

    public function test_update_kebijakan_menolak_rate_deposito_di_luar_rentang(): void
    {
        $sebelum = Setting::depositoRatePa();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'deposito_rate_pa' => '5',   // 500% p.a. — jelas tidak sah
            ]))
            ->assertSessionHasErrors(['deposito_rate_pa']);

        Setting::flush();

        $this->assertSame($sebelum, Setting::depositoRatePa());
    }

    public function test_akun_penampung_tazir_dapat_dikosongkan_kembali(): void
    {
        $akunTazir = CoaModel::query()->where('is_active', true)->orderBy('kode_akun')->first();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'akun_tazir_id' => (string) $akunTazir->id,
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();
        $this->assertSame((int) $akunTazir->id, Setting::akunTazirId());

        // Dikosongkan → kembali mengikuti akun Dana Kebajikan (2-3001).
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'akun_tazir_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();
        $this->assertNull(Setting::akunTazirId());
    }

    public function test_pembaruan_sebagian_tidak_mengubah_parameter_psak_lain(): void
    {
        $sebelum = [
            'rate' => Setting::depositoRatePa(),
            'nisbah' => Setting::nisbahDefaultPersen(),
            'margin' => Setting::marginDefaultPersen(),
            'aml' => Setting::amlThreshold(),
            'pembulatan' => Setting::pembulatanDesimal(),
            'ckpn' => Setting::persenCkpn(),
        ];

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatan([
                'nisbah_default_persen' => '70',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(70.0, Setting::nisbahDefaultPersen());
        $this->assertSame($sebelum['rate'], Setting::depositoRatePa());
        $this->assertSame($sebelum['margin'], Setting::marginDefaultPersen());
        $this->assertSame($sebelum['aml'], Setting::amlThreshold());
        $this->assertSame($sebelum['pembulatan'], Setting::pembulatanDesimal());
        $this->assertSame($sebelum['ckpn'], Setting::persenCkpn());
    }
}
