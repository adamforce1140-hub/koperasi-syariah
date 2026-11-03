<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUDIT — KETAHANAN PARAMETER RUTE TERHADAP MASUKAN TIDAK SAH.
 *
 * Setiap rute berparameter dipanggil dengan nilai yang tidak masuk akal
 * (string non-numerik, lalu ID yang tidak ada). Rute boleh menolak dengan
 * 4xx/302 — yang TIDAK boleh terjadi adalah galat server 5xx, karena itu
 * berarti masukan pengguna dapat menjatuhkan sistem.
 *
 * STATUS: AKTIF (gate) sejak T-1 dikerjakan. Perbaikan: pola global
 * `Route::pattern()` untuk parameter `id`/`*_id` di `bootstrap/app.php`
 * (lihat `ParameterRuteNumerikTest`). Sebelumnya test ini menemukan 130
 * kombinasi peran×rute yang membalas 500.
 */
class AuditKetahananParameterRuteTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'audit-parameter-uji';

    /** Nilai uji: non-numerik, lalu ID yang pasti tidak ada. */
    private const NILAI_UJI = ['abc', '99999999'];

    private array $pengguna = [];

    private function pengguna(string $role): User
    {
        if (isset($this->pengguna[$role])) {
            return $this->pengguna[$role];
        }

        $user = User::firstOrCreate(
            ['email' => 'audit.parameter.'.$role.'@koperasi.test'],
            ['name' => 'Parameter '.$role, 'password' => Hash::make(self::PASSWORD), 'role' => $role, 'is_active' => true],
        );
        $user->forceFill(['password' => Hash::make(self::PASSWORD), 'role' => $role, 'is_active' => true])->save();

        if ($role === 'anggota') {
            Anggota::firstOrCreate(['user_id' => $user->id], [
                'no_anggota' => 'AG-PARAM-'.$user->id,
                'nama_lengkap' => 'Anggota Parameter',
                'nik' => str_pad((string) $user->id, 16, '0', STR_PAD_LEFT),
                'jenis_kelamin' => 'Laki-laki',
                'alamat' => 'Alamat Uji',
                'kota' => 'Jakarta',
                'provinsi' => 'DKI Jakarta',
                'status_anggota' => 'Aktif',
                'tanggal_gabung' => now()->toDateString(),
            ]);
        }

        return $this->pengguna[$role] = $user;
    }

    public function test_parameter_tidak_sah_tidak_menyebabkan_galat_server(): void
    {
        $laporan = [];
        $galat = [];

        foreach (['super_admin', 'pengurus', 'manajer', 'teller', 'ao', 'dps', 'admin', 'anggota'] as $role) {
            $this->actingAs($this->pengguna($role));

            foreach (Route::getRoutes() as $rute) {
                $uri = $rute->uri();

                if (! str_contains($uri, '{') || str_starts_with($uri, '_ignition') || str_starts_with($uri, 'api/')) {
                    continue;
                }

                foreach (self::NILAI_UJI as $nilai) {
                    $terisi = preg_replace('/\{[^}]+\??\}/', $nilai, $uri);

                    foreach (array_diff($rute->methods(), ['HEAD']) as $metode) {
                        try {
                            $respons = $this->call($metode, '/'.$terisi, [], [], [], [
                                'HTTP_ACCEPT' => 'text/html',
                            ]);
                            $kode = $respons->getStatusCode();
                        } catch (\Throwable $e) {
                            $kode = 599;
                            $galat[] = "{$role} {$metode} /{$terisi} → EXCEPTION ".get_class($e).': '.$e->getMessage();
                        }

                        if ($kode >= 500) {
                            $galat[] = "{$role} {$metode} /{$terisi} (nilai={$nilai}) → {$kode}";
                            $laporan[] = sprintf('[%s] %s /%s = %d', $role, $metode, $terisi, $kode);
                        }
                    }
                }
            }

            $this->app['auth']->forgetGuards();
            session()->flush();
        }

        $this->assertSame([], array_unique($galat), "Galat server pada masukan tidak sah:\n".implode("\n", array_unique($galat)));
    }
}
