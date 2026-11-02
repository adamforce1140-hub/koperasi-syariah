<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menjadikan enum `anggota.status_anggota` KOMPATIBEL-MUNDUR.
 *
 * Migrasi 2026_09_24_000100 memperluas siklus hidup keanggotaan dengan
 * MENGGANTI nilai lama ('Aktif' -> 'ACTIVE', dst). Akibatnya penulisan nilai
 * lama yang masih tersebar di kode aplikasi (mis. AOController yang menulis
 * 'aktif', view edit anggota, command uji coba) gagal di level basis data
 * karena nilai di luar daftar enum dipotong menjadi '' — dan pada kolom
 * NOT NULL MySQL melempar "Data truncated for column 'status_anggota'".
 *
 * Migrasi ini:
 *  1. menambahkan kembali nilai lama sebagai alias yang sah, sehingga kode
 *     lama tetap berjalan tanpa regresi;
 *  2. menormalkan seluruh data lama ke nilai siklus hidup baru agar
 *     perhitungan (tenure, seleksi anggota ACTIVE) konsisten satu nilai saja.
 *
 * Penulisan data baru WAJIB memakai konstanta Anggota::STATUS_*.
 */
return new class extends Migration
{
    /** Nilai kanonik (siklus hidup) + alias legacy yang dipertahankan. */
    private const ENUM_KOMPATIBEL = [
        'ACTIVE', 'PENDING', 'INACTIVE', 'SUSPENDED', 'Keluar',
        'Aktif', 'Tidak Aktif', 'Nonaktif', 'Dibekukan',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('anggota', 'status_anggota')) {
            return;
        }

        // 1. Alias legacy tetap diterima agar tidak ada penulisan yang gagal.
        $daftar = "'".implode("','", self::ENUM_KOMPATIBEL)."'";
        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM({$daftar}) NOT NULL DEFAULT 'PENDING'");

        // 2. Normalisasi data: satu makna = satu nilai kanonik.
        foreach ([
            'Aktif' => 'ACTIVE',
            'Tidak Aktif' => 'INACTIVE',
            'Nonaktif' => 'INACTIVE',
            'Dibekukan' => 'SUSPENDED',
        ] as $lama => $baru) {
            DB::table('anggota')
                ->where('status_anggota', $lama)
                ->update(['status_anggota' => $baru]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('anggota', 'status_anggota')) {
            return;
        }

        $daftar = "'".implode("','", self::ENUM_KOMPATIBEL)."'";
        DB::statement("ALTER TABLE `anggota` MODIFY `status_anggota` ENUM({$daftar}) NOT NULL DEFAULT 'PENDING'");
    }
};
