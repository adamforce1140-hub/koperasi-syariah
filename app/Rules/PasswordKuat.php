<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Kebijakan kekuatan password (UU No. 27/2022 — Pasal 35 & 39: kewajiban
 * menerapkan langkah teknis yang layak untuk melindungi data pribadi).
 *
 * Parameter dibaca dari `config/keamanan.php` sehingga dapat disetel lewat
 * variabel lingkungan tanpa mengubah kode. Di lingkungan `local`/`testing`
 * syaratnya dilonggarkan agar akun demo & seeder tetap sah.
 *
 * ATURAN INI DIPAKAI SAAT MEMBUAT/MENGGANTI PASSWORD — BUKAN SAAT LOGIN.
 * Memaksakan kebijakan pada login akan mengunci pengguna lama yang passwordnya
 * dibuat sebelum kebijakan ini ada.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class PasswordKuat implements ValidationRule
{
    public function __construct(private ?string $namaPengguna = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $kebijakan = (array) config('keamanan.password', []);

        if (! is_string($value) || $value === '') {
            return; // Aturan `required` yang menangani nilai kosong.
        }

        $min = (int) ($kebijakan['min'] ?? 8);
        if (mb_strlen($value) < $min) {
            $fail("Password minimal {$min} karakter.");
        }

        if (($kebijakan['huruf_besar'] ?? false) && ! preg_match('/[A-Z]/', $value)) {
            $fail('Password harus memuat minimal satu huruf besar.');
        }

        if (($kebijakan['angka'] ?? false) && ! preg_match('/\d/', $value)) {
            $fail('Password harus memuat minimal satu angka.');
        }

        if (($kebijakan['simbol'] ?? false) && ! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('Password harus memuat minimal satu karakter simbol.');
        }

        // Password yang identik dengan nama pengguna sangat mudah ditebak.
        if ($this->namaPengguna !== null && $this->namaPengguna !== ''
            && mb_strtolower($value) === mb_strtolower($this->namaPengguna)) {
            $fail('Password tidak boleh sama dengan nama pengguna.');
        }
    }
}
