<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * BLIND INDEX UNTUK DATA PRIBADI (PII) — KEPATUHAN UU PDP No. 27/2022.
 *
 * Latar belakang masalah
 * ----------------------
 * Cast `encrypted` pada Eloquent bersifat NON-DETERMINISTIK: nilai yang sama
 * menghasilkan ciphertext berbeda pada setiap penyimpanan (IV acak + MAC).
 * Akibatnya kolom terenkripsi TIDAK DAPAT dipakai untuk:
 *   - constraint `unique()` (dua baris dengan NIK sama tetap lolos), dan
 *   - pencarian `WHERE kolom = ?` / `LIKE ?`.
 *
 * Solusi: "blind index" — kolom terpisah berisi HMAC-SHA256 deterministik dari
 * nilai yang sudah dinormalisasi. Kolom ini TIDAK dapat dibalik (one-way),
 * tetapi cukup untuk:
 *   - menjamin keunikan (unique index), dan
 *   - pencarian NIK secara tepat (exact match) tanpa mendekripsi tabel.
 *
 * Mengapa HMAC (bukan hash polos)?
 * --------------------------------
 * Ruang nilai NIK relatif kecil (16 digit) sehingga hash polos rentan
 * brute-force. Dengan kunci rahasia aplikasi (`APP_KEY`) sebagai kunci HMAC,
 * penyerang yang hanya memiliki dump basis data TIDAK dapat menghitung ulang
 * blind index tanpa kunci tersebut.
 *
 * Catatan keamanan: bila `APP_KEY` dirotasi, seluruh blind index harus
 * dihitung ulang (jalankan `php artisan db:seed --class=RebuildPiiBlindIndexSeeder`
 * atau migrasi backfill). Rotasi kunci juga membuat data terenkripsi lama tidak
 * terbaca — lihat docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md.
 */
class PiiHasher
{
    /**
     * Normalisasi nilai identitas sebelum di-hash.
     *
     * NIK hanya berisi digit; pemisah umum (spasi, titik, tanda hubung) dan
     * karakter non-digit dibuang agar "3273 0101 0101 0001" dan
     * "3273010101010001" menghasilkan blind index yang sama.
     */
    public static function normalize(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $bersih = preg_replace('/[^0-9A-Za-z]/', '', trim($nilai));

        return strtoupper((string) $bersih);
    }

    /**
     * Hitung blind index deterministik (HMAC-SHA256, hex 64 karakter).
     *
     * Mengembalikan null untuk nilai kosong agar kolom nullable tetap konsisten
     * (dua nilai kosong tidak dianggap "sama" sehingga unique index pada kolom
     * blind index tidak menolak baris tanpa NIK).
     */
    public static function hash(?string $nilai): ?string
    {
        $normal = self::normalize($nilai);

        if ($normal === null || $normal === '') {
            return null;
        }

        // Kunci HMAC diambil dari APP_KEY. Bila APP_KEY belum diisi (mis. saat
        // instalasi baru), gunakan fallback deterministik dari nama aplikasi
        // agar aplikasi tetap berjalan — namun tetap catat peringatan pada log.
        $kunci = (string) config('app.key');

        if ($kunci === '') {
            // R-01 — Fallback kunci TIDAK boleh senyap: blind index menjadi
            // dapat diprediksi siapa pun yang tahu konstanta ini, sehingga
            // keunikan/pencarian PII tidak lagi terlindungi. Peringatkan
            // SEKALI per proses (bukan per pemanggilan) agar log tidak banjir.
            self::peringatkanFallback();

            $kunci = 'koperasi-syariah-blind-index-fallback';
        }

        return hash_hmac('sha256', $normal, $kunci);
    }

    /**
     * True bila `$blindIndex` benar-benar hasil HMAC dari `$nilai`.
     *
     * Dibungkus `hash_equals` agar perbandingan bersifat waktu-konstan
     * (mencegah timing attack).
     */
    public static function cocok(?string $blindIndex, ?string $nilai): bool
    {
        $dihitung = self::hash($nilai);

        if ($dihitung === null || $blindIndex === null) {
            return false;
        }

        return hash_equals($dihitung, $blindIndex);
    }

    /**
     * R-01 — Peringatkan (sekali per proses) bahwa blind index memakai kunci
     * fallback karena `APP_KEY` kosong.
     *
     * Dipisah sebagai metode tersendiri agar `hash()` tetap murni/deterministik
     * dan mudah diuji. Penanda statis mencegah log terisi ribuan baris identik
     * saat satu permintaan memproses banyak baris PII.
     */
    private static function peringatkanFallback(): void
    {
        static $sudah = false;

        if ($sudah) {
            return;
        }

        $sudah = true;

        Log::warning(
            'PiiHasher: APP_KEY kosong — blind index memakai kunci fallback dan TIDAK aman. '.
            'Isi APP_KEY (php artisan key:generate) lalu bangun ulang blind index '.
            '(php artisan db:seed --class=RebuildPiiBlindIndexSeeder).'
        );
    }
}
