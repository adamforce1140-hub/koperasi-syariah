<?php

namespace App\Support;

/**
 * Pembaca SSOT izin rute (`config/izin.php`) — DYN-18.
 *
 * Middleware `CekIzin` dan pengujian invariant navigasi membaca peta yang sama
 * dari kelas ini, sehingga tidak ada daftar peran kedua yang bisa menyimpang.
 */
final class Izin
{
    /** @return array<string, list<string>> */
    public static function peta(): array
    {
        return (array) config('izin.izin', []);
    }

    public static function ada(string $nama): bool
    {
        return array_key_exists($nama, self::peta());
    }

    /**
     * Peran yang boleh menjalankan satu izin.
     *
     * @return list<string>
     */
    public static function peran(string $nama): array
    {
        return array_values(array_map('strval', (array) (self::peta()[$nama] ?? [])));
    }

    /**
     * Apakah satu peran memegang izin bernama.
     *
     * Penjaga wewenang DI DALAM controller memakai pembaca ini agar sumber
     * wewenangnya sama persis dengan gerbang rute (`izin:`). Perbandingan
     * level peran TIDAK dipakai: level hanyalah peringkat organisasi, sehingga
     * peran berlevel tinggi yang tidak diberi izin (mis. `dps`) akan lolos.
     */
    public static function peranMemegang(?string $peran, string $nama): bool
    {
        return in_array((string) $peran, self::peran($nama), true);
    }

    /**
     * Peran yang lolos SELURUH izin yang diberikan (irisan).
     *
     * Rute dapat dijaga beberapa izin sekaligus; middleware mensyaratkan
     * semuanya lolos, jadi himpunan peran efektifnya adalah irisan.
     *
     * @param  list<string>  $nama
     * @return list<string>
     */
    public static function peranEfektif(array $nama): array
    {
        $hasil = null;

        foreach ($nama as $satu) {
            $peran = self::peran($satu);
            $hasil = $hasil === null ? $peran : array_values(array_intersect($hasil, $peran));
        }

        return $hasil ?? [];
    }
}
