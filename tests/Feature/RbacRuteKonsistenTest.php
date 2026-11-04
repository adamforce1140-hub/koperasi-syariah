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
 *  1. Setiap `role:` pada seluruh rute terdaftar di `HierarkiRole::peran()`,
 *     dan tidak ada lagi rute yang memakai gerbang peran literal (seluruhnya
 *     memakai izin bernama `izin:`).
 *  2. Perintah `rbac:periksa` melaporkan NOL temuan pada konfigurasi & rute
 *     (termasuk peta izin, konfigurasi hierarki, rute dasbor peran, dan rute
 *     pengalihan ramah).
 *
 * `IzinRuteTest` (DYN-18) sudah menjaga sisi `izin:` pada rute; di sini fokus
 * pada `role:` yang belum tercakup, plus gerbang command yang menyeluruh.
 */
class RbacRuteKonsistenTest extends TestCase
{
    /**
     * DYN-18 — migrasi tuntas: tidak ada rute yang masih menyebut peran
     * literal. Gerbang rute wajib memakai izin bernama (`izin:`) agar satu
     * sumber kebenaran tetap `config/izin.php`.
     *
     * Bila kelak `role:` dipakai kembali, nama perannya tetap harus sah
     * menurut `config/role_hierarki.php` — karena itu pemeriksaan nama
     * dijalankan sebelum rute literal ditolak.
     */
    public function test_rute_tidak_lagi_memakai_gerbang_peran_literal(): void
    {
        $tersisa = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'role:')) {
                    continue;
                }

                $tersisa[] = $rute->uri().' → '.$mw;

                foreach (explode(',', substr($mw, strlen('role:'))) as $peran) {
                    $peran = trim($peran);

                    if ($peran !== '') {
                        $this->assertTrue(
                            HierarkiRole::dikenal($peran),
                            "Rute memakai peran '{$peran}' yang tidak terdaftar di config/role_hierarki.php."
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $tersisa,
            "Rute masih memakai peran literal:\n".implode("\n", $tersisa)
        );
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
