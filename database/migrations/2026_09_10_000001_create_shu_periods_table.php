<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shu_periods', function (Blueprint $table) {
            $table->id();
            $table->string('tahun_buku', 20);           // misal: 2025/2026
            $table->decimal('total_shu_bersih', 18, 2);
            $table->decimal('persen_cadangan', 5, 2)->default(30);
            $table->decimal('persen_jasa_modal', 5, 2)->default(40);
            $table->decimal('persen_jasa_transaksi', 5, 2)->default(20);
            $table->decimal('persen_pengurus', 5, 2)->default(10);
            $table->decimal('nominal_cadangan', 18, 2)->default(0);
            $table->decimal('nominal_jasa_modal', 18, 2)->default(0);
            $table->decimal('nominal_jasa_transaksi', 18, 2)->default(0);
            $table->decimal('nominal_pengurus', 18, 2)->default(0);
            $table->enum('status', ['Draft', 'Diproses', 'Selesai'])->default('Draft');
            $table->unsignedBigInteger('jurnal_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('diproses_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shu_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shu_period_id');
            $table->unsignedBigInteger('anggota_id');
            $table->unsignedBigInteger('rekening_id')->nullable();  // rekening sukarela
            $table->decimal('total_simpanan_modal', 18, 2)->default(0); // simpanan pokok + wajib
            $table->decimal('total_transaksi_bagi_hasil', 18, 2)->default(0); // angsuran/bagi hasil
            $table->decimal('shu_jasa_modal', 18, 2)->default(0);
            $table->decimal('shu_jasa_transaksi', 18, 2)->default(0);
            $table->decimal('total_shu', 18, 2)->default(0);
            $table->unsignedBigInteger('transaksi_id')->nullable(); // mutasi yg dibuat
            $table->timestamps();

            $table->foreign('shu_period_id')->references('id')->on('shu_periods')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shu_distributions');
        Schema::dropIfExists('shu_periods');
    }
};
