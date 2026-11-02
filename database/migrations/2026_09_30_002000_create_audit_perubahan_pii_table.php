<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit perubahan DATA PRIBADI (UU No. 27/2022 tentang Pelindungan Data
 * Pribadi — Pasal 35, 39, 40: akuntabilitas & hak Subjek Data untuk menelusuri
 * siapa mengubah data pribadinya dan kapan).
 *
 * Dipisahkan dari `laporan_keuangan_logs` (khusus modul Laporan Keuangan) dan
 * dari `audit_logs` (khusus mutasi rekening) supaya tiap jejak audit tetap
 * fokus dan mudah difilter.
 *
 * PENTING — MINIMALISASI DATA (Pasal 16):
 * Tabel ini HANYA menyimpan NAMA kolom yang berubah, TIDAK PERNAH nilainya.
 * Menyalin NIK/alamat ke tabel log akan memperbanyak permukaan kebocoran tanpa
 * menambah nilai audit.
 *
 * Kolom `hash` + `hash_sebelumnya` membentuk rantai hash sehingga penyuntingan
 * atau penghapusan satu baris dapat dideteksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_perubahan_pii', function (Blueprint $table) {
            $table->id();

            // ── Identitas pelaku ──
            $table->unsignedBigInteger('user_id')->nullable();
            // Nama & peran disalin apa adanya (snapshot) agar jejak audit tetap
            // utuh walau akun pengguna kelak diubah atau dihapus.
            $table->string('user_nama', 100)->nullable();
            $table->string('role', 30)->nullable();

            // ── Objek yang diubah ──
            $table->string('model', 100);
            $table->unsignedBigInteger('model_id');

            // ── Apa yang terjadi ──
            $table->string('aksi', 20);                 // tambah, edit, hapus, pulihkan
            $table->json('kolom')->nullable();          // daftar NAMA kolom (tanpa nilai)
            $table->unsignedSmallInteger('jumlah_kolom')->default(0);
            $table->string('deskripsi')->nullable();

            // ── Konteks permintaan ──
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // ── Integritas (hash chain) ──
            $table->unsignedBigInteger('hash_sebelumnya_id')->nullable();
            $table->string('hash_sebelumnya', 64)->nullable();
            $table->string('hash', 64);

            // Append-only: hanya created_at yang dipakai.
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['model', 'model_id']);
            $table->index('aksi');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_perubahan_pii');
    }
};
