<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Verifikasi Gelombang 2 untuk pengerasan konfigurasi & perlindungan PII.
 *
 * Fokus pengujian:
 *  1. T-01: proxy tepercaya bersumber dari env `TRUSTED_PROXIES`.
 *  2. T-02: dokumen PII disimpan pada disk privat `dokumen` dan hanya dapat
 *     diunduh oleh peran yang berhak lewat rute `berkas.unduh`.
 *  3. T-03: enkripsi sesi & cookie secure bersumber dari env dengan default aman.
 *  4. T-04: masa berlaku token Sanctum dibatasi (default 8 jam).
 *
 * Lihat: docs/AUDIT-MENYELURUH-PSAK-OJK.md (Gelombang 2 — T-01..T-04).
 *
 * Catatan: kelas ini memakai DatabaseTransactions (bukan RefreshDatabase)
 * agar konsisten dengan konvensi suite. Seluruh test lain mengandalkan
 * master data hasil seed pada DB dev yang sama; menjalankan migrate:fresh
 * di tengah suite akan menghapus data tersebut dan merusak test sesudahnya.
 */
class PengerasanKonfigurasiDanDokumenTest extends TestCase
{
    use DatabaseTransactions;

    private function buatPengguna(string $role): User
    {
        return User::create([
            'name' => 'Uji '.ucfirst($role).' Gelombang 2',
            'email' => $role.'_w2_'.uniqid().'@contoh.test',
            'password' => Hash::make('rahasia'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    // ---------------------------------------------------------------------
    // T-01 — Trusted proxies
    // ---------------------------------------------------------------------

    public function test_proxy_tepercaya_bersumber_dari_env_bukan_hardcode(): void
    {
        $sumber = (string) file_get_contents(app_path('Http/Middleware/TrustProxies.php'));

        // Nilai proxy tidak boleh di-hardcode `'*'` langsung pada properti.
        $this->assertStringNotContainsString("\$proxies = '*';", $sumber,
            'Daftar proxy tepercaya tidak boleh di-hardcode.');
        $this->assertStringContainsString('TRUSTED_PROXIES', $sumber,
            'TrustProxies harus membaca env TRUSTED_PROXIES.');
    }

    public function test_proxy_tepercaya_kosong_berarti_tidak_ada_proxy_dipercaya(): void
    {
        // Tanpa env TRUSTED_PROXIES, aplikasi tidak mempercayai header
        // X-Forwarded-* sehingga IP klien tidak dapat dipalsukan.
        $middleware = new TrustProxies;
        $properti = new \ReflectionProperty($middleware, 'proxies');
        $properti->setAccessible(true);

        $nilai = $properti->getValue($middleware);

        $this->assertTrue(
            $nilai === null || $nilai === '',
            'Default proxy tepercaya harus kosong (aman untuk akses langsung).'
        );
    }

    // ---------------------------------------------------------------------
    // T-02 — Dokumen PII pada disk privat
    // ---------------------------------------------------------------------

    public function test_disk_dokumen_privat_terdaftar(): void
    {
        $this->assertContains('dokumen', array_keys(config('filesystems.disks')),
            'Disk privat `dokumen` harus terdaftar pada config/filesystems.php.');

        $this->assertSame('private', config('filesystems.disks.dokumen.visibility'),
            'Disk dokumen harus ber-visibility private (tidak diekspos ke web).');
    }

    public function test_dokumen_privat_tidak_dapat_diakses_tanpa_login(): void
    {
        Storage::fake('dokumen');
        Storage::disk('dokumen')->put('ktp/uji-ktp.txt', 'rahasia');

        $respons = $this->get(route('berkas.unduh', ['path' => 'ktp/uji-ktp.txt']));

        // Belum terautentikasi → dialihkan ke halaman login.
        $respons->assertStatus(302);
    }

    public function test_dokumen_privat_dapat_diunduh_oleh_peran_berhak(): void
    {
        Storage::fake('dokumen');
        Storage::disk('dokumen')->put('ktp/uji-ktp.txt', 'isi-dokumen');

        $admin = $this->buatPengguna('admin');

        $respons = $this->actingAs($admin)
            ->get(route('berkas.unduh', ['path' => 'ktp/uji-ktp.txt']));

        $respons->assertStatus(200);
    }

    public function test_dokumen_privat_ditolak_untuk_peran_tidak_berhak(): void
    {
        Storage::fake('dokumen');
        Storage::disk('dokumen')->put('ktp/uji-ktp.txt', 'isi-dokumen');

        // Peran `anggota` tidak termasuk daftar peran pengunduh dokumen PII.
        $anggota = $this->buatPengguna('anggota');

        $respons = $this->actingAs($anggota)
            ->get(route('berkas.unduh', ['path' => 'ktp/uji-ktp.txt']));

        $respons->assertStatus(403);
    }

    /**
     * Seluruh prefiks dokumen PII hanya boleh dibaca peran back-office.
     *
     * `DokumenController::unduh` memeriksa peran per-prefiks (bukan kepemilikan
     * berkas), sehingga batas keamanannya sepenuhnya bergantung pada peta
     * `izin_dokumen`. Test ini mengunci peta tersebut: bila suatu saat peran
     * anggota/nasabah ditambahkan ke salah satu prefiks, akses lintas anggota
     * akan terbuka dan test ini gagal lebih dulu.
     */
    public function test_seluruh_prefiks_dokumen_menolak_peran_anggota_dan_nasabah(): void
    {
        Storage::fake('dokumen');

        $prefiks = array_keys(config('role_hierarki.izin_dokumen'));

        $this->assertNotEmpty($prefiks, 'Peta izin_dokumen tidak boleh kosong.');

        foreach ($prefiks as $folder) {
            Storage::disk('dokumen')->put($folder.'/uji.txt', 'isi-dokumen');

            foreach (['anggota', 'nasabah'] as $peran) {
                $pengguna = $this->buatPengguna($peran);

                $this->actingAs($pengguna)
                    ->get(route('berkas.unduh', ['path' => $folder.'/uji.txt']))
                    ->assertStatus(403, "Peran {$peran} tidak boleh mengunduh prefiks {$folder}.");
            }
        }
    }

    public function test_jalur_traversal_dan_folder_tak_dikenal_ditolak(): void
    {
        $admin = $this->buatPengguna('admin');

        $this->actingAs($admin)
            ->get(route('berkas.unduh', ['path' => 'ktp/../rahasia.txt']))
            ->assertStatus(404);

        $this->actingAs($admin)
            ->get(route('berkas.unduh', ['path' => 'folder_lain/berkas.txt']))
            ->assertStatus(404);
    }

    public function test_tidak_ada_berkas_pii_tersisa_pada_disk_publik(): void
    {
        // Berkas PII lama tidak boleh tersisa di storage/app/public.
        foreach (['ktp', 'pengajuan', 'aml_documents'] as $folder) {
            $this->assertTrue(
                Storage::disk('public')->allFiles($folder) === [],
                "Folder PII `{$folder}` tidak boleh berada di disk publik."
            );
        }
    }

    // ---------------------------------------------------------------------
    // T-03 — Default aman sesi
    // ---------------------------------------------------------------------

    public function test_default_enkripsi_sesi_aman(): void
    {
        $this->assertStringContainsString(
            "env('SESSION_ENCRYPT', true)",
            (string) file_get_contents(base_path('config/session.php')),
            'Default enkripsi sesi harus true demi keamanan.'
        );
    }

    public function test_cookie_secure_default_mengikuti_lingkungan(): void
    {
        // Di lingkungan produksi (bukan local/testing) cookie wajib secure.
        $sumber = (string) file_get_contents(base_path('config/session.php'));

        $this->assertStringContainsString('SESSION_SECURE_COOKIE', $sumber);
        $this->assertStringContainsString("=== 'production'", $sumber,
            'Cookie secure harus otomatis aktif di produksi.');

        // Lingkungan pengujian bukan produksi → secure tidak dipaksa.
        $this->assertFalse(config('session.secure'));
    }

    /**
     * `.env.example` adalah berkas yang disalin menjadi `.env` di server baru.
     * Bila nilai contohnya melemahkan default `config/`, setiap deployment
     * yang mengikuti dokumentasi otomatis kehilangan proteksi tanpa ada yang
     * menyadarinya. Test ini mengunci nilai contoh tersebut.
     */
    public function test_env_example_tidak_melemahkan_default_keamanan(): void
    {
        $contoh = $this->bacaEnvExample();

        $wajibAman = [
            'APP_DEBUG' => 'false',
            'IGNITION_ENABLE_RUNNABLE_SOLUTIONS' => 'false',
            'SESSION_ENCRYPT' => 'true',
            'SESSION_SECURE_COOKIE' => 'true',
            'KEAMANAN_CSP_AKTIF' => 'true',
            'KEAMANAN_CSP_ENFORCE' => 'true',
            'KEAMANAN_AUDIT_PII' => 'true',
            'BACKUP_ENKRIPSI' => 'true',
        ];

        foreach ($wajibAman as $kunci => $nilai) {
            $this->assertArrayHasKey($kunci, $contoh,
                "`{$kunci}` wajib ada di .env.example agar deployment baru sadar akan setelan ini.");
            $this->assertSame($nilai, strtolower($contoh[$kunci]),
                "`.env.example` menulis {$kunci}={$contoh[$kunci]}, seharusnya {$nilai}.");
        }
    }

    /**
     * Kunci yang sengaja TIDAK diisi di `.env` lokal karena nilainya mengikuti
     * default `config/` per lingkungan. Ditulis kosong (`KUNCI=`) pun berbahaya
     * untuk kunci boolean: `env()` mengembalikan "" dan `(bool) ""` = false,
     * sehingga proteksi turun diam-diam. Karena itu dilarang sama sekali.
     */
    private const KUNCI_BOLEH_TIDAK_ADA = ['KEAMANAN_CSP_ENFORCE'];

    /**
     * Setiap kunci di `.env.example` harus diketahui `.env` lokal, baik diisi
     * nilai maupun sengaja dibiarkan kosong. Hanya kunci pada daftar pengecualian
     * yang boleh absen.
     */
    public function test_env_local_memuat_seluruh_kunci_keamanan_dari_contoh(): void
    {
        $contoh = $this->bacaEnvExample();
        $lokal = $this->bacaEnv(base_path('.env'));

        if ($lokal === []) {
            $this->markTestSkipped('Berkas .env tidak tersedia pada lingkungan uji ini.');
        }

        $kunciKeamanan = array_values(array_filter(
            array_keys($contoh),
            fn (string $k) => str_starts_with($k, 'KEAMANAN_')
                || str_starts_with($k, 'SESSION_')
                || str_starts_with($k, 'BACKUP_')
                || str_starts_with($k, 'IGNITION_')
        ));

        $hilang = array_values(array_diff($kunciKeamanan, array_keys($lokal)));

        $diabaikan = [];

        foreach ($hilang as $i => $kunci) {
            if (in_array($kunci, self::KUNCI_BOLEH_TIDAK_ADA, true)) {
                $diabaikan[] = $kunci;
                unset($hilang[$i]);
            }
        }

        // Kunci boolean yang mengikuti default config tidak boleh ditulis kosong.
        foreach ($diabaikan as $kunci) {
            $this->assertArrayNotHasKey($kunci, $lokal,
                "{$kunci} tidak boleh ditulis di .env, bahkan kosong: `(bool) \"\"` = false.");
        }

        $this->assertSame([], array_values($hilang),
            'Kunci keamanan berikut ada di .env.example tetapi tidak ada di .env: '
            .implode(', ', $hilang));
    }

    /** @return array<string, string> */
    private function bacaEnvExample(): array
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example wajib ada sebagai acuan deployment.');

        return $this->bacaEnv($path);
    }

    /** @return array<string, string> */
    private function bacaEnv(string $path): array
    {
        $hasil = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
            $baris = trim($baris);

            if ($baris === '' || str_starts_with($baris, '#')) {
                continue;
            }

            if (! str_contains($baris, '=')) {
                continue;
            }

            [$kunci, $nilai] = explode('=', $baris, 2);
            $hasil[trim($kunci)] = trim($nilai, " \t\"'");
        }

        return $hasil;
    }

    // ---------------------------------------------------------------------
    // T-04 — Masa berlaku token Sanctum
    // ---------------------------------------------------------------------

    public function test_masa_berlaku_token_sanctum_dibatasi(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull($expiration,
            'Masa berlaku token Sanctum tidak boleh null (berlaku selamanya).');
        $this->assertIsNumeric($expiration);
        $this->assertGreaterThan(0, (int) $expiration);
    }
}
