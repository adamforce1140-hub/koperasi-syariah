<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit PERISTIWA APPROVAL transaksi (RBAC — rantai otorisasi berjenjang).
 *
 * Menjawab pertanyaan tata kelola yang tidak dapat dijawab tabel
 * `approval_transaksi` sendirian: SIAPA mengajukan, SIAPA memutuskan, KAPAN,
 * dan lewat TAHAP mana — termasuk setiap penolakan dan setiap PERCOBAAN
 * pemutusan yang gagal karena peran tidak berwenang.
 *
 * Beda dengan `approval_transaksi` (menyimpan keadaan SEKARANG), tabel ini
 * append-only: satu baris per peristiwa, tidak pernah diubah maupun dihapus.
 * Keutuhannya dijaga rantai hash (`hash` + `hash_sebelumnya`) sehingga
 * penyuntingan atau penghapusan satu baris langsung terdeteksi.
 *
 * Dasar: UU No. 25/1992 Pasal 30 (akuntabilitas Pengurus), PP No. 7/2021,
 * POJK No. 76/2016 (pengendalian internal & jejak audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('approval_transaksi_logs')) {
            return;
        }

        Schema::create('approval_transaksi_logs', function (Blueprint $table) {
            $table->id();

            // ── Objek yang diaudit ──
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->string('kode_approval', 60)->nullable();

            // ── Peristiwa ──
            // diajukan | disetujui | ditolak | gagal_diputus | dibatalkan
            $table->string('peristiwa', 30);

            // Tahap approval saat peristiwa terjadi (mis. PENDING_AO), supaya
            // urutan tahap pada rantai berjenjang tetap terbaca dari jejak.
            $table->string('status_sebelum', 30)->nullable();
            $table->string('status_sesudah', 30)->nullable();

            // ── Pelaku (snapshot agar jejak tetap utuh bila akun kelak berubah) ──
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nama', 150)->nullable();
            $table->string('role', 30)->nullable();

            // Peran yang seharusnya berwenang pada tahap ini; diisi pada
            // peristiwa `gagal_diputus` agar konflik lintas-peran terekam.
            $table->string('role_berwenang', 30)->nullable();

            // ── Ringkasan nilai (untuk penelusuran tanpa join) ──
            $table->decimal('nominal', 15, 2)->nullable();

            $table->text('catatan')->nullable();

            // ── Konteks permintaan ──
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ── Integritas (hash chain) ──
            $table->unsignedBigInteger('hash_sebelumnya_id')->nullable();
            $table->string('hash_sebelumnya', 64)->nullable();
            $table->string('hash', 64);

            // Append-only: hanya created_at yang dipakai.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['approval_id', 'created_at'], 'approval_logs_objek_waktu_index');
            $table->index(['peristiwa', 'created_at'], 'approval_logs_peristiwa_waktu_index');
            $table->index('kode_approval', 'approval_logs_kode_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_transaksi_logs');
    }
};
