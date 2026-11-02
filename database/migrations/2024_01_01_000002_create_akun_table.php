<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akun', function (Blueprint $table) {
            $table->id();
            $table->string('kode_akun', 20)->unique();
            $table->string('nama_akun', 150);
            $table->enum('klasifikasi', [
                'Aset', 'Kewajiban', 'Modal', 'Pendapatan', 'Beban',
            ]);
            $table->enum('jenis_akun', ['Header', 'Detail'])->default('Detail');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->decimal('saldo_normal', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('akun')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akun');
    }
};
