<?php

namespace Tests\Feature;

use App\Models\KebijakanKoperasi;
use App\Support\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kontrak parameter kebijakan: kunci `Setting::TIPE` yang berkolom di DB
 * harus konsisten dengan `$fillable`, `KOLOM_AUDIT`, dan `LABEL_KOLOM`
 * pada model `KebijakanKoperasi`.
 */
class KebijakanKontrakParameterTest extends TestCase
{
    use DatabaseTransactions;

    /** @return list<string> Kunci TIPE yang memiliki kolom fisik di tabel. */
    private function kunciBerkolom(): array
    {
        return array_values(array_filter(
            array_keys(Setting::TIPE),
            fn (string $kunci): bool => Schema::hasColumn('kebijakan_koperasis', $kunci),
        ));
    }

    public function test_fillable_tidak_memuat_duplikat(): void
    {
        $fillable = (new KebijakanKoperasi)->getFillable();

        $this->assertSame(
            array_values(array_unique($fillable)),
            $fillable,
            'Ada kunci ganda pada $fillable KebijakanKoperasi.'
        );
    }

    public function test_fillable_hanya_berisi_kunci_yang_dikenal_setting(): void
    {
        $asing = array_values(array_diff(
            (new KebijakanKoperasi)->getFillable(),
            array_keys(Setting::TIPE),
        ));

        $this->assertSame([], $asing, 'Kunci $fillable tidak terdaftar di Setting::TIPE: '.implode(', ', $asing));
    }

    public function test_setiap_kolom_db_yang_dikenal_setting_bisa_di_mass_assign(): void
    {
        $tanpaFillable = array_values(array_diff(
            $this->kunciBerkolom(),
            (new KebijakanKoperasi)->getFillable(),
        ));

        $this->assertSame(
            [],
            $tanpaFillable,
            'Kolom kebijakan di DB tidak ada di $fillable: '.implode(', ', $tanpaFillable)
        );
    }

    public function test_setiap_kolom_db_yang_dikenal_setting_diaudit_dan_berlabel(): void
    {
        $kolom = $this->kunciBerkolom();

        $tanpaAudit = array_values(array_diff($kolom, KebijakanKoperasi::KOLOM_AUDIT));
        $tanpaLabel = array_values(array_diff($kolom, array_keys(KebijakanKoperasi::LABEL_KOLOM)));

        $this->assertSame([], $tanpaAudit, 'Kolom tidak ada di KOLOM_AUDIT: '.implode(', ', $tanpaAudit));
        $this->assertSame([], $tanpaLabel, 'Kolom tidak ada di LABEL_KOLOM: '.implode(', ', $tanpaLabel));
    }

    public function test_fallback_tanpa_baris_membawa_default_setiap_kolom_db(): void
    {
        KebijakanKoperasi::query()->delete();

        $fallback = KebijakanKoperasi::getSetting();

        $hilang = [];
        foreach ($this->kunciBerkolom() as $kunci) {
            if (Setting::DEFAULTS[$kunci] !== null && $fallback->getAttribute($kunci) === null) {
                $hilang[] = $kunci;
            }
        }

        $this->assertSame(
            [],
            $hilang,
            'Fallback getSetting() kehilangan nilai default untuk kolom: '.implode(', ', $hilang)
        );
    }
}
