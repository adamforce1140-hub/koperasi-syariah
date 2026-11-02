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
    // Masa berlaku token akses (menit). Default 8 jam agar token yang bocor
    // tidak berlaku selamanya; setel `SANCTUM_EXPIRATION=null` untuk menonaktifkan.
    'expiration' => env('SANCTUM_EXPIRATION', 480),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'verify_csrf_token' => VerifyCsrfToken::class,
    ],
];
