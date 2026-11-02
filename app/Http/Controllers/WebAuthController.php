<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\HierarkiRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class WebAuthController extends Controller
{
    public function loginForm()
    {
        if (Auth::check()) {
            return $this->redirectByRole(Auth::user());
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            /* OJK-09 — Tegakkan status keaktifan akun.
             | Sebelumnya `is_active` tidak pernah diperiksa saat autentikasi
             | sehingga akun yang dinonaktifkan (mis. pegawai keluar, akun
             | disuspend) tetap bisa masuk. Kini sesi langsung dibatalkan bila
             | akun nonaktif dan percobaan dicatat sebagai kegagalan. */
            if (! Auth::user()->is_active) {
                AuditLog::record('Login Gagal', Auth::id(), null, sprintf(
                    'Percobaan login akun NONAKTIF: %s (peran %s).',
                    Auth::user()->email,
                    Auth::user()->role ?? '-'
                ));

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors(['email' => 'Akun Anda sedang dinonaktifkan. Hubungi pengurus koperasi.'])->withInput($request->except('password'));
            }

            $request->session()->regenerate();

            /* TAHAP 5 (R4.1) — Jejak audit autentikasi.
             | Sebelum ini, login/logout tidak meninggalkan jejak sama sekali
             | sehingga investigasi insiden (mis. pemakaian akun bersama atau
             | percobaan brute force) tidak punya bukti. Dicatat ke `audit_logs`
             | yang sudah menjadi kanal audit tunggal sistem. */
            AuditLog::record('Login Berhasil', 0, null, sprintf(
                'Login berhasil: %s (peran %s).',
                Auth::user()->email,
                Auth::user()->role ?? '-'
            ));

            return $this->redirectByRole(Auth::user());
        }

        AuditLog::record('Login Gagal', 0, null, sprintf(
            'Percobaan login gagal untuk email %s.',
            (string) $request->input('email')
        ));

        return back()->withErrors(['email' => 'Email atau password salah.'])->withInput($request->except('password'));
    }

    public function logout(Request $request)
    {
        // Dicatat SEBELUM logout agar `user_id` masih terisi pengguna yang keluar.
        if (Auth::check()) {
            AuditLog::record('Logout', 0, null, sprintf(
                'Logout: %s (peran %s).',
                Auth::user()->email,
                Auth::user()->role ?? '-'
            ));
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function redirectByRole(User $user)
    {
        // Pemetaan peran → dasbor dibaca dari SATU sumber pusat
        // (`role_hierarki.peran.*.dashboard_route` via HierarkiRole), sehingga
        // menambah peran baru tidak lagi mengubah method ini.
        $rute = HierarkiRole::dashboardRoute($user->role);

        // Peran tak dikenal / belum punya dasbor sebelumnya dikembalikan ke
        // `login` — padahal pengguna SUDAH terautentikasi, sehingga terjadi loop
        // login tanpa akhir. Dikembalikan ke halaman netral agar tidak memutar
        // balik.
        if ($rute === null || ! Route::has($rute)) {
            return redirect('/');
        }

        return redirect()->route($rute);
    }

    public function redirectByRolePublic(User $user)
    {
        return $this->redirectByRole($user);
    }
}
