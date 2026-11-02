<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran_simpanan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pengajuan_id');
            $table->decimal('nominal', 15, 2);
            $table->enum('metode_bayar', [
                'Transfer Bank', 'Virtual Account', 'QRIS', 'Setor Tunai',
            ])->default('Transfer Bank');
            $table->string('bukti_bayar')->nullable(); // path file bukti transfer
            $table->enum('status', [
                'Menunggu Konfirmasi', 'Dikonfirmasi', 'Ditolak',
            ])->default('Menunggu Konfirmasi');
            $table->text('catatan')->nullable();
            $table->unsignedBigInteger('dikonfirmasi_oleh')->nullable();
            $table->timestamp('waktu_konfirmasi')->nullable();
            $table->timestamps();

            $table->foreign('pengajuan_id')->references('id')->on('pengajuan_rekening')->cascadeOnDelete();
            $table->foreign('dikonfirmasi_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pembayaran_simpanan');
    }
};
