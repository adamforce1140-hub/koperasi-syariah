<?php

namespace Tests\Feature;

use App\Models\JenisSimpanan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * DEMONSTRASI PENGUJIAN E2E PER PERAN (ROLE).
 *
 * Menguji secara nyata (HTTP request melalui stack middleware penuh) untuk
 * SETIAP peran fungsional sistem: dps, pengurus, manajer, ao, teller.
 *
 * Yang diverifikasi per peran:
 *  1. Otentikasi & pengalihan dashboard sesuai peran.
 *  2. Akses menu/halaman utama peran sendiri → HTTP 200.
 *  3. Penolakan akses (403) ke area peran lain (RBAC negatif).
 *  4. CRUD/aksi tulis sesuai izin peran.
 *  5. Ketersediaan endpoint denyut (real-time) untuk semua peran.
 *
 * Catatan: test ini TIDAK mengubah kode produksi; ia hanya membaca kebijakan
 * yang berlaku sehingga bila matriks izin berubah, test mengikuti.
 */
class DemonstrasiPerPeranTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'password-demo-uji';

    /** Urutan peran dari hierarki tertinggi. */
    private const PERAN = ['dps', 'pengurus', 'super_admin', 'manajer', 'ao', 'teller'];

    /** Halaman indeks utama per peran yang WAJIB dapat diakses (200). */
    private const HALAMAN_PERAN = [
        'teller' => [
            'dashboard.teller',
            'teller.cif.index',
            'teller.kas.opening',
        ],
        'ao' => [
            'dashboard.ao',
            'ao.pengajuan.index',
            'ao.survei.index',
            'ao.tracking.index',
            'ao.nasabah.index',
            'ao.tunggakan.index',
            'ao.restrukturisasi.index',
            'ao.keringanan-tazir.index',
        ],
        'manajer' => [
            'dashboard.manajer',
            'manajer.approval-pembiayaan.index',
            'manajer.approval-transaksi.index',
            'manajer.beban.index',
            'manajer.pembiayaan-aktif.index',
            'manajer.npf.index',
            'manajer.closing.index',
            'manajer.cif.index',
        ],
        'pengurus' => [
            'dashboard.pengurus',
            'pengurus.pembiayaan.index',
            'pengurus.approval.index',
            'pengurus.laporan.index',
            'pengurus.kas.index',
            'pengurus.kebijakan.index',
            'pengurus.aml.index',
        ],
        'dps' => [
            'dashboard.dps',
            'dps.audit-akad',
            'dps.review-produk.index',
            'dps.dana-kebajikan',
            'dps.beban.index',
            'dps.laporan-keuangan',
            'dps.zakat.index',
            'dps.pengesahan-akad',
        ],
        'super_admin' => [
            'dashboard.super-admin',
            'super-admin.kinerja-unit',
            'laporan.psak101',
            'laporan.neraca',
            'laporan.buku-besar',
            'pengurus.produk.index',
            'pengurus.jenis-simpanan.index',
            'pengurus.users.index',
            'admin.settings.index',
        ],
    ];

    /** Area yang HARUS ditolak (403) bagi peran tertentu. */
    private const HARUS_DITOLAK = [
        'teller' => ['dashboard.manajer', 'dashboard.pengurus', 'dashboard.dps', 'dashboard.ao'],
        'ao' => ['dashboard.teller', 'dashboard.manajer', 'dashboard.pengurus', 'dashboard.dps'],
        'manajer' => ['dashboard.teller', 'dashboard.ao', 'dashboard.pengurus', 'dashboard.dps'],
        'pengurus' => ['dashboard.teller', 'dashboard.ao', 'dashboard.manajer', 'dashboard.dps'],
        'dps' => ['dashboard.teller', 'dashboard.ao', 'dashboard.manajer', 'dashboard.pengurus'],
        // Super Admin adalah level sistem tertinggi, NAMUN dashboard operasional
        // peran lain tetap ber-guard eksklusif (role:teller/ao/manajer/...),
        // sehingga Super Admin tetap ditolak (403) di sana.
        'super_admin' => ['dashboard.teller', 'dashboard.ao', 'dashboard.manajer', 'dashboard.pengurus', 'dashboard.dps'],
    ];

    /** Hasil terukur per peran, dipakai untuk penegasan akhir. */
    private array $ringkasan = [];

    private function user(string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $role.'@koperasi.test'],
            [
                'name' => 'Uji '.strtoupper($role),
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

    /* ═══════════════════════════════════════════════════════════════════
     * 1. OTENTIKASI & PENALIHAN DASHBOARD PER PERAN
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_setiap_peran_dapat_login_dan_diarahkan_ke_dashboard_yang_benar(): void
    {
        // Rute POST /login ber-`throttle:5,1`; loop 6 peran akan memicu 429
        // palsu. Batas laju bukan objek uji di sini (diuji terpisah di
        // KeamananHeaderTest/Tahap5HardeningTest), jadi middleware-nya
        // dinonaktifkan agar penalihan per peran terukur apa adanya.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (self::PERAN as $role) {
            $user = $this->user($role);

            $respons = $this->post('/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ]);

            $respons->assertRedirect();
            $this->assertAuthenticatedAs($user);

            // Dashboard Super Admin memakai URI ber-tanda-hubung
            // (/super-admin/dashboard), sehingga pencocokan berbasis nama rute
            // lebih andal daripada substring '/{role}/'.
            $namaDashboard = $role === 'super_admin' ? 'dashboard.super-admin' : 'dashboard.'.$role;

            $this->assertSame(
                route($namaDashboard),
                (string) $respons->headers->get('Location'),
                "Peran {$role} harus diarahkan ke {$namaDashboard} setelah login."
            );

            $this->post('/logout');
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 2. AKSES HALAMAN UTAMA PER PERAN (HARUS 200)
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_halaman_utama_setiap_peran_dapat_diakses(): void
    {
        foreach (self::HALAMAN_PERAN as $role => $routes) {
            $user = $this->user($role);
            $sukses = 0;

            foreach ($routes as $nama) {
                $mulai = microtime(true);
                $respons = $this->actingAs($user)->get(route($nama));
                $ms = (microtime(true) - $mulai) * 1000;

                $this->assertSame(
                    200,
                    $respons->getStatusCode(),
                    "[{$role}] GET {$nama} harus 200, dapat {$respons->getStatusCode()}."
                );
                $sukses++;
                $this->catatWaktu($role, $nama, $ms);
            }

            $this->assertSame(count($routes), $sukses, "Semua halaman peran {$role} harus 200.");
            $this->ringkasan[$role]['halaman_ok'] = $sukses;

            $this->bersihkanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 3. RBAC NEGATIF — AKSES LINTAS PERAN HARUS DITOLAK (403)
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_setiap_peran_ditolak_mengakses_dashboard_peran_lain(): void
    {
        foreach (self::HARUS_DITOLAK as $role => $routesLarangan) {
            $user = $this->user($role);
            $ditolak = 0;

            foreach ($routesLarangan as $nama) {
                $respons = $this->actingAs($user)->get(route($nama));

                $this->assertSame(
                    403,
                    $respons->getStatusCode(),
                    "[{$role}] GET {$nama} harus 403 (RBAC), dapat {$respons->getStatusCode()}."
                );
                $ditolak++;
            }

            $this->assertSame(count($routesLarangan), $ditolak);
            $this->ringkasan[$role]['ditolak_ok'] = $ditolak;

            $this->bersihkanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 4. ENDPOINT REAL-TIME (DENYUT) TERSEDIA UNTUK SEMUA PERAN
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_endpoint_denyut_tersedia_untuk_semua_peran(): void
    {
        foreach (self::PERAN as $role) {
            $user = $this->user($role);

            $respons = $this->actingAs($user)->get(route('denyut'));
            $this->assertSame(200, $respons->getStatusCode(), "Denyut harus 200 untuk {$role}.");

            $this->bersihkanSesi();
        }
    }

    /* ═══════════════════════════════════════════════════════════════════
     * 5. AKSI TULIS (CRUD) SESUAI IZIN PERAN
     * ═══════════════════════════════════════════════════════════════════ */

    /**
     * Super Admin dapat menambah jenis simpanan (CREATE).
     *
     * PEMISAHAN TUGAS: parameter produk simpanan adalah konfigurasi sistem,
     * sehingga pengelolaannya ada di tangan administrator teknis (Super Admin),
     * bukan Pengurus yang hanya menetapkan kebijakan strategis.
     */
    public function test_super_admin_dapat_membuat_jenis_simpanan(): void
    {
        $user = $this->user('super_admin');

        // 'Simpanan Hari Raya' ada pada ENUM rekening.jenis_rekening namun belum
        // di-seed → aman untuk uji CREATE tanpa bentrok unique.
        $respons = $this->actingAs($user)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'tipe' => 'sukarela',
            'min_deposit_amount' => 10000,
            'is_active' => 1,
        ]);

        // Harus diarahkan kembali (sukses) atau 200, BUKAN 403/500.
        $this->assertNotSame(403, $respons->getStatusCode(), 'Super Admin berhak menyimpan jenis simpanan.');
        $this->assertNotSame(500, $respons->getStatusCode(), 'Tidak boleh galat server.');
        $this->assertContains($respons->getStatusCode(), [200, 302]);

        $this->assertDatabaseHas('jenis_simpanans', ['nama' => 'Simpanan Hari Raya']);

        $this->bersihkanSesi();
    }

    /** Super Admin dapat memperbarui (UPDATE) jenis simpanan yang dibuatnya. */
    public function test_super_admin_dapat_memperbarui_jenis_simpanan(): void
    {
        $user = $this->user('super_admin');

        $this->actingAs($user)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'tipe' => 'sukarela',
            'min_deposit_amount' => 10000,
            'is_active' => 1,
        ]);

        $jenis = JenisSimpanan::where('nama', 'Simpanan Hari Raya')->firstOrFail();

        $respons = $this->actingAs($user)->put(
            route('pengurus.jenis-simpanan.update', $jenis),
            [
                'nama' => 'Simpanan Hari Raya',
                'tipe' => 'sukarela',
                'min_deposit_amount' => 25000,
                'is_active' => 1,
            ]
        );

        $this->assertNotSame(500, $respons->getStatusCode(), 'UPDATE tidak boleh galat server.');
        $this->assertContains($respons->getStatusCode(), [200, 302]);
        $this->assertSame(
            25000.0,
            (float) $jenis->fresh()->min_deposit_amount,
            'Nilai minimum simpanan harus ter-update.'
        );

        $this->bersihkanSesi();
    }

    /** Super Admin dapat menghapus (DELETE) jenis simpanan yang belum dipakai rekening. */
    public function test_super_admin_dapat_menghapus_jenis_simpanan_belum_dipakai(): void
    {
        $user = $this->user('super_admin');

        $this->actingAs($user)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Simpanan Hari Raya',
            'tipe' => 'sukarela',
            'min_deposit_amount' => 10000,
            'is_active' => 1,
        ]);

        $jenis = JenisSimpanan::where('nama', 'Simpanan Hari Raya')->firstOrFail();

        $respons = $this->actingAs($user)
            ->delete(route('pengurus.jenis-simpanan.destroy', $jenis));

        $this->assertNotSame(500, $respons->getStatusCode(), 'DELETE tidak boleh galat server.');
        $this->assertDatabaseMissing('jenis_simpanans', ['id' => $jenis->id]);

        $this->bersihkanSesi();
    }

    /** AO tidak boleh mengakses pengaturan kebijakan pengurus. */
    public function test_ao_tidak_dapat_mengakses_pengaturan_pengurus(): void
    {
        $user = $this->user('ao');

        $this->actingAs($user)
            ->get(route('pengurus.jenis-simpanan.index'))
            ->assertStatus(403);

        $this->bersihkanSesi();
    }

    /** Teller tidak boleh mengakses area DPS. */
    public function test_teller_tidak_dapat_mengakses_area_dps(): void
    {
        $user = $this->user('teller');

        $this->actingAs($user)
            ->get(route('dps.review-produk.index'))
            ->assertStatus(403);

        $this->bersihkanSesi();
    }

    /** DPS bersifat read-only: tidak boleh memakai aksi tulis pengurus. */
    public function test_dps_tidak_dapat_melakukan_aksi_tulis_pengurus(): void
    {
        $user = $this->user('dps');

        $respons = $this->actingAs($user)->post(route('pengurus.jenis-simpanan.store'), [
            'nama' => 'Harusnya Ditolak '.uniqid(),
            'kode' => 'X'.strtoupper(substr(uniqid(), -4)),
            'is_active' => 1,
            'min_setoran' => 10000,
        ]);

        $this->assertSame(403, $respons->getStatusCode(), 'DPS read-only tidak boleh menulis.');

        $this->bersihkanSesi();
    }

    /** Manajer tidak boleh menembus batas ke area pengurus (persetujuan final). */
    public function test_manajer_tidak_dapat_mengakses_persetujuan_pengurus(): void
    {
        $user = $this->user('manajer');

        $this->actingAs($user)
            ->get(route('pengurus.pembiayaan.index'))
            ->assertStatus(403);

        $this->bersihkanSesi();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * PENEGASAN: SETIAP PERAN TERUJI TUNTAS
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_ringkasan_menunjukkan_semua_peran_teruji(): void
    {
        // Jalankan seluruh skenario dalam satu siklus agar ringkasan terisi.
        $this->test_halaman_utama_setiap_peran_dapat_diakses();
        $this->test_setiap_peran_ditolak_mengakses_dashboard_peran_lain();

        foreach (self::PERAN as $role) {
            $this->assertArrayHasKey($role, $this->ringkasan, "Peran {$role} belum diuji.");
            $this->assertGreaterThan(
                0,
                $this->ringkasan[$role]['halaman_ok'] ?? 0,
                "Peran {$role} harus punya halaman yang berhasil diakses."
            );
            $this->assertGreaterThan(
                0,
                $this->ringkasan[$role]['ditolak_ok'] ?? 0,
                "Peran {$role} harus punya area yang berhasil ditolak."
            );
        }
    }

    /* ─────────────────────────── UTILITAS ─────────────────────────── */

    private function catatWaktu(string $role, string $nama, float $ms): void
    {
        $this->ringkasan[$role]['waktu'][$nama] = round($ms, 2);

        // Anggaran respons halaman: 5 detik (longgar untuk basis data berisi
        // data demo lengkap + suite berjalan berurutan). Melebihi ini menandakan
        // kueri N+1 / kebocoran performa yang perlu ditindak lanjut.
        $this->assertLessThan(
            5000,
            $ms,
            "[{$role}] {$nama} memakan {$ms} ms — melebihi anggaran respons 5 detik."
        );
    }

    private function bersihkanSesi(): void
    {
        // Cegah kebocoran sesi antar-request dalam satu test.
        $this->app['auth']->forgetGuards();
        session()->flush();
    }
}
