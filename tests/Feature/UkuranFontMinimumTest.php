<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * S-3 — UKURAN FONT TIDAK BOLEH DI BAWAH LANTAI TERBACA.
 *
 * Teks di bawah 0.7rem (sekitar 11px) sulit dibaca pada layar biasa dan
 * tidak memenuhi kenyamanan baca minimum. Pemeriksaan ini memindai seluruh
 * berkas view untuk nilai `font-size` (inline maupun di blok <style>) dan
 * menandai nilai yang lebih kecil dari lantai.
 */
class UkuranFontMinimumTest extends TestCase
{
    private const LANTAI_REM = 0.7;

    /** @return list<string> */
    private function berkasView(): array
    {
        $akar = base_path('resources/views');
        $hasil = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($akar, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $berkas) {
            if ($berkas->isFile() && str_ends_with($berkas->getFilename(), '.blade.php')) {
                $hasil[] = str_replace('\\', '/', substr($berkas->getPathname(), strlen(base_path()) + 1));
            }
        }

        sort($hasil);

        return $hasil;
    }

    /** @return list<string> */
    private function fontTerlaluKecil(string $isi): array
    {
        preg_match_all('/font-size\s*:\s*(\d*\.?\d+)rem/i', $isi, $semua, PREG_OFFSET_CAPTURE);

        $temuan = [];

        foreach ($semua[1] as $i => [$nilai, $offset]) {
            if ((float) $nilai < self::LANTAI_REM) {
                $baris = substr_count(substr($isi, 0, $offset), "\n") + 1;
                $temuan[] = "{$nilai}rem (baris {$baris})";
            }
        }

        return $temuan;
    }

    public function test_tidak_ada_font_di_bawah_lantai_terbaca(): void
    {
        $bermasalah = [];

        foreach ($this->berkasView() as $berkas) {
            $temuan = $this->fontTerlaluKecil(file_get_contents(base_path($berkas)));

            if ($temuan !== []) {
                $bermasalah[$berkas] = $temuan;
            }
        }

        $this->assertSame(
            [],
            $bermasalah,
            'Ukuran font di bawah '.self::LANTAI_REM."rem ditemukan:\n".json_encode(
                $bermasalah,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }
}
