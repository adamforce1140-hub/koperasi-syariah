<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log audit khusus modul Laporan Keuangan.
     *
     * Dipisahkan dari tabel `audit_logs` yang sudah ada karena tabel lama itu
     * khusus mencatat mutasi rekening (kolom no_rekening, nominal) dan tidak
     * menyimpan peran pengguna maupun perubahan data. Mencampur keduanya akan
     * membuat laporan audit keuangan sulit difilter dan dibaca.
     *
     * Kolom `hash` + `hash_sebelumnya` membentuk rantai hash (hash chain):
     * setiap baris menyegel isi dirinya sekaligus hash baris sebelumnya,
     * sehingga penghapusan atau penyuntingan satu baris dapat dideteksi.
     */
    public function up(): void
    {
        Schema::create('laporan_keuangan_logs', function (Blueprint $table) {
            $table->id();

            // ── Identitas pelaku ──
            $table->unsignedBigInteger('user_id')->nullable();
            // Peran disalin apa adanya (bukan relasi) supaya jejak audit tetap
            // utuh walau peran pengguna berubah di kemudian hari.
            $table->string('user_nama')->nullable();
            $table->string('role', 30)->nullable();

            // ── Apa yang dilakukan ──
            $table->string('modul', 50);                    // neraca, buku_besar, laporan_manajer
            $table->string('aktivitas', 20);                // lihat, unduh, edit, hapus, ekspor
            $table->string('deskripsi')->nullable();
            $table->string('route', 150)->nullable();
            $table->string('metode', 10)->nullable();

            // ── Konteks permintaan ──
            $table->string('url', 500)->nullable();
            $table->json('parameter')->nullable();          // query string yang dipakai
            $table->json('perubahan_data')->nullable();     // {sebelum: ..., sesudah: ...}
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ── Hasil ──
            $table->unsignedSmallInteger('status_http')->nullable();
            $table->boolean('berhasil')->default(true);

            // ── Integritas (hash chain) ──
            $table->unsignedBigInteger('hash_sebelumnya_id')->nullable();
            $table->string('hash_sebelumnya', 64)->nullable();
            $table->string('hash', 64);

            // Timestamp akses. Hanya created_at yang dipakai — log append-only
            // tidak pernah di-update, jadi tidak perlu kolom updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Indeks untuk kebutuhan filter laporan audit
            $table->index('role');
            $table->index('modul');
            $table->index('aktivitas');
            $table->index('created_at');
            $table->index(['role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_keuangan_logs');
    }
};
