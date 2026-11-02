<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('jurnal_id');
            $table->unsignedBigInteger('akun_id');
            $table->integer('baris');
            $table->decimal('debet', 15, 2)->default(0);
            $table->decimal('kredit', 15, 2)->default(0);
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('anggota_id')->nullable();
            $table->unsignedBigInteger('rekening_id')->nullable();
            $table->timestamps();

            $table->foreign('jurnal_id')->references('id')->on('jurnal')->cascadeOnDelete();
            $table->foreign('akun_id')->references('id')->on('akun')->restrictOnDelete();
            $table->foreign('anggota_id')->references('id')->on('anggota')->nullOnDelete();
            $table->foreign('rekening_id')->references('id')->on('rekening')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal_detail');
    }
};
