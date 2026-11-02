<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurnal', function (Blueprint $table) {
            $table->id();
            $table->string('no_jurnal', 30)->unique();
            $table->date('tanggal_jurnal');
            $table->enum('jenis_jurnal', [
                'Jurnal Umum', 'Jurnal Penerimaan Kas', 'Jurnal Pengeluaran Kas',
                'Jurnal Penyesuaian', 'Jurnal Penutup', 'Jurnal Pembalik',
                'Jurnal Otomatis', 'Jurnal Transaksi',
            ])->default('Jurnal Umum');
            $table->unsignedBigInteger('transaksi_id')->nullable();
            $table->unsignedBigInteger('pembiayaan_id')->nullable();
            $table->string('sumber', 50)->nullable();
            $table->string('no_ref', 50)->nullable();
            $table->text('keterangan');
            $table->decimal('total_debet', 15, 2)->default(0);
            $table->decimal('total_kredit', 15, 2)->default(0);
            $table->boolean('is_balance')->default(true);
            $table->enum('status', ['Draft', 'Posted', 'Void'])->default('Draft');
            $table->dateTime('posted_at')->nullable();
            $table->unsignedBigInteger('user_posted')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('transaksi_id')->references('id')->on('transaksi')->nullOnDelete();
            $table->foreign('pembiayaan_id')->references('id')->on('pembiayaan')->nullOnDelete();
            $table->foreign('user_posted')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurnal');
    }
};
