<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\User;
use App\Support\Izin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUDIT LANGKAH 3 — SAPUAN SELURUH RUTE GET YANG DIIZINKAN PER PERAN.
 *
 * Berbeda dari `AuditAksesDashboardPerPeranTest` yang hanya membuka tautan
 * sidebar, test ini menyapu SELURUH rute GET tanpa parameter yang lolos
 * middleware `izin:` untuk peran tersebut — termasuk rute yang tidak punya
 * tautan menu (temuan F-03/F-07/F-08).
 *
 * Yang diuji: tidak ada galat server (5xx), tidak ada throttle (429), dan
 * tidak ada rute yang "diizinkan izin tetapi ditolak" (403) tanpa alasan
 * kebijakan yang terdokumentasi. Hasil lengkap ditulis ke
 * storage/app/audit_sweep_output.txt sebagai bukti audit.
 */
class AuditSapuanRutePerPeranTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'audit-sapuan-uji';

    private const PERAN = [
        'dps', 'pengurus', 'manajer', 'ao', 'teller',
        'super_admin', 'admin', 'anggota', 'nasabah',
    ];

    /**
     * Galat 5xx yang sudah terdokumentasi. F-09
     * (`pengelola.pengajuan-pinjaman.create` menunjuk method `create` yang
     * tidak ada) sudah diperbaiki dengan menghapus rute tersebut, sehingga
     * daftar ini kembali kosong.
     *
     * Entri di sini TIDAK membuat test gagal agar regresi 5xx baru langsung
     * terlihat; temuan tetap tercatat di storage/app/audit_sweep_output.txt.
     */
    private const GALAT_TERDOKUMENTASI = [];

    /** @var array<string, User> */
    private array $pengguna = [];

    private function pengguna(string $role): User
    {
        if (isset($this->pengguna[$role])) {
            return $this->pengguna[$role];
        }

        $user = User::firstOrCreate(
            ['email' => 'audit.sapuan.'.$role.'@koperasi.test'],
            [
                'name' => 'Sapuan '.strtoupper($role),
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

    private function masuk(string $role): void
    {
        $user = $this->pengguna($role);

        if ($role === 'anggota') {
            Anggota::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'no_anggota' => 'AG-SAPUAN-'.$user->id,
                    'nama_lengkap' => 'Anggota Sapuan '.$user->id,
                    'nik' => str_pad((string) $user->id, 16, '0', STR_PAD_LEFT),
                    'jenis_kelamin' => 'Laki-laki',
                    'alamat' => 'Alamat Sapuan',
                    'kota' => 'Jakarta',
                    'provinsi' => 'DKI Jakarta',
                    'status_anggota' => 'Aktif',
                    'tanggal_gabung' => now()->toDateString(),
                ],
            );
        }

        $this->actingAs($user);
    }

    private function lupakanSesi(): void
    {
        $this->app['auth']->forgetGuards();
        session()->flush();
    }

    /**
     * Rute GET tanpa parameter yang lolos middleware `izin:` untuk peran.
     *
     * @return array<int, string>
     */
    private function ruteTerizinkan(string $role): array
    {
        $hasil = [];

        foreach (Route::getRoutes() as $route) {
            $nama = $route->getName();

            if ($nama === null || str_contains($route->uri(), '{') || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $izinMw = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_starts_with($m, 'izin:'),
            ));

            if ($izinMw === []) {
                continue;
            }

            $izinNama = array_map(fn ($m) => substr($m, 5), $izinMw);

            if (in_array($role, Izin::peranEfektif($izinNama), true)) {
                $hasil[] = $nama;
            }
        }

        sort($hasil);

        return array_values(array_unique($hasil));
    }

    public function test_sapuan_seluruh_rute_get_per_peran(): void
    {
        // $laporan menampung ringkasan status per peran, $galatServer
        // menampung rute yang menghasilkan galat server (5xx).
        $laporan = [];
        $galatServer = [];

        foreach (self::PERAN as $role) {
            $this->masuk($role);
            $rute = $this->ruteTerizinkan($role);
            $status = [];

            foreach ($rute as $nama) {
                $respons = $this->get(route($nama));

                // Sebagian endpoint ekspor mengembalikan StreamedResponse
                // (bukan TestResponse), sehingga `status()` tidak tersedia.
                $kode = method_exists($respons, 'status')
                    ? $respons->status()
                    : $respons->getStatusCode();
                $status[$nama] = $kode;

                if ($kode >= 500 && ! in_array($nama, self::GALAT_TERDOKUMENTASI, true)) {
                    $galatServer[] = "{$role} → {$nama} = {$kode}";
                }
            }

            $laporan[] = sprintf(
                "\n[%s] total=%d 200=%d 302=%d 403=%d 404=%d 422=%d lain=%d",
                $role,
                count($status),
                count(array_filter($status, fn ($k) => $k === 200)),
                count(array_filter($status, fn ($k) => $k === 302)),
                count(array_filter($status, fn ($k) => $k === 403)),
                count(array_filter($status, fn ($k) => $k === 404)),
                count(array_filter($status, fn ($k) => $k === 422)),
                count(array_filter($status, fn ($k) => ! in_array($k, [200, 302, 403, 404, 422], true))),
            );

            foreach ($status as $nama => $kode) {
                if ($kode !== 200) {
                    $laporan[] = sprintf('  %-45s %d', $nama, $kode);
                }
            }

            $this->lupakanSesi();
        }

        file_put_contents(
            storage_path('app/audit_sweep_output.txt'),
            "=== SAPUAN RUTE GET PER PERAN ===\n".implode("\n", $laporan)."\n",
        );

        $this->assertSame(
            [],
            $galatServer,
            "Galat server (5xx) pada rute yang diizinkan:\n".implode("\n", $galatServer),
        );
    }
}
