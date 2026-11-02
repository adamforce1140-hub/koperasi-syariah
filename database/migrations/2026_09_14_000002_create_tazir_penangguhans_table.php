<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel tunggakan Ta'zir (denda) yang DITOLAK keringanannya.
 *
 * Konteks:
 *   Angsuran pokok & margin bulan berjalan sudah dibayar di loket teller,
 *   lalu pengajuan keringanan ta'zir ditolak Manajer. Sesuai safeguard:
 *     - Transaksi pokok & margin TIDAK dibatalkan (tidak ada void/reversal).
 *     - Ta'zir hanya dibukukan ke Dana Kebajikan (2-3001) saat tunai diterima
 *       (Cash Basis Syariah / PSAK 109).
 *   Karena itu tunggakan dicatat di sini dengan is_tazir_paid = false, lalu
 *   ditagihkan pada transaksi berikutnya di loket teller.
 *
 * SAFEGUARD: tidak mengubah/menghapus tabel & kolom yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tazir_penangguhans')) {
            return;
        }

        Schema::create('tazir_penangguhans', function (Blueprint $table) {
            $table->id();

            // Pengajuan keringanan yang ditolak (sumber tunggakan)
            $table->unsignedBigInteger('tazir_relief_request_id')->nullable();

            // Jadwal angsuran asal denda (pokok & margin-nya sudah dibayar)
            $table->unsignedBigInteger('jadwal_angsuran_id')->nullable();
            $table->unsignedBigInteger('pembiayaan_id')->nullable();
            $table->unsignedBigInteger('anggota_id')->nullable();

            // Nominal ta'zir tertunggak yang wajib dibayar anggota
            $table->decimal('nominal_tazir', 15, 2)->default(0);

            // Status pelunasan tunggakan (cash basis)
            $table->boolean('is_tazir_paid')->default(false);
            $table->timestamp('paid_at')->nullable();

            // Jejak pelunasan: angsuran & transaksi tempat tunggakan dilunasi
            $table->unsignedBigInteger('paid_angsuran_id')->nullable();
            $table->unsignedBigInteger('transaksi_id')->nullable();

            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['anggota_id', 'is_tazir_paid']);
            $table->index(['pembiayaan_id', 'is_tazir_paid']);

            if (Schema::hasTable('tazir_relief_requests')) {
                $table->foreign('tazir_relief_request_id')
                    ->references('id')->on('tazir_relief_requests')->nullOnDelete();
            }
            if (Schema::hasTable('angsuran')) {
                $table->foreign('jadwal_angsuran_id')
                    ->references('id')->on('angsuran')->nullOnDelete();
                $table->foreign('paid_angsuran_id')
                    ->references('id')->on('angsuran')->nullOnDelete();
            }
            if (Schema::hasTable('pembiayaan')) {
                $table->foreign('pembiayaan_id')
                    ->references('id')->on('pembiayaan')->nullOnDelete();
            }
            if (Schema::hasTable('anggota')) {
                $table->foreign('anggota_id')
                    ->references('id')->on('anggota')->nullOnDelete();
            }
            if (Schema::hasTable('transaksi')) {
                $table->foreign('transaksi_id')
                    ->references('id')->on('transaksi')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tazir_penangguhans');
    }
};
