<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel notifikasi database Laravel (Illuminate\Notifications\DatabaseNotification).
 *
 * Dipakai antara lain untuk mencatat penahanan (HOLD) SHU anggota akibat
 * tunggakan Simpanan Wajib (OVERDUE) agar Pengurus menerima pemberitahuan
 * yang dapat ditelusuri — lihat App\Notifications\ShuDitahanNotification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
