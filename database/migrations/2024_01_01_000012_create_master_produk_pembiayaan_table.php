<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_produk_pembiayaan', function (Blueprint $table) {
            $table->id();
            $table->string('nama_produk');
            $table->enum('akad_syariah', ['Murabahah', 'Musyarakah', 'Mudharabah', 'Ijarah', 'Qardh']);
            $table->decimal('min_plafon', 15, 2)->default(1000000);
            $table->decimal('max_plafon', 15, 2)->default(50000000);
            $table->decimal('margin_persen', 5, 2)->default(1.5);
            $table->decimal('biaya_admin', 15, 2)->default(50000);
            $table->integer('min_bulan_anggota')->default(3)->comment('Minimal sudah menjadi anggota berapa bulan');
            $table->integer('min_tenor_bulan')->default(3);
            $table->integer('max_tenor_bulan')->default(60);
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_produk_pembiayaan');
    }
};
