<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan Simpanan Wajib bulanan per anggota.
 *
 * Satu baris = satu kewajiban setoran untuk satu periode bulan (bill_period
 * berformat 'YYYY-MM'). Penerbitan dilakukan scheduler harian
 * (`mandatory-savings:process-daily`) memakai `firstOrCreate` sehingga satu
 * anggota tidak mungkin memiliki dua tagihan untuk periode yang sama.
 *
 * Alur status:
 *   UNPAID   → tagihan diterbitkan, menunggu pembayaran
 *   PAID     → berhasil dibayar (tunai / auto-debit dari Simpanan Sukarela)
 *   OVERDUE  → saldo Simpanan Sukarela tidak mencukupi saat auto-debit;
 *              memicu notifikasi ke anggota & admin, dan menahan (HOLD)
 *              pencairan SHU anggota pada penutupan tahun buku.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mandatory_savings_bills')) {
            return;
        }

        Schema::create('mandatory_savings_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');            // FK anggota
            $table->string('bill_period', 7);                   // format wajib 'YYYY-MM'
            $table->decimal('amount', 12, 2);                   // nominal rupiah
            $table->enum('status', ['UNPAID', 'PAID', 'OVERDUE'])->default('UNPAID');
            $table->dateTime('paid_at')->nullable();
            $table->enum('payment_method', ['CASH', 'AUTO_DEBIT', 'TRANSFER'])->nullable();
            $table->unsignedBigInteger('transaksi_id')->nullable();  // mutasi auto-debit
            $table->unsignedBigInteger('jurnal_id')->nullable();     // jurnal PSAK terkait
            $table->dateTime('notified_at')->nullable();             // audit notifikasi OVERDUE
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('anggota')->onDelete('cascade');

            // Anti-duplikat: satu tagihan per anggota per periode.
            $table->unique(['member_id', 'bill_period'], 'mandatory_bills_member_period_unique');
            // Mempercepat query scheduler & kalkulasi SHU (deteksi OVERDUE).
            $table->index(['status', 'bill_period'], 'mandatory_bills_status_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandatory_savings_bills');
    }
};
