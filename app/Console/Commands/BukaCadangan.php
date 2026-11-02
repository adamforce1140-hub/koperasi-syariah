<?php

namespace App\Console\Commands;

use App\Support\CadanganTerenkripsi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * BukaCadangan — dekripsi cadangan `.sql.gz.enc` menjadi `.sql.gz`.
 *
 * Pasangan dari `db:cadangkan`. Tanpa perintah ini berkas cadangan terenkripsi
 * tidak dapat dipulihkan, sehingga enkripsi hanya akan menjadi jalan buntu
 * saat bencana terjadi.
 *
 *   php artisan db:buka-cadangan storage/app/backups/koperasi_syariah-20261029-030000.sql.gz.enc
 *   gunzip -c koperasi_syariah-20261029-030000.sql.gz | mysql -u root -p koperasi_syariah
 */
class BukaCadangan extends Command
{
    protected $signature = 'db:buka-cadangan
                            {berkas : Jalur berkas cadangan .sql.gz.enc}
                            {--tujuan= : Jalur keluaran (default: nama berkas tanpa .enc)}';

    protected $description = 'Mendekripsi berkas cadangan basis data (.sql.gz.enc) menjadi .sql.gz';

    public function handle(): int
    {
        $berkas = (string) $this->argument('berkas');

        if (! is_file($berkas)) {
            $this->error("Berkas cadangan tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $tujuan = (string) ($this->option('tujuan') ?: preg_replace('/\.enc$/', '', $berkas));

        if ($tujuan === $berkas) {
            $this->error('Berkas tujuan sama dengan berkas sumber. Tentukan --tujuan yang berbeda.');

            return self::FAILURE;
        }

        try {
            CadanganTerenkripsi::dekripsiBerkas($berkas, $tujuan);
        } catch (Throwable $e) {
            $this->error('Dekripsi gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Cadangan dibuka: %s (%.1f KB)', $tujuan, File::size($tujuan) / 1024));

        return self::SUCCESS;
    }
}
