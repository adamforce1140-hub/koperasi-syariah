<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penambahan parameter metode perhitungan partisipasi SHU anggota.
 *
 * Untuk koperasi syariah, satu-satunya metode yang diakui sistem adalah
 * `SRH` (Saldo Rata-rata Harian) karena pembagian jasa modal/usaha harus
 * mencerminkan kontribusi anggota sepanjang periode, bukan posisi akhir
 * periode (metode saldo akhir) maupun bobot waktu tetap.
 *
 * Kolom sengaja tetap berupa ENUM dengan satu nilai valid ('SRH') agar:
 *   1. Nilai yang tidak dikenal tertolak di lapisan database, bukan hanya
 *      pada validasi aplikasi (pertahanan berlapis).
 *   2. Penambahan metode baru di masa depan cukup melalui migrasi
 *      `MODIFY COLUMN`, tanpa mengubah struktur tipe data.
 *
 * Perubahan nilai aktual (bila ada) diserahkan kepada pengurus via UI
 * Kebijakan; migrasi hanya menyediakan kolom beserta default 'SRH'.
 *
 * Operasi dijaga `Schema::hasColumn` sehingga migrasi idempoten dan aman
 * dijalankan berulang.
 *
 * PSAK terkait: 101 (penyajian laporan keuangan syariah) dan pedoman
 * akuntansi koperasi syariah (pembagian SHU berbasis kontribusi anggota).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        if (! Schema::hasColumn('kebijakan_koperasis', 'metode_perhitungan_shu')) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->enum('metode_perhitungan_shu', ['SRH'])
                    ->default('SRH')
                    ->after('shu_pct_dana_pendidikan')
                    ->comment('Metode perhitungan partisipasi SHU anggota. Satu-satunya nilai yang didukung: SRH (Saldo Rata-rata Harian).');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        if (Schema::hasColumn('kebijakan_koperasis', 'metode_perhitungan_shu')) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->dropColumn('metode_perhitungan_shu');
            });
        }
    }
};
