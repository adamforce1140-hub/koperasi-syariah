<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan pos alokasi ke-5 (Dana Pendidikan & Sosial) pada SHU Tahunan.
 *
 * Sebelumnya SHU hanya dibagi ke 4 pos: Cadangan, Jasa Modal, Jasa Transaksi,
 * dan Dana Pengurus. Sesuai AD/ART ditambahkan pos Dana Pendidikan & Sosial
 * sehingga total alokasi tetap dapat mencapai 100%.
 *
 * Akun jurnal untuk pos ini adalah 2-3003 (Utang Dana Sosial & Pendidikan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shu_periods', function (Blueprint $table) {
            // Ditempatkan setelah persen_pengurus agar urutan kolom tetap logis.
            $table->decimal('persen_sosial', 5, 2)->default(0)->after('persen_pengurus');
            $table->decimal('nominal_sosial', 18, 2)->default(0)->after('nominal_pengurus');
        });
    }

    public function down(): void
    {
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->dropColumn(['persen_sosial', 'nominal_sosial']);
        });
    }
};
