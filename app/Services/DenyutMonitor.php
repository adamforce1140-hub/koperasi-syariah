<?php

namespace App\Services;

use App\Support\DenyutData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DenyutMonitor — pemantau kesehatan mesin sinkronisasi real-time.
 *
 * MENGAPA KELAS INI ADA
 * ---------------------
 * Seluruh mesin denyut bergantung pada SATU sumbu: penyimpanan cache tempat
 * buku versi (`DenyutData`) ditulis. Bila cache bermasalah (disk penuh,
 * permission salah, driver salah konfigurasi, Redis mati), gejala yang terlihat
 * pengguna hanyalah "dashboard tidak pernah berubah" — tanpa pesan apa pun di
 * `laravel.log`. Keheningan itu membuat akar masalah sulit dilacak.
 *
 * Kelas ini menutup celah tersebut dengan dua fungsi:
 *
 *   1. `catat()` — menuliskan kegagalan baca/tulis buku versi ke log pada
 *      kanal khusus `denyut`, sekali per jendela waktu (anti-banjir log).
 *   2. `kesehatan()` — pemeriksaan mandiri (self-test) tulis→baca→bersih yang
 *      memberi jawaban "sehat/tidak" dalam satu pemanggilan, dipakai oleh
 *      perintah Artisan `denyut:kesehatan`.
 *
 * PRINSIP DEGRADASI ANGGUN
 * ------------------------
 * Kegagalan pemantauan TIDAK PERNAH boleh menghentikan operasional. Setiap
 * metode di sini menelan `Throwable`; yang terburuk adalah ketiadaan catatan
 * log, bukan transaksi teller yang gagal.
 */
class DenyutMonitor
{
    /** Kanal log khusus agar catatan denyut mudah dipisah dari log aplikasi. */
    public const KANAL = 'denyut';

    /** Awalan kunci uji tulis–baca (tidak bertabrakan dengan kunci domain). */
    private const KUNCI_UJI = 'denyut:selftest';

    /** Jendela anti-banjir log (detik). */
    private const JEDA_LOG = 60;

    /** Awalan kunci penghitung anti-banjir log. */
    private const KUNCI_LOG = 'denyut:log:';

    /**
     * Catat anomali mesin denyut tanpa pernah menghentikan pemanggil.
     *
     * Dipanggil dari titik-titik rawan (tulis buku versi, pembacaan snapshot).
     * Pesan ditulis paling sering sekali per `JEDA_LOG` detik per konteks agar
     * kegagalan beruntun tidak membanjiri berkas log.
     *
     * @param  array<string,mixed>  $konteks
     */
    public static function catat(string $konteks, Throwable $kesalahan, array $konteksTambahan = []): void
    {
        try {
            if (! self::bolehCatat($konteks)) {
                return;
            }

            Log::channel(self::KANAL)->warning('Denyut real-time bermasalah', array_merge([
                'konteks' => $konteks,
                'pesan' => $kesalahan->getMessage(),
                'kelas' => $kesalahan::class,
            ], $konteksTambahan));
        } catch (Throwable) {
            // Pemantauan tidak boleh menjadi sumber kegagalan baru.
        }
    }

    /**
     * Pemeriksaan mandiri penyimpanan buku versi.
     *
     * Melakukan satu siklus tulis → baca → bersih pada kunci uji yang terpisah
     * dari domain bisnis, sehingga aman dijalankan kapan saja — termasuk di
     * tengah jam operasional — tanpa menaikkan versi domain mana pun.
     *
     * @return array{
     *     sehat: bool,
     *     driver: string,
     *     pesan: string,
     *     versi_terbaca: int|null,
     *     latensi_ms: float
     * }
     */
    public static function kesehatan(): array
    {
        $driver = (string) config('cache.default');
        $mulai = microtime(true);

        try {
            $nilaiAwal = (int) Cache::get(self::KUNCI_UJI, 0);
            $baru = (int) Cache::increment(self::KUNCI_UJI);
            $terbaca = (int) Cache::get(self::KUNCI_UJI, 0);

            Cache::forget(self::KUNCI_UJI);

            $latensi = (microtime(true) - $mulai) * 1000;

            if ($terbaca !== $nilaiAwal + 1) {
                return [
                    'sehat' => false,
                    'driver' => $driver,
                    'pesan' => 'Nilai tidak konsisten: diharapkan '.($nilaiAwal + 1).", terbaca {$terbaca}.",
                    'versi_terbaca' => $terbaca,
                    'latensi_ms' => round($latensi, 3),
                ];
            }

            return [
                'sehat' => true,
                'driver' => $driver,
                'pesan' => 'Buku versi dapat ditulis dan dibaca.',
                'versi_terbaca' => $terbaca,
                'latensi_ms' => round($latensi, 3),
            ];
        } catch (Throwable $e) {
            return [
                'sehat' => false,
                'driver' => $driver,
                'pesan' => 'Gagal: '.$e->getMessage(),
                'versi_terbaca' => null,
                'latensi_ms' => round((microtime(true) - $mulai) * 1000, 3),
            ];
        }
    }

    /**
     * Ringkasan diagnostik untuk operator: versi seluruh domain + umur proses.
     *
     * @return array<string,mixed>
     */
    public static function ringkasan(): array
    {
        $peta = DenyutData::peta();

        return [
            'tanda' => $peta['tanda'],
            'versi' => $peta['versi'],
            'total_mutasi' => array_sum($peta['versi']),
            'transportasi' => [
                'sse' => (bool) config('denyut.sse'),
                'tunggu' => (bool) config('denyut.tunggu'),
            ],
            'kesehatan' => self::kesehatan(),
        ];
    }

    /**
     * Kendali anti-banjir log. Benar bila konteks belum dicatat dalam `JEDA_LOG`.
     */
    private static function bolehCatat(string $konteks): bool
    {
        try {
            return Cache::add(self::KUNCI_LOG.$konteks, time(), self::JEDA_LOG);
        } catch (Throwable) {
            // Bila cache sendiri sedang bermasalah, tetap izinkan pencatatan
            // agar kegagalan justru terekam, bukan hilang.
            return true;
        }
    }
}
