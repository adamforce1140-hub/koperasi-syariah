<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan status 'Dibayar Sebagian' pada enum status_angsuran.
 *
 * Diperlukan untuk mencatat angsuran yang uangnya sudah diterima sebagian:
 * pokoknya belum tertagih penuh, dan kekurangannya tersimpan di kolom
 * sisa_pokok agar tetap terhitung sebagai kewajiban anggota.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('angsuran')) {
            return;
        }

        // MySQL: ubah definisi enum agar memuat nilai baru.
        DB::statement("
            ALTER TABLE `angsuran`
            MODIFY COLUMN `status_angsuran`
            ENUM('Belum Jatuh Tempo','Jatuh Tempo','Terlambat','Sudah Dibayar','Dibayar Sebagian')
            NULL DEFAULT 'Belum Jatuh Tempo'
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('angsuran')) {
            return;
        }

        // Kembalikan baris 'Dibayar Sebagian' ke 'Sudah Dibayar' sebelum enum dipersempit.
        DB::table('angsuran')
            ->where('status_angsuran', 'Dibayar Sebagian')
            ->update(['status_angsuran' => 'Sudah Dibayar']);

        DB::statement("
            ALTER TABLE `angsuran`
            MODIFY COLUMN `status_angsuran`
            ENUM('Belum Jatuh Tempo','Jatuh Tempo','Terlambat','Sudah Dibayar')
            NULL DEFAULT 'Belum Jatuh Tempo'
        ");
    }
};
