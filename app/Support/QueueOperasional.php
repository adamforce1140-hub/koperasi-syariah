<?php

namespace App\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Config;

/**
 * Gerbang operasi berat (job) — Rekomendasi 4, langkah 5 & 7.
 *
 * Menentukan apakah sebuah job dijalankan melalui antrian (asinkron) atau
 * langsung (sinkron), dan menyediakan parameter ketahanan (tries/backoff/
 * timeout) dari config `job_operasional`.
 *
 * ATURAN UTAMA: `aktif()` default FALSE sehingga seluruh operasi berjalan
 * SINKRON — persis perilaku sistem sebelum ekstraksi job. Tidak ada perubahan
 * hasil sampai operator sengaja menyalakan `FEATURE_QUEUE_ASYNC`.
 */
class QueueOperasional
{
    /**
     * Apakah antrian asinkron diaktifkan?
     */
    public static function aktif(): bool
    {
        return (bool) Config::get('job_operasional.aktif', false);
    }

    /**
     * Jalankan sebuah job sesuai saklar.
     *
     *  - antrian MATI (default) → job dieksekusi LANGSUNG pada proses yang
     *    sama melalui `Bus::dispatchNow()` sehingga TIDAK menyentuh koneksi
     *    antrian sama sekali (tidak ada record di tabel `jobs`).
     *  - antrian HIDUP            → job didorong ke antrian (`dispatch`).
     *
     * Mengembalikan job yang dieksekusi agar pemanggil (dan pengujian) dapat
     * memeriksa hasilnya.
     */
    public static function jalankan(ShouldQueue $job): ShouldQueue
    {
        if (static::aktif()) {
            dispatch($job);

            return $job;
        }

        /* Mode sinkron memakai `Bus::dispatchNow()` — BUKAN `dispatch_sync()`
         | maupun `dispatchSync()`. Pada Laravel 12, job yang `ShouldQueue`
         | akan tetap dialihkan ke `Queue::push()` oleh kedua metode tersebut
         | (karena `commandShouldBeQueued()`), sehingga terekam sebagai job
         | antrian. `dispatchNow()` mengeksekusi handler pada proses ini
         | tanpa menyentuh antrian — persis perilaku sebelum ekstraksi job. */
        app(Dispatcher::class)->dispatchNow($job);

        return $job;
    }

    /**
     * Parameter ketahanan (tries/backoff/timeout) untuk sebuah job.
     *
     * @return array{tries: int, backoff: array<int, int>, timeout: int}
     */
    public static function parameter(string $kunci): array
    {
        $default = [
            'tries' => 3,
            'backoff' => [60, 300, 900],
            'timeout' => 900,
        ];

        $konfig = Config::get("job_operasional.jobs.{$kunci}", []);

        if (! is_array($konfig)) {
            return $default;
        }

        return [
            'tries' => (int) ($konfig['tries'] ?? $default['tries']),
            'backoff' => (array) ($konfig['backoff'] ?? $default['backoff']),
            'timeout' => (int) ($konfig['timeout'] ?? $default['timeout']),
        ];
    }
}
