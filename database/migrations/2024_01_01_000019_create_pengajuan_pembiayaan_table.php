<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_pembiayaan', function (Blueprint $table) {
            $table->id();
            $table->string('no_pengajuan', 30)->unique();
            $table->unsignedBigInteger('anggota_id');
            $table->unsignedBigInteger('produk_id')->nullable();
            $table->string('jenis_akad', 50);
            $table->decimal('jumlah_pengajuan', 15, 2);
            $table->integer('tenor'); // dalam bulan
            $table->string('tujuan', 255);
            $table->string('agunan', 255)->nullable();
            $table->enum('status', ['Menunggu', 'Diproses', 'Disetujui', 'Ditolak'])->default('Menunggu');
            $table->unsignedBigInteger('diproses_oleh')->nullable(); // user_id teller/manajer
            $table->timestamp('tanggal_diproses')->nullable();
            $table->text('catatan_pengelola')->nullable();
            $table->unsignedBigInteger('pembiayaan_id')->nullable(); // jika disetujui, link ke pembiayaan
            $table->timestamps();

            $table->foreign('anggota_id')->references('id')->on('anggota')->cascadeOnDelete();
            $table->foreign('produk_id')->references('id')->on('master_produk_pembiayaan')->nullOnDelete();
            $table->foreign('diproses_oleh')->references('id')->on('users')->nullOnDelete();
            $table->foreign('pembiayaan_id')->references('id')->on('pembiayaan')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_pembiayaan');
    }
};
