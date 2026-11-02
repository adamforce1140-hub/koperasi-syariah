<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat pembagian bagi hasil deposito per periode bulan.
 *
 * `gross_koperasi_revenue` adalah pendapatan koperasi dari penempatan dana
 * (dasar perhitungan bagi hasil), sedangkan `member_profit_amount` adalah porsi
 * anggota sesuai nisbah. Kombinasi unik (deposito, tahun, bulan) mencegah satu
 * periode dibayarkan dua kali saat scheduler dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposito_profit_sharings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deposito_id');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->decimal('gross_koperasi_revenue', 15, 2);
            $table->decimal('member_profit_amount', 15, 2);
            $table->dateTime('payout_date');
            $table->enum('status', ['PAID', 'FAILED'])->default('PAID');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();

            $table->foreign('deposito_id')->references('id')->on('depositos')->cascadeOnDelete();
            $table->foreign('journal_id')->references('id')->on('jurnal')->nullOnDelete();

            $table->unique(['deposito_id', 'period_year', 'period_month'], 'deposito_bagi_hasil_periode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposito_profit_sharings');
    }
};
