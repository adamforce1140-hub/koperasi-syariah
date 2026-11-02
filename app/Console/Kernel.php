<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        /*
         * PENTING: method ini TIDAK PERNAH DIPANGGIL.
         *
         * Bootstrap Laravel 12 proyek ini (lihat bootstrap/app.php) memakai
         * Illuminate\Foundation\Console\Kernel, bukan App\Console\Kernel.
         * Seluruh penjadwalan karena itu WAJIB didaftarkan di routes/console.php
         * agar benar-benar ter-boot dan muncul pada `php artisan schedule:list`:
         *
         *   - deposito:process-maturity         (harian 00:01 WIB)
         *   - deposito:process-matured          (harian 00:01 WIB)
         *   - mandatory-savings:process-daily   (harian 00:01 WIB)
         *   - ckpn:hitung-penyesuaian           (harian 00:01 WIB)
         *   - angsuran:run-autodebet            (harian 07:00 WIB)
         *
         * Isi di bawah hanya disimpan sebagai dokumentasi; jangan menambahkan
         * jadwal baru di sini karena tidak akan pernah dieksekusi.
         *
         * Command `inspire` bawaan framework sengaja TIDAK dijadwalkan: tidak
         * memberi nilai operasional dan hanya mengotori log produksi.
         */
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
