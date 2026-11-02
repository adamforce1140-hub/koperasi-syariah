<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat tindakan penanganan tunggakan Simpanan Wajib (audit trail).
 *
 * Satu baris = satu tindakan yang diambil sistem/petugas atas satu tagihan
 * Simpanan Wajib (mandatory_savings_bills). Tabel APPEND-ONLY: baris tidak
 * pernah diubah/dihapus setelah tercatat demi transparansi kepada anggota
 * dan keperluan audit (spesifikasi #5).
 *
 * Jenis tindakan (kolom `jenis`):
 *   AUTO_DEBIT_BERHASIL — pemotongan saldo Sukarela berhasil (jurnal PSAK).
 *   AUTO_DEBIT_GAGAL    — saldo Sukarela tidak mencukupi / tidak ada.
 *   NOTIFIKASI          — notifikasi aman-PDP terkirim ke anggota/admin.
 *   JADWAL_BERTAHAP     — penjadwalan cicilan bertahap sesuai kemampuan.
 *   CICILAN_DIBAYAR     — pembayaran satu cicilan dari jadwal bertahap.
 *   RESCHEDULE          — penjadwalan ulang tenggat TANPA DENDA (syariah).
 *   BAYAR_TELLER        — pelunasan manual di loket Teller.
 *   TANDAI_OVERDUE      — tagihan ditandai OVERDUE.
 *
 * Prinsip syariah: tidak ada denda atas keterlambatan. Kolom `denda` sengaja
 * TIDAK disediakan; penjadwalan ulang dicatat dengan `nominal_denda_sebelum`
 * = `nominal_denda_sesudah` = 0 untuk membuktikan kepatuhan pada audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('simpanan_wajib_tindakans')) {
            return;
        }

        Schema::create('simpanan_wajib_tindakans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id');                 // FK mandatory_savings_bills
            $table->unsignedBigInteger('member_id');               // denormalisasi untuk query cepat
            $table->string('jenis', 40);                           // lihat daftar jenis di docblock
            $table->string('periode', 7)->nullable();              // periode tagihan saat tindakan
            $table->decimal('nominal', 12, 2)->default(0);         // nominal terkait tindakan
            $table->decimal('saldo_sukarela', 14, 2)->nullable();  // snapshot saldo saat tindakan
            $table->string('status_hasil', 20)->default('BERHASIL'); // BERHASIL|GAGAL|INFO

            // Penjadwalan bertahap / reschedule.
            $table->unsignedSmallInteger('cicilan_ke')->nullable();   // cicilan ke-n
            $table->unsignedSmallInteger('total_cicilan')->nullable(); // total cicilan
            $table->decimal('nominal_cicilan', 12, 2)->nullable();     // nominal per cicilan
            $table->date('tenggat_lama')->nullable();                  // tenggat sebelum reschedule
            $table->date('tenggat_baru')->nullable();                  // tenggat sesudah reschedule

            // Anti-denda (prinsip syariah): selalu 0, disimpan untuk bukti audit.
            $table->decimal('denda', 12, 2)->default(0);

            $table->string('alasan', 60)->nullable();              // mis. SALDO_TIDAK_CUKUP
            $table->text('catatan')->nullable();                   // pesan notifikasi / catatan petugas
            $table->unsignedBigInteger('user_id')->nullable();     // pelaku (null = sistem/scheduler)
            $table->dateTime('waktu_tindakan');                    // waktu kejadian (bukan created_at)
            $table->timestamps();

            $table->foreign('bill_id')->references('id')->on('mandatory_savings_bills')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('anggota')->onDelete('cascade');

            $table->index(['bill_id', 'waktu_tindakan'], 'wjt_bill_waktu_index');
            $table->index(['member_id', 'jenis'], 'wjt_member_jenis_index');
            $table->index(['jenis', 'waktu_tindakan'], 'wjt_jenis_waktu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simpanan_wajib_tindakans');
    }
};
