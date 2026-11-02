<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul PENGUNDURAN DIRI / EXIT KEANGGOTAAN (`member_terminations`).
 *
 * Satu-satunya pintu yang SAH untuk mengembalikan dana Simpanan Pokok
 * (CoA 3-1001) dan Simpanan Wajib (CoA 3-1002). Kedua akun tersebut adalah
 * MODAL/EKUITAS KOPERASI (PSAK 101 ¶ 31), sehingga tidak boleh dicairkan lewat
 * jalur penarikan rutin selama anggota masih aktif.
 *
 * Alur: DIAJUKAN → DISETUJUI (dana dibayarkan + jurnal PSAK 108 diposting) →
 * status anggota menjadi TERMINATED. Penolakan menyimpan alasannya.
 *
 * Dasar syariah & regulasi:
 *   - PSAK 108 — akuntansi transaksi asuransi syariah & penyelesaian hak
 *     anggota saat berhenti (pengembalian dana anggota).
 *   - PSAK 101 ¶ 31 — Simpanan Pokok & Wajib disajikan sebagai Modal Anggota.
 *   - UU No. 25/1992 Pasal 30 & PP No. 7/2021 — tata kelola & akuntabilitas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_terminations')) {
            return;
        }

        Schema::create('member_terminations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('anggota_id')->constrained('anggota')->restrictOnDelete();

            // Nomor dokumen exit, unik — dipakai sebagai referensi audit.
            $table->string('no_terminasi', 40)->unique();

            // ── Alur ──
            // DIAJUKAN | DISETUJUI | DITOLAK | DIBATALKAN
            $table->string('status', 20)->default('DIAJUKAN');

            // ── Metode pengembalian dana (lihat MemberTermination::METODE_*) ──
            // CASH          → tunai via teller, KREDIT 1-1001 Kas Teller
            // TRANSFER_BANK → transfer rekening koperasi, KREDIT 1-1002 Kas di Bank
            $table->string('metode', 20)->nullable();
            $table->string('nomor_rekening_tujuan', 60)->nullable();
            $table->string('nama_bank_tujuan', 100)->nullable();

            // ── Nilai yang dikembalikan (rupiah) ──
            $table->decimal('nominal_simpanan_pokok', 15, 2)->default(0);
            $table->decimal('nominal_simpanan_wajib', 15, 2)->default(0);
            $table->decimal('total_dikembalikan', 15, 2)->default(0);

            // ── Jejak eksekusi ──
            // Tautan ke buku besar (`transaksi`) & jurnal PSAK 108. Kolom ini
            // juga menjadi KUNCI IDEMPOTENSI: selama `jurnal_id` terisi, exit
            // tidak boleh diproses ulang.
            $table->unsignedBigInteger('transaksi_id')->nullable();
            $table->unsignedBigInteger('jurnal_id')->nullable();
            $table->string('no_jurnal', 30)->nullable();

            // ── Pejabat & waktu ──
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diproses_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users')->nullOnDelete();

            $table->date('tanggal_pengunduran_diri')->nullable();
            $table->timestamp('diajukan_pada')->nullable();
            $table->timestamp('diproses_pada')->nullable();

            $table->text('catatan')->nullable();
            $table->text('alasan_penolakan')->nullable();

            $table->timestamps();

            $table->index(['anggota_id', 'status'], 'member_terminations_anggota_status_index');
            $table->index(['status', 'created_at'], 'member_terminations_status_waktu_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_terminations');
    }
};
