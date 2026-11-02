<?php

namespace App\Http\Middleware;

use App\Support\Izin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang IZIN BERNAMA — DYN-18.
 *
 * Rute menyebut wewenang secara semantik (`izin:kas.opening`), bukan daftar
 * peran. Pemetaan izin → peran hidup di `config/izin.php` (SSOT), sehingga
 * penambahan/pengalihan peran tidak lagi menyentuh definisi rute.
 *
 * Beberapa izin pada satu rute bersifat DAN: pengguna harus lolos semuanya.
 */
class CekIzin
{
    public function handle(Request $request, Closure $next, string ...$izin): Response
    {
        // Gagal cepat bila nama izin tidak terdaftar. Tanpa ini, salah ketik
        // (mis. `izin:kas.openig`) membuat rute SELALU 403 dan tampak seperti
        // masalah hak akses, padahal konfigurasinya yang salah.
        foreach ($izin as $nama) {
            if (! Izin::ada($nama)) {
                throw new \InvalidArgumentException(
                    'Middleware izin: nama izin "'.$nama.'" tidak dikenal. '
                    .'Tambahkan entri pada config/izin.php (bagian `izin`) '
                    .'atau perbaiki nama izin pada definisi rute.'
                );
            }
        }

        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $peran = (string) auth()->user()->role;

        foreach ($izin as $nama) {
            if (! in_array($peran, Izin::peran($nama), true)) {
                abort(403, 'Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman ini.');
            }
        }

        return $next($request);
    }
}
