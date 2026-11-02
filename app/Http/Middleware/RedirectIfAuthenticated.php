<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                // Redirect otomatis ke dashboard sesuai role.
                // `admin` diarahkan ke Pengaturan Kebijakan; bila tidak
                // ditangani, admin akan dilempar kembali ke `login` sehingga
                // terjadi loop karena middleware ini menjaga rute `guest`.
                // Peran tak dikenal → halaman netral (bukan `login`).
                return match ($user->role) {
                    'admin' => redirect()->route('admin.settings.index'),
                    'teller' => redirect()->route('dashboard.teller'),
                    'ao' => redirect()->route('dashboard.ao'),
                    'manajer' => redirect()->route('dashboard.manajer'),
                    'pengurus' => redirect()->route('dashboard.pengurus'),
                    'dps' => redirect()->route('dashboard.dps'),
                    'anggota' => redirect()->route('dashboard.nasabah'),
                    default => redirect('/'),
                };
            }
        }

        return $next($request);
    }
}
