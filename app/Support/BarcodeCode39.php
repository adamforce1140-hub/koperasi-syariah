<?php

namespace App\Support;

/**
 * Generator barcode Code 39 (Code 3 of 9) tanpa dependensi eksternal.
 *
 * Dipakai pada dokumen Bukti Penutupan Keanggotaan agar nomor referensi
 * `EXIT/YYYYMM/NNNNNN` dapat dipindai (scan) oleh mesin kasir / pemindai
 * barcode standar. Code 39 dipilih karena:
 *  - alfabetnya mencakup A–Z, 0–9, `-`, `.`, spasi, `$`, `/`, `+`, `%`
 *    sehingga nomor referensi berformat `EXIT/202610/000001` dapat
 *    dikodekan apa adanya;
 *  - tidak memerlukan checksum wajib (opsional), implementasinya sederhana
 *    dan hasilnya dapat diverifikasi manual.
 *
 * Keluaran berupa SVG murni (string) sehingga aman dirender di HTML maupun
 * disematkan sebagai data-URI pada dokumen PDF dompdf.
 */
final class BarcodeCode39
{
    /**
     * Pola 9 elemen per karakter (bar-space bergantian, diawali bar).
     * `n` = narrow (1 modul), `w` = wide (3 modul).
     *
     * @var array<string, string>
     */
    private const POLA = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn',
        'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn',
        'J' => 'nnnnwwwnn', 'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw',
        'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn',
        '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn',
    ];

    private const PENANDA = '*';

    /**
     * Hasilkan SVG barcode Code 39.
     *
     * @param  string  $teks  Nomor referensi (karakter di luar alfabet dibuang).
     * @param  float  $tinggiMm  Tinggi batang dalam milimeter.
     * @param  float  $lebarModulMm  Lebar satu modul sempit (0,2 mm ≈ 203 dpi thermal).
     * @param  bool  $tampilTeks  Tampilkan teks di bawah barcode.
     */
    public static function svg(
        string $teks,
        float $tinggiMm = 12.0,
        float $lebarModulMm = 0.25,
        bool $tampilTeks = true
    ): string {
        $bersih = self::bersihkan($teks);

        if ($bersih === '') {
            return '';
        }

        // Karakter penanda start/stop mengapit data.
        $data = self::PENANDA.$bersih.self::PENANDA;

        $bars = [];
        $x = 0.0;

        foreach (str_split($data) as $indeks => $karakter) {
            $pola = self::POLA[$karakter] ?? null;

            if ($pola === null) {
                continue;
            }

            for ($i = 0; $i < 9; $i++) {
                $lebar = $pola[$i] === 'w' ? 3.0 : 1.0;

                // Elemen genap = bar (hitam), ganjil = spasi (putih).
                if ($i % 2 === 0) {
                    $bars[] = [$x, $lebar];
                }

                $x += $lebar;
            }

            // Jarak antar karakter = 1 modul.
            if ($indeks < strlen($data) - 1) {
                $x += 1.0;
            }
        }

        $totalModul = max($x, 1.0);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" '
            .'width="'.self::mm($totalModul * $lebarModulMm).'" '
            .'height="'.self::mm($tinggiMm).'" '
            .'viewBox="0 0 '.self::num($totalModul).' 100" '
            .'preserveAspectRatio="none">'
            .'<rect width="100%" height="100%" fill="#ffffff"/>';

        foreach ($bars as [$posisi, $lebar]) {
            $svg .= '<rect x="'.self::num($posisi).'" y="0" '
                .'width="'.self::num($lebar).'" height="100" fill="#000000"/>';
        }

        $svg .= '</svg>';

        if ($tampilTeks) {
            $svg .= '<div style="font-family:monospace;font-size:7pt;letter-spacing:1px;'
                .'text-align:center;margin-top:1mm;">'.e($bersih).'</div>';
        }

        return $svg;
    }

    /** Data-URI siap pakai untuk `<img src="...">` (dompdf). */
    public static function dataUri(string $teks, float $tinggiMm = 12.0, float $lebarModulMm = 0.25): string
    {
        $svg = self::svg($teks, $tinggiMm, $lebarModulMm, false);

        return $svg === ''
            ? ''
            : 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Buang karakter yang tidak ada pada alfabet Code 39. */
    public static function bersihkan(string $teks): string
    {
        $teks = strtoupper(trim($teks));

        return preg_replace('/[^A-Z0-9\-\. \$\/\+%]/', '', $teks) ?? '';
    }

    private static function num(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function mm(float $nilai): string
    {
        return self::num($nilai).'mm';
    }
}
