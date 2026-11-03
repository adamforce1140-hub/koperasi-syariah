<?php

namespace App\Support;

/**
 * Penyaring markup ringan untuk teks label BERISI HTML.
 *
 * Komponen `<x-label-berkas>` (S-2) menerima teks yang boleh memuat penanda
 * wajib `*` — mis. `Tanggal Transaksi <span style="color:#dc2626;">*</span>`.
 * Teks itu ditulis pemrogram di view, bukan masukan pengguna, tetapi tetap
 * diperlakukan sebagai data tak tepercaya agar markup yang keliru tidak dapat
 * menyelinapkan atribut penanganan kejadian atau tag lain ke dalam halaman.
 *
 * CARA KERJA (allowlist, bukan denylist):
 *   1. Seluruh teks di-escape lebih dulu — apa pun bentuk masukannya, hasil
 *      dasar selalu teks aman.
 *   2. Setelah itu HANYA bentuk persis berikut yang dipulihkan menjadi tag
 *      hidup:
 *        `<span>` `</span>` `<small>` `</small>`, dan versinya yang memakai
 *        tepat satu atribut `style="color:#rgb|#rrggbb"`.
 *      Semua atribut lain, properti gaya lain, dan tag lain tetap ter-escape
 *      sehingga tampil sebagai teks biasa — bukan tag setengah hidup.
 *
 * Delimiter regex memakai `~` karena pola memuat `#` (warna heksadesimal).
 * Proyek tidak memuat pustaka sanitizer, jadi penyaring ini sengaja sesempit
 * mungkin dan seluruh cabangnya dikunci `Tests\Unit\TeksLabelAmanTest`.
 */
final class TeksLabelAman
{
    private const TAG_DIIZINKAN = ['span', 'small'];

    public static function saring(string $teks): string
    {
        $hasil = htmlspecialchars($teks, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Tag penutup polos: `&lt;/span&gt;` / `&lt;/small&gt;`.
        $hasil = preg_replace_callback(
            '~&lt;/(span|small)\s*&gt;~i',
            static fn (array $m): string => '</'.strtolower($m[1]).'>',
            $hasil
        ) ?? $hasil;

        // Tag pembuka: HANYA `span`/`small`, HANYA gaya warna heksadesimal.
        // Bentuk lain (atribut tambahan, gaya lain) tidak cocok dan tetap
        // ter-escape sepenuhnya.
        $nama = implode('|', self::TAG_DIIZINKAN);

        $hasil = preg_replace_callback(
            '~&lt;('.$nama.')(?:\s+style\s*=\s*&quot;\s*color\s*:\s*(#[0-9a-f]{3}(?:[0-9a-f]{3})?)\s*;?\s*&quot;)?\s*/?&gt;~i',
            static function (array $m): string {
                $tag = strtolower($m[1]);
                $warna = $m[2] ?? '';

                return $warna === ''
                    ? "<{$tag}>"
                    : "<{$tag} style=\"color:{$warna}\">";
            },
            $hasil
        ) ?? $hasil;

        return $hasil;
    }
}
