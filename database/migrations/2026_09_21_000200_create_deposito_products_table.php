<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produk Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah).
 *
 * Setiap produk menetapkan tenor (bulan) beserta nisbah bagi hasil anggota dan
 * koperasi. Sistem bagi hasil memakai NISBAH (persentase porsi), bukan bunga
 * tetap, sehingga tidak ada kewajiban nominal yang dijamin di muka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposito_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('tenor_months');
            $table->decimal('nisbah_anggota', 5, 2);
            $table->decimal('nisbah_koperasi', 5, 2);
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'tenor_months']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposito_products');
    }
};
