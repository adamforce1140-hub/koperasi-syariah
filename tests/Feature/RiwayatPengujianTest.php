<?php

namespace Tests\Feature;

use App\Models\TestRun;
use App\Models\User;
use App\Services\NavigationService;
use App\Services\TestRunnerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * RIWAYAT PENGUJIAN — verifikasi kualitas sistem dari dalam aplikasi.
 *
 * Mengunci seluruh syarat fitur:
 *   1. Hanya `super_admin` yang dapat mengakses & menjalankan pengujian (RBAC).
 *      Verifikasi kualitas sistem = pemeliharaan teknis (IT), sehingga berada di
 *      Super Admin — bukan kewenangan kebijakan Pengurus (pemisahan tugas).
 *   2. Formulir menerima tanggal + modul, memvalidasi masukan dengan ketat.
 *   3. Hasil pengujian tersimpan sebagai riwayat (tabel `test_runs`) dan
 *      ditampilkan kembali di halaman.
 *   4. Definisi modul menunjuk berkas uji yang benar-benar ada.
 *   5. Keamanan perintah: target uji di luar folder `tests/` ditolak, dan
 *      modul tak dikenal ditolak — tanpa menyentuh subproses.
 *
 * Pengujian TIDAK menjalankan PHPUnit sungguhan (berat & rekursif); layanan
 * runner diganti tiruan pada test alur. Logika murni (daftar berkas &
 * sanitasi target) diuji langsung lewat API publiknya.
 */
class RiwayatPengujianTest extends TestCase
{
    use DatabaseTransactions;

    /** Role yang TIDAK berwenang atas fitur ini. */
    private const PERAN_TIDAK_BERWENANG = ['admin', 'teller', 'manajer', 'ao', 'dps', 'anggota', 'pengurus'];

