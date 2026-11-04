<?php

namespace Tests\Feature;

use App\Http\Middleware\KeamananHeader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
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

    public function test_csp_dikirim_dalam_mode_laporan_di_lingkungan_non_produksi(): void
    {
        // Pada APP_ENV=local/testing, default config adalah Report-Only agar
        // aktivasi CSP dapat bertahap tanpa memutus UI pengembangan.
        config(['keamanan.header.csp_enforce' => false]);

        $respons = $this->lewatMiddleware(Request::create('/uji', 'GET'));

        $this->assertNull($respons->headers->get('Content-Security-Policy'),
            'Mode Report-Only tidak boleh mengirim header penegakan.');

        $laporan = (string) $respons->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $laporan);
        $this->assertStringContainsString("frame-ancestors 'self'", $laporan);
        $this->assertStringContainsString("object-src 'none'", $laporan);
    }

    /**
     * Nilai default `csp_enforce` bergantung pada lingkungan: produksi menegakkan
     * CSP, local/testing hanya melaporkan. Test ini mengevaluasi berkas config
     * apa adanya pada dua nilai APP_ENV berbeda, sehingga tetap sah tanpa
     * bergantung pada APP_ENV saat suite dijalankan.
     */
    public function test_default_csp_menegakkan_header_di_produksi(): void
    {
        $semula = [$_ENV['APP_ENV'] ?? null, $_SERVER['APP_ENV'] ?? null];

        try {
            foreach (['production' => true, 'local' => false, 'testing' => false] as $lingkungan => $harusMenegakkan) {
                putenv("APP_ENV={$lingkungan}");
                $_ENV['APP_ENV'] = $lingkungan;
                $_SERVER['APP_ENV'] = $lingkungan;

                $config = require base_path('config/keamanan.php');

                $this->assertSame(
                    $harusMenegakkan,
                    $config['header']['csp_enforce'],
                    "Default csp_enforce pada APP_ENV={$lingkungan} tidak sesuai."
                );
            }
        } finally {
            putenv($semula[0] === null ? 'APP_ENV' : "APP_ENV={$semula[0]}");
            $_ENV['APP_ENV'] = $semula[0];
            $_SERVER['APP_ENV'] = $semula[1];
        }
    }

    public function test_view_tidak_memuat_aset_dari_asal_luar(): void
    {
        // CSP produksi hanya mengizinkan asal sendiri. Setiap rujukan aset
        // eksternal (font, CSS, JS, gambar) akan diblokir peramban dan
        // mematahkan tampilan, sekaligus membuat sistem bergantung internet.
        $pelanggaran = [];

        foreach (File::allFiles(resource_path('views')) as $berkas) {
            if (! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $isi = (string) preg_replace('~\{\{--.*?--\}\}~s', '', $berkas->getContents());

            if (preg_match_all('~\b(?:src|href)\s*=\s*["\']\s*https?://[^"\']+~i', $isi, $cocok)) {
                foreach ($cocok[0] as $temuan) {
                    $pelanggaran[] = $berkas->getRelativePathname().' → '.$temuan;
                }
            }

            if (preg_match_all('~url\(\s*["\']?https?://[^)"\']+~i', $isi, $cocok)) {
                foreach ($cocok[0] as $temuan) {
                    $pelanggaran[] = $berkas->getRelativePathname().' → '.$temuan;
                }
            }
        }

        $this->assertSame([], $pelanggaran, implode(PHP_EOL, array_merge(
            ['Aset eksternal berikut akan diblokir CSP produksi:'],
            $pelanggaran
        )));
    }

    public function test_aset_pihak_ketiga_disajikan_dari_server_sendiri(): void
    {
        $wajib = [
            'public/css/figtree.css',
            'public/vendor/tom-select/tom-select.bootstrap5.min.css',
            'public/vendor/tom-select/tom-select.complete.min.js',
            'public/vendor/sweetalert2/sweetalert2.min.css',
            'public/vendor/sweetalert2/sweetalert2.all.min.js',
            'public/js/qrcode.min.js',
        ];

        foreach ($wajib as $jalur) {
            $this->assertFileExists(public_path(substr($jalur, strlen('public/'))), "Aset lokal {$jalur} tidak ditemukan.");
        }

        $font = glob(public_path('fonts/figtree/*.woff2')) ?: [];
        $this->assertCount(8, $font, 'Delapan berkas Figtree (4 bobot × 2 subset) harus tersedia lokal.');
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
