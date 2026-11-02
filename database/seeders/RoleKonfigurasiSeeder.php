<?php

namespace Database\Seeders;

use App\Models\RoleKonfigurasi;
use Illuminate\Database\Seeder;

/**
 * Menyimpan konfigurasi peran ke tabel `role_konfigurasi` — langkah #6
 * permintaan pemilik sistem: pengaturan hak akses harus BERTAHAN di semua
 * sesi, tidak bergantung pada berkas config yang berubah tiap deploy.
 *
 * Idempoten: dapat dijalankan berulang tanpa menggandakan baris.
 */
class RoleKonfigurasiSeeder extends Seeder
{
    public function run(): void
    {
        $hasil = RoleKonfigurasi::sinkronkan();

        $hitung = array_count_values($hasil);

        $this->command?->info(sprintf(
            'role_konfigurasi: %d dibuat, %d diperbarui, %d tetap.',
            $hitung['dibuat'] ?? 0,
            $hitung['diperbarui'] ?? 0,
            $hitung['tetap'] ?? 0
        ));
    }
}
