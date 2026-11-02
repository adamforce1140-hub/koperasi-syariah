<?php

namespace Tests\Feature;

use App\Models\Pembiayaan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * DYN-09 — AKAD DEFAULT DARI KONFIGURASI (kunci regresi).
 *
 * Mengunci tiga sifat:
 *   1. `Pembiayaan::akadDefault()` selalu menghasilkan akad yang dikenal
 *      sistem (`Pembiayaan::daftarAkad()`), termasuk saat config diubah.
 *   2. Config yang tidak valid tidak boleh menghasilkan akad tak dikenal;
 *      sistem jatuh ke akad kanonik pertama (Murabahah).
 *   3. Tidak ada lagi fallback literal `?? 'Murabahah'` di kode aplikasi
 *      maupun Blade, agar kebijakan akad default tetap satu sumber.
 */
class AkadDefaultDinamisTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_config_menghasilkan_murabahah(): void
    {
        config(['pembiayaan.akad_default' => null]);

        $this->assertSame(Pembiayaan::AKAD_MURABAHAH, Pembiayaan::akadDefault());
    }

    public function test_akad_default_selalu_merupakan_akad_yang_dikenal(): void
    {
        $this->assertContains(Pembiayaan::akadDefault(), Pembiayaan::daftarAkad());
    }

    public function test_config_valid_dipakai_sebagai_default(): void
    {
        config(['pembiayaan.akad_default' => Pembiayaan::AKAD_MUDHARABAH]);

        $this->assertSame(Pembiayaan::AKAD_MUDHARABAH, Pembiayaan::akadDefault());
    }

    public function test_config_tidak_dikenal_jatuh_ke_murabahah(): void
    {
        config(['pembiayaan.akad_default' => 'Akad Karangan']);

        $this->assertSame(Pembiayaan::AKAD_MURABAHAH, Pembiayaan::akadDefault());
    }

    public function test_tidak_ada_fallback_literal_murabahah_di_kode(): void
    {
        $pola = [
            "/\\?\\?\\s*'Murabahah'/",
            '/\\?\\?\\s*"Murabahah"/',
            "/\\?:\\s*'Murabahah'/",
            "/input\\(\\s*'akad'\\s*,\\s*'Murabahah'\\s*\\)/",
        ];

        $pelanggaran = [];

        foreach ($this->berkasSumber() as $berkas) {
            $isi = File::get($berkas);

            foreach ($pola as $regex) {
                if (preg_match($regex, $isi)) {
                    $pelanggaran[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $berkas);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            'Fallback akad literal masih ada; gunakan Pembiayaan::akadDefault().'
        );
    }

    /**
     * @return list<string>
     */
    private function berkasSumber(): array
    {
        $berkas = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php') {
                $berkas[] = $file->getPathname();
            }
        }

        foreach (File::allFiles(resource_path('views')) as $file) {
            if ($file->getExtension() === 'php') {
                $berkas[] = $file->getPathname();
            }
        }

        return $berkas;
    }
}
