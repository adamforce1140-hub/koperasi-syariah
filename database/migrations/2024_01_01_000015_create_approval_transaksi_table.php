<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_transaksi', function (Blueprint $table) {
            $table->id();
            $table->string('kode_approval')->unique();
            $table->string('tipe_referensi'); // transaksi, pembiayaan
            $table->unsignedBigInteger('referensi_id');
            $table->string('jenis_transaksi');
            $table->decimal('nominal', 15, 2);
            $table->string('jenis_pembayaran')->default('tunai'); // tunai, non_tunai
            $table->enum('status', ['PENDING_MANAJER', 'PENDING_PENGURUS', 'APPROVED', 'REJECTED'])->default('PENDING_MANAJER');
            $table->foreignId('diajukan_oleh')->constrained('users');
            $table->string('role_pengaju');
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users');
            $table->string('role_approver')->nullable();
            $table->text('catatan_pengaju')->nullable();
            $table->text('catatan_approver')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'tipe_referensi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_transaksi');
    }
};
