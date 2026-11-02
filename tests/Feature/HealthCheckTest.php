<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Observability — verifikasi probe kesehatan `/health` (liveness) & `/readyz`
 * (readiness).
 *
 * Fokus pengujian:
 *  1. Kedua endpoint dapat diakses TANPA autentikasi (dipakai infrastruktur).
 *  2. Liveness membalas 200 dan tidak menyentuh dependensi.
 *  3. Readiness memeriksa database, cache, dan antrean; membalas 200 saat siap.
 *  4. Endpoint dapat dimatikan lewat konfigurasi (feature flag) → 404.
 *  5. Kedua endpoint dibatasi lajunya sesuai `keamanan.health`.
 */
class HealthCheckTest extends TestCase
{
    public function test_liveness_dapat_diakses_tanpa_autentikasi(): void
    {
        $respons = $this->getJson('/health');

        $respons->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'service', 'waktu']);
    }

    public function test_readiness_memeriksa_seluruh_dependensi_dan_membalas_200(): void
    {
        $respons = $this->getJson('/readyz');

        $respons->assertStatus(200)
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure([
                'status',
                'pemeriksaan' => [
                    'database' => ['ok', 'driver'],
                    'cache' => ['ok', 'driver'],
                    'queue' => ['ok', 'driver'],
                ],
                'waktu',
            ]);

        $this->assertTrue($respons->json('pemeriksaan.database.ok'));
        $this->assertTrue($respons->json('pemeriksaan.cache.ok'));
        $this->assertTrue($respons->json('pemeriksaan.queue.ok'));
    }

    public function test_endpoint_dapat_dinonaktifkan_lewat_konfigurasi(): void
    {
        config(['keamanan.health.aktif' => false]);

        $this->getJson('/health')->assertStatus(404);
        $this->getJson('/readyz')->assertStatus(404);
    }

    public function test_endpoint_dibatasi_lajunya_sesuai_konfigurasi(): void
    {
        config(['keamanan.health.rate_limit_per_menit' => 2]);

        $this->getJson('/health')->assertStatus(200);
        $this->getJson('/health')->assertStatus(200);
        $this->getJson('/health')->assertStatus(429);
    }
}
