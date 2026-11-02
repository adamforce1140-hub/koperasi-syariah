<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan respons HTTP (hardening defensif).
 *
 * Dipasang global pada grup `web` sehingga berlaku untuk seluruh halaman
 * aplikasi maupun endpoint yang memakai sesi. Seluruh saklar dibaca dari
 * `config/keamanan.php` (yang bersumber dari variabel lingkungan) sehingga
 * tetap bekerja walau `php artisan config:cache` aktif di produksi.
 *
 * Lihat: docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md
 */
class KeamananHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $respons = $next($request);

        if (! $respons instanceof Response) {
            return $respons;
        }

        $this->pasangHeaderDasar($respons);
        $this->pasangCsp($respons);
        $this->pasangHsts($request, $respons);

        return $respons;
    }

    /** Header yang selalu aman dipasang, termasuk di lingkungan lokal. */
    private function pasangHeaderDasar(Response $respons): void
    {
        $header = [
            // Cegah MIME sniffing (browser menebak tipe berkas berbeda).
            'X-Content-Type-Options' => 'nosniff',
            // Batasi kebocoran URL lintas-origin.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Larang pemuatan dalam <frame> pihak ketiga (clickjacking).
            'X-Frame-Options' => 'SAMEORIGIN',
            // Matikan API perangkat yang tidak dipakai sistem.
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
        ];

        foreach ($header as $nama => $nilai) {
            if (! $respons->headers->has($nama)) {
                $respons->headers->set($nama, $nilai);
            }
        }
    }

    /**
     * Content Security Policy.
     *
     * Sumber daya yang diizinkan sengaja dibatasi pada asal yang sama.
     * `frame-ancestors` menggantikan peran X-Frame-Options pada peramban modern.
     *
     * S-04 — DUA MODE:
     *   - Mode longgar (default lokal/testing): `script-src`/`style-src` masih
     *     memuat `'unsafe-inline'` agar view lama yang memakai blok inline tetap
     *     berjalan. Header tetap dikirim sebagai Report-Only bila
     *     `csp_enforce=false`.
     *   - Mode KETAT (`keamanan.header.csp_strict=true`): nonce acak per
     *     permintaan ditambahkan ke `script-src`/`style-src` dan `'unsafe-inline'`
     *     DIBUANG, sehingga XSS inline benar-benar diblokir. Nonce juga
     *     diserahkan ke Vite (`Vite::useCspNonce`) agar aset bawaan `@vite`
     *     otomatis memakai nonce yang sama. Skrip/style inline yang masih
     *     tersisa harus diberi `nonce="{{ csp_nonce() }}"` sebelum mode ini
     *     diaktifkan di produksi.
     */
    private function pasangCsp(Response $respons): void
    {
        if (! (bool) config('keamanan.header.csp_aktif', true)) {
            return;
        }

        $ketat = (bool) config('keamanan.header.csp_strict', false);

        if ($ketat) {
            $nonce = Vite::useCspNonce();

            $sumberSkrip = "'self' 'nonce-{$nonce}' 'strict-dynamic'";
            $sumberGaya = "'self' 'nonce-{$nonce}'";
        } else {
            $sumberSkrip = "'self' 'unsafe-inline'";
            $sumberGaya = "'self' 'unsafe-inline'";
        }

        $kebijakan = implode('; ', [
            "default-src 'self'",
            "script-src {$sumberSkrip}",
            "style-src {$sumberGaya}",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $terapkan = (bool) config('keamanan.header.csp_enforce', false);

        $respons->headers->set(
            $terapkan ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            $kebijakan
        );
    }

    /**
     * Strict-Transport-Security HANYA pada koneksi HTTPS. Mengirim HSTS pada
     * `http://localhost` akan memaksa peramban mengunci skema https dan
     * membuat server pengembangan tidak bisa diakses.
     */
    private function pasangHsts(Request $request, Response $respons): void
    {
        if (! (bool) config('keamanan.header.hsts_aktif', false)) {
            return;
        }

        if (! $request->secure()) {
            return;
        }

        $respons->headers->set(
            'Strict-Transport-Security',
            'max-age='.(int) config('keamanan.header.hsts_max_age', 31536000).'; includeSubDomains'
        );
    }
}
