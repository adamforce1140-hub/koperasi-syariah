<?php

namespace Tests\Feature;

use App\Support\Setting;
use Tests\TestCase;

/**
 * DYN-08 — Form kebijakan harus konsisten dengan metadata `Setting`.
 *
 * Form `pengurus/kebijakan.blade.php` sengaja tetap ditulis per-field agar
 * label & bantuan spesifik tiap parameter tidak hilang. Yang dijaga di sini
 * adalah ikatan metadata-nya:
 *
 *   1. Setiap field di form WAJIB berupa kunci yang dikenal `Setting::TIPE`.
 *   2. Setiap parameter yang boleh diubah Pengurus WAJIB muncul di form —
 *      parameter baru tidak bisa lagi "ada di DB tetapi tidak bisa diubah".
 *   3. Atribut `step` input numerik WAJIB mengikuti `Setting::langkah()`
 *      (presisi metadata), bukan angka manual yang bisa menyimpang.
 */
class KebijakanFormMetadataTest extends TestCase
{
    /**
     * Parameter `Setting::TIPE` yang memang TIDAK diedit dari form kebijakan
     * Pengurus (dikelola modul lain, konfigurasi teknis, atau COA terpusat).
     *
     * @var list<string>
     */
    private const TIDAK_DI_FORM = [
        // Identitas & konfigurasi teknis
        'nama_koperasi',
        'npwp_koperasi',
        'toleransi_persen',
        'posting_wajib_transaksi_sukses',
        'rekonsiliasi_inline_aktif',
        'tutup_buku_sampai',

        // Pelaporan PPATK / LTKT (dikelola modul kepatuhan)
        'ltkt_threshold',
        'ppatk_jenis_tunai',
        'ppatk_nama_pelapor',
        'ppatk_kode_pelapor',

        // Operasional Simpanan Wajib (dikelola layar penanganan khusus)
        'simpanan_wajib_auto_debit_aktif',
        'simpanan_wajib_penanganan_dinamis',
        'simpanan_wajib_maks_cicilan',
        'simpanan_wajib_reschedule_bulan',
        'simpanan_wajib_maks_reschedule',
        'simpanan_wajib_notif_anonim',
        'simpanan_wajib_ambang_kurang_ringan',
        'autodebit_wajib_konfirmasi',

        // Pemetaan COA kanonik (dikelola Master COA / pemetaan akad)
        'account_kas_teller_id',
        'account_kas_brankas_id',
        'account_piutang_murabahah_id',
        'account_piutang_mudharabah_id',
        'account_piutang_musyarakah_id',
        'account_piutang_ijarah_id',
        'account_piutang_qardh_id',
        'account_investasi_mudharabah_id',
        'account_investasi_musyarakah_id',
        'account_margin_deferred_id',
        'account_simpanan_sukarela_id',
        'account_dana_kebajikan_id',
        'account_dana_sosial_id',
        'account_titipan_selisih_id',
    ];

    /**
     * Pengecualian `step` yang disengaja: `tazir_rate` dibulatkan ke ratusan
     * rupiah sebagai keputusan UI, bukan presisi tipe datanya.
     *
     * @var array<string, string>
     */
    private const STEP_KHUSUS = [
        'tazir_rate' => '100',
    ];

    /** @return list<string> */
    private function fieldForm(): array
    {
        $sumber = file_get_contents(resource_path('views/pengurus/kebijakan.blade.php'));

        preg_match_all('/\bname="([a-z0-9_]+)"/', $sumber, $cocok);

        return array_values(array_unique($cocok[1]));
    }

    public function test_setiap_field_form_dikenal_metadata_setting(): void
    {
        $field = $this->fieldForm();

        $this->assertNotEmpty($field, 'Tidak ada field kebijakan yang terbaca dari view.');

        $tidakDikenal = array_values(array_diff($field, array_keys(Setting::TIPE)));

        $this->assertSame(
            [],
            $tidakDikenal,
            'Field form kebijakan tidak terdaftar di Setting::TIPE: '.implode(', ', $tidakDikenal)
        );
    }

    public function test_semua_parameter_editable_muncul_di_form(): void
    {
        $wajib = array_values(array_diff(array_keys(Setting::TIPE), self::TIDAK_DI_FORM));
        $field = $this->fieldForm();

        sort($wajib);
        sort($field);

        $this->assertSame(
            $wajib,
            $field,
            'Ada parameter Setting::TIPE yang belum/tidak lagi tampil di form kebijakan '
            .'(atau field form tidak lagi terdaftar sebagai parameter).'
        );
    }

    public function test_step_input_numerik_mengikuti_metadata(): void
    {
        $sumber = file_get_contents(resource_path('views/pengurus/kebijakan.blade.php'));

        preg_match_all(
            '/<input\b[^>]*?\btype="number"[^>]*?\bname="([a-z0-9_]+)"[^>]*?\bstep="([^"]*)"/s',
            $sumber,
            $cocok,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($cocok, 'Tidak ada input numerik yang terbaca dari view.');

        foreach ($cocok as [$tag, $key, $step]) {
            $harapan = self::STEP_KHUSUS[$key] ?? Setting::langkah($key);

            // Sumber view boleh memakai ekspresi metadata (disarankan) atau
            // literal yang kebetulan sama dengan metadata (pengecualian UI).
            $ekspresi = "{{ \\App\\Support\\Setting::langkah('{$key}') }}";

            $this->assertTrue(
                $step === $ekspresi || $step === $harapan,
                "Atribut step untuk `{$key}` harus mengikuti metadata Setting (harapan {$harapan}, ditemukan {$step})."
            );
        }
    }

    public function test_langkah_dan_presisi_desimal_mengikuti_metadata(): void
    {
        $this->assertSame(4, Setting::presisiDesimal('deposito_rate_pa'));
        $this->assertSame('0.0001', Setting::langkah('deposito_rate_pa'));

        $this->assertSame(2, Setting::presisiDesimal('ckpn_persen_kol1'));
        $this->assertSame('0.01', Setting::langkah('ckpn_persen_kol1'));

        $this->assertSame('1', Setting::langkah('kolektibilitas_kol1_hari'));

        $this->assertSame(2, Setting::presisiDesimal('parameter_tidak_dikenal'));
        $this->assertSame('decimal', Setting::tipe('minimal_pengajuan_pembiayaan'));
    }

    /**
     * Preview ta'zir WAJIB memakai saklar yang sama dengan mesin perhitungan.
     *
     * `TazirService::fiturAktif()` menutup fitur denda bila
     * `financing_late_fee_per_day` = 0 atau mode `DISABLED`. Sebelumnya preview
     * hanya membaca `tazir_rate`, sehingga menampilkan nominal ta'zir padahal
     * fitur NONAKTIF — angka di layar tidak akan pernah menjadi tagihan.
     */
    public function test_preview_tazir_memakai_saklar_financing_late_fee_per_day(): void
    {
        $sumber = file_get_contents(resource_path('views/pengurus/kebijakan.blade.php'));

        $this->assertStringContainsString(
            '[name=financing_late_fee_per_day]',
            $sumber,
            'Preview ta\'zir harus membaca input financing_late_fee_per_day.'
        );

        $this->assertStringContainsString(
            'lateFee <= 0',
            $sumber,
            'Preview ta\'zir harus menonaktifkan diri saat saklar denda bernilai 0.'
        );

        $this->assertStringNotContainsString(
            "getElementById('limit_pengurus')",
            $sumber,
            'Tidak boleh ada lagi rujukan ke #limit_pengurus yang tidak dirender.'
        );
    }
}
