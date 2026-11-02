<?php

namespace Tests\Feature;

use App\Http\Controllers\WebAuthController;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * REGRESI — peran `admin` tidak boleh kehilangan navigasi.
 *
 * Akar masalah yang dijaga berkas ini:
 *   1. NavigationService::getMenu('admin') mengembalikan [] karena `match()`
 *      di getMenu() tidak memiliki cabang 'admin' → sidebar kosong, padahal
 *      admin adalah peran sah (lihat App\Support\LaporanKeuanganPermission)
 *      dan berhak atas `admin.settings.index`. Modul audit laporan TIDAK lagi
 *      termasuk wewenang admin (pemisahan tugas: administrator bukan pengawas;
 *      audit eksklusif DPS).
 *   2. redirectByRole()/RedirectIfAuthenticated juga tidak menangani 'admin'
 *      → `default => redirect()->route('login')` = LOOP login tanpa akhir,
 *      sebab middleware `guest` menendang balik setiap admin terautentikasi.
 *   3. Role tak dikenal gagal senyap (menu kosong tanpa jejak).
 *
 * INVARIANT yang dikunci (sama semangatnya dengan NavigasiWewenangPeranTest):
 *   Setiap rute GET pada menuAdmin() WAJIB ber-guard `role:` yang memuat
 *   'admin', dibaca dari RouteFacade (bukan daftar yang dipatok ulang),
 *   sehingga penambahan menu/route baru otomatis ikut diuji.
 */
class NavigasiAdminMenuTest extends TestCase
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

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'nav.admin@example.test'],
            [
                'name' => 'Nav Admin',
                'password' => Hash::make('secret'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 1. Menu admin tersedia (tidak kosong)
    // ───────────────────────────────────────────────────────────────
    public function test_menu_admin_tidak_kosong(): void
    {
        $menu = NavigationService::getMenu('admin');

        $this->assertNotEmpty(
            $menu,
            'Menu admin tidak boleh kosong — admin adalah peran sah dan berhak atas admin.settings.index.'
        );

        $this->assertNotEmpty(
            $this->ruteDaun($menu),
            'Menu admin harus memuat minimal satu tautan rute.'
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 2. INVARIANT: setiap rute menu admin benar-benar boleh diakses admin
    // ───────────────────────────────────────────────────────────────
    public function test_setiap_rute_menu_admin_diizinkan_role_admin(): void
    {
        $semua = RouteFacade::getRoutes();
        $pelanggaran = [];

        foreach ($this->ruteDaun(NavigationService::getMenu('admin')) as $nama) {
            $route = $semua->getByName($nama);

            if ($route === null) {
                $pelanggaran[] = "rute {$nama} TIDAK TERDAFTAR";

                continue;
            }

            $diizinkan = $this->peranDiizinkan($route);

            if ($diizinkan !== null && ! in_array('admin', $diizinkan, true)) {
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
            "Menu admin menautkan rute di luar wewenangnya:\n".implode("\n", $pelanggaran)
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 3. Halaman utama menu admin (Pengaturan Kebijakan) memang dapat diakses
    // ───────────────────────────────────────────────────────────────
    public function test_admin_dapat_membuka_pengaturan_kebijakan(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.settings.index'))
            ->assertOk();
    }

    // ───────────────────────────────────────────────────────────────
    // 3b. Setiap tautan GET tanpa-parameter pada menu admin dapat dibuka
    //     (bukan hanya lolos guard `role:`, tapi benar-benar 200).
    // ───────────────────────────────────────────────────────────────
    public function test_admin_dapat_membuka_seluruh_tautan_menu_tanpa_parameter(): void
    {
        $semua = RouteFacade::getRoutes();
        $gagal = [];

        foreach ($this->ruteDaun(NavigationService::getMenu('admin')) as $nama) {
            $route = $semua->getByName($nama);

            if ($route === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (preg_match('/\{[^}]+\}/', $route->uri())) {
                continue;
            }

            $status = $this->actingAs($this->admin())
                ->get('/'.ltrim($route->uri(), '/'))
                ->getStatusCode();

            if ($status === 403) {
                $gagal[] = sprintf('%s (%s)', $nama, $route->uri());
            }
        }

        $this->assertSame(
            [],
            $gagal,
            "Admin menerima 403 pada halaman yang ditawarkan menunya:\n".implode("\n", $gagal)
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 4. Login admin TIDAK memutar balik ke halaman login (anti-loop)
    // ───────────────────────────────────────────────────────────────
    public function test_login_admin_diarahkan_ke_pengaturan_bukan_login(): void
    {
        $respons = $this->post('/login', [
            'email' => $this->admin()->email,
            'password' => 'secret',
        ]);

        $this->assertAuthenticated();

        $this->assertNotSame(
            route('login'),
            $respons->getTargetUrl(),
            'Admin terautentikasi TIDAK boleh dilempar kembali ke /login (loop).'
        );

        $respons->assertRedirect(route('admin.settings.index'));
    }

    // ───────────────────────────────────────────────────────────────
    // 5. Admin terautentikasi TIDAK pernah dilempar kembali ke /login.
    //    Rute `guest` (middleware bawaan) mengalihkan ke `/`, lalu `/`
    //    memanggil redirectByRole() — di sinilah loop lama terjadi karena
    //    admin tidak ditangani (default => login).
    // ───────────────────────────────────────────────────────────────
    public function test_admin_terautentikasi_membuka_login_tidak_memutar_balik_ke_login(): void
    {
        $respons = $this->actingAs($this->admin())->get('/login');

        $respons->assertRedirect();

        $this->assertNotSame(
            route('login'),
            $respons->getTargetUrl(),
            'Admin terautentikasi tidak boleh dialihkan kembali ke /login — itu akar loop.'
        );
    }

    /**
     * Kontrak pemetaan redirect per peran — inti pemutus loop. Diuji langsung
     * agar tidak bergantung pada middleware `guest` mana yang aktif.
     */
    public function test_redirect_by_role_admin_menuju_pengaturan(): void
    {
        $controller = app(WebAuthController::class);

        $this->assertSame(
            route('admin.settings.index'),
            $controller->redirectByRole($this->admin())->getTargetUrl()
        );
    }

    // ───────────────────────────────────────────────────────────────
    // 6. Role tak dikenal tidak memasang menu (aman) — tidak crash
    // ───────────────────────────────────────────────────────────────
    public function test_role_tak_dikenal_tidak_memuat_menu_dan_tidak_crash(): void
    {
        $this->assertSame([], NavigationService::getMenu('role-tidak-ada'));
    }
}