    /** @var array<string, User> */
    private array $pengguna = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'teller', 'manajer', 'ao', 'dps', 'anggota', 'pengurus'] as $peran) {
            $this->pengguna[$peran] = User::firstOrCreate(
                ['email' => "riwayat.uji.{$peran}@test.local"],
                [
                    'name' => 'Uji RiwayatPengujian '.ucfirst($peran),
                    'password' => Hash::make('password'),
                    'role' => $peran,
                    'is_active' => true,
                ]
            );
        }
    }

    private function sebagai(string $peran): User
    {
        return $this->pengguna[$peran];
    }

    /**
     * Buat satu baris riwayat tanpa menjalankan PHPUnit.
     */
    private function riwayatContoh(array $override = []): TestRun
    {
        return TestRun::create(array_merge([
            'modul' => 'Simpanan Wajib',
            'target_uji' => '8 berkas uji Simpanan Wajib',
            'tanggal_uji' => now()->toDateString(),
            'status' => TestRun::STATUS_PASSED,
            'exit_code' => '0',
            'jumlah_tes' => 42,
            'lulus' => 42,
            'gagal' => 0,
            'dilewati' => 0,
            'assertions' => 137,
            'durasi_ms' => 1234,
            'detail' => [],
            'dijalankan_oleh' => $this->sebagai('pengurus')->id,
            'nama_pelaksana' => $this->sebagai('pengurus')->name,
        ], $override));
    }

    /* =====================================================================
     | 1. KEAMANAN — HANYA PENGURUS
     |===================================================================== */

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get(route('pengurus.riwayat-pengujian.index'))->assertRedirect(route('login'));

        $this->post(route('pengurus.riwayat-pengujian.jalankan'), [
            'modul' => 'simpanan_wajib',
            'tanggal_uji' => now()->toDateString(),
        ])->assertRedirect(route('login'));
    }

    #[DataProvider('peranTidakBerwenangProvider')]
    public function test_role_tidak_berwenang_ditolak_403(string $peran): void
    {
        $user = $this->sebagai($peran);

        $this->actingAs($user)->get(route('pengurus.riwayat-pengujian.index'))->assertForbidden();

        $this->actingAs($user)->post(route('pengurus.riwayat-pengujian.jalankan'), [
            'modul' => 'simpanan_wajib',
            'tanggal_uji' => now()->toDateString(),
        ])->assertForbidden();
    }

    public static function peranTidakBerwenangProvider(): array
    {
        $data = [];

        foreach (self::PERAN_TIDAK_BERWENANG as $peran) {
            $data["role {$peran}"] = [$peran];
        }

        return $data;
    }

    public function test_super_admin_dapat_membuka_halaman_riwayat_pengujian(): void
    {
        $this->actingAs($this->sebagai('super_admin'))
            ->get(route('pengurus.riwayat-pengujian.index'))
            ->assertOk()
            ->assertSee('Riwayat Pengujian', false)
            ->assertSee('Run Test', false);
    }

    /* =====================================================================
     | 2. VALIDASI MASUKAN
     |===================================================================== */

    public function test_validasi_menolak_modul_kosong(): void
    {
        $sebelum = TestRun::count();

        $this->actingAs($this->sebagai('super_admin'))
            ->post(route('pengurus.riwayat-pengujian.jalankan'), [
                'tanggal_uji' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('modul');

        $this->assertSame($sebelum, TestRun::count(), 'Tidak boleh ada riwayat saat validasi gagal.');
    }

    public function test_validasi_menolak_modul_tidak_dikenal(): void
    {
        $sebelum = TestRun::count();

        $this->actingAs($this->sebagai('super_admin'))
            ->post(route('pengurus.riwayat-pengujian.jalankan'), [
                'modul' => 'modul_hantu',
                'tanggal_uji' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('modul');

        $this->assertSame($sebelum, TestRun::count());
    }

    public function test_validasi_menolak_tanggal_tidak_valid(): void
    {
        $sebelum = TestRun::count();

        $this->actingAs($this->sebagai('super_admin'))
            ->post(route('pengurus.riwayat-pengujian.jalankan'), [
                'modul' => 'simpanan_wajib',
                'tanggal_uji' => 'bukan-tanggal',
            ])
            ->assertSessionHasErrors('tanggal_uji');

        $this->assertSame($sebelum, TestRun::count());
    }

    /* =====================================================================
     | 3. ALUR RUN TEST (layanan runner ditiru agar tidak rekursif)
     |===================================================================== */

    public function test_super_admin_dapat_menjalankan_pengujian_dan_hasil_tersimpan(): void
    {
        $superAdmin = $this->sebagai('super_admin');
        $run = $this->riwayatContoh();

        $this->mock(TestRunnerService::class, function ($mock) use ($run) {
            $mock->shouldReceive('jalankan')
                ->once()
                ->andReturn($run);
        });

        $this->actingAs($superAdmin)
            ->post(route('pengurus.riwayat-pengujian.jalankan'), [
                'modul' => 'simpanan_wajib',
                'tanggal_uji' => now()->toDateString(),
            ])
            ->assertRedirect(route('pengurus.riwayat-pengujian.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('test_runs', ['id' => $run->id]);
    }

    public function test_hasil_pengujian_tampil_pada_tabel_riwayat(): void
    {
        $this->riwayatContoh([
            'modul' => 'Modul Uji Tampil',
            'status' => TestRun::STATUS_PASSED,
        ]);

        $this->actingAs($this->sebagai('super_admin'))
            ->get(route('pengurus.riwayat-pengujian.index'))
            ->assertOk()
            ->assertSee('Modul Uji Tampil', false)
            ->assertSee('LULUS', false);
    }

    public function test_super_admin_dapat_menghapus_entri_riwayat(): void
    {
        $run = $this->riwayatContoh();

        $this->actingAs($this->sebagai('super_admin'))
            ->delete(route('pengurus.riwayat-pengujian.hapus', $run->id))
            ->assertRedirect(route('pengurus.riwayat-pengujian.index'));

        $this->assertDatabaseMissing('test_runs', ['id' => $run->id]);
    }

    public function test_menghapus_entri_tidak_ada_menghasilkan_404(): void
    {
        $idHantu = (int) (TestRun::max('id') ?? 0) + 9999;

        $this->actingAs($this->sebagai('super_admin'))
            ->delete(route('pengurus.riwayat-pengujian.hapus', $idHantu))
            ->assertNotFound();
    }

    /* =====================================================================
     | 4. DEFINISI MODUL & KEAMANAN PERINTAH (API PUBLIK LAYANAN)
     |===================================================================== */

    public function test_modul_simpanan_wajib_terdaftar(): void
    {
        $this->assertArrayHasKey('simpanan_wajib', TestRunnerService::MODUL);
        $this->assertNotEmpty(TestRunnerService::MODUL['simpanan_wajib']['berkas']);
    }

    public function test_semua_berkas_modul_benar_benar_ada(): void
    {
        foreach (TestRunnerService::MODUL as $kunci => $definisi) {
            if (empty($definisi['berkas'])) {
                continue; // modul "semua" menjalankan seluruh suite.
            }

            foreach ($definisi['berkas'] as $berkas) {
                $this->assertFileExists(
                    base_path($berkas),
                    "Modul '{$kunci}' menunjuk berkas uji yang tidak ada: {$berkas}"
                );
            }
        }
    }

    public function test_modul_tidak_dikenal_ditolak_tanpa_menyimpan_riwayat(): void
    {
        $sebelum = TestRun::count();

        try {
            app(TestRunnerService::class)->jalankan('modul_hantu', $this->sebagai('super_admin'));
            $this->fail('Seharusnya melempar RuntimeException untuk modul tak dikenal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak dikenal', $e->getMessage());
        }

        $this->assertSame($sebelum, TestRun::count());
    }

    public function test_target_uji_di_luar_folder_tests_ditolak(): void
    {
        $sebelum = TestRun::count();

        try {
            app(TestRunnerService::class)->jalankan(
                'simpanan_wajib',
                $this->sebagai('super_admin'),
                now()->toDateString(),
                'tests/../.env'
            );
            $this->fail('Seharusnya melempar RuntimeException untuk target tidak sah.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Target uji tidak sah', $e->getMessage());
        }

        $this->assertSame($sebelum, TestRun::count(), 'Target tidak sah tidak boleh membuat riwayat.');
    }

    /* =====================================================================
     | 5. MENU NAVIGASI — MILIK SUPER ADMIN, BUKAN PENGURUS
     |===================================================================== */

    public function test_menu_super_admin_memuat_tautan_riwayat_pengujian(): void
    {
        $rute = $this->ruteMenu('super_admin');

        $this->assertContains('pengurus.riwayat-pengujian.index', $rute);
    }

    public function test_menu_pengurus_tidak_lagi_memuat_tautan_riwayat_pengujian(): void
    {
        // PEMISAHAN TUGAS: verifikasi kualitas sistem = pemeliharaan teknis (IT),
        // bukan kewenangan kebijakan Pengurus.
        $rute = $this->ruteMenu('pengurus');

        $this->assertNotContains('pengurus.riwayat-pengujian.index', $rute);
    }

    /** Kumpulkan seluruh nama rute dari menu sidebar sebuah peran. */
    private function ruteMenu(string $peran): array
    {
        $rute = [];

        $telusuri = function (array $items) use (&$telusuri, &$rute): void {
            foreach ($items as $item) {
                if (! empty($item['route'])) {
                    $rute[] = $item['route'];
                }
                if (! empty($item['children'])) {
                    $telusuri($item['children']);
                }
            }
        };

        $telusuri(NavigationService::getMenu($peran));

        return $rute;
    }
}
