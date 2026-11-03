<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        /*
         * Pembatas laju endpoint health-check (`/health`, `/readyz`).
         *
         * Kedua endpoint sengaja TIDAK berautentikasi karena dipanggil
         * infrastruktur (load balancer, orkestrator, uptime monitor). Batas
         * per menit dibaca dari konfigurasi (`keamanan.health`) sehingga dapat
         * disesuaikan lewat env `HEALTH_RATE_LIMIT_PER_MINUTE` tanpa mengubah
         * kode, sekaligus mencegah probe disalahgunakan untuk membebani sistem.
         */
        RateLimiter::for('health', function (Request $request) {
            $batas = max(1, (int) config('keamanan.health.rate_limit_per_menit', 60));

            return Limit::perMinute($batas)->by($request->ip());
        });

        /*
         * T-2 — pembatas laju kanal API mobile. Keduanya dibatasi per
         * PENGGUNA (jatuh ke IP bila anonim) agar satu akun tidak dapat
         * menghujani basis data; angkanya diatur di `keamanan.throttle`.
         */
        RateLimiter::for('transaksi', function (Request $request) {
            $batas = max(1, (int) config('keamanan.throttle.transaksi', 30));

            return Limit::perMinute($batas)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('baca-api', function (Request $request) {
            $batas = max(1, (int) config('keamanan.throttle.baca-api', 60));

            return Limit::perMinute($batas)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
