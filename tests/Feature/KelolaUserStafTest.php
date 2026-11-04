<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\HierarkiRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KELOLA AKUN STAF (tambah user & reset kata sandi).
 *
 * Halaman `pengurus/users` sebelumnya hanya dapat mengubah peran — akun staf
 * baru dan kata sandi yang lupa harus diselesaikan lewat tinker/DB. Test ini
 * mengunci dua aksi tulis yang baru dibuka:
 *   1. Tambah akun staf oleh pemegang `master.data` (Super Admin), dengan
 *      password ter-hash, kebijakan PasswordKuat, dan audit log.
 *   2. Reset kata sandi pengguna lain, tetap menghormati
 *      `HierarkiRole::bolehMengubahPeran()` (peran puncak tak tersentuh).
 *   3. Peran non-`master.data` tetap 403.
 */
class KelolaUserStafTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::firstOrCreate(
            ['email' => 'kelola.staf.superadmin@test.local'],
            [
                'name' => 'Uji Kelola Staf Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );

        $this->pengurus = User::firstOrCreate(
            ['email' => 'kelola.staf.pengurus@test.local'],
            [
                'name' => 'Uji Kelola Staf Pengurus',
                'password' => Hash::make('password'),
                'role' => 'pengurus',
                'is_active' => true,
            ]
        );
    }

    protected function tearDown(): void
    {
        HierarkiRole::flush();
        parent::tearDown();
    }

    /* ══════════════════════════════════════════════════════════════════
     |  1. TAMBAH USER
     ══════════════════════════════════════════════════════════════════ */

    public function test_super_admin_dapat_menambah_user_staf(): void
    {
        $response = $this->actingAs($this->superAdmin)->post(route('pengurus.users.store'), [
            'name' => 'Teller Baru',
            'email' => 'teller.baru@test.local',
            'role' => 'teller',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ]);

        $response->assertRedirect(route('pengurus.users.index'));

        $user = User::where('email', 'teller.baru@test.local')->first();

        $this->assertNotNull($user, 'User baru harus tersimpan.');
        $this->assertSame('Teller Baru', $user->name);
        $this->assertSame('teller', $user->role);
        $this->assertTrue((bool) $user->is_active, 'Akun baru harus langsung aktif.');
        $this->assertNotSame('Rahasia123', $user->password, 'Password harus ter-hash.');
        $this->assertTrue(Hash::check('Rahasia123', $user->password));

        $this->assertTrue(
            AuditLog::where('keterangan', 'like', '%teller.baru@test.local%')->exists(),
            'Pembuatan akun harus tercatat di audit log.'
        );
    }

    public function test_email_duplikat_ditolak(): void
    {
        $this->actingAs($this->superAdmin)->post(route('pengurus.users.store'), [
            'name' => 'Duplikat',
            'email' => $this->pengurus->email,
            'role' => 'teller',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', $this->pengurus->email)->count());
    }

    public function test_peran_di_luar_kewenangan_aktor_ditolak(): void
    {
        // Bagi Super Admin seluruh peran sah, jadi uji peran tak dikenal.
        $this->actingAs($this->superAdmin)->post(route('pengurus.users.store'), [
            'name' => 'Peran Asing',
            'email' => 'peran.asing@test.local',
            'role' => 'dewa',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ])->assertSessionHasErrors('role');

        $this->assertNull(User::where('email', 'peran.asing@test.local')->first());
    }

    public function test_password_lemah_ditolak(): void
    {
        $this->actingAs($this->superAdmin)->post(route('pengurus.users.store'), [
            'name' => 'Sandi Lemah',
            'email' => 'sandi.lemah@test.local',
            'role' => 'teller',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'sandi.lemah@test.local')->first());
    }

    public function test_peran_tanpa_master_data_tidak_dapat_menambah_user(): void
    {
        $this->actingAs($this->pengurus)->post(route('pengurus.users.store'), [
            'name' => 'Selundupan',
            'email' => 'selundupan@test.local',
            'role' => 'teller',
            'password' => 'Rahasia123',
            'password_confirmation' => 'Rahasia123',
        ])->assertForbidden();

        $this->assertNull(User::where('email', 'selundupan@test.local')->first());
    }

    /* ══════════════════════════════════════════════════════════════════
     |  2. RESET KATA SANDI
     ══════════════════════════════════════════════════════════════════ */

    public function test_super_admin_dapat_mereset_kata_sandi_pengguna(): void
    {
        $response = $this->actingAs($this->superAdmin)->put(
            route('pengurus.users.kata-sandi', $this->pengurus),
            [
                'password' => 'BaruSekali123',
                'password_confirmation' => 'BaruSekali123',
            ]
        );

        $response->assertRedirect(route('pengurus.users.index'));

        $this->assertTrue(Hash::check('BaruSekali123', $this->pengurus->fresh()->password));
        $this->assertTrue(
            AuditLog::where('keterangan', 'like', '%'.$this->pengurus->email.'%')->exists(),
            'Reset kata sandi harus tercatat di audit log.'
        );
    }

    public function test_reset_kata_sandi_password_lemah_ditolak(): void
    {
        $lama = $this->pengurus->password;

        $this->actingAs($this->superAdmin)->put(
            route('pengurus.users.kata-sandi', $this->pengurus),
            ['password' => 'abc', 'password_confirmation' => 'abc']
        )->assertSessionHasErrors('password');

        $this->assertSame($lama, $this->pengurus->fresh()->password);
    }

    public function test_peran_tanpa_master_data_tidak_dapat_mereset_kata_sandi(): void
    {
        $lama = $this->superAdmin->password;

        $this->actingAs($this->pengurus)->put(
            route('pengurus.users.kata-sandi', $this->superAdmin),
            ['password' => 'BaruSekali123', 'password_confirmation' => 'BaruSekali123']
        )->assertForbidden();

        $this->assertSame($lama, $this->superAdmin->fresh()->password);
    }

    /* ══════════════════════════════════════════════════════════════════
     |  3. ANTARMUKA
     ══════════════════════════════════════════════════════════════════ */

    public function test_halaman_users_memuat_form_tambah_dan_tombol_reset(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('pengurus.users.index'))
            ->assertOk()
            ->assertSee('Tambah Akun Staf')
            ->assertSee(route('pengurus.users.store'), false)
            ->assertSee('Reset Sandi')
            // URL reset dipasang JS per baris (JSON ter-escape di HTML), jadi
            // yang diuji adalah keberadaan fungsi pemicunya.
            ->assertSee('bukaModalResetSandi', false)
            ->assertSee('kata-sandi', false);
    }
}
