<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BENTROK `id` — SATU `id` HANYA BOLEH MUNCUL SEKALI PER HALAMAN.
 *
 * Pemindai ini hanya membaca `id` statis (tanpa `{{ }}`) dan hanya menghitung
 * duplikasi dalam satu berkas view. Duplikasi di dalam cabang `@if` yang
 * saling eksklusif dicatat sebagai pengecualian yang disebutkan namanya.
 */
class IdUnikFormTest extends TestCase
{
    /**
     * Pengecualian: kedua elemen berada di cabang `@if` yang saling eksklusif
     * berdasarkan peran pengguna tunggal (`User::berperan`), sehingga tidak
     * pernah tampil bersamaan.
     *
     * @var array<string, list<string>>
     */
    private const CABANG_EKSKLUSIF = [
        'pengajuan-pembiayaan/show.blade.php' => ['catatan_manajer', 'catatan_pengurus'],
    ];

    public function test_id_statis_tidak_ganda_dalam_satu_berkas_view(): void
    {
        $temuan = [];

        foreach (File::allFiles(resource_path('views')) as $berkas) {
            if (! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $relatif = str_replace('\\', '/', $berkas->getRelativePathname());
            preg_match_all('/\sid="([^"{}]*)"/', $berkas->getContents(), $cocok);

            foreach (array_count_values($cocok[1]) as $id => $jumlah) {
                if ($jumlah < 2) {
                    continue;
                }
                if (in_array($id, self::CABANG_EKSKLUSIF[$relatif] ?? [], true)) {
                    continue;
                }
                $temuan[] = "{$relatif}: id=\"{$id}\" muncul {$jumlah} kali";
            }
        }

        $this->assertSame([], $temuan, 'Ditemukan id ganda dalam satu berkas view:'."\n".implode("\n", $temuan));
    }

    public function test_pengecualian_cabang_eksklusif_masih_relevan(): void
    {
        foreach (self::CABANG_EKSKLUSIF as $berkas => $ids) {
            $isi = File::get(resource_path('views/'.$berkas));

            foreach ($ids as $id) {
                $this->assertSame(
                    2,
                    substr_count($isi, 'id="'.$id.'"'),
                    "Pengecualian id=\"{$id}\" di {$berkas} tidak lagi berpola dua kemunculan; tinjau ulang daftar pengecualian."
                );
            }
        }
    }
}
