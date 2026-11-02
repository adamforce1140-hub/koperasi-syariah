<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perubahan (audit trail) konfigurasi kebijakan koperasi.
 *
 * Setiap penyimpanan parameter — Simpanan Pokok, Simpanan Wajib, kebijakan
 * penahanan SHU, limit otorisasi, maupun konfigurasi ta'zir — dicatat di sini
 * lengkap dengan nilai sebelum/sesudah sehingga perubahan dapat diaudit tanpa
 * menyentuh kode program.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kebijakan_koperasi_logs')) {
            return;
        }

        Schema::create('kebijakan_koperasi_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nama_user', 150)->nullable();
            $table->string('role_user', 50)->nullable();
            $table->string('aksi', 30)->default('UPDATE');   // CREATE | UPDATE
            $table->string('kolom', 100);                     // nama kolom yang berubah
            $table->text('nilai_lama')->nullable();
            $table->text('nilai_baru')->nullable();
            $table->string('keterangan', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['kolom', 'created_at'], 'kebijakan_logs_kolom_waktu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kebijakan_koperasi_logs');
    }
};
