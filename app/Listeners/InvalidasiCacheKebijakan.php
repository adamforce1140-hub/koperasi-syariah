<?php

namespace App\Listeners;

use App\Events\KebijakanBerubah;
use App\Support\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Listener: invalidasi cache pengaturan saat baris kebijakan koperasi berubah.
 *
 * IDEMPOTEN & AMAN: `Setting::flush()` hanya menghapus cache, sehingga aman
 * dipanggil berulang kali. Exception ditelan agar penyimpanan kebijakan tidak
 * pernah gagal karena masalah cache.
 */
class InvalidasiCacheKebijakan
{
    public function handle(KebijakanBerubah $event): void
    {
        try {
            Setting::flush();
        } catch (\Throwable $e) {
            Log::warning('Gagal invalidasi cache kebijakan: '.$e->getMessage());
        }
    }
}
