<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('angsuran', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pembiayaan_id');
            $table->unsignedBigInteger('transaksi_id')->nullable();
            $table->integer('angsuran_ke');
            $table->date('tanggal_jatuh_tempo');
            $table->date('tanggal_bayar')->nullable();
            $table->decimal('pokok_angsuran', 15, 2)->default(0);
            $table->decimal('margin_ujroh', 15, 2)->default(0);
            $table->decimal('jumlah_angsuran', 15, 2);
            $table->decimal('denda', 15, 2)->default(0);
            $table->decimal('total_bayar', 15, 2)->default(0);
            $table->decimal('sisa_pokok', 15, 2);
            $table->enum('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat', 'Sudah Dibayar', 'Lewat'])->default('Belum Jatuh Tempo');
            $table->integer('hari_terlambat')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('pembiayaan_id')->references('id')->on('pembiayaan')->cascadeOnDelete();
            $table->foreign('transaksi_id')->references('id')->on('transaksi')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('angsuran');
    }
};
