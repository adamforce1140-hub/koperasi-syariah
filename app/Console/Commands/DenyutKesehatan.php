<?php

namespace App\Console\Commands;

use App\Services\DenyutMonitor;
use App\Support\DenyutData;
use Illuminate\Console\Command;

/**
 * DenyutKesehatan — periksa kesehatan mesin sinkronisasi real-time.
 *
 * KAPAN PERINTAH INI DIPAKAI
 * --------------------------
 * Gejala paling menipu dari mesin denyut adalah DIAM: pengguna mengeluh
 * "dashboard tidak pernah berubah", tetapi tidak ada galat di `laravel.log`
 * karena seluruh kegagalan buku versi ditelan diam-diam (demi keselamatan
 * transaksi keuangan — lihat `DenyutData::bump()`).
 *
 * Perintah ini memberi operator satu jawaban tegas dalam satu kali jalan:
 *
 *   1. Apakah penyimpanan cache tempat buku versi ditulis benar-benar hidup
 *      (uji tulis → baca → bersih pada kunci terpisah dari domain bisnis)?
 *   2. Berapa versi setiap domain saat ini — untuk memastikan observer memang
 *      menaikkan versi saat data berubah?
 *   3. Transportasi mana yang sedang aktif (SSE / long polling) sesuai konfigurasi?
 *
 * Exit code FAILURE dikembalikan bila self-test gagal, sehingga perintah dapat
 * dipakai sebagai gerbang pemeriksaan pada pipeline rilis maupun cron pemantau.
 */
class DenyutKesehatan extends Command
{
    protected $signature = 'denyut:kesehatan
                            {--rinci : Tampilkan versi seluruh domain, bukan hanya ringkasan}';

    protected $description = 'Periksa kesehatan mesin sinkronisasi real-time (buku versi denyut) beserta transportasinya.';

    public function handle(): int
    {
        $kesehatan = DenyutMonitor::kesehatan();
        $ringkasan = DenyutMonitor::ringkasan();

        $this->info('PEMERIKSAAN MESIN SINKRONISASI REAL-TIME (DENYUT)');
        $this->newLine();

        $this->table(
            ['Properti', 'Nilai'],
            [
                ['Driver cache', $kesehatan['driver']],
                ['Transportasi SSE', $this->ya($ringkasan['transportasi']['sse'])],
                ['Transportasi long polling', $this->ya($ringkasan['transportasi']['tunggu'])],
                ['Total mutasi terhitung', number_format($ringkasan['total_mutasi'], 0, ',', '.')],
                ['Sidik jari (tanda)', $ringkasan['tanda']],
                ['Latensi self-test', number_format($kesehatan['latensi_ms'], 3, ',', '.').' ms'],
            ]
        );

        if ($this->option('rinci')) {
            $this->newLine();
            $this->line('Versi per domain:');
            $this->table(
                ['Domain', 'Versi'],
                collect(DenyutData::domain())
                    ->map(fn (string $d) => [$d, number_format($ringkasan['versi'][$d] ?? 0, 0, ',', '.')])
                    ->all()
            );
        }

        $this->newLine();

        if ($kesehatan['sehat']) {
            $this->info('SEHAT — '.$kesehatan['pesan']);

            return self::SUCCESS;
        }

        $this->error('TIDAK SEHAT — '.$kesehatan['pesan']);
        $this->warn('Periksa konfigurasi CACHE_DRIVER, izin tulis folder cache, dan ketersediaan layanan cache (Redis/Memcached) bila dipakai.');

        return self::FAILURE;
    }

    private function ya(bool $nilai): string
    {
        return $nilai ? 'aktif' : 'nonaktif';
    }
}
