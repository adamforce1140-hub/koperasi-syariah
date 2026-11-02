<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keringanan_tazirs')) {
            Schema::create('keringanan_tazirs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('angsuran_id')->constrained('angsuran')->cascadeOnDelete();
                $table->foreignId('pembiayaan_id')->constrained('pembiayaan')->cascadeOnDelete();
                $table->foreignId('anggota_id')->constrained('anggota')->cascadeOnDelete();
                $table->foreignId('diajukan_oleh')->constrained('users')->cascadeOnDelete();   // teller
                $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->nullOnDelete(); // manajer
                $table->decimal('nominal_tazir_hitung', 15, 2)->default(0); // kalkulasi sistem
                $table->decimal('nominal_tazir_final', 15, 2)->default(0);  // 0 jika dibebaskan
                $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
                $table->text('alasan_pengajuan');      // dari teller
                $table->text('catatan_manajer')->nullable(); // dari manajer
                $table->timestamp('diputuskan_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('anggota_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('keringanan_tazirs');
    }
};
