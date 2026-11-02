<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\RouteServiceProvider;
use App\Support\Setting;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Auth\Passwords\PasswordResetServiceProvider;
use Illuminate\Broadcasting\BroadcastServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\ConsoleSupportServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Notifications\NotificationServiceProvider;
use Illuminate\Pagination\PaginationServiceProvider;
use Illuminate\Pipeline\PipelineServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;

return [

    'name' => env('APP_NAME', 'Koperasi Syariah'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => 'Asia/Jakarta',
    'locale' => 'id',
    'fallback_locale' => 'en',
    'faker_locale' => 'id_ID',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Kredensial Demo (non-produksi)
    |--------------------------------------------------------------------------
    |
    | Panel "Akses Cepat Demo Akun" pada halaman login dan UserSeeder memakai
    | nilai di sini agar password demo TIDAK lagi ditulis di dalam kode/view.
    | Panel hanya tampil di lingkungan local/testing; di produksi panel
    | disembunyikan dan UserSeeder menolak membuat akun tanpa password
    | eksplisit (DEMO_USER_PASSWORD).
    |
    | PENTING — satu sumber kebenaran:
    | Nilai `login_enabled` dan `password` di bawah ini dihitung SEKALI di
    | titik ini, lalu UserSeeder menyalinnya ke database. Bila UserSeeder
    | memakai nilai default yang berbeda dari yang dipakai view login, tombol
    | "Akses Cepat Demo Akun" akan mengisi password yang tidak pernah cocok
    | dengan hash di database — tombol tampak berfungsi tetapi login selalu
    | gagal. Karena itu fallback password di sini adalah SATU-SATUNYA
    | fallback, dan hanya berlaku di luar produksi.
    |
    */
    'demo' => (function () {
        $env = (string) env('APP_ENV', 'production');
        $lokal = in_array($env, ['local', 'testing'], true);

        // Panel demo tidak pernah boleh tampil di produksi, sekalipun
        // DEMO_LOGIN_ENABLED disetel `true` secara keliru.
        $aktif = $lokal && filter_var(
            env('DEMO_LOGIN_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN
        );

        $password = (string) (env('DEMO_USER_PASSWORD') ?? '');

        // Fallback non-produksi agar alur demo tidak putus saat kredensial
        // tidak diisi. Di produksi sengaja dibiarkan kosong supaya UserSeeder
        // menolak membuat akun dengan password yang dapat ditebak.
        if ($password === '' && $lokal) {
            $password = 'password123';
        }

        return [
            'login_enabled' => $aktif,
            'password' => $password,
        ];
    })(),

    'maintenance' => [
        'driver' => 'file',
        // 'store' => 'redis',
    ],
    'providers' => [

        /*
         * Laravel Framework Service Providers...
         */
        AuthServiceProvider::class,
        BroadcastServiceProvider::class,
        BusServiceProvider::class,
        CacheServiceProvider::class,
        ConsoleSupportServiceProvider::class,
        CookieServiceProvider::class,
        DatabaseServiceProvider::class,
        EncryptionServiceProvider::class,
        FilesystemServiceProvider::class,
        FoundationServiceProvider::class,
        HashServiceProvider::class,
        MailServiceProvider::class,
        NotificationServiceProvider::class,
        PaginationServiceProvider::class,
        PipelineServiceProvider::class,
        QueueServiceProvider::class,
        RedisServiceProvider::class,
        PasswordResetServiceProvider::class,
        SessionServiceProvider::class,
        TranslationServiceProvider::class,
        ValidationServiceProvider::class,
        ViewServiceProvider::class,

        /*
         * Package Service Providers...
         */
        SanctumServiceProvider::class,

        /*
         * Application Service Providers...
         */
        AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        EventServiceProvider::class,
        RouteServiceProvider::class,

    ],
    'aliases' => [

        'App' => Illuminate\Support\Facades\App::class,
        'Arr' => Arr::class,
        'Artisan' => Artisan::class,
        'Auth' => Auth::class,
        'Blade' => Blade::class,
        'Broadcast' => Broadcast::class,
        'Bus' => Bus::class,
        'Cache' => Cache::class,
        'Config' => Config::class,
        'Cookie' => Cookie::class,
        'Crypt' => Crypt::class,
        'Date' => Date::class,
        'DB' => DB::class,
        'Eloquent' => Model::class,
        'Event' => Event::class,
        'File' => File::class,
        'Gate' => Gate::class,
        'Hash' => Hash::class,
        'Http' => Http::class,
        'Js' => Js::class,
        'Lang' => Lang::class,
        'Log' => Log::class,
        'Mail' => Mail::class,
        'Notification' => Notification::class,
        'Password' => Password::class,
        'Queue' => Queue::class,
        'RateLimiter' => RateLimiter::class,
        'Redirect' => Redirect::class,
        'Request' => Request::class,
        'Response' => Response::class,
        'Route' => Route::class,
        'Schema' => Schema::class,
        'Session' => Session::class,
        'Storage' => Storage::class,
        'Str' => Str::class,
        'URL' => URL::class,
        'Validator' => Validator::class,
        'View' => View::class,

        // Master Settings koperasi — dapat dipanggil global: \Setting::get('key', $default)
        'Setting' => Setting::class,

    ],

];
