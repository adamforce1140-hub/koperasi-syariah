<?php

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),
    'guard' => ['web'],
    /*
     * Masa berlaku token akses (menit).
     *
     * T-2 — kunci `expiration` Sanctum adalah umur ABSOLUT sejak token
     * diterbitkan, bukan masa menganggur: token berhenti berlaku tepat pada
     * `created_at + expiration` berapa pun seringnya dipakai. Komentar lama
     * ("8 jam") menjanjikan perilaku sliding yang tidak pernah ada, dan
     * `SANCTUM_EXPIRATION` tidak disetel di env manapun sehingga nilai default
     * berlaku diam-diam.
     *
     * Kebijakan yang diinginkan: token mati setelah TIDAK DIPAKAI selama
     * `SANCTUM_MASA_MENGGUR` (default 8 jam) — klien mobile yang aktif tetap
     * bekerja, sedangkan token yang hilang/bocor tidak dapat disalahgunakan
     * keesokan hari. Umur absolut tetap dipasang sebagai batas keras
     * `SANCTUM_EXPIRATION` (default 30 hari), selaras ukuran `expires_at`
     * Sanctum bila `SANCTUM_EXPIRATION` diisi.
     */
    'expiration' => env('SANCTUM_EXPIRATION', 60 * 24 * 30),
    'masa_menganggur_menit' => (int) env('SANCTUM_MASA_MENGGUR', 480),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'verify_csrf_token' => VerifyCsrfToken::class,
    ],
];
