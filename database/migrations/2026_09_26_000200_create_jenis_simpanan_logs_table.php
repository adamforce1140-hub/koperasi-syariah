<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log aktivitas perubahan parameter Jenis Simpanan.
 *
 * Mencatat siapa yang mengubah, kapan diubah, jenis simpanan mana yang
 * terdampak, kolom apa yang berubah, serta nilai lama dan nilai barunya.
 * Mengikuti pola kebijakan_koperasi_logs agar konsisten dengan audit trail
 * parameter lain di sistem.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jenis_simpanan_logs')) {
            return;
        }

        Schema::create('jenis_simpanan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jenis_simpanan_id')->nullable()
                ->constrained('jenis_simpanans')->nullOnDelete();
            $table->string('nama_jenis', 100)->nullable();   // snapshot nama saat perubahan
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nama_user', 150)->nullable();
            $table->string('role_user', 50)->nullable();
            $table->string('aksi', 30)->default('UPDATE');   // CREATE | UPDATE | DELETE
            $table->string('kolom', 100);                    // nama kolom yang berubah
            $table->text('nilai_lama')->nullable();
            $table->text('nilai_baru')->nullable();
            $table->string('keterangan', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['kolom', 'created_at'], 'jenis_simpanan_logs_kolom_waktu_index');
            $table->index('jenis_simpanan_id', 'jenis_simpanan_logs_jenis_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jenis_simpanan_logs');
    }
};
