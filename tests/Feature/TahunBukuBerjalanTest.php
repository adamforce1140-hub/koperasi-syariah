<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Services\TahunBukuService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `TahunBukuService::tahunBerjalan()` — tahun buku, bukan tahun takwim.
 *
 * Penahanan SHU (HOLD) adalah perhitungan per periode tahun buku. Memakai
 * `now()->year` membuat koperasi bertahun buku non-kalender (mis. Juli–Juni)
 * menghitung penahanan pada periode yang salah: Januari 2026 seharusnya masih
 * tahun buku 2025. Test ini mengunci perilaku tersebut.
 */
class TahunBukuBerjalanTest extends TestCase
{
    use DatabaseTransactions;

    private function aturTahunBuku(int $bulanMulai): void
    {
        $kebijakan = KebijakanKoperasi::getSetting();
        $kebijakan->tahun_buku_mulai_bulan = $bulanMulai;
        $kebijakan->save();

        Setting::flush();
    }

    public function test_tahun_berjalan_sama_dengan_tahun_takwim_pada_kebijakan_kalender(): void
    {
        $this->aturTahunBuku(1);

        $service = app(TahunBukuService::class);

        $this->assertTrue($service->kalender());
        $this->assertSame((int) now()->year, $service->tahunBerjalan());
    }

    public function test_tahun_berjalan_mengikuti_tahun_buku_non_kalender(): void
    {
        $this->aturTahunBuku(7);

        $service = app(TahunBukuService::class);
        $harapan = now()->month >= 7 ? (int) now()->year : (int) now()->year - 1;

        $this->assertFalse($service->kalender());
        $this->assertSame($harapan, $service->tahunBerjalan());
        $this->assertSame($service->tahunBukuDari(now()), $service->tahunBerjalan());
    }

    public function test_perhitungan_shu_tidak_lagi_memakai_now_year(): void
    {
        // Pemindaian baris yang benar-benar menetapkan periode penahanan SHU —
        // bukan seluruh berkas, karena `now()->year` masih sah sebagai nilai
        // awal filter tanggal di form (bukan periode akuntansi).
        $titik = [
            app_path('Http/Controllers/Dashboard/PengurusController.php') => '$tahunBukuBerjalan',
            app_path('Http/Controllers/Pengurus/ShuController.php') => '$tahunBerjalan',
        ];

        foreach ($titik as $berkas => $variabel) {
            $baris = collect(file($berkas))
                ->map(fn ($l) => rtrim($l, "\r\n"))
                ->search(fn ($l) => str_starts_with(trim($l), $variabel.' ='));

            $this->assertNotFalse($baris, basename($berkas).' tidak menetapkan '.$variabel.'.');

            $penetapan = trim(file($berkas)[$baris]);

            $this->assertStringNotContainsString(
                'now()->year',
                $penetapan,
                basename($berkas).' masih memakai tahun takwim sebagai periode penahanan SHU.'
            );
            $this->assertStringContainsString(
                'tahunBerjalan()',
                $penetapan,
                basename($berkas).' harus memakai TahunBukuService::tahunBerjalan() untuk penahanan SHU.'
            );
        }
    }
}
