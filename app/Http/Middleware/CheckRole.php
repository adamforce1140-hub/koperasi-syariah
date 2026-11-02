<?php

namespace App\Http\Middleware;

use App\Support\HierarkiRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Nama peran pada rute divalidasi terhadap daftar peran di
        // config/role_hierarki.php. Tanpa ini, salah ketik (mis. `role:manajerx`)
        // tidak pernah ketahuan: rute tetap terdaftar, tetapi SELALU 403 dan
        // tampak seperti masalah hak akses. Gagal cepat di sini membuat
        // kesalahan konfigurasi terlihat saat rute diuji/diakses.
        foreach ($roles as $peran) {
            if (! HierarkiRole::dikenal($peran)) {
                throw new \InvalidArgumentException(
                    'Middleware role: nama peran "'.$peran.'" tidak dikenal. '
                    .'Tambahkan entri pada config/role_hierarki.php (bagian `peran`) '
                    .'atau perbaiki nama peran pada definisi rute.'
                );
            }
        }

        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Role valid: admin, teller, manajer, pengurus, ao, dps, anggota.
        // 'anggota' MASIH aktif dipakai untuk akses swalayan: grup rute
        // `role:anggota` di routes/web.php menjaga seluruh modul nasabah
        // (dashboard, simpanan, mutasi, angsuran, pengajuan, profil).
        // Komentar versi lama menyatakan sebaliknya dan menyesatkan saat audit.
        // Perbandingan STRICT (argumen ketiga = true) untuk mencegah kelonggaran
        // tipe (mis. nilai integer 0/1 secara longgar sama dengan string).
        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman ini.');
        }

        return $next($request);
    }
}
