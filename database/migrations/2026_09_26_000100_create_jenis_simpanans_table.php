<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data Jenis Simpanan (parameter dinamis).
 *
 * Sebelumnya pemetaan "jenis simpanan -> kode akun COA" tersebar hardcoded di
 * controller (mis. $akunMap pada CifController) dan di penyedia jurnal
 * otomatis. Tabel ini menjadi sumber kebenaran tunggal sehingga pengurus dapat
 * menambah / mengubah / menonaktifkan jenis simpanan beserta akun COA-nya
 * kapan saja tanpa mengubah kode inti sistem.
 *
 * Atribut sesuai kebutuhan:
 *   - id          : ID unik
 *   - nama        : nama jenis simpanan (harus selaras dengan nilai ENUM
 *                   rekening.jenis_rekening agar alur lama tetap kompatibel)
 *   - kode_akun   : kode akun COA terkait (mis. 3-1001 Simpanan Pokok)
 *   - tipe        : pengelompokan (pokok | wajib | sukarela | berjangka | lain)
 *   - is_active   : status aktif / nonaktif
 *   - timestamps  : created_at / updated_at (timestamp perubahan)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jenis_simpanans')) {
            return;
        }

        Schema::create('jenis_simpanans', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100)->unique();
            $table->string('kode_akun', 30)->nullable();
            $table->string('tipe', 30)->default('lain');
            $table->boolean('is_active')->default(true);
            $table->string('keterangan', 255)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'tipe'], 'jenis_simpanans_aktif_tipe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_simpanans');
    }
};
