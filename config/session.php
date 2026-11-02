<?php

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    // Enkripsi payload sesi. Default dinyalakan demi keamanan; dapat
    // dimatikan lewat env `SESSION_ENCRYPT=false` bila diperlukan.
    'encrypt' => env('SESSION_ENCRYPT', true),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => 'sessions',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_session'
    ),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    // Kirim cookie hanya via HTTPS. Default true di produksi (mencegah
    // penyadapan cookie sesi); setel `SESSION_SECURE_COOKIE=false` untuk
    // pengembangan lokal berbasis HTTP.
    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production'),
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
