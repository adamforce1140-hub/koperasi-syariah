<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kas_harian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('tanggal');
            $table->decimal('modal_awal', 15, 2)->default(0);
            $table->json('tambah_modal')->nullable(); // array tambahan modal
            $table->string('catatan_opening')->nullable();
            $table->time('waktu_opening')->nullable();

            // Closing
            $table->decimal('fisik_kas', 15, 2)->nullable();
            $table->decimal('saldo_sistem', 15, 2)->nullable();
            $table->decimal('selisih', 15, 2)->nullable();
            $table->string('status_closing')->nullable(); // Balanced, Lebih, Kurang
            $table->string('catatan_closing')->nullable();
            $table->time('waktu_closing')->nullable();

            $table->timestamps();

            // Satu teller hanya boleh 1 opening per hari
            $table->unique(['user_id', 'tanggal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kas_harian');
    }
};
