<?php

namespace Tests\Feature;

use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * INVARIANT SSOT IZIN RUTE — DYN-18.
 *
 * Middleware `izin:` baru gagal saat rute diakses, sehingga salah ketik dapat
 * lolos dari suite bila rute tersebut tidak pernah dibuka pengujian lain. Uji
 * ini menutup celah itu dengan memeriksa seluruh definisi rute sekaligus:
 *
 *  1. Setiap nama izin pada rute terdaftar di `config/izin.php`.
 *  2. Setiap izin terdaftar benar-benar dipakai — tidak ada entri yatim.
 *  3. Setiap peran pada peta izin adalah nama sah di SSOT RBAC.
 */
class IzinRuteTest extends TestCase
{
    /** @return list<string> */
    private function izinPadaRute(): array
    {
        $nama = [];

        foreach (Route::getRoutes() as $rute) {
            foreach ($rute->gatherMiddleware() as $mw) {
                if (! is_string($mw) || ! str_starts_with($mw, 'izin:')) {
                    continue;
                }

                foreach (explode(',', substr($mw, strlen('izin:'))) as $satu) {
                    $satu = trim($satu);

                    if ($satu !== '') {
                        $nama[$satu] = true;
                    }
                }
            }
        }

        return array_keys($nama);
    }

    public function test_setiap_izin_pada_rute_terdaftar_di_ssot(): void
    {
        $dipakai = $this->izinPadaRute();

        $this->assertNotEmpty($dipakai, 'Tidak ada rute ber-middleware izin: yang terbaca.');

        foreach ($dipakai as $nama) {
            $this->assertTrue(
                Izin::ada($nama),
                "Rute memakai izin '{$nama}' yang tidak terdaftar di config/izin.php."
            );
        }
    }

    public function test_tidak_ada_izin_terdaftar_yang_yatim(): void
    {
        $dipakai = $this->izinPadaRute();
        $yatim = array_values(array_diff(array_keys(Izin::peta()), $dipakai));

        $this->assertSame(
            [],
            $yatim,
            'Izin terdaftar tetapi tidak dipakai rute mana pun: '.implode(', ', $yatim)
        );
    }

    public function test_setiap_peran_pada_peta_izin_dikenal_ssot_rbac(): void
    {
        foreach (Izin::peta() as $nama => $daftarPeran) {
            $this->assertNotEmpty(
                $daftarPeran,
                "Izin '{$nama}' tidak memetakan peran apa pun — rute akan selalu 403."
            );

            foreach ((array) $daftarPeran as $peran) {
                $this->assertTrue(
                    HierarkiRole::dikenal((string) $peran),
                    "Izin '{$nama}' memuat peran '{$peran}' yang tidak dikenal config/role_hierarki.php."
                );
            }
        }
    }
}
