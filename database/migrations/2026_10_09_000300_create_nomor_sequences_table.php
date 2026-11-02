<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor Sequence (penomoran dokumen/transaksi) — perbaikan RACE CONDITION (K-03).
 *
 * Latar belakang (hasil audit): penomoran `no_transaksi` (TRX-YYYYMMDD-XXXXXX)
 * di beberapa service dihitung dengan pola "baca MAX lalu +1" DI DALAM
 * transaksi, tetapi TANPA penguncian baris. Dua proses yang berjalan hampir
 * bersamaan (mis. scheduler auto-debit + aksi teller) dapat membaca MAX yang
 * SAMA, menghasilkan nomor yang identik, lalu gagal pada unique index
 * `transaksi.no_transaksi` → alur transaksi utama terhenti (500).
 *
 * Tabel ini menjadi SUMBER URUTAN TUNGGAL per prefix hari. Generator mengunci
 * baris (`SELECT ... FOR UPDATE`) sehingga proses paralel terserialisasi:
 * proses kedua menunggu proses pertama commit dan membaca nilai terbaru.
 * Nilai urutan ikut ROLLBACK bersama transaksi pemanggil sehingga tidak ada
 * nomor "bolong" saat transaksi gagal.
 *
 * Tabel bersifat aditif & backward-compatible: generator melakukan seeding
 * otomatis dari MAX(`no_transaksi`) yang sudah ada sehingga data lama tetap
 * aman dan tidak ada nomor yang tertimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nomor_sequences')) {
            return;
        }

        Schema::create('nomor_sequences', function (Blueprint $table) {
            // Prefix dokumen menjadi PRIMARY KEY (mis. `TRX-20261009-`).
            $table->string('prefix', 60)->primary();
            // Nilai urutan terakhir yang telah dipakai untuk prefix tsb.
            $table->unsignedBigInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomor_sequences');
    }
};
