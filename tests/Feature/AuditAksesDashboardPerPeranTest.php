<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\User;
use App\Services\NavigationService;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUDIT MENYELURUH PERAN (ROLE) — DASHBOARD & HAK AKSES.
 *
 * Test ini adalah INSTRUMEN AUDIT, bukan pengunci perilaku ideal. Setiap
 * assertion diberi pesan yang menyebut peran + rute agar kegagalan langsung
 * terbaca sebagai temuan. Temuan yang memang berupa ketidaksesuaian desain
 * (mis. dasbor `admin` yang berbagi halaman) diuji sebagai PERILAKU SAAT INI
 * dan diberi komentar `TEMUAN F-xx` agar tidak dianggap regresi.
 *
 * Riwayat perbaikan: F-01 (peran `nasabah` tanpa tujuan setelah login sehingga
 * terjadi lingkar pengalihan) SUDAH DIPERBAIKI lewat T-1 — `nasabah` kini
 * diarahkan ke formulir pengajuan keanggotaan. Nasabah tetap NON-anggota:
 * nol izin transaksi, seluruh menu swalayan tetap 403.
 *
 * Cakupan: 9 peran pada `config/role_hierarki.php` — dps, pengurus, manajer,
 * ao, teller, super_admin, admin, anggota, nasabah.
 */
class AuditAksesDashboardPerPeranTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'audit-peran-uji';

    /** Seluruh peran yang dikenal sistem (urutan: level menurun). */
    private const PERAN = [
        'dps', 'pengurus', 'manajer', 'ao', 'teller',
        'super_admin', 'admin', 'anggota', 'nasabah',
    ];

    /** Peran → rute dashboard yang ditetapkan `role_hierarki.peran.*.dashboard_route`. */
    private const DASHBOARD = [
        'dps' => 'dashboard.dps',
        'pengurus' => 'dashboard.pengurus',
        'manajer' => 'dashboard.manajer',
        'ao' => 'dashboard.ao',
        'teller' => 'dashboard.teller',
        'super_admin' => 'dashboard.super-admin',
        'admin' => 'admin.settings.index', // F-02: bukan dasbor eksklusif
        'anggota' => 'dashboard.nasabah',
        // T-1 (DIPERBAIKI): nasabah bukan anggota, jadi diarahkan ke formulir
        // pengajuan keanggotaan (rute publik) alih-alih lingkar pengalihan.
        'nasabah' => 'pengajuan-rekening.publik.form',
    ];

    /** Dashboard yang benar-benar EKSKLUSIF milik satu peran. */
    private const DASHBOARD_EKSKLUSIF = [
        'dps' => 'dashboard.dps',
        'pengurus' => 'dashboard.pengurus',
        'manajer' => 'dashboard.manajer',
        'ao' => 'dashboard.ao',
        'teller' => 'dashboard.teller',
        'super_admin' => 'dashboard.super-admin',
        'anggota' => 'dashboard.nasabah',
    ];

    /** Endpoint potongan HTML (denyut) dashboard. */
    private const RINGKAS = [
        'dps' => 'dashboard.dps.ringkas',
        'pengurus' => 'dashboard.pengurus.ringkas',
        'manajer' => 'dashboard.manajer.ringkas',
        'ao' => 'dashboard.ao.ringkas',
        'teller' => 'dashboard.teller.ringkas',
        'super_admin' => 'dashboard.super-admin.ringkas',
        'anggota' => 'nasabah.dashboard.ringkas',
    ];

    /** @var array<string, User> */
    private array $pengguna = [];

    /* ═══════════════════════════════════════════════════════════════════
     * FIXTURE
     * ═══════════════════════════════════════════════════════════════════ */

    private function pengguna(string $role): User
    {
        if (isset($this->pengguna[$role])) {
            return $this->pengguna[$role];
        }

        $user = User::firstOrCreate(
            ['email' => 'audit.role.'.$role.'@koperasi.test'],
            [
                'name' => 'Audit '.strtoupper($role),
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

        return $this->pengguna[$role] = $user;
    }

    /** Dashboard swalayan menolak akun tanpa baris `anggota` (403). */
    private function siapkanAnggota(User $user): Anggota
    {
        return Anggota::firstOrCreate(
            ['user_id' => $user->id],
            [
                'no_anggota' => 'AG-AUDIT-'.$user->id,
                'nama_lengkap' => 'Anggota Audit '.$user->id,
                'nik' => str_pad((string) $user->id, 16, '0', STR_PAD_LEFT),
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Audit',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ],
        );
    }

    private function masuk(string $role): User
    {
        $user = $this->pengguna($role);

        if ($role === 'anggota') {
            $this->siapkanAnggota($user);
        }

        $this->actingAs($user);

        return $user;
    }

    private function lupakanSesi(): void
    {
        $this->app['auth']->forgetGuards();
        session()->flush();
    }

    /** Seluruh nama rute yang tampil di sidebar peran (rekursif). */
    private function ruteMenu(string $role): array
    {
        $hasil = [];
        $walk = function (array $items) use (&$walk, &$hasil) {
            foreach ($items as $item) {
                if (! empty($item['route'])) {
                    $hasil[] = $item['route'];
                }
                if (! empty($item['children'])) {
                    $walk($item['children']);
                }
            }
        };

        $walk(NavigationService::getMenu($role));

        return array_values(array_unique($hasil));
    }

    /* ═══════════════════════════════════════════════════════════════════
     * LANGKAH 1 — INVENTARIS PERAN & DASHBOARD
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_inventaris_sembilan_peran_dan_pemetaan_dashboard(): void
    {
        $terdaftar = HierarkiRole::peran();

        foreach (self::PERAN as $role) {
            $this->assertContains($role, $terdaftar, "Peran {$role} tidak terdaftar di role_hierarki.");
            $this->assertSame(
                self::DASHBOARD[$role],
                HierarkiRole::dashboardRoute($role),
                "Pemetaan dashboard peran {$role} tidak sesuai.",
            );
        }

        // T-1 (DIPERBAIKI) — `nasabah` kini punya tujuan setelah login:
        // formulir pengajuan keanggotaan, bukan lingkar pengalihan.
        $this->assertSame(
            'pengajuan-rekening.publik.form',
            HierarkiRole::dashboardRoute('nasabah'),
            'T-1: nasabah harus diarahkan ke formulir pengajuan keanggotaan.',
        );

        foreach (self::DASHBOARD as $role => $rute) {
            if ($rute === null) {
                continue;
            }
            $this->assertTrue(Route::has($rute), "Rute dashboard {$rute} untuk {$role} tidak terdaftar.");
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * LANGKAH 2 — VERIFIKASI DASHBOARD PER PERAN
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_dashboard_sendiri_dapat_dimuat_untuk_setiap_peran(): void
    {
        foreach (self::DASHBOARD as $role => $rute) {
            if ($rute === null) {
                continue;
            }

            $this->masuk($role);
            $respons = $this->get(route($rute));

            $this->assertSame(
                200,
                $respons->status(),
                "Peran {$role} gagal memuat dashboard {$rute} (HTTP {$respons->status()}).",
            );

            $this->lupakanSesi();
        }
    }

    public function test_endpoint_ringkas_dashboard_dapat_dimuat(): void
    {
        foreach (self::RINGKAS as $role => $rute) {
            $this->masuk($role);
            $respons = $this->get(route($rute));

            $this->assertSame(
                200,
                $respons->status(),
                "Peran {$role} gagal memuat endpoint ringkas {$rute} (HTTP {$respons->status()}).",
            );
            $this->assertTrue(
                $respons->headers->get('content-type') !== null
                    && str_contains((string) $respons->headers->get('content-type'), 'application/json'),
                "Endpoint ringkas {$rute} tidak mengembalikan JSON.",
            );

            $this->lupakanSesi();
        }
    }

    public function test_dashboard_peran_lain_selalu_ditolak(): void
    {
        foreach (self::PERAN as $role) {
            $this->masuk($role);

            foreach (self::DASHBOARD_EKSKLUSIF as $pemilik => $rute) {
                if ($pemilik === $role) {
                    continue;
                }

                $respons = $this->get(route($rute));
                $this->assertSame(
                    403,
                    $respons->status(),
                    "Kebocoran akses: peran {$role} dapat membuka dashboard {$pemilik} ({$rute}).",
                );
            }

            $this->lupakanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * LANGKAH 3 — UJI SELURUH TAUTAN MENU (FITUR DASHBOARD)
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_seluruh_tautan_menu_peran_dapat_dibuka(): void
    {
        foreach (self::PERAN as $role) {
            if ($role === 'nasabah') {
                continue; // F-01 diuji terpisah: seluruh menu memang 403.
            }

            $this->masuk($role);

            foreach ($this->ruteMenu($role) as $nama) {
                $respons = $this->get(route($nama));

                $this->assertSame(
                    200,
                    $respons->status(),
                    "Menu {$role} → {$nama} gagal dimuat (HTTP {$respons->status()}).",
                );
            }

            $this->lupakanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * F-01 (DIPERBAIKI lewat T-1) — PERAN NASABAH
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_nasabah_diarahkan_ke_formulir_pengajuan_dan_menu_tetap_ditolak(): void
    {
        // Nasabah adalah NON-anggota (OJK-06): tidak ada izin yang mencakupnya.
        $this->assertNotContains(
            'nasabah',
            Izin::peran('nasabah.swalayan'),
            'OJK-06: nasabah bukan anggota, jadi tidak boleh memakai izin swalayan.',
        );

        $this->masuk('nasabah');

        // T-1 — `GET /` tidak lagi memutar balik ke `/`; nasabah diarahkan ke
        // formulir pengajuan keanggotaan (rute publik, tanpa gerbang izin).
        $responsAkar = $this->get('/');
        $this->assertSame(302, $responsAkar->status(), 'Nasabah seharusnya dialihkan dari akar situs.');
        $this->assertSame(
            route('pengajuan-rekening.publik.form'),
            $responsAkar->headers->get('Location'),
            'T-1: nasabah harus diarahkan ke formulir pengajuan, bukan ke halaman yang sama.',
        );

        // Formulir pengajuan memang dapat dibuka (bukan 403) setelah login.
        $this->assertSame(
            200,
            $this->get(route('pengajuan-rekening.publik.form'))->status(),
            'T-1: nasabah harus dapat membuka formulir pengajuan keanggotaan.',
        );

        // Meski punya tujuan, nasabah TETAP tidak berwenang atas dasbor anggota.
        $this->assertSame(
            403,
            $this->get(route('dashboard.nasabah'))->status(),
            'OJK-06: nasabah tidak boleh membuka dashboard.nasabah.',
        );

        // Menu alias menampilkan 6 tautan, seluruhnya 403.
        foreach ($this->ruteMenu('nasabah') as $nama) {
            $this->assertSame(
                403,
                $this->get(route($nama))->status(),
                "OJK-06: tautan menu nasabah {$nama} seharusnya ditolak (403).",
            );
        }

        $this->lupakanSesi();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TEMUAN F-02 — DASHBOARD ADMIN BERBAGI HALAMAN
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_temuan_dashboard_admin_berbagi_halaman_dengan_pengurus_dan_super_admin(): void
    {
        foreach (['admin', 'pengurus', 'super_admin'] as $role) {
            $this->masuk($role);

            $this->assertSame(
                200,
                $this->get(route('admin.settings.index'))->status(),
                "Peran {$role} seharusnya dapat membuka admin.settings.index.",
            );

            $this->lupakanSesi();
        }

        // Tidak ada izin dasbor khusus admin.
        $this->assertFalse(
            Izin::ada('dasbor.admin'),
            'TEMUAN F-02: admin belum punya izin dasbor eksklusif.',
        );
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TEMUAN F-03 — AKSES OPERASIONAL LOKET DI LUAR MENU
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_temuan_teller_loket_dapat_diakses_manajer_dan_pengurus_tanpa_menu(): void
    {
        $ruteLoket = [
            'teller.kas.opening',
            'teller.setor',
            'teller.tarik',
            'teller.kas.closing',
        ];

        foreach (['manajer', 'pengurus'] as $role) {
            $this->masuk($role);

            $menu = $this->ruteMenu($role);

            foreach ($ruteLoket as $nama) {
                $this->assertSame(
                    200,
                    $this->get(route($nama))->status(),
                    "TEMUAN F-03: {$role} tidak dapat membuka {$nama} padahal izin teller.loket dimiliki.",
                );
                $this->assertNotContains(
                    $nama,
                    $menu,
                    "TEMUAN F-03: {$nama} dapat diakses {$role} tetapi tidak ada di sidebar.",
                );
            }

            $this->lupakanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TEMUAN F-04/F-07 — IZIN OPERASIONAL PADA PERAN SISTEM & PENGAWAS
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_temuan_izin_operasional_pada_peran_non_operasional(): void
    {
        // F-04: super_admin dapat membuka halaman setoran angsuran (fungsi teller).
        $this->masuk('super_admin');
        $this->assertSame(
            200,
            $this->get(route('pengurus.angsuran.index'))->status(),
            'TEMUAN F-04: super_admin tidak dapat membuka pengurus.angsuran.index.',
        );
        $this->lupakanSesi();

        // F-07 (SELESAI, A6): admin (administrator sistem) memegang
        // `autodebit.konfirmasi` — wewenang itu nyata, jadi halamannya harus
        // dapat dibuka DAN ditemukan dari sidebar. Temuan lama "dapat diakses
        // tetapi tidak ada di menu" sudah ditutup dengan menambahkan entri
        // Pusat Konfirmasi Auto-Debit pada menu admin.
        $this->masuk('admin');
        $this->assertSame(
            200,
            $this->get(route('autodebit.index'))->status(),
            'F-07: admin tidak dapat membuka autodebit.index padahal memegang izinnya.',
        );
        $this->assertContains(
            'autodebit.index',
            $this->ruteMenu('admin'),
            'F-07: admin berwenang mengonfirmasi auto-debit, jadi menunya wajib ada di sidebar.',
        );
        $this->lupakanSesi();

        // F-07b: manajer dapat mengelola zakat (termasuk aksi tulis) tanpa menu.
        $this->masuk('manajer');
        $this->assertSame(
            200,
            $this->get(route('zakat.index'))->status(),
            'TEMUAN F-07b: manajer tidak dapat membuka zakat.index.',
        );
        $this->assertNotContains(
            'zakat.index',
            $this->ruteMenu('manajer'),
            'TEMUAN F-07b: zakat.index dapat diakses manajer tetapi tidak ada di sidebar.',
        );
        $this->lupakanSesi();

        // F-07c: DPS memegang ckpn.lihat tetapi tidak punya tautan CKPN.
        $this->masuk('dps');
        $this->assertSame(
            200,
            $this->get(route('laporan.ckpn'))->status(),
            'TEMUAN F-07c: dps tidak dapat membuka laporan.ckpn.',
        );
        $this->assertNotContains(
            'laporan.ckpn',
            $this->ruteMenu('dps'),
            'TEMUAN F-07c: laporan.ckpn dapat diakses dps tetapi tidak ada di sidebar.',
        );
        $this->lupakanSesi();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TEMUAN F-06 — FITUR MANAJER YANG DISEMBUNYIKAN
     * ═══════════════════════════════════════════════════════════════════ */

    public function test_temuan_fitur_manajer_tersembunyi_dialihkan_bukan_403(): void
    {
        $this->masuk('manajer');

        foreach ([
            'manajer.audit.index',
            'manajer.kas.index',
            'manajer.bagi-hasil.index',
            'manajer.kinerja-staf.index',
            'manajer.laporan.index',
        ] as $nama) {
            $respons = $this->get(route($nama));

            // Perilaku saat ini: pengalihan ramah ke dashboard (302), bukan 403.
            $this->assertSame(
                302,
                $respons->status(),
                "TEMUAN F-06: {$nama} seharusnya dialihkan (302) untuk manajer.",
            );
        }

        $this->lupakanSesi();
    }
}
