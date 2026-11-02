<?php

namespace Tests\Feature;

use App\Http\Controllers\WebAuthController;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\HierarkiRole;
use App\Support\Izin;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * REGRESI — peran `super_admin` harus utuh: menu, wewenang, dan anti-403.
 *
 * Akar masalah yang dijaga berkas ini meniru NavigasiAdminMenuTest, sebab
 * `super_admin` memakai ulang banyak halaman peran lain (Pengurus/Pengaturan/
 * Admin). Bila menu memuat tautan yang ber-guard `role:` tanpa menyertakan
 * `super_admin`, setiap klik berakhir 403 "Akses Ditolak".
 *
 * INVARIANT yang dikunci (dibaca dari sumber kebenaran, bukan daftar yang
 * dipatok ulang, sehingga menu/rute baru otomatis ikut diuji):
 *   1. Setiap rute GET pada menuSuperAdmin() WAJIB ber-guard `role:` yang
 *      memuat 'super_admin' — atau tanpa guard peran sama sekali.
 *   2. Setiap tautan GET tanpa-parameter dapat BENAR-BENAR dibuka (200),
 *      bukan hanya lolos guard.
 *   3. `super_admin` adalah PUNCAK ADMINISTRATIF: level tertinggi dalam
 *      HierarkiRole (di atas DPS dan seluruh peran administratif lain),
 *      namun tetap BUKAN penyetuju transaksi.
 *   4. Login super_admin diarahkan ke dashboard-nya sendiri, bukan loop
 *      kembali ke /login.
 *   5. Setiap peran ENUM punya pemetaan redirect eksplisit (anti-loop login).
 *   6. Role tak dikenal tidak memasang menu (aman) — tidak crash.
 */
class NavigasiSuperAdminMenuTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Kumpulkan seluruh nama rute daun pada menu sebuah peran.
     *
     * @return list<string>
     */
    private function ruteDaun(array $menu): array
    {
        $nama = [];

        foreach ($menu as $item) {
            if (! empty($item['children'])) {
                foreach ($item['children'] as $child) {
                    if (! empty($child['route'])) {
                        $nama[] = $child['route'];
                    }
                }

                continue;
            }

            if (! empty($item['route'])) {
                $nama[] = $item['route'];
            }
        }

        return $nama;
    }

    /**
     * Daftar peran yang diizinkan pada middleware `role:`/`izin:` sebuah rute,
     * atau null bila rute tidak dibatasi peran (bebas).
     *
     * DYN-18: `izin:` diresolusi lewat SSOT config/izin.php (irisan bila
     * sebuah rute dijaga beberapa izin).
     *
     * @return list<string>|null
     */
    private function peranDiizinkan(LaravelRoute $route): ?array
    {
        $izin = [];

        foreach ($route->gatherMiddleware() as $mw) {
            if (! is_string($mw)) {
                continue;
            }

            if (str_starts_with($mw, 'role:')) {
                return array_map('trim', explode(',', substr($mw, 5)));
            }

            if (str_starts_with($mw, 'izin:')) {
                $izin = array_merge($izin, array_map('trim', explode(',', substr($mw, 5))));
            }
        }

        return $izin === [] ? null : Izin::peranEfektif($izin);
    }

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'nav.super_admin@example.test'],
            [
                'name' => 'Nav Super Admin',
                'password' => Hash::make('secret'),
                'role' => 'super_admin',
                'is_active' => true,
            ]
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 1. Menu super_admin tersedia (tidak kosong)
    // ───────────────────────────────────────────────────────────────
    public function test_menu_super_admin_tidak_kosong(): void
    {
        $menu = NavigationService::getMenu('super_admin');

        $this->assertNotEmpty(
            $menu,
            'Menu super_admin tidak boleh kosong — ia peran administratif sah dengan hierarki tertinggi.'
        );

        $this->assertNotEmpty(
            $this->ruteDaun($menu),
            'Menu super_admin harus memuat minimal satu tautan rute.'
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 2. INVARIANT: setiap rute menu super_admin benar-benar boleh diakses
    // ───────────────────────────────────────────────────────────────
    public function test_setiap_rute_menu_super_admin_diizinkan_role_super_admin(): void
    {
        $semua = RouteFacade::getRoutes();
        $pelanggaran = [];

        foreach ($this->ruteDaun(NavigationService::getMenu('super_admin')) as $nama) {
            $route = $semua->getByName($nama);

            if ($route === null) {
                $pelanggaran[] = "rute {$nama} TIDAK TERDAFTAR";

                continue;
            }

            $diizinkan = $this->peranDiizinkan($route);

            if ($diizinkan !== null && ! in_array('super_admin', $diizinkan, true)) {
                $pelanggaran[] = sprintf(
                    '%s hanya untuk role:%s',
                    $nama,
                    implode(',', $diizinkan)
                );
            }
        }

        $this->assertSame(
            [],
            $pelanggaran,
            "Menu super_admin menautkan rute di luar wewenangnya:\n".implode("\n", $pelanggaran)
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 3. Dashboard Super Admin memang dapat diakses
    // ───────────────────────────────────────────────────────────────
    public function test_super_admin_dapat_membuka_dashboard(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('dashboard.super-admin'))
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────────────
    // 3b. Endpoint real-time (ringkas) mengembalikan JSON berisi HTML
    //     potongan untuk setiap sasaran denyut.
    // ───────────────────────────────────────────────────────────────
    public function test_super_admin_dapat_memuat_ringkasan_real_time(): void
    {
        $respons = $this->actingAs($this->superAdmin())
            ->get(route('dashboard.super-admin.ringkas'));

        $respons->assertOk();

        foreach (['stats', 'notifikasi', 'keuangan', 'kolektibilitas', 'aktivitas', 'sistem'] as $sasaran) {
            $respons->assertJsonPath("html.{$sasaran}", fn ($nilai) => is_string($nilai) && $nilai !== '');
        }
    }

    // ───────────────────────────────────────────────────────────────
    // 3c. Setiap tautan GET tanpa-parameter pada menu super_admin dapat
    //     dibuka (bukan hanya lolos guard `role:`, tapi benar-benar 200).
    // ───────────────────────────────────────────────────────────────
    public function test_super_admin_dapat_membuka_seluruh_tautan_menu_tanpa_parameter(): void
    {
        $semua = RouteFacade::getRoutes();
        $gagal = [];

        foreach ($this->ruteDaun(NavigationService::getMenu('super_admin')) as $nama) {
            $route = $semua->getByName($nama);

            if ($route === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (preg_match('/\{[^}]+\}/', $route->uri())) {
                continue;
            }

            $status = $this->actingAs($this->superAdmin())
                ->get('/'.ltrim($route->uri(), '/'))
                ->getStatusCode();

            if ($status === 403) {
                $gagal[] = sprintf('%s (%s)', $nama, $route->uri());
            }
        }

        $this->assertSame(
            [],
            $gagal,
            "Super Admin menerima 403 pada halaman yang ditawarkan menunya:\n".implode("\n", $gagal)
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 4. Hierarki: super_admin adalah PUNCAK ADMINISTRATIF (level tertinggi,
    //    di atas DPS), namun TIDAK menjadi penyetuju transaksi.
    // ───────────────────────────────────────────────────────────────
    public function test_hierarki_super_admin_berada_di_puncak_administratif(): void
    {
        $levelSuper = HierarkiRole::level('super_admin');
        $levelAdmin = HierarkiRole::level('admin');
        $levelAnggota = HierarkiRole::level('anggota');
        $levelDps = HierarkiRole::level('dps');

        // Level TERTINGGI: di atas admin, anggota, dan juga DPS.
        $this->assertGreaterThan($levelAdmin, $levelSuper, 'super_admin harus di atas admin.');
        $this->assertGreaterThan($levelAnggota, $levelSuper, 'super_admin harus di atas anggota.');
        $this->assertGreaterThan($levelDps, $levelSuper, 'super_admin adalah puncak administratif, di atas DPS.');

        foreach (['pengurus', 'manajer', 'ao', 'teller'] as $peran) {
            $this->assertGreaterThan(
                HierarkiRole::level($peran),
                $levelSuper,
                "super_admin harus di atas peran {$peran}."
            );
        }

        // Super Admin sengaja TIDAK berada di rantai penyetuju transaksi.
        $this->assertFalse(
            HierarkiRole::penyetuju('super_admin'),
            'Super Admin bukan penyetuju transaksi (pemisahan tugas maker-checker).'
        );

        // Elemen terakhir daftar peran adalah super_admin.
        $peran = HierarkiRole::peran();
        $this->assertSame('super_admin', end($peran));
    }

    // ───────────────────────────────────────────────────────────────
    // 5. Authorization matriks: super_admin TIDAK boleh mengubah/menghapus
    //    data lewat modul laporan (pemisahan tugas maker-checker).
    // ───────────────────────────────────────────────────────────────
    public function test_super_admin_tidak_boleh_edit_hapus_modul_laporan(): void
    {
        foreach (LaporanKeuanganPermission::modulLaporan() as $modul) {
            $this->assertFalse(
                LaporanKeuanganPermission::boleh(
                    'super_admin',
                    $modul,
                    LaporanKeuanganPermission::EDIT
                ),
                "super_admin tidak boleh EDIT modul laporan '{$modul}'."
            );

            $this->assertFalse(
                LaporanKeuanganPermission::boleh(
                    'super_admin',
                    $modul,
                    LaporanKeuanganPermission::HAPUS
                ),
                "super_admin tidak boleh HAPUS modul laporan '{$modul}'."
            );
        }
    }

    // ───────────────────────────────────────────────────────────────
    // 6. Login super_admin diarahkan ke dashboard sendiri (anti-loop)
    // ───────────────────────────────────────────────────────────────
    public function test_login_super_admin_diarahkan_ke_dashboard(): void
    {
        $respons = $this->post('/login', [
            'email' => $this->superAdmin()->email,
            'password' => 'secret',
        ]);

        $this->assertAuthenticated();

        $this->assertNotSame(
            route('login'),
            $respons->getTargetUrl(),
            'Super Admin terautentikasi TIDAK boleh dilempar kembali ke /login (loop).'
        );

        $respons->assertRedirect(route('dashboard.super-admin'));
    }

    // ───────────────────────────────────────────────────────────────
    // 7. Kontrak pemetaan redirect per peran — setiap peran ENUM punya
    //    tujuan eksplisit, tidak mengandalkan `default`.
    // ───────────────────────────────────────────────────────────────
    public function test_redirect_by_role_super_admin(): void
    {
        $controller = app(WebAuthController::class);

        $this->assertSame(
            route('dashboard.super-admin'),
            $controller->redirectByRole($this->superAdmin())->getTargetUrl()
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 8. Role tak dikenal tidak memasang menu (aman) — tidak crash
    // ───────────────────────────────────────────────────────────────
    public function test_role_tak_dikenal_tidak_memuat_menu_dan_tidak_crash(): void
    {
        $this->assertSame([], NavigationService::getMenu('role-tidak-ada'));
    }
}
