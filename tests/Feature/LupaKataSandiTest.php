<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * C1 — Fitur "Lupa kata sandi".
 *
 * Mengunci kontrak alur atur ulang kata sandi berbasis broker `users`
 * (`config/auth.php`) yang sebelumnya tidak berfungsi karena tabel
 * `password_reset_tokens` belum ada:
 *
 *  1. Tautan tampil di halaman login (tidak lagi tersembunyi).
 *  2. Permintaan tautan mengirim notifikasi reset ke email terdaftar.
 *  3. Email TIDAK terdaftar tetap mendapat pesan yang sama (anti-enumerasi).
 *  4. Token sah dapat mengganti kata sandi; token salah ditolak.
 *  5. Kata sandi baru wajib lolos kebijakan `PasswordKuat`.
 *  6. Permintaan tautan dibatasi lajunya.
 *
 * Basis data memuat data nyata → memakai prefix unik + DatabaseTransactions.
 */
class LupaKataSandiTest extends TestCase
{
    use DatabaseTransactions;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'RESET'.strtoupper(substr(uniqid(), -6));
    }

    private function user(string $role = 'teller'): User
    {
        return User::create([
            'name' => 'Pengguna '.$this->prefix,
            'email' => strtolower($role).'.'.$this->prefix.'@test.local',
            'password' => 'rahasia-lama',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_tautan_lupa_kata_sandi_tampil_di_halaman_login(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Lupa kata sandi?')
            ->assertSee(route('password.request'), false);
    }

    public function test_halaman_lupa_kata_sandi_dapat_dibuka_tamu(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Alamat Email');
    }

    public function test_halaman_atur_ulang_dapat_dibuka_dengan_token(): void
    {
        $this->get(route('password.reset', ['token' => 'token-uji', 'email' => 'uji@test.local']))
            ->assertOk()
            ->assertSee('Kata Sandi Baru')
            ->assertSee('token-uji', false);
    }

    public function test_permintaan_tautan_mengirim_notifikasi_reset(): void
    {
        Notification::fake();

        $user = $this->user();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_email_atur_ulang_memakai_bahasa_indonesia(): void
    {
        $user = $this->user();

        $mail = (new ResetPassword(Password::broker()->createToken($user)))->toMail($user);
        $html = (string) $mail->render();

        // Pesan bawaan framework berbahasa Inggris; `lang/id.json` yang
        // diterapkan lewat `locale = id` harus menutup seluruh kalimatnya.
        $this->assertSame('Notifikasi Atur Ulang Kata Sandi', $mail->subject);
        $this->assertStringContainsString('permintaan atur ulang kata sandi untuk akun Anda', $html);
        $this->assertStringContainsString('kedaluwarsa dalam 60 menit', $html);
        $this->assertStringNotContainsString('You are receiving this email', $html);
        $this->assertStringNotContainsString('If you did not request a password reset', $html);
    }

    public function test_email_tidak_terdaftar_tetap_memberi_pesan_yang_sama(): void
    {
        Notification::fake();

        // Anti-enumerasi: penyerang tidak boleh bisa membedakan email
        // terdaftar dan tidak terdaftar dari respons yang diterima.
        $this->post(route('password.email'), ['email' => 'tidak.ada.'.$this->prefix.'@test.local'])
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_kata_sandi_dapat_diubah_dengan_token_sah(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'RahasiaBaru123',
            'password_confirmation' => 'RahasiaBaru123',
        ])->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(
            Hash::check('RahasiaBaru123', $user->password),
            'Kata sandi baru seharusnya tersimpan setelah token sah diverifikasi.'
        );
    }

    public function test_token_salah_ditolak_dan_kata_sandi_tidak_berubah(): void
    {
        $user = $this->user();

        $this->post(route('password.update'), [
            'token' => 'token-palsu',
            'email' => $user->email,
            'password' => 'RahasiaBaru123',
            'password_confirmation' => 'RahasiaBaru123',
        ])->assertSessionHasErrors('email');

        $user->refresh();

        $this->assertTrue(
            Hash::check('rahasia-lama', $user->password),
            'Kata sandi lama harus tetap berlaku ketika token ditolak.'
        );
    }

    public function test_kata_sandi_baru_lemah_ditolak_kebijakan(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ab1',
            'password_confirmation' => 'ab1',
        ])->assertSessionHasErrors('password');

        $user->refresh();

        $this->assertTrue(Hash::check('rahasia-lama', $user->password));
    }

    public function test_konfirmasi_kata_sandi_tidak_cocok_ditolak(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'RahasiaBaru123',
            'password_confirmation' => 'RahasiaBaru124',
        ])->assertSessionHasErrors('password');

        $user->refresh();

        $this->assertTrue(Hash::check('rahasia-lama', $user->password));
    }

    public function test_permintaan_tautan_dibatasi_lima_percobaan_per_menit(): void
    {
        Notification::fake();

        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => $user->email])
                ->assertSessionHas('status');
        }

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertStatus(429);
    }
}
