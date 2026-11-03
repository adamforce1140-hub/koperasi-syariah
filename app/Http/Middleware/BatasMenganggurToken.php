<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batas masa MENGGANGUR token Sanctum (T-2).
 *
 * `config('sanctum.expiration')` bawaan Sanctum adalah umur ABSOLUT sejak
 * token diterbitkan, bukan masa tidak dipakai. Akibatnya token mobile yang
 * dipakai setiap hari tetap mati di tengah jam kerja tepat saat `expiration`
 * terlampaui, sementara token yang bocor dan tidak pernah dipakai tetap sah
 * sampai umur absolutnya habis.
 *
 * Middleware ini menutup celah tersebut: token yang `last_used_at` (atau
 * `created_at` bila belum pernah dipakai) lebih tua dari
 * `config('sanctum.masa_menganggur_menit')` dihapus dan permintaan dijawab
 * 401. Umur absolut tetap berlaku sebagai batas keras lewat `expiration`.
 *
 * Hanya token yang benar-benar dikirim yang diperiksa; autentikasi sesi
 * (`Sanctum::actingAs`/guard web) tidak tersentuh.
 */
class BatasMenganggurToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Guard web (sesi) mengembalikan `TransientToken` yang tidak punya
        // kolom waktu — hanya token personal di basis data yang dipantau.
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $batas = (int) config('sanctum.masa_menganggur_menit', 0);

        if ($batas > 0) {
            // `last_used_at` dan `expires_at` TIDAK di-cast ke tanggal oleh
            // model Sanctum, sehingga bisa berupa string mentah.
            $terakhir = $token->last_used_at ?? $token->created_at;
            $terakhir = $terakhir ? Carbon::parse($terakhir) : null;

            if ($terakhir !== null && $terakhir->copy()->addMinutes($batas)->isPast()) {
                $token->delete();

                return response()->json([
                    'success' => false,
                    'message' => 'Sesi Anda telah berakhir karena tidak ada aktivitas. Silakan masuk kembali.',
                ], 401);
            }
        }

        return $next($request);
    }
}
