<?php

namespace Tests\Feature;

use App\Http\Middleware\KeamananHeader;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Verifikasi hardening keamanan data (UU PDP No. 27/2022).
 *
 * Fokus pengujian:
 *  1. Header keamanan HTTP terpasang pada respons.
 *  2. HSTS HANYA dikirim pada koneksi HTTPS.
 *  3. CSP diekstrak dengan benar dan dapat dimatikan lewat env.
 *  4. Konfigurasi sesi mengikuti env (enkripsi & cookie secure).
 *  5. Endpoint publik dibatasi lajunya.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class KeamananHeaderTest extends TestCase
{
    /**
     * Membangun respons melalui middleware secara langsung supaya skenario
     * HTTPS dapat disimulasikan tanpa memerlukan TLS di mesin pengujian.
     */
    private function lewatMiddleware(Request $request): Response
    {
        $middleware = new KeamananHeader;

        return $middleware->handle($request, static function () {
            return new Response('<html lang="id"><body>uji</body></html>', 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        });
    }

    public static function headerDasarProvider(): array
    {
        return [
            'nosniff' => ['X-Content-Type-Options', 'nosniff'],
            'referrer policy' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
            'frame options' => ['X-Frame-Options', 'SAMEORIGIN'],
        ];
    }

    #[DataProvider('headerDasarProvider')]
    public function test_header_keamanan_dasar_terpasang(string $nama, string $nilai): void
    {
        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));

        $this->assertSame($nilai, $respons->headers->get($nama), "Header {$nama} tidak sesuai.");
    }

    public function test_permissions_policy_mematikan_api_perangkat_sensitif(): void
    {
        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));
        $nilai = (string) $respons->headers->get('Permissions-Policy');

        foreach (['geolocation', 'microphone', 'camera'] as $fitur) {
            $this->assertStringContainsString($fitur.'=()', $nilai,
                "Fitur {$fitur} seharusnya dinonaktifkan pada Permissions-Policy.");
        }
    }

    public function test_csp_dikirim_dalam_mode_laporan_secara_bawaan(): void
    {
        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));

        $this->assertNull($respons->headers->get('Content-Security-Policy'),
            'Mode bawaan seharusnya Report-Only agar aktivasi dapat bertahap.');

        $laporan = (string) $respons->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $laporan);
        $this->assertStringContainsString("frame-ancestors 'self'", $laporan);
        $this->assertStringContainsString("object-src 'none'", $laporan);
    }

    public function test_csp_dapat_dinonaktifkan_lewat_env(): void
    {
        config(['keamanan.header.csp_aktif' => false]);

        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));

        $this->assertNull($respons->headers->get('Content-Security-Policy'));
        $this->assertNull($respons->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_csp_dapat_diterapkan_penuh_lewat_env(): void
    {
        config(['keamanan.header.csp_enforce' => true]);

        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));

        $this->assertNotNull($respons->headers->get('Content-Security-Policy'));
        $this->assertNull($respons->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_tidak_dikirim_pada_koneksi_http_meski_diaktifkan(): void
    {
        config(['keamanan.header.hsts_aktif' => true]);

        $respons = $this->lewatMiddleware(Request::create('http://localhost/uji', 'GET'));

        $this->assertNull($respons->headers->get('Strict-Transport-Security'),
            'HSTS tidak boleh dikirim lewat HTTP (akan mengunci localhost ke HTTPS).');
    }

    public function test_hsts_dikirim_pada_koneksi_https_bila_diaktifkan(): void
    {
        config(['keamanan.header.hsts_aktif' => true]);

        $respons = $this->lewatMiddleware(Request::create('https://koperasi.test/uji', 'GET'));

        $hsts = (string) $respons->headers->get('Strict-Transport-Security');
        $this->assertStringContainsString('max-age=', $hsts);
        $this->assertStringContainsString('includeSubDomains', $hsts);
    }

    public function test_header_keamanan_terpasang_pada_respons_web_aplikasi(): void
    {
        $respons = $this->get('/buka-rekening');

        // Halaman publik tidak memerlukan autentikasi sehingga cocok untuk
        // memastikan middleware global benar-benar aktif di jalur HTTP nyata.
        $this->assertTrue(
            in_array($respons->getStatusCode(), [200, 302], true),
            'Halaman publik seharusnya dapat diakses tanpa login.'
        );
        $respons->assertHeader('X-Content-Type-Options', 'nosniff');
        $respons->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_payload_sesi_dienkripsi_mengikuti_konfigurasi(): void
    {
        // Nilai sesi berasal dari env SESSION_ENCRYPT; dibaca lewat file config
        // agar tetap berlaku saat `config:cache` aktif di produksi. Default
        // dinyalakan (true) demi keamanan; hanya dimatikan bila env eksplisit.
        $this->assertSame(env('SESSION_ENCRYPT', true), config('session.encrypt'),
            'Konfigurasi enkripsi sesi harus bersumber dari env.');

        $berkas = require base_path('config/session.php');
        $this->assertArrayHasKey('encrypt', $berkas);
        $this->assertStringContainsString('SESSION_ENCRYPT',
            (string) file_get_contents(base_path('config/session.php')),
            'config/session.php harus membaca env SESSION_ENCRYPT.');
    }

    public function test_cookie_sesi_dilindungi_http_only_dan_same_site(): void
    {
        $this->assertTrue(config('session.http_only'),
            'Cookie sesi harus HttpOnly agar tidak dapat dibaca skrip klien.');
        $this->assertSame('lax', config('session.same_site'),
            'SameSite=Lax membatasi pengiriman cookie lintas-situs.');
    }

    public function test_konfigurasi_keamanan_dibaca_lewat_file_config(): void
    {
        // `env()` di luar file config bernilai null saat config:cache aktif,
        // sehingga middleware WAJIB membaca config, bukan env langsung.
        $this->assertIsArray(config('keamanan.header'));
        $this->assertArrayHasKey('csp_aktif', config('keamanan.header'));
        $this->assertArrayHasKey('hsts_aktif', config('keamanan.header'));

        $sumber = (string) file_get_contents(app_path('Http/Middleware/KeamananHeader.php'));
        $this->assertStringNotContainsString('env(', $sumber,
            'Middleware keamanan tidak boleh memanggil env() langsung — akan gagal saat config di-cache.');
    }

    public function test_endpoint_publik_dibatasi_laju(): void
    {
        $rute = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'pengajuan-rekening.publik.store');

        $this->assertNotNull($rute, 'Rute pengajuan publik tidak ditemukan.');

        $middleware = $rute->gatherMiddleware();
        $this->assertContains('throttle:10,1', $middleware,
            'Endpoint publik POST /buka-rekening wajib dibatasi laju (anti-abuse).');
    }

    public function test_rate_limit_endpoint_publik_membalas_429_setelah_batas_terlampaui(): void
    {
        // Batas POST adalah 10 permintaan per menit. Permintaan ke-11 harus
        // ditolak, membuktikan throttle benar-benar aktif (bukan sekadar label).
        for ($i = 0; $i < 10; $i++) {
            $this->post('/buka-rekening', [])->assertStatus(302);
        }

        $this->post('/buka-rekening', [])->assertStatus(429);
    }
}
