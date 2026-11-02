<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perluasan enum `anggota.status_anggota` ke siklus hidup keanggotaan penuh.
 *
 * Enum lama hanya mengenal 4 status operasional:
 *   'Aktif' | 'Tidak Aktif' | 'Keluar' | 'Dibekukan'
 *
 * Modul Simpanan Syariah membutuhkan status siklus hidup yang lebih tegas —
 * khususnya PENDING untuk anggota yang baru mendaftar namun belum melunasi
 * setoran awal (Simpanan Pokok + Simpanan Wajib bulan ke-1). Status berubah
 * menjadi ACTIVE secara otomatis setelah setoran awal tervalidasi.
 *
 * Pemetaan data lama (migrasi data, bukan rename destruktif):
 *   'Aktif'       -> 'ACTIVE'
 *   'Tidak Aktif' -> 'INACTIVE'
 *   'Dibekukan'   -> 'SUSPENDED'
 *   'Keluar'      -> tetap 'Keluar' (status historis keluarnya anggota,
 *                    bukan bagian dari siklus aktif; dipertahankan apa adanya
 *                    agar riwayat keanggotaan lama tidak berubah makna)
 *
 * Kolom tetap ENUM (bukan string) supaya integritas nilai terjaga di level
 * basis data, dan tetap kompatibel dengan validator `in:...` di controller.
 */
return new class extends Migration
{
    private const ENUM_BARU = ['ACTIVE', 'PENDING', 'INACTIVE', 'SUSPENDED', 'Keluar'];

    public function up(): void
    {
        if (! Schema::hasColumn('anggota', 'status_anggota')) {
            return;
        }

        // 1. Longgarkan kolom lebih dulu agar nilai baru dapat ditulis saat
        //    migrasi data (ENUM MySQL menolak nilai di luar daftarnya).
        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` VARCHAR(20) NOT NULL DEFAULT 'PENDING'");

        // 2. Migrasi data dari nilai lama ke nilai siklus hidup baru.
        DB::table('anggota')->where('status_anggota', 'Aktif')->update(['status_anggota' => 'ACTIVE']);
        DB::table('anggota')->where('status_anggota', 'Tidak Aktif')->update(['status_anggota' => 'INACTIVE']);
        DB::table('anggota')->where('status_anggota', 'Dibekukan')->update(['status_anggota' => 'SUSPENDED']);

        // 3. Kembalikan sebagai ENUM lengkap.
        $daftar = "'".implode("','", self::ENUM_BARU)."'";
        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM({$daftar}) NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        if (! Schema::hasColumn('anggota', 'status_anggota')) {
            return;
        }

        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` VARCHAR(20) NOT NULL DEFAULT 'Aktif'");

        DB::table('anggota')->where('status_anggota', 'ACTIVE')->update(['status_anggota' => 'Aktif']);
        DB::table('anggota')->where('status_anggota', 'INACTIVE')->update(['status_anggota' => 'Tidak Aktif']);
        DB::table('anggota')->where('status_anggota', 'SUSPENDED')->update(['status_anggota' => 'Dibekukan']);
        DB::table('anggota')->where('status_anggota', 'PENDING')->update(['status_anggota' => 'Tidak Aktif']);

        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM('Aktif','Tidak Aktif','Keluar','Dibekukan') NOT NULL DEFAULT 'Aktif'");
    }
};
