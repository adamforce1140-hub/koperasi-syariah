<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter persentase dinamis pada Master COA (PSAK 101/108).
 *
 * Sebelumnya nisbah bagi hasil Simpanan Sukarela dan rate margin pembiayaan
 * ditanam di kode (hardcode) atau tersebar di tabel produk. Dengan kolom ini
 * seluruh parameter persentase menjadi SATU SUMBER KEBENARAN di Master COA
 * sehingga dapat diubah kapan saja lewat menu Master COA tanpa mengubah kode.
 *
 * Empat kolom baru pada tabel `coas`:
 *  - `has_percentage`                : penanda akun menerapkan persentase
 *                                      (nisbah / margin / SHU). Simpanan Pokok
 *                                      dan Simpanan Wajib bernilai false karena
 *                                      imbalannya diselesaikan lewat SHU Tahunan
 *                                      pada bagian Ekuitas, bukan nisbah bulanan.
 *  - `percentage_type`               : nisbah | margin | shu
 *  - `default_percentage_member`     : Nisbah Anggota ATAU Rate Margin Pembiayaan
 *  - `default_percentage_institution`: Nisbah Koperasi (pengelola dana)
 *
 * Catatan tabel: nama tabel nyata di proyek ini adalah `coas` (bukan
 * `chart_of_accounts`). Karena penambahan kolom pada tabel `coas` sepenuhnya
 * dimungkinkan, tabel relasi terpisah `coa_settings` tidak diperlukan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coas', function (Blueprint $table) {
            if (! Schema::hasColumn('coas', 'has_percentage')) {
                // Ditempatkan setelah `klasifikasi_neraca` agar urutan kolom
                // konfigurasi tetap berdekatan dengan atribut penyajian.
                $table->boolean('has_percentage')->default(false)->after('klasifikasi_neraca');
            }

            if (! Schema::hasColumn('coas', 'percentage_type')) {
                $table->enum('percentage_type', ['nisbah', 'margin', 'shu'])
                    ->nullable()
                    ->default(null)
                    ->after('has_percentage');
            }

            if (! Schema::hasColumn('coas', 'default_percentage_member')) {
                $table->decimal('default_percentage_member', 5, 2)
                    ->default(0)
                    ->after('percentage_type');
            }

            if (! Schema::hasColumn('coas', 'default_percentage_institution')) {
                $table->decimal('default_percentage_institution', 5, 2)
                    ->default(0)
                    ->after('default_percentage_member');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coas', function (Blueprint $table) {
            foreach ([
                'default_percentage_institution',
                'default_percentage_member',
                'percentage_type',
                'has_percentage',
            ] as $kolom) {
                if (Schema::hasColumn('coas', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
