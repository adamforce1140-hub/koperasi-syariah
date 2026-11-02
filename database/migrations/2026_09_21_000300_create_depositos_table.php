<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilyet Deposito / Simpanan Berjangka Syariah.
 *
 * Nama kolom mengikuti spesifikasi modul, dengan penyesuaian pada foreign key
 * agar merujuk tabel yang benar-benar ada pada skema aplikasi ini:
 *   member_id               → anggota.id      (bukan `members`)
 *   source_saving_account_id → rekening.id    (bukan `saving_accounts`)
 *
 * `deposito_rekening_id` menautkan bilyet ini ke rekening Simpanan Berjangka
 * (tabel `rekening`) yang dipakai modul teller & Portofolio CIF, supaya tidak
 * ada dua catatan deposito yang berjalan tanpa hubungan satu sama lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depositos', function (Blueprint $table) {
            $table->id();
            $table->string('deposito_number', 30)->unique();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('source_saving_account_id');
            $table->unsignedBigInteger('deposito_rekening_id')->nullable();

            $table->decimal('principal_amount', 15, 2);

            // Nisbah disalin dari produk saat pembuatan (audit trail): perubahan
            // nisbah produk di kemudian hari tidak boleh mengubah deposito lama.
            $table->decimal('nisbah_anggota', 5, 2);
            $table->decimal('nisbah_koperasi', 5, 2);

            $table->date('start_date');
            // Selalu dihitung sistem: start_date + tenor_months. Tidak diisi manual.
            $table->date('maturity_date');

            $table->boolean('is_aro')->default(false);
            $table->enum('status', ['PENDING', 'ACTIVE', 'MATURED', 'CLOSED'])->default('PENDING');
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('anggota')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('deposito_products')->restrictOnDelete();
            $table->foreign('source_saving_account_id')->references('id')->on('rekening')->restrictOnDelete();
            $table->foreign('deposito_rekening_id')->references('id')->on('rekening')->nullOnDelete();

            // Mempercepat query scheduler deposito jatuh tempo.
            $table->index(['status', 'is_aro', 'maturity_date'], 'depositos_jatuh_tempo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositos');
    }
};
