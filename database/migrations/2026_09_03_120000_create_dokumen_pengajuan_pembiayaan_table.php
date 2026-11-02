<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_pengajuan_pembiayaan', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pengajuan_id');
            $table->string('jenis_dokumen', 50);
            $table->string('nama_file', 255);
            $table->string('path_file', 500);
            $table->unsignedInteger('ukuran_file')->nullable();
            $table->timestamps();

            $table->foreign('pengajuan_id')
                ->references('id')
                ->on('pengajuan_pembiayaan')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_pengajuan_pembiayaan');
    }
};
