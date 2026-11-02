<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\RoleKonfigurasi;
use App\Support\HierarkiRole;
use App\Support\Izin;
use Illuminate\Console\Command;

/**
 * Pulihkan hak akses yang tercatat di `role_konfigurasi` — langkah #3 & #6.
 *
 *   php artisan role:pulihkan --dry-run   # tampilkan yang akan dipulihkan
 *   php artisan role:pulihkan             # pulihkan lalu sinkronkan ulang
 *
 * Hanya MENAMBAH izin yang tercatat tetapi hilang dari config; tidak pernah
 * mencabut. Bila pewarisan puncak administratif (`super_admin`) tidak utuh,
 * perintah menolak berjalan dan melaporkannya — puncak administratif tidak
 * boleh kehilangan hak aksesnya secara diam-diam.
 */
class PulihkanKonfigurasiRole extends Command
{
    protected $signature = 'role:pulihkan {--dry-run : Tampilkan saja, tanpa mengubah apa pun}';

    protected $description = 'Pulihkan hak akses peran yang tercatat di role_konfigurasi namun hilang dari config';

    public function handle(): int
    {
        $puncak = HierarkiRole::puncak();
        $catatan = RoleKonfigurasi::query()->where('role', $puncak)->first();

        // Integritas puncak administratif: snapshot-nya WAJIB ada dan memuat
        // seluruh izin modul. Tanpa snapshot, tidak ada dasar pemulihan.
        if ($catatan === null) {
            $this->error("Snapshot peran '{$puncak}' belum ada. Jalankan `php artisan role:sinkronkan` lebih dulu.");

            return self::FAILURE;
        }

        $tercatat = (array) ($catatan->izin ?? []);
        $berlaku = [];

        foreach (Izin::peta() as $nama => $daftar) {
            if (in_array($puncak, array_map('strval', (array) $daftar), true)) {
                $berlaku[] = $nama;
            }
        }

        $hilangDiPuncak = array_values(array_diff($tercatat, $berlaku));

        if ($hilangDiPuncak !== []) {
            $this->warn("Peran '{$puncak}' kehilangan izin tercatat: ".implode(', ', $hilangDiPuncak));
        }

        $dipulihkan = RoleKonfigurasi::pulihkan();

        if ($dipulihkan === []) {
            $this->info('Tidak ada hak akses yang perlu dipulihkan.');

            return self::SUCCESS;
        }

        foreach ($dipulihkan as $peran => $daftar) {
            $this->line("  {$peran}: +".implode(', +', $daftar));
        }

        if ($this->option('dry-run')) {
            $this->comment('Mode dry-run: config tidak diubah.');

            return self::SUCCESS;
        }

        // Perubahan config hanya berlaku pada proses ini. Sinkronkan ulang
        // agar snapshot di database kembali sama dengan config yang berlaku.
        RoleKonfigurasi::sinkronkan(true);

        AuditLog::record(
            'role.pulihkan',
            0,
            null,
            'Hak akses dipulihkan dari role_konfigurasi: '
                .collect($dipulihkan)->map(fn ($daftar, $peran) => $peran.' ('.count($daftar).' izin)')->implode(', ')
        );

        $this->info('Hak akses dipulihkan. Berkas config perlu diselaraskan bila penyimpangan berasal dari suntingan manual.');

        return self::SUCCESS;
    }
}
