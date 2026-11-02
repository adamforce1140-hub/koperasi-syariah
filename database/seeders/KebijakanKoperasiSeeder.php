<?php

namespace Database\Seeders;

use App\Models\KebijakanKoperasi;
use App\Support\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seed baris TUNGGAL (singleton) kebijakan koperasi.
 *
 * Tabel `kebijakan_koperasis` dirancang menyimpan satu baris berisi banyak
 * kolom parameter (lihat AdminSettingsController — "satu baris, banyak kolom").
 * Tanpa seeder ini, `KebijakanKoperasi::first()` mengembalikan null sehingga
 * setiap pemanggil yang membutuhkan instance utuh — form editor Pengurus
 * maupun test — berangkat dari baris kosong (kolom NULL) dan gagal validasi.
 *
 * Nilai awal diambil dari `Setting::DEFAULTS` (satu sumber nilai fallback)
 * agar baris hasil seed identik dengan perilaku default sistem. Hanya kolom
 * yang benar-benar ada di tabel yang diisi (lihat Schema::getColumnListing)
 * sehingga seeder tetap aman bila struktur tabel berubah — mis. `Setting`
 * memuat kunci yang bukan kolom (account_dana_*).
 *
 * Idempoten: baris yang sudah ada tidak ditimpa sehingga perubahan kebijakan
 * dari UI tidak pernah terhapus oleh `db:seed` ulang.
 */
class KebijakanKoperasiSeeder extends Seeder
{
    public function run(): void
    {
        if (KebijakanKoperasi::query()->exists()) {
            $this->command?->info('KebijakanKoperasiSeeder: baris kebijakan sudah ada — dilewati (idempoten).');

            return;
        }

        $kolom = Schema::getColumnListing('kebijakan_koperasis');
        $data = array_intersect_key(Setting::DEFAULTS, array_flip($kolom));

        KebijakanKoperasi::create($data);

        $this->command?->info('KebijakanKoperasiSeeder: baris kebijakan default dibuat ('.count($data).' parameter).');
    }
}
