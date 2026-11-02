<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Models\ShuPeriod;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * R4-5 — Penyatuan sumber SHU (temuan audit K-03).
 *
 * Mengunci kontrak SUMBER TUNGGAL alokasi SHU:
 *
 *  1. TINGKAT KEBIJAKAN — `Setting::alokasiShu()` (4 pos, `shu_pct_*`) adalah
 *     satu-satunya pembaca persentase kebijakan. Totalnya harus 100.
 *  2. TINGKAT PERIODE — `Setting::alokasiShuDrafDefault()` adalah SATU-SATUNYA
 *     jembatan 4→5 pos menuju tabel `shu_periods`; total 5 pos harus tepat 100
 *     dan pemetaan pos harus mengikuti aturan tetap.
 *
 * Seluruh pengujian bersifat "tanpa ubah perilaku uang/jurnal": hanya membaca
 * nilai SSOT dan memastikan jembatannya konsisten.
 */
class PenyatuanSumberShuTest extends TestCase
{
    // Konvensi suite ini: memakai DB dev bersama yang sudah ter-seed, bukan
    // `RefreshDatabase` (yang akan menjatuhkan seluruh tabel DB bersama).
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Bersihkan cache agar pembacaan Setting selalu segar dan tidak
        // terpengaruh nilai test lain (DB pengujian bersifat bersama).
        Cache::forget(Setting::CACHE_KEY);
    }

    /** (a) Total 4 pos kebijakan harus tepat 100%. */
    public function test_kebijakan_empat_pos_berjumlah_tepat_100(): void
    {
        $alokasi = Setting::alokasiShu();

        $this->assertSame(
            ['cadangan_modal', 'jasa_anggota', 'jasa_modal', 'dana_pendidikan'],
            array_keys($alokasi),
            'Kunci kebijakan SHU harus 4 pos kanonik dalam urutan tetap.'
        );

        $this->assertSame(100.0, Setting::totalPersenAlokasiShu());
    }

    /** (b) Jembatan 4→5 pos menjumlahkan tepat 100% dan memetakan pos dengan benar. */
    public function test_jembatan_draf_default_berjumlah_tepat_100_dan_memeta_benar(): void
    {
        $kebijakan = Setting::alokasiShu();
        $draf = Setting::alokasiShuDrafDefault();

        $this->assertSame(
            ['persen_cadangan', 'persen_jasa_modal', 'persen_jasa_transaksi', 'persen_pengurus', 'persen_sosial'],
            array_keys($draf),
            'Kunci jembatan draf harus 5 pos `shu_periods` dalam urutan tetap.'
        );

        // Pemetaan pos resmi
        $this->assertSame((float) $kebijakan['cadangan_modal'], $draf['persen_cadangan']);
        $this->assertSame((float) $kebijakan['jasa_modal'], $draf['persen_jasa_modal']);
        $this->assertSame((float) $kebijakan['jasa_anggota'], $draf['persen_jasa_transaksi']);
        $this->assertSame((float) $kebijakan['dana_pendidikan'], $draf['persen_sosial']);

        // Sisa penyeimbang = pengurus, selalu ≥ 0
        $this->assertGreaterThanOrEqual(0.0, $draf['persen_pengurus']);

        // Total kelima pos tepat 100%
        $this->assertSame(100.0, round(array_sum($draf), 2));
    }

    /** (c) Jembatan mengikuti perubahan kebijakan — bukan nilai terpisah. */
    public function test_jembatan_mengikuti_perubahan_kebijakan(): void
    {
        // Ubah kebijakan via cache (tanpa menyentuh DB) lalu pastikan jembatan
        // ikut berubah dan tetap berjumlah 100.
        $settings = Setting::all();
        $settings['shu_pct_cadangan_modal'] = 25.00; // 25
        $settings['shu_pct_jasa_anggota'] = 25.00; // 25
        $settings['shu_pct_jasa_modal'] = 30.00; // 30
        $settings['shu_pct_dana_pendidikan'] = 10.00; // 10 -> sisa 10 pengurus
        Cache::put(Setting::CACHE_KEY, $settings, Setting::CACHE_TTL);

        $draf = Setting::alokasiShuDrafDefault();

        $this->assertSame(25.0, $draf['persen_cadangan']);
        $this->assertSame(30.0, $draf['persen_jasa_modal']);
        $this->assertSame(25.0, $draf['persen_jasa_transaksi']);
        $this->assertSame(10.0, $draf['persen_sosial']);
        $this->assertSame(10.0, $draf['persen_pengurus']);
        $this->assertSame(100.0, round(array_sum($draf), 2));
    }

    /** (d) KebijakanKoperasi hanya mendelegasikan ke Setting (satu jalur). */
    public function test_kebijakankoperasi_hanya_delegasi_ke_setting(): void
    {
        $this->assertSame(Setting::alokasiShu(), KebijakanKoperasi::alokasiShu());
        $this->assertSame(Setting::totalPersenAlokasiShu(), KebijakanKoperasi::totalPersenAlokasiShu());
    }

    /** (e) ShuController::store() memakai nilai default dari Setting (bukan literal). */
    public function test_shu_controller_store_memakai_default_setting(): void
    {
        $sumber = file_get_contents(app_path('Http/Controllers/Pengurus/ShuController.php'));

        $this->assertStringContainsString(
            'Setting::alokasiShuDrafDefault()',
            $sumber,
            'ShuController::store() harus mengambil nilai awal draf dari jembatan SSOT.'
        );
    }

    /** (f) View kebijakan tidak memuat literal persentase ganda (30/40/20/10). */
    public function test_view_kebijakan_tidak_memuat_literal_persen_ganda(): void
    {
        $sumber = file_get_contents(resource_path('views/pengurus/kebijakan.blade.php'));

        $this->assertStringContainsString(
            'Setting::alokasiShu()',
            $sumber,
            'View kebijakan harus mengambil fallback persen dari SSOT Setting::alokasiShu().'
        );

        // Ambil HANYA baris input SHU (yang memakai `shu_pct_*`) lalu pastikan
        // tidak ada fallback literal `?? <angka>` — semua fallback harus
        // memakai $shuKebijakan.
        $barisShu = array_filter(
            preg_split('/\R/', $sumber),
            fn (string $baris): bool => str_contains($baris, 'shu_pct_')
                && str_contains($baris, 'old(')
        );

        $this->assertNotEmpty($barisShu, 'Baris input SHU tidak ditemukan di view kebijakan.');

        foreach ($barisShu as $baris) {
            $this->assertStringContainsString('$shuKebijakan[', $baris);
            $this->assertDoesNotMatchRegularExpression('#\?\?\s*[0-9]#', $baris);
        }
    }

    /** (g) Command uji `test:shu` tidak mendefinisikan ulang persentase secara literal. */
    public function test_command_test_shu_memakai_jembatan_ssot(): void
    {
        $sumber = file_get_contents(app_path('Console/Commands/TestShu.php'));

        $this->assertStringContainsString('Setting::alokasiShuDrafDefault()', $sumber);

        foreach (["'persen_cadangan'        => 30", "'persen_jasa_modal'      => 40", "'persen_jasa_transaksi'  => 20", "'persen_pengurus'        => 10"] as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $sumber,
                'TestShu tidak boleh lagi menulis persentase 5 pos secara literal.'
            );
        }
    }

    /** (h) `shu_periods` menyimpan angka historis — kebijakan hanya nilai awal. */
    public function test_periode_menyimpan_angka_historis_tidak_ditimpa_kebijakan(): void
    {
        $periode = ShuPeriod::create([
            'tahun_buku' => '2099',
            'total_shu_bersih' => 100_000_000,
            'persen_cadangan' => 33.00,
            'persen_jasa_modal' => 22.00,
            'persen_jasa_transaksi' => 22.00,
            'persen_pengurus' => 11.00,
            'persen_sosial' => 12.00,
            'nominal_cadangan' => 33_000_000,
            'nominal_jasa_modal' => 22_000_000,
            'nominal_jasa_transaksi' => 22_000_000,
            'nominal_pengurus' => 11_000_000,
            'nominal_sosial' => 12_000_000,
            'status' => 'Draft',
        ]);

        // Ubah kebijakan — angka periode yang sudah tersimpan TIDAK berubah.
        $settings = Setting::all();
        $settings['shu_pct_cadangan_modal'] = 5.00;
        Cache::put(Setting::CACHE_KEY, $settings, Setting::CACHE_TTL);

        $this->assertSame('33.00', (string) $periode->fresh()->persen_cadangan);
        $this->assertSame(5.0, Setting::alokasiShu()['cadangan_modal']);
    }
}
