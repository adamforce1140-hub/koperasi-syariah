<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Pengujian (Test Runs).
 *
 * Menyimpan jejak setiap eksekusi pengujian otomatis yang dijalankan Pengurus
 * langsung dari UI aplikasi (menu "Riwayat Pengujian"). Setiap baris merekam:
 *   • modul & berkas/kelas uji yang dijalankan,
 *   • tanggal pengujian yang dipilih operator,
 *   • ringkasan hasil (lulus/gagal/skipped, jumlah assertion, durasi),
 *   • detail per-tes dalam bentuk JSON (nama, status, pesan kegagalan),
 *   • siapa yang menjalankan dan kapan baris dibuat.
 *
 * Tabel ini bersifat append-only dari sudut pandang aplikasi — tidak ada
 * pembaruan setelah baris dibuat, sehingga jejak audit tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('test_runs')) {
            return;
        }

        Schema::create('test_runs', function (Blueprint $table) {
            $table->id();

            // Modul & target pengujian
            $table->string('modul', 120);
            $table->string('target_uji', 255)->nullable();
            $table->date('tanggal_uji');

            // Status keseluruhan: passed | failed | error | running
            $table->string('status', 20)->default('running');
            $table->string('exit_code', 10)->nullable();

            // Ringkasan hasil (dari keluaran PHPUnit / TestResponse)
            $table->unsignedInteger('jumlah_tes')->default(0);
            $table->unsignedInteger('lulus')->default(0);
            $table->unsignedInteger('gagal')->default(0);
            $table->unsignedInteger('dilewati')->default(0);
            $table->unsignedInteger('assertions')->default(0);
            $table->unsignedInteger('durasi_ms')->default(0);

            // Detail per-tes & keluaran mentah (dipotong agar aman di DB)
            $table->json('detail')->nullable();
            $table->text('cuplikan_keluaran')->nullable();

            // Pelaksana
            $table->foreignId('dijalankan_oleh')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('nama_pelaksana', 150)->nullable();

            $table->timestamps();

            $table->index(['modul', 'tanggal_uji']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_runs');
    }
};
