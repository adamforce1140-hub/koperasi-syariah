<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NavigationService;
use App\Support\HierarkiRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PETA AKSES PERAN ↔ MENU (regresi konfigurasi akses).
 *
 * Mengunci konfigurasi akses pada LAPIS MENU & PENALIHAN sesuai peran standar
 * koperasi syariah. Melengkapi — bukan menggantikan — invariant rute↔guard di
 * `NavigasiWewenangPeranTest` dan E2E per peran di `DemonstrasiPerPeranTest`.
 *
 * Yang dikunci di sini (khususnya untuk peran `admin` & `anggota` yang belum
 * tercakup uji per-peran lain):
 *
 *  1. Setiap peran sah pada SSOT RBAC (`config/role_hierarki.php`) MEMILIKI
 *     menu (tidak ada peran yang jatuh ke `default => []`). Menambah peran baru
 *     tanpa menambah cabang di `NavigationService::getMenu()` akan menggagalkan
 *     uji ini. DYN-01: daftar peran dibaca dari SSOT, bukan dari ENUM skema.
 *  2. Setiap peran standar memiliki menu yang tidak kosong.
 *  3. `nasabah` adalah alias menu `anggota` (kompatibilitas data lama).
 *  4. Login tiap peran diarahkan ke dashboard yang benar.
 *  5. Setiap peran DITOLAK (403) saat membuka dashboard eksklusif peran lain.
 *
 * Catatan: test ini TIDAK mengubah kode produksi; ia membaca kebijakan yang
 * berlaku sehingga bila matriks izin berubah, test mengikuti.
 */
class PetaAksesPeranMenuTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'password-peta-uji';

    /** Delapan peran standar (di luar alias legacy `nasabah`). */
    private const PERAN = [
        'super_admin', 'admin', 'dps', 'pengurus', 'manajer', 'ao', 'teller', 'anggota',
    ];

    /** Dashboard tujuan login tiap peran. */
    private const DASHBOARD_LOGIN = [
        'super_admin' => 'dashboard.super-admin',
        'admin' => 'admin.settings.index',
        'dps' => 'dashboard.dps',
        'pengurus' => 'dashboard.pengurus',
        'manajer' => 'dashboard.manajer',
        'ao' => 'dashboard.ao',
        'teller' => 'dashboard.teller',
        'anggota' => 'dashboard.nasabah',
    ];

    /**
     * Dashboard EKSKLUSIF tiap peran (guard `role:` hanya memuat pemiliknya).
     * Dipakai sebagai sasaran uji tolak-akses (403).
     */
    private const DASHBOARD_EKSKLUSIF = [
        'super_admin' => 'dashboard.super-admin',
        'dps' => 'dashboard.dps',
        'pengurus' => 'dashboard.pengurus',
        'manajer' => 'dashboard.manajer',
        'ao' => 'dashboard.ao',
        'teller' => 'dashboard.teller',
        'anggota' => 'dashboard.nasabah',
    ];

    private function user(string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => 'peta.'.$role.'@koperasi.test'],
            [
                'name' => 'Peta '.strtoupper($role),
                'password' => Hash::make(self::PASSWORD),
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->forceFill([
            'password' => Hash::make(self::PASSWORD),
            'role' => $role,
            'is_active' => true,
        ])->save();

        return $user;
    }

    private function bersihkanSesi(): void
    {
        $this->app['auth']->forgetGuards();
        session()->flush();
    }

    /**
     * Daftar peran sah — dibaca dari SSOT RBAC.
     *
     * DYN-01: `users.role` bukan lagi ENUM, sehingga daftar peran tidak dapat
     * diturunkan dari skema. Sumber kebenarannya adalah
     * `config/role_hierarki.php` melalui `HierarkiRole::peran()`.
     */
    private function nilaiPeranSah(): array
    {
        return HierarkiRole::peran();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 1. KELENGKAPAN KONFIGURASI MENU PER NILAI ENUM
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_setiap_peran_sah_memiliki_menu(): void
    {
        $peran = $this->nilaiPeranSah();

        $this->assertNotEmpty($peran, 'Daftar peran SSOT RBAC tidak terbaca.');

        foreach ($peran as $role) {
            $this->assertNotEmpty(
                NavigationService::getMenu($role),
                "Peran sah '{$role}' tidak memiliki menu — tambahkan cabang di NavigationService::getMenu()."
            );
        }
    }

    public function test_setiap_peran_standar_punya_menu_tidak_kosong(): void
    {
        foreach (self::PERAN as $role) {
            $this->assertNotEmpty(
                NavigationService::getMenu($role),
                "Menu peran standar '{$role}' kosong."
            );
        }
    }

    public function test_nasabah_memakai_menu_yang_sama_dengan_anggota(): void
    {
        $this->assertSame(
            NavigationService::getMenu('anggota'),
            NavigationService::getMenu('nasabah'),
            '`nasabah` harus menjadi alias menu `anggota` (kompatibilitas data lama).'
        );
    }

    public function test_role_tak_dikenal_tidak_memiliki_menu(): void
    {
        $this->assertSame([], NavigationService::getMenu('peran_tidak_dikenal'));
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. PENALIHAN LOGIN SESUAI PERAN
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_setiap_peran_diarahkan_ke_dashboard_yang_benar(): void
    {
        // POST /login ber-`throttle:5,1`; loop 8 peran akan memicu 429 palsu.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (self::PERAN as $role) {
            $user = $this->user($role);

            $respons = $this->post('/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ]);

            $respons->assertRedirect();
            $this->assertAuthenticatedAs($user);

            $this->assertSame(
                route(self::DASHBOARD_LOGIN[$role]),
                (string) $respons->headers->get('Location'),
                "Peran {$role} harus diarahkan ke ".self::DASHBOARD_LOGIN[$role].' setelah login.'
            );

            $this->post('/logout');
            $this->bersihkanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 3. PENOLAKAN AKSES (403) KE DASHBOARD PERAN LAIN
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_setiap_peran_ditolak_mengakses_dashboard_peran_lain(): void
    {
        foreach (self::PERAN as $role) {
            $pengguna = $this->user($role);

            foreach (self::DASHBOARD_EKSKLUSIF as $pemilik => $namaRute) {
                if ($pemilik === $role) {
                    continue;
                }

                $this->actingAs($pengguna)
                    ->get(route($namaRute))
                    ->assertForbidden();

                $this->bersihkanSesi();
            }
        }
    }
}
