<?php

namespace App\Console\Commands;

use App\Models\RoleKonfigurasi;
use Illuminate\Console\Command;

/**
 * Snapshot konfigurasi peran di database — langkah #6.
 *
 *   php artisan role:sinkronkan            # tulis snapshot dari config
 *   php artisan role:sinkronkan --cek      # hanya periksa (exit 1 bila ada selisih)
 *   php artisan role:sinkronkan --paksa    # tulis ulang seluruh baris
 *
 * Baris hasil sinkronisasi adalah CATATAN KEBENARAN: bila config berubah
 * tanpa sengaja (izin hilang), `--cek` menampilkannya sehingga hak akses
 * tidak lenyap diam-diam. Lihat `role:pulihkan` untuk memulihkannya.
 */
class SinkronkanKonfigurasiRole extends Command
{
    protected $signature = 'role:sinkronkan
                            {--cek : Hanya laporkan selisih, tanpa menulis}
                            {--paksa : Tulis ulang seluruh baris walau isinya sama}';

    protected $description = 'Sinkronkan konfigurasi peran (izin, kelompok, izin dokumen) ke tabel role_konfigurasi';

    public function handle(): int
    {
        if ($this->option('cek')) {
            return $this->laporkanSelisih();
        }

        $hasil = RoleKonfigurasi::sinkronkan((bool) $this->option('paksa'));

        $hitung = array_count_values($hasil);

        $this->table(
            ['Peran', 'Keadaan'],
            collect($hasil)->map(fn (string $keadaan, string $peran): array => [$peran, $keadaan])->values()->all()
        );

        $this->info(sprintf(
            'Selesai: %d dibuat, %d diperbarui, %d tetap.',
            $hitung['dibuat'] ?? 0,
            $hitung['diperbarui'] ?? 0,
            $hitung['tetap'] ?? 0
        ));

        return self::SUCCESS;
    }

    private function laporkanSelisih(): int
    {
        $selisih = RoleKonfigurasi::selisih();

        if ($selisih === []) {
            $this->info('Konfigurasi peran di database SELARAS dengan config.');

            return self::SUCCESS;
        }

        foreach ($selisih as $peran => $rincian) {
            $this->warn("Peran '{$peran}': {$rincian['jenis']}");

            if (isset($rincian['rincian'])) {
                foreach ($rincian['rincian'] as $kunci => $daftar) {
                    if ($daftar['hilang'] !== []) {
                        $this->line('  - hilang di config ('.$kunci.'): '.implode(', ', $daftar['hilang']));
                    }

                    if ($daftar['muncul'] !== []) {
                        $this->line('  + baru di config ('.$kunci.'): '.implode(', ', $daftar['muncul']));
                    }
                }
            }
        }

        $this->error(count($selisih).' peran menyimpang. Jalankan `php artisan role:sinkronkan`.');

        return self::FAILURE;
    }
}
