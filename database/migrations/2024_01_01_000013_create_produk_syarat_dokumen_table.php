<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produk_syarat_dokumen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produk_id')->constrained('master_produk_pembiayaan')->onDelete('cascade');
            $table->string('nama_dokumen');
            $table->boolean('is_required')->default(true);
            $table->string('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produk_syarat_dokumen');
    }
};
