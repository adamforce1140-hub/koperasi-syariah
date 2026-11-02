<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Assert;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Penjaga cache sekali-per-proses.
     *
     * Prosedur proyek mewajibkan `php artisan optimize:clear` SEBELUM
     * `php artisan test` pada setiap sesi pengujian. Skrip resmi
     * (`scripts/run-tests.ps1`) sudah menegakkannya, tetapi jalur langsung
     * (`php artisan test`) tidak. Penjaga ini menutup celah tersebut agar
     * cache yang tidak teratur tidak pernah menghasilkan kegagalan palsu.
     *
     * Risiko yang dicegah: `bootstrap/cache/config.php` membekukan isi .env
     * termasuk APP_KEY; suite yang berjalan di atasnya gagal massal dengan
     * galat 419 CSRF yang menyesatkan.
     */
    private static bool $cacheSudahDivalidasi = false;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        if (! self::$cacheSudahDivalidasi) {
            self::$cacheSudahDivalidasi = true;
            $this->pastikanCacheBersih();

            // Cache rute/konfigurasi yang ter-cache juga dapat menyembunyikan
            // rute baru. Wajib dinonaktifkan selama pengujian.
            Assert::assertFalse(
                $app->configurationIsCached(),
                'Konfigurasi masih ter-cache saat suite berjalan. Jalankan '
                .'"php artisan optimize:clear" lebih dahulu (lihat docs/RUNNING-SERVER.md).'
            );
        }

        return $app;
    }

    /**
     * Pastikan tidak ada artefak cache konfigurasi yang tertinggal.
     *
     * CATATAN PENTING: `bootstrap/cache/packages.php` dan `services.php`
     * BUKAN cache konfigurasi. Keduanya adalah manifest paket hasil
     * `package:discover` yang SENGAJA dibiarkan oleh `optimize:clear` dan
     * dibutuhkan Laravel untuk booting. Memperlakukannya sebagai pelanggaran
     * akan menolak semua pengujian — termasuk yang sudah bersih.
     *
     * Yang benar-benar berbahaya (dan karenanya diperiksa):
     *   • config.php  — membekukan seluruh isi .env, termasuk APP_KEY.
     *   • routes-*.php — menyembunyikan rute yang baru ditambahkan.
     *   • events.php  — membekukan peta event/listener.
     */
    private function pastikanCacheBersih(): void
    {
        $direktori = dirname(__DIR__).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';

        if (! is_dir($direktori)) {
            return;
        }

        // Manifest paket bawaan Laravel — selalu ada, tidak pernah dianggap kotor.
        $dikecualikan = ['packages.php', 'services.php'];

        $sisa = [];
        foreach (glob($direktori.DIRECTORY_SEPARATOR.'*.php') ?: [] as $berkas) {
            if (! in_array(basename($berkas), $dikecualikan, true)) {
                $sisa[] = basename($berkas);
            }
        }

        if (! empty($sisa)) {
            throw new RuntimeException(
                'Cache belum direset sebelum pengujian. Berkas tertinggal: '
                .implode(', ', $sisa)
                .". Jalankan 'php artisan optimize:clear' lebih dahulu "
                .'(lihat docs/RUNNING-SERVER.md, bagian "Prosedur Pengujian").'
            );
        }
    }
}
