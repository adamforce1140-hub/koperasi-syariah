<?php

namespace Tests\Feature;

use App\Models\Anggota;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REGRESI RUNTIME — endpoint baca JSON harus membalas 200, bukan HTML/500.
 *
 * Latar belakang: `routes/api.php` pernah menunjuk controller WEB pada
 * `apiResource` sehingga `GET /api/anggota` membalas HTML dan endpoint lain
 * menghasilkan 500 karena method tidak ada. Test ini menjaga agar setiap
 * endpoint baca yang dipakai klien mobile/API tetap melayani JSON.
 *
 * Sengaja TIDAK memakai `actingAs()` berganti guard: rute `auth:sanctum`
 * mengubah guard bawaan proses (`shouldUse`), sehingga pemeriksaan di sini
 * memakai guard `web` secara eksplisit.
 */
class ApiBacaJsonTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'uji-api-baca';

    private function teller(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'uji.api.baca.teller@koperasi.test'],
            [
                'name' => 'Uji API Baca',
                'password' => Hash::make(self::PASSWORD),
                'role' => 'teller',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $user->forceFill([
            'password' => Hash::make(self::PASSWORD),
            'role' => 'teller',
            'is_active' => true,
        ])->save();

        return $user;
    }

    public function test_seluruh_endpoint_baca_api_membalas_json_200(): void
    {
        $anggota = Anggota::query()->first();
        $this->assertNotNull($anggota, 'Butuh minimal satu baris anggota untuk menguji endpoint detail.');

        $uris = [
            '/api/anggota',
            '/api/anggota?search=uji',
            '/api/anggota/'.$anggota->id,
            '/api/rekening',
            '/api/transaksi',
            '/api/pembiayaan',
            '/api/angsuran',
            '/api/akun',
            '/api/jurnal',
            '/api/approval/status',
            '/api/produk-pembiayaan',
            '/api/deposito/products',
            '/api/deposito/coa-config',
        ];

        foreach ($uris as $uri) {
            $respons = $this->actingAs($this->teller(), 'web')->getJson($uri);

            $this->assertSame(
                200,
                $respons->getStatusCode(),
                "Endpoint {$uri} tidak membalas 200 (isi: ".substr($respons->getContent(), 0, 200).')'
            );

            $this->assertTrue(
                $respons->headers->get('Content-Type') !== null
                    && str_contains((string) $respons->headers->get('Content-Type'), 'json'),
                "Endpoint {$uri} tidak membalas JSON."
            );
        }
    }
}
