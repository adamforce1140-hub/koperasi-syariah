<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Rules\PasswordKuat;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * C1 — "Lupa kata sandi".
 *
 * Alur standar Laravel yang memanfaatkan broker `users` pada `config/auth.php`
 * (tabel `password_reset_tokens`, kedaluwarsa 60 menit, jeda 60 detik):
 *
 *   1. Tamu meminta tautan  → token di-hash & disimpan, tautan dikirim lewat
 *      notifikasi email (`MAIL_MAILER` menentukan kanalnya).
 *   2. Tamu membuka tautan  → formulir kata sandi baru.
 *   3. Simpan               → token diverifikasi, kata sandi diganti, token
 *      dihapus, dan jejak audit dicatat.
 *
 * Catatan keamanan:
 *  - Respons permintaan tautan SELALU sama, baik email terdaftar maupun tidak,
 *    agar tidak menjadi alat enumerasi akun.
 *  - Kata sandi baru wajib melewati `App\Rules\PasswordKuat` — kebijakan yang
 *    sama dengan jalur pembuatan akun, sehingga tidak ada jalur pintas.
 */
class PasswordResetController extends Controller
{
    /** Formulir permintaan tautan atur ulang. */
    public function formLupa(): View
    {
        return view('auth.forgot-password');
    }

    /** Kirim tautan atur ulang ke email yang diberikan. */
    public function kirimTautan(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ]);

        $email = (string) $request->input('email');

        // Status asli (terkirim / email tidak dikenal / sedang dibatasi) sengaja
        // TIDAK diteruskan ke tampilan — lihat catatan enumerasi di atas.
        Password::sendResetLink($request->only('email'));

        AuditLog::record('Permintaan Reset Kata Sandi', 0, null, sprintf(
            'Permintaan tautan atur ulang kata sandi untuk %s.',
            $email
        ));

        return back()->with('status', 'Jika email tersebut terdaftar, tautan atur ulang kata sandi telah dikirim. Silakan periksa kotak masuk Anda.');
    }

    /** Formulir kata sandi baru (token dari tautan email). */
    public function formReset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /** Verifikasi token lalu ganti kata sandi. */
    public function simpan(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', new PasswordKuat],
        ], [
            'token.required' => 'Token atur ulang tidak ditemukan.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            AuditLog::record('Reset Kata Sandi Berhasil', 0, null, sprintf(
                'Kata sandi akun %s berhasil diatur ulang.',
                (string) $request->input('email')
            ));

            return redirect()
                ->route('login')
                ->with('status', 'Kata sandi berhasil diubah. Silakan masuk dengan kata sandi baru Anda.');
        }

        AuditLog::record('Reset Kata Sandi Gagal', 0, null, sprintf(
            'Percobaan atur ulang kata sandi gagal untuk %s (status: %s).',
            (string) $request->input('email'),
            $status
        ));

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Tautan atur ulang kata sandi tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.']);
    }
}
