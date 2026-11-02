<?php

namespace Tests\Feature;

use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * INVARIANT KONSISTENSI RBAC — DYN-16.
 *
 * Middleware `role:` dan `izin:` baru melempar exception saat RUTE DIAKSES.
 * Artinya salah ketik nama peran/izin dapat lolos seluruh suite bila rute
 * tersebut tidak pernah dibuka pengujian lain, dan di produksi tampil sebagai
 * 403 yang menyerupai masalah hak akses. Uji ini mengunci dua lapis:
 *
 *  1. Setiap `role:` pada seluruh rute terdaftar di `HierarkiRole::peran()`.
 *  2. Perintah `rbac:periksa` melaporkan NOL temuan pada konfigurasi & rute
 *     (termasuk peta izin, konfigurasi hierarki, rute dasbor peran, dan rute
 *     pengalihan ramah).
 *
 * `IzinRuteTest` (DYN-18) sudah menjaga sisi `izin:` pada rute; di sini fokus
 * pada `role:` yang belum tercakup, plus gerbang command yang menyeluruh.
 */
class RbacRuteKonsistenTest extends TestCase
{
    /** @return list<string> */
    private function rolePadaRute(): array
    {
        $nama = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'role:')) {
                    continue;
                }

                foreach (explode(',', substr($mw, strlen('role:'))) as $satu) {
                    $satu = trim($satu);

                    if ($satu !== '') {
                        $nama[$satu] = true;
                    }
                }
            }
        }

        return array_keys($nama);
    }

    public function test_setiap_peran_pada_rute_dikenal_ssot_rbac(): void
    {
        $dipakai = $this->rolePadaRute();

        $this->assertNotEmpty($dipakai, 'Tidak ada rute ber-middleware role: yang terbaca.');

        foreach ($dipakai as $peran) {
            $this->assertTrue(
                HierarkiRole::dikenal($peran),
                "Rute memakai peran '{$peran}' yang tidak terdaftar di config/role_hierarki.php."
            );
        }
    }

    public function test_command_rbac_periksa_tidak_menemukan_temuan(): void
    {
        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('Seluruh nama peran & izin pada rute dan konfigurasi konsisten dengan SSOT RBAC.')
            ->assertExitCode(0);
    }

    public function test_command_rbac_periksa_gagal_bila_izin_tak_terdaftar(): void
    {
        $peta = Izin::peta();
        $peta['uji.salah_ketik'] = ['peran_yang_tidak_ada'];

        config(['izin.izin' => $peta]);

        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('IZIN_PERAN_TAK_DIKENAL — 1 temuan')
            ->assertExitCode(1);
    }

    public function test_command_rbac_periksa_gagal_bila_rute_menunjuk_peran_tak_dikenal(): void
    {
        Route::middleware('role:manajerx')->get('/uji-rbac-salah-ketik', fn () => 'ok');

        $this->artisan('rbac:periksa')
            ->expectsOutputToContain('ROUTE_ROLE_TAK_DIKENAL — 1 temuan')
            ->assertExitCode(1);
    }
}
