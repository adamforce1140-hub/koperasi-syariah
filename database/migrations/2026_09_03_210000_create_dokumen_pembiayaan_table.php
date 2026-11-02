<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_pembiayaan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pembiayaan_id');
            $table->string('jenis_dokumen', 100);
            $table->string('nama_file', 255);
            $table->string('path_file', 500);
            $table->unsignedInteger('ukuran_file')->nullable(); // bytes
            $table->unsignedBigInteger('diupload_oleh')->nullable();
            $table->timestamps();

            $table->foreign('pembiayaan_id')
                ->references('id')
                ->on('pembiayaan')
                ->cascadeOnDelete();

            $table->foreign('diupload_oleh')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_pembiayaan');
    }
};
