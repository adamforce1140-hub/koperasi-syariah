<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buang sesi yang menunjuk akun yang sudah tidak ada.
 *
 * `SessionGuard::id()` mengembalikan nilai dari sesi walau pengguna gagal
 * diambil dari basis data, sehingga `auth()->id()` masih memberi id akun yang
 * sudah dihapus. Id "hantu" itu lalu dipakai sebagai `user_id` saat mencatat
 * audit dan ditolak foreign key → HTTP 500 (mis. login gagal dari browser yang
 * masih membawa sesi lama).
 */
class BersihkanSesiTanpaAkun
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if ($request->hasSession()
            && $request->session()->has($guard->getName())
            && $guard->user() === null) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
