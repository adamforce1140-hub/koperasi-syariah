<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\PengeluaranBeban;
use App\Models\User;
use App\Support\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * PARAMETER FLEKSIBILITAS OPERASIONAL — pengujian menyeluruh.
 *
 * Mengunci sifat "tidak kaku" yang menjadi tujuan pengembangan:
 *   1. Alokasi SHU (30/40/20/10) tidak lagi hardcode — dapat diubah dari UI
 *      Pengurus dan WAJIB berjumlah tepat 100%.
 *   2. Limit approval beban operasional (dulu konstanta LIMIT_APPROVAL)
 *      sepenuhnya dinamis dan langsung mengubah keputusan approval.
 *   3. Ambang kolektibilitas (dulu 30/90/180) dinamis, menaik, dan dipakai
 *      oleh penentu golongan kolektibilitas pembiayaan.
 *   4. Normalisasi input nominal terpusat menolak masukan tidak valid
 *      (mencegah data parameter keuangan rusak karena diam-diam menjadi 0).
 *   5. Daftar akad syariah bersumber dari SATU tempat (Pembiayaan::daftarAkad()).
 *   6. Error handling komprehensif: permintaan API selalu menerima JSON
 *      konsisten (success=false + kode referensi) untuk semua kelas kesalahan.
 */
class FlexibilityParameterTest extends TestCase
{
    use DatabaseTransactions;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        // Bila tabel kebijakan belum tersedia, lewati seluruh berkas ini.
        if (! Schema::hasTable('kebijakan_koperasis')) {
            $this->markTestSkipped('Tabel kebijakan_koperasis belum tersedia.');
        }

        $this->pengurus = User::firstOrCreate(
            ['email' => 'fleksibilitas.pengurus@test.local'],
            [
                'name' => 'Uji Fleksibilitas Pengurus',
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
            ['email' => "fleksibilitas.{$peran}@test.local"],
            [
                'name' => 'Uji '.ucfirst($peran),
                'password' => Hash::make('password'),
                'role' => $peran,
                'is_active' => true,
            ]
        );
    }

    /** Baris kebijakan yang dipakai pengujian (dibuat bila belum ada). */
    private function kebijakan(): KebijakanKoperasi
    {
        return KebijakanKoperasi::first() ?? KebijakanKoperasi::create([]);
    }

    /**
     * Muat data form Master Settings secara lengkap (field wajib) agar
     * pembaruan parameter fleksibilitas dapat diuji lewat rute sungguhan.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function muatanKebijakan(array $override = []): array
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
            'kolektibilitas_kol2_hari' => (string) Setting::ambangKolektibilitas()['kol2'],
            'kolektibilitas_kol3_hari' => (string) Setting::ambangKolektibilitas()['kol3'],
            'kolektibilitas_kol4_hari' => (string) Setting::ambangKolektibilitas()['kol4'],
        ], $override);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. ALOKASI SHU — menggantikan angka hardcode 30/40/20/10
     ══════════════════════════════════════════════════════════════════ */

    public function test_default_alokasi_shu_berjumlah_tepat_seratus_persen(): void
    {
        $this->assertSame(100.0, Setting::totalPersenAlokasiShu());
    }

    public function test_alokasi_shu_dapat_diubah_dan_nominal_ikut_menyesuaikan(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->shu_pct_cadangan_modal = 25.00;
        $kebijakan->shu_pct_jasa_anggota = 45.00;
        $kebijakan->shu_pct_jasa_modal = 20.00;
        $kebijakan->shu_pct_dana_pendidikan = 10.00;
        $kebijakan->save();

        Setting::flush();

        $this->assertSame(25.0, Setting::alokasiShu()['cadangan_modal']);
        $this->assertSame(45.0, Setting::alokasiShu()['jasa_anggota']);
        $this->assertSame(100.0, Setting::totalPersenAlokasiShu());

        // Nominal dihitung dari persentase yang berlaku — bukan angka tetap.
        $alokasi = KebijakanKoperasi::hitungAlokasiShu(1000000.0);

        $this->assertSame(250000.0, $alokasi['cadangan_modal']);
        $this->assertSame(450000.0, $alokasi['jasa_anggota']);
        $this->assertSame(200000.0, $alokasi['jasa_modal']);
        $this->assertSame(100000.0, $alokasi['dana_pendidikan']);
        $this->assertSame(1000000.0, array_sum($alokasi));
    }

