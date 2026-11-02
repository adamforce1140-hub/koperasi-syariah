<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * HEALTH-CHECK & OBSERVABILITY.
 *
 * Menyediakan dua probe standar untuk orkestrator/infrastruktur:
 *
 *   GET /health  — LIVENESS. Membuktikan proses aplikasi hidup dan mampu
 *                  merespons. TIDAK menyentuh dependensi eksternal, sehingga
 *                  tidak pernah membuat orkestrator me-restart pod hanya karena
 *                  DB sedang lambat.
 *
 *   GET /readyz  — READINESS. Memeriksa kesiapan dependensi yang dibutuhkan
 *                  untuk melayani permintaan (database, cache, antrean). Balas
 *                  200 bila siap, 503 bila ada yang belum siap — sehingga
 *                  trafik tidak dikirim ke instance yang belum siap.
 *
 * Keamanan: kedua endpoint tidak memerlukan autentikasi (dipakai infrastruktur)
 * sehingga dilindungi rate limit dan dapat dimatikan lewat env
 * `HEALTH_ENDPOINT_ENABLED`. Respons sengaja RINGKAS — tanpa stack trace,
 * kredensial, atau detail koneksi — agar tidak membocorkan informasi internal.
 */
class HealthController extends Controller
{
    /**
     * LIVENESS — proses hidup. Tanpa pemeriksaan dependensi.
     */
    public function live(): JsonResponse
    {
        if (! $this->aktif()) {
            return $this->nonaktif();
        }

        return response()->json([
            'status' => 'ok',
            'service' => config('app.name'),
            'waktu' => now()->toIso8601String(),
        ]);
    }

    /**
     * READINESS — kesiapan dependensi (DB, cache, queue).
     */
    public function ready(): JsonResponse
    {
        if (! $this->aktif()) {
            return $this->nonaktif();
        }

        $pemeriksaan = [
            'database' => $this->periksaDatabase(),
            'cache' => $this->periksaCache(),
            'queue' => $this->periksaQueue(),
        ];

        $siap = true;
        foreach ($pemeriksaan as $hasil) {
            if (($hasil['ok'] ?? false) !== true) {
                $siap = false;
                break;
            }
        }

        return response()->json([
            'status' => $siap ? 'ok' : 'degraded',
            'pemeriksaan' => $pemeriksaan,
            'waktu' => now()->toIso8601String(),
        ], $siap ? 200 : 503);
    }

    /**
     * Apakah endpoint health-check diaktifkan (env `HEALTH_ENDPOINT_ENABLED`).
     */
    private function aktif(): bool
    {
        return (bool) config('keamanan.health.aktif', true);
    }

    /**
     * Respons ketika endpoint sengaja dinonaktifkan — 404 agar infrastruktur
     * yang memantaunya tahu endpoint memang tidak tersedia.
     */
    private function nonaktif(): JsonResponse
    {
        return response()->json([
            'status' => 'disabled',
            'message' => 'Endpoint health-check dinonaktifkan.',
        ], 404);
    }

    /**
     * @return array{ok: bool, driver: string, keterangan?: string}
     */
    private function periksaDatabase(): array
    {
        $driver = (string) config('database.default');

        try {
            // `select 1` membuktikan koneksi benar-benar melayani query,
            // bukan sekadar objek PDO yang bisa dibuat.
            DB::select('select 1');

            return ['ok' => true, 'driver' => $driver];
        } catch (Throwable $e) {
            Log::error('Health-check: database tidak siap.', ['exception' => $e->getMessage()]);

            return ['ok' => false, 'driver' => $driver, 'keterangan' => 'koneksi database gagal'];
        }
    }

    /**
     * @return array{ok: bool, driver: string, keterangan?: string}
     */
    private function periksaCache(): array
    {
        $driver = (string) config('cache.default');

        try {
            $kunci = 'health:'.uniqid('', true);
            Cache::put($kunci, 'ok', 5);
            $nilai = Cache::get($kunci);
            Cache::forget($kunci);

            if ($nilai !== 'ok') {
                return ['ok' => false, 'driver' => $driver, 'keterangan' => 'cache tidak mengembalikan nilai yang disimpan'];
            }

            return ['ok' => true, 'driver' => $driver];
        } catch (Throwable $e) {
            Log::error('Health-check: cache tidak siap.', ['exception' => $e->getMessage()]);

            return ['ok' => false, 'driver' => $driver, 'keterangan' => 'cache gagal diakses'];
        }
    }

    /**
     * @return array{ok: bool, driver: string, keterangan?: string}
     */
    private function periksaQueue(): array
    {
        $driver = (string) config('queue.default');

        // Driver `sync` berjalan inline tanpa antrean eksternal → selalu siap.
        if ($driver === 'sync') {
            return ['ok' => true, 'driver' => 'sync', 'keterangan' => 'tanpa antrean eksternal'];
        }

        try {
            // `size()` melakukan panggilan ringan ke backend antrean.
            Queue::connection()->size();

            return ['ok' => true, 'driver' => $driver];
        } catch (Throwable $e) {
            Log::error('Health-check: antrean tidak siap.', ['exception' => $e->getMessage()]);

            return ['ok' => false, 'driver' => $driver, 'keterangan' => 'antrean gagal diakses'];
        }
    }
}
