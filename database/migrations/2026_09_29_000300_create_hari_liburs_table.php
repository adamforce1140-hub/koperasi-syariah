<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender Hari Libur Nasional & Cuti Bersama.
 *
 * Dipakai oleh penjadwalan angsuran pembiayaan: bila tanggal jatuh tempo
 * angsuran jatuh pada hari libur / akhir pekan, tanggal digeser ke hari kerja
 * terdekat sesuai aturan koperasi (maju atau mundur — diatur di
 * `kebijakan_koperasis.hari_libur_strategi`).
 *
 * Data hari libur TIDAK ditanam di kode (zero-hardcoding): diisi lewat seeder
 * dan dapat dipelihara pengurus melalui menu master. Tabel ini juga menjadi
 * satu-satunya sumber kebenaran bagi laporan & validasi tanggal operasional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hari_liburs')) {
            return;
        }

        Schema::create('hari_liburs', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->unique();
            $table->string('keterangan', 150);
            $table->string('jenis', 30)->default('Nasional')->comment('Nasional | Cuti Bersama | Koperasi');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tanggal', 'is_active'], 'hari_liburs_tanggal_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hari_liburs');
    }
};
