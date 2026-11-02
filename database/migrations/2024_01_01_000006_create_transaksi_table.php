<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi', function (Blueprint $table) {
            $table->id();
            $table->string('no_transaksi', 30)->unique();
            $table->dateTime('waktu_transaksi');
            $table->unsignedBigInteger('rekening_id')->nullable();
            $table->unsignedBigInteger('anggota_id')->nullable();
            $table->unsignedBigInteger('pembiayaan_id')->nullable();
            $table->enum('jenis_transaksi', [
                'Setoran Tunai', 'Penarikan Tunai', 'Transfer Masuk', 'Transfer Keluar',
                'Pembayaran Angsuran', 'Pencairan Pembiayaan', 'Simpanan Pokok',
                'Simpanan Wajib', 'Simpanan Sukarela', 'Penarikan Simpanan',
                'Setoran Simpanan Berjangka', 'Penarikan Simpanan Berjangka',
                'Bayar Angsuran', 'Bagi Hasil', 'Biaya Administrasi', 'Denda', 'Lainnya',
            ]);
            $table->enum('posisi', ['Debet', 'Kredit']);
            $table->decimal('jumlah', 15, 2);
            $table->decimal('saldo_setelah', 15, 2)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('sumber_transaksi', ['Teller', 'Mobile', 'Internet', 'Transfer', 'Auto'])->default('Teller');
            $table->enum('status_transaksi', ['Pending', 'Sukses', 'Gagal', 'Dibatalkan'])->default('Sukses');
            $table->string('referensi', 100)->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('rekening_id')->references('id')->on('rekening')->nullOnDelete();
            $table->foreign('anggota_id')->references('id')->on('anggota')->nullOnDelete();
            $table->foreign('pembiayaan_id')->references('id')->on('pembiayaan')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi');
    }
};
