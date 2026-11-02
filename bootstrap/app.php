<?php

use App\Exceptions\JournalPostingException;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\CekIzin;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\KeamananHeader;
use App\Http\Middleware\LaporanKeuanganAccess;
use App\Http\Middleware\PastikanAnggotaAktif;
use App\Http\Middleware\PastikanKasTerbuka;
use App\Http\Middleware\ShareNavigationMenu;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Observability — probe kesehatan untuk infrastruktur.
         *
         * Didaftarkan di luar grup `web` dengan sengaja: probe tidak butuh
         * sesi/CSRF dan harus tetap merespons meski lapisan sesi bermasalah.
         * Keduanya tanpa autentikasi (dipakai load balancer/orkestrator),
         * sehingga dilindungi rate limit `throttle:health` dan dapat
         * dimatikan lewat env `HEALTH_ENDPOINT_ENABLED` (diperiksa di
         * HealthController). Lihat config/keamanan.php → 'health'.
         */
        then: function () {
            Route::middleware('throttle:health')->group(function () {
                Route::get('/health', [HealthController::class, 'live'])->name('health.live');
                Route::get('/readyz', [HealthController::class, 'ready'])->name('health.ready');
            });
        },
    )

    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => CheckRole::class,
            // DYN-18: gerbang izin bernama. Rute menyebut wewenang
            // (`izin:kas.opening`), pemetaan izin → peran ada di config/izin.php.
            'izin' => CekIzin::class,
            // Gerbang RBAC + audit modul Laporan Keuangan. Dipasang SEBELUM
            // 'role:' pada rute agar percobaan akses tidak sah ikut tercatat.
            'laporan.akses' => LaporanKeuanganAccess::class,
            'anggota.aktif' => PastikanAnggotaAktif::class,
            // Guard sesi kas: transaksi tunai hanya sah bila laci teller
            // (kas_harian) berstatus OPEN pada tanggal bisnis berjalan.
            'kas.terbuka' => PastikanKasTerbuka::class,
        ]);
        // Amankan sesi (S-01): invalidasi sesi lama saat password berubah.
        // Tanpa ini, sesi yang masih aktif tetap sah meski kredensial sudah
        // diganti/dikompromikan.
        $middleware->authenticateSessions();
        // Inject menu navigasi ke semua view untuk user yang sudah login
        $middleware->appendToGroup('web', ShareNavigationMenu::class);
        // Header keamanan (UU PDP No. 27/2022): nosniff, CSP, Referrer-Policy,
        // X-Frame-Options, Permissions-Policy, dan HSTS pada koneksi HTTPS.
        $middleware->appendToGroup('web', KeamananHeader::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
        |----------------------------------------------------------------------
        | Penanganan error terpusat
        |----------------------------------------------------------------------
        | Satu referensi unik per permintaan dipakai bersama oleh logger dan
        | pesan ke pengguna, sehingga keluhan "muncul kode ERR-XXXX" dapat
        | ditelusuri langsung ke baris log yang tepat.
        */
        $referensi = null;

        $ambilReferensi = static function () use (&$referensi): string {
            if ($referensi === null) {
                $referensi = 'ERR-'.strtoupper(bin2hex(random_bytes(4)));
            }

            return $referensi;
        };

        /*
         * Permintaan API selalu dibalas JSON, termasuk saat klien lupa
         * mengirim header Accept: application/json. Pengecualian diberikan
         * untuk AuthenticationException agar rute API berbasis sesi
         * (middleware 'web'+'auth') tetap mengalihkan tamu ke halaman login,
         * bukan membalas 401 mentah.
         */
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($e instanceof AuthenticationException) {
                return $request->expectsJson();
            }

            return $request->is('api/*') || $request->expectsJson();
        });

        /*
         * Log terstruktur khusus kesalahan data. Exception lain tetap dicatat
         * lengkap oleh logger bawaan Laravel; di sini hanya ditambahkan entri
         * ringkas yang mudah dicari (tanpa membocorkan SQL/nilai binding).
         */
        $exceptions->report(function (Throwable $e) use ($ambilReferensi) {
            if (! $e instanceof QueryException) {
                return;
            }

            Log::error('Kesalahan pengolahan data', [
                'reference' => $ambilReferensi(),
                'sql_state' => $e->getCode(),
                'connection' => $e->getConnectionName(),
                'url' => request()?->fullUrl(),
                'method' => request()?->method(),
                'user_id' => auth()->id(),
            ]);
        });

        /*
         * Renderer ramah untuk web dan selubung JSON konsisten untuk API.
         * Klasifikasi memakai exception yang SUDAH dipetakan Laravel
         * (ModelNotFoundException → NotFoundHttpException, AuthorizationException
         * → AccessDeniedHttpException), sehingga cukup memeriksa tipe HTTP.
         */
        $exceptions->render(function (Throwable $e, Request $request) use ($ambilReferensi) {
            // Validasi dibiarkan ditangani Laravel agar payload `errors` standar
            // (yang dibaca form dan klien API) tidak hilang.
            if ($e instanceof ValidationException) {
                return null;
            }

            $json = $request->expectsJson() || $request->is('api/*');

            /*
             * Tamu pada rute berbasis sesi dibiarkan mengikuti alur bawaan
             * Laravel (redirect ke halaman login), termasuk rute 'api/*' yang
             * memakai middleware 'web'+'auth' tanpa header Accept JSON.
             * Hanya klien yang benar-benar meminta JSON yang menerima 401.
             */
            if ($e instanceof AuthenticationException && ! $request->expectsJson()) {
                return null;
            }

            // 403 pada permintaan non-JSON memakai view errors/403.blade.php
            // yang sudah sadar-peran.
            if (! $json && $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403) {
                return null;
            }

            [$status, $judul, $pesan] = (static function (Throwable $e): array {
                if ($e instanceof AuthenticationException) {
                    return [401, 'Sesi Berakhir', 'Sesi Anda telah berakhir. Silakan masuk kembali untuk melanjutkan.'];
                }

                if ($e instanceof NotFoundHttpException) {
                    return [404, 'Data Tidak Ditemukan', 'Data atau halaman yang Anda tuju tidak ditemukan atau sudah dihapus.'];
                }

                if ($e instanceof MethodNotAllowedHttpException) {
                    return [405, 'Metode Tidak Diizinkan', 'Permintaan tidak dapat diproses dengan metode ini.'];
                }

                if ($e instanceof TooManyRequestsHttpException) {
                    $tunggu = (int) ($e->getHeaders()['Retry-After'] ?? 0);

                    return [429, 'Terlalu Banyak Permintaan', $tunggu > 0
                        ? 'Terlalu banyak percobaan dalam waktu singkat. Silakan coba lagi dalam '.$tunggu.' detik.'
                        : 'Silakan tunggu beberapa saat sebelum mencoba lagi.'];
                }

                /*
                 * Kegagalan VALIDASI posting jurnal (CoA tidak ditemukan,
                 * jurnal tidak balance, pelanggaran PSAK 108/109) adalah
                 * penolakan bisnis — bukan gangguan server. Sebelumnya
                 * pengguna hanya melihat halaman 500 generik tanpa sebab,
                 * padahal pesan exception sudah menjelaskan aturan yang
                 * dilanggar sehingga dapat langsung ditindaklanjuti.
                 */
                if ($e instanceof JournalPostingException) {
                    return [422, 'Jurnal Ditolak', $e->getMessage()];
                }

                if ($e instanceof QueryException) {
                    return [500, 'Gangguan Pengolahan Data', 'Terjadi gangguan pada pengolahan data. Tim teknis telah menerima laporan ini.'];
                }

                if ($e instanceof HttpExceptionInterface) {
                    $kode = $e->getStatusCode();

                    $peta = [
                        400 => ['Permintaan Tidak Valid', 'Permintaan tidak dapat diproses karena data yang dikirim tidak sesuai.'],
                        403 => ['Akses Ditolak', 'Anda tidak memiliki izin untuk mengakses halaman atau data ini.'],
                        419 => ['Sesi Kedaluwarsa', 'Sesi Anda telah kedaluwarsa. Muat ulang halaman lalu coba lagi.'],
                        503 => ['Layanan Tidak Tersedia', 'Layanan sedang tidak tersedia. Silakan coba beberapa saat lagi.'],
                    ];

                    return [$kode, ...($peta[$kode] ?? [
                        'Terjadi Kesalahan',
                        'Permintaan tidak dapat diproses (kode '.$kode.').',
                    ])];
                }

                return [500, 'Terjadi Kesalahan', 'Terjadi kesalahan tak terduga pada sistem. Silakan coba beberapa saat lagi.'];
            })($e);

            $referensi = $ambilReferensi();

            /*
             * Pembatasan laju tidak pernah sampai ke logger bawaan Laravel
             * (HttpException dikecualikan dari pelaporan), padahal halaman error
             * menampilkan kode referensi kepada pengguna. Tanpa entri ini kode
             * tersebut mustahil ditelusuri saat pengguna melaporkannya.
             */
            if ($e instanceof TooManyRequestsHttpException) {
                Log::warning('Permintaan dibatasi oleh rate limiting', [
                    'reference' => $referensi,
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_id' => auth()->id(),
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                ]);
            }

            $respons = $json
                ? response()->json([
                    'success' => false,
                    'message' => $pesan,
                    'error' => [
                        'status' => $status,
                        'reference' => $referensi,
                    ],
                ], $status)
                : response()->view('errors.umum', [
                    'kode' => $status,
                    'judul' => $judul,
                    'pesan' => $pesan,
                    'referensi' => $referensi,
                ], $status);

            /*
             * Middleware pembatas laju menitipkan header Retry-After dan
             * X-RateLimit-* pada exception. Respons di atas dibangun ulang,
             * sehingga header itu hilang bila tidak disalin ulang — akibatnya
             * klien tidak tahu kapan boleh mencoba lagi.
             */
            if ($e instanceof HttpExceptionInterface) {
                $respons->headers->add($e->getHeaders());
            }

            return $respons;
        });
    })->create();

return $app;