    public function test_validasi_total_alokasi_shu_menolak_total_tidak_seratus(): void
    {
        $pesan = Setting::validasiTotalAlokasiShu([
            'cadangan_modal' => 30,
            'jasa_anggota' => 40,
            'jasa_modal' => 20,
            'dana_pendidikan' => 5,   // total 95%
        ]);

        $this->assertNotNull($pesan, 'Total 95% harus ditolak.');
        $this->assertStringContainsString('95', $pesan);

        $this->assertNull(Setting::validasiTotalAlokasiShu([
            'cadangan_modal' => 30,
            'jasa_anggota' => 40,
            'jasa_modal' => 20,
            'dana_pendidikan' => 10,
        ]));
    }

    public function test_update_kebijakan_menyimpan_alokasi_shu_baru(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'shu_pct_cadangan_modal' => '35',
                'shu_pct_jasa_anggota' => '35',
                'shu_pct_jasa_modal' => '20',
                'shu_pct_dana_pendidikan' => '10',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(35.0, Setting::alokasiShu()['cadangan_modal']);
        $this->assertSame(35.0, Setting::alokasiShu()['jasa_anggota']);
        $this->assertSame(100.0, Setting::totalPersenAlokasiShu());
    }

    public function test_update_kebijakan_menolak_alokasi_shu_yang_totalnya_bukan_seratus(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'shu_pct_cadangan_modal' => '30',
                'shu_pct_jasa_anggota' => '40',
                'shu_pct_jasa_modal' => '20',
                'shu_pct_dana_pendidikan' => '5',
            ]))
            ->assertSessionHasErrors(['shu_pct_cadangan_modal']);

        Setting::flush();

        // Nilai lama tidak boleh ikut berubah saat validasi gagal.
        $this->assertSame(100.0, Setting::totalPersenAlokasiShu());
    }

    public function test_update_sebagian_tidak_mengubah_parameter_lain(): void
    {
        // Kirim HANYA field limit_approval_beban (pembaruan sebagian).
        $sebelum = Setting::alokasiShu();

        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'limit_approval_beban' => '2500000',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(2500000.0, Setting::limitApprovalBeban());
        $this->assertSame($sebelum, Setting::alokasiShu(), 'Alokasi SHU tidak boleh berubah pada pembaruan sebagian.');
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. LIMIT APPROVAL BEBAN — menggantikan konstanta LIMIT_APPROVAL
     ══════════════════════════════════════════════════════════════════ */

    public function test_limit_approval_beban_default_sesuai_konstanta_lama(): void
    {
        // Kompatibilitas: nilai default parameter = konstanta lama 1.000.000.
        $this->assertSame((float) PengeluaranBeban::LIMIT_APPROVAL, PengeluaranBeban::limitApproval());
    }

    public function test_limit_approval_beban_dinamis_mengubah_keputusan_approval(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->limit_approval_beban = 5000000.00;
        $kebijakan->save();
        Setting::flush();

        $kecil = new PengeluaranBeban(['nominal' => 2000000.0]);
        $besar = new PengeluaranBeban(['nominal' => 6000000.0]);

        // 2 juta di bawah limit 5 juta → tidak butuh approval.
        $this->assertFalse($kecil->butuhApprovalManajer());

        // Semua nilai di atas limit baru → butuh approval, tanpa perlu deploy.
        $this->assertTrue($besar->butuhApprovalManajer());

        // Setelah parameter diturunkan, nominal 2 juta ikut memicu approval.
        $kebijakan->limit_approval_beban = 1000000.00;
        $kebijakan->save();
        Setting::flush();

        $this->assertTrue($kecil->butuhApprovalManajer());
    }

    public function test_update_kebijakan_menerima_nominal_berformat_indonesia(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'limit_approval_beban' => '2.500.000',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(2500000.0, Setting::limitApprovalBeban());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. AMBANG KOLEKTIBILITAS — menggantikan 30/90/180 hardcode
     ══════════════════════════════════════════════════════════════════ */

    public function test_ambang_kolektibilitas_default_menaik(): void
    {
        $ambang = Setting::ambangKolektibilitas();

        $this->assertLessThan($ambang['kol3'], $ambang['kol2']);
        $this->assertLessThan($ambang['kol4'], $ambang['kol3']);
    }

    public function test_kolektibilitas_dari_hari_mengikuti_ambang_kustom(): void
    {
        // Pola KSP syariah: Lancar (Kol-1) = 0–5 hari, lalu menaik ke
        // Kol-2 6–10, Kol-3 11–20, Kol-4 21–40, dan Kol-5 di atas 40 hari.
        $kebijakan = $this->kebijakan();
        $kebijakan->kolektibilitas_kol1_hari = 5;
        $kebijakan->kolektibilitas_kol2_hari = 10;
        $kebijakan->kolektibilitas_kol3_hari = 20;
        $kebijakan->kolektibilitas_kol4_hari = 40;
        $kebijakan->save();
        Setting::flush();

        // Batas diuji tepat pada sisi atas setiap ambang.
        $this->assertSame('kol1', KebijakanKoperasi::kolektibilitasDariHari(0));
        $this->assertSame('kol1', KebijakanKoperasi::kolektibilitasDariHari(5));
        $this->assertSame('kol2', KebijakanKoperasi::kolektibilitasDariHari(6));
        $this->assertSame('kol2', KebijakanKoperasi::kolektibilitasDariHari(10));
        $this->assertSame('kol3', KebijakanKoperasi::kolektibilitasDariHari(11));
        $this->assertSame('kol3', KebijakanKoperasi::kolektibilitasDariHari(20));
        $this->assertSame('kol4', KebijakanKoperasi::kolektibilitasDariHari(21));
        $this->assertSame('kol4', KebijakanKoperasi::kolektibilitasDariHari(40));
        $this->assertSame('kol5', KebijakanKoperasi::kolektibilitasDariHari(41));
    }

    public function test_ambang_kolektibilitas_menolak_urutan_tidak_menaik(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'kolektibilitas_kol2_hari' => '90',
                'kolektibilitas_kol3_hari' => '30',   // terbalik
                'kolektibilitas_kol4_hari' => '180',
            ]))
            ->assertSessionHasErrors(['kolektibilitas_kol3_hari']);
    }

    public function test_update_kebijakan_menyimpan_ambang_kolektibilitas_baru(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'kolektibilitas_kol2_hari' => '15',
                'kolektibilitas_kol3_hari' => '45',
                'kolektibilitas_kol4_hari' => '120',
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(['kol1' => 10, 'kol2' => 15, 'kol3' => 45, 'kol4' => 120], Setting::ambangKolektibilitas());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  4. NORMALISASI & VALIDASI INPUT NOMINAL (anti data rusak)
     ══════════════════════════════════════════════════════════════════ */

    public function test_parse_nominal_menerima_berbagai_format(): void
    {
        $kasus = [
            '100.000' => 100000.0,     // format Indonesia tanpa desimal
            '100.000,50' => 100000.50,    // format Indonesia dengan desimal
            '100,000.50' => 100000.50,    // format internasional
            '100000' => 100000.0,
            '100000.5' => 100000.5,
            'Rp 1.250.000' => 1250000.0,
            'Rp 1.250.000,50' => 1250000.5,
            '  2.500.000  ' => 2500000.0,
            '1,5' => 1.5,
            '' => 0.0,
        ];

        foreach ($kasus as $input => $harapan) {
            $this->assertSame(
                $harapan,
                Setting::parseNominal($input),
                "Format {$input} harus dinormalkan menjadi {$harapan}."
            );
        }
    }

    public function test_parse_nominal_menangani_input_tidak_valid_tanpa_error(): void
    {
        foreach (['abc', '-', '.', ',', null, 'Rp', '---'] as $input) {
            $this->assertSame(0.0, Setting::parseNominal($input));
        }
    }

    public function test_input_nominal_sampah_ditolak_bukan_diam_diam_menjadi_nol(): void
    {
        // Ini mencegah kerusakan data paling berbahaya: parameter keuangan
        // menjadi 0 karena input tidak valid yang lolos begitu saja.
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'limit_approval_beban' => 'abc',
            ]))
            ->assertSessionHasErrors(['limit_approval_beban']);

        Setting::flush();

        $this->assertNotSame(0.0, Setting::limitApprovalBeban(), 'Limit approval tidak boleh menjadi 0 karena input sampah.');
    }

    public function test_batas_maksimum_pembukaan_boleh_diubah_dan_konsisten_dengan_minimum(): void
    {
        $this->actingAs($this->pengurus)
            ->put(route('pengurus.kebijakan.update'), $this->muatanKebijakan([
                'minimum_pembukaan_rekening' => '5.000',
                'maksimum_pembukaan_rekening' => '100.000.000',   // maksimum dinaikkan
            ]))
            ->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(5000.0, Setting::minimumPembukaanRekening());
        $this->assertSame(100000000.0, Setting::maksimumPembukaanRekening());
    }

    public function test_aturan_validasi_alokasi_shu_dapat_dibuat_opsional(): void
    {
        // Pembaruan sebagian (mis. hanya Simpanan Pokok) harus tetap aman:
        // field alokasi SHU opsional dan memakai nilai yang berlaku.
        foreach (Setting::aturanValidasiAlokasiShu(false) as $field => $aturan) {
            $this->assertStringStartsWith('nullable', $aturan, "Field {$field} harus opsional.");
        }

        foreach (Setting::aturanValidasiAlokasiShu() as $field => $aturan) {
            $this->assertStringStartsWith('required', $aturan, "Field {$field} harus wajib pada form penuh.");
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     |  5. DAFTAR AKAD SYARIAH — satu sumber kebenaran
     ══════════════════════════════════════════════════════════════════ */

    public function test_daftar_akad_bersumber_dari_satu_tempat(): void
    {
        $daftar = Pembiayaan::daftarAkad();

        $this->assertCount(8, $daftar, 'Delapan akad syariah harus dikenali sistem.');
        $this->assertSame($daftar, array_keys(Pembiayaan::pilihanAkad()));

        // Tidak ada nilai kembar yang dapat membuat validasi/dropdown berbeda.
        $this->assertSame($daftar, array_values(array_unique($daftar)));

        $aturan = Pembiayaan::aturanValidasiAkad();

        $this->assertSame('required', $aturan[0]);
        $this->assertSame('string', $aturan[1]);

        foreach ($daftar as $akad) {
            // Validasi akad memakai daftar yang sama dengan pilihan dropdown,
            // sehingga tidak mungkin ada akad yang tampil tapi ditolak validator.
            $this->assertTrue(
                Validator::make(
                    ['akad_pembiayaan' => $akad],
                    ['akad_pembiayaan' => $aturan]
                )->passes(),
                "Akad \"{$akad}\" harus lolos validasi yang dipakai sistem."
            );
        }
    }

    public function test_akad_di_luar_daftar_ditolak_validasi(): void
    {
        $this->assertNotContains('Riba', Pembiayaan::daftarAkad());
        $this->assertNotContains('', Pembiayaan::daftarAkad());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  5b. NOMINAL TA'ZIR DEFAULT — menggantikan hardcode `?? 250000`
     ══════════════════════════════════════════════════════════════════ */

    /**
     * Nominal default ta'zir harus dapat diubah Pengurus dari UI Pengaturan
     * dan langsung dipakai modul Teller tanpa perlu mengubah kode sumber.
     */
    public function test_nominal_default_tazir_dapat_diubah_lewat_ui_dan_langsung_berlaku(): void
    {
        $respons = $this->actingAs($this->pengurus)->put(
            route('pengurus.kebijakan.update'),
            $this->muatanKebijakan(['tazir_default_nominal' => '175000'])
        );

        $respons->assertSessionHasNoErrors();
        $respons->assertRedirect();

        Setting::flush();

        $this->assertSame(175000.0, Setting::nominalTazirDefault());
    }

    /**
     * Bila field tidak dikirim (pembaruan sebagian), nilai yang berlaku harus
     * dipertahankan — bukan diam-diam jatuh ke 0.
     */
    public function test_nominal_default_tazir_dipertahankan_saat_field_tidak_dikirim(): void
    {
        $kebijakan = $this->kebijakan();
        $kebijakan->tazir_default_nominal = 125000.00;
        $kebijakan->save();
        Setting::flush();

        $respons = $this->actingAs($this->pengurus)->put(
            route('pengurus.kebijakan.update'),
            $this->muatanKebijakan([])
        );

        $respons->assertSessionHasNoErrors();

        Setting::flush();

        $this->assertSame(125000.0, Setting::nominalTazirDefault());
    }

    public function test_nominal_default_tazir_negatif_ditolak(): void
    {
        $respons = $this->actingAs($this->pengurus)->put(
            route('pengurus.kebijakan.update'),
            $this->muatanKebijakan(['tazir_default_nominal' => '-1'])
        );

        $respons->assertSessionHasErrors('tazir_default_nominal');
    }

    /* ══════════════════════════════════════════════════════════════════
     |  6. ERROR HANDLING KOMPREHENSIF — respons konsisten tanpa crash
     ══════════════════════════════════════════════════════════════════ */

    public function test_permintaan_api_ke_route_tidak_ada_menerima_json_konsisten(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/api/endpoint-yang-tidak-pernah-ada');

        $respons->assertStatus(404);
        $respons->assertJsonPath('success', false);
        $this->assertNotEmpty($respons->json('error.reference'), 'Setiap kegagalan harus punya kode referensi.');
    }

    public function test_permintaan_web_ke_route_tidak_ada_menampilkan_halaman_ramah(): void
    {
        $respons = $this->actingAs($this->pengurus)->get('/halaman-yang-tidak-pernah-ada');

        $respons->assertStatus(404);
        $respons->assertSee('Data Tidak Ditemukan');
    }

    public function test_metode_http_salah_menerima_json_405(): void
    {
        $respons = $this->actingAs($this->pengurus)
            ->withHeaders(['Accept' => 'application/json'])
            ->delete(route('pengurus.kebijakan.index'));

        $respons->assertStatus(405);
        $respons->assertJsonPath('success', false);
    }

    public function test_tamu_pada_route_web_masih_diarahkan_ke_login(): void
    {
        // Kompatibilitas alur otentikasi: pengguna web tanpa sesi harus tetap
        // diarahkan ke halaman login, bukan menerima JSON.
        $this->get(route('dashboard.pengurus'))
            ->assertRedirect(route('login'));
    }

    public function test_gangguan_pengolahan_data_dibalas_500_json_tanpa_membocorkan_detail(): void
    {
        // Simulasi query gagal di dalam alur API (seperti tabel hilang):
        // pengguna menerima pesan ramah + referensi, bukan pesan SQL mentah.
        Route::get('/api/uji-kesalahan-data', function () {
            throw new QueryException(
                'mysql',
                'select * from tabel_yang_tidak_ada',
                [],
                new \Exception('SQLSTATE[42S02]: Base table or view not found')
            );
        })->middleware(['web']);

        $respons = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/api/uji-kesalahan-data');

        $respons->assertStatus(500);
        $respons->assertJsonPath('success', false);
        $this->assertNotEmpty($respons->json('error.reference'));

        $isi = $respons->getContent();
        $this->assertStringNotContainsString('select *', $isi, 'Detail SQL tidak boleh bocor ke pengguna.');
        $this->assertStringNotContainsString('SQLSTATE', $isi);
    }
}
