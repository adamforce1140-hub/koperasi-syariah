<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menormalkan KEMBALI data `anggota.status_anggota` ke nilai kanonik.
 *
 * Latar belakang (bug "The selected status anggota is invalid"):
 *  - Validasi form menolak seluruh nilai kanonik (ACTIVE dll) karena aturan
 *    `in:` di beberapa controller masih memakai alias lama;
 *  - seeder & command uji coba juga masih menulis alias lama ('Aktif'),
 *    sehingga data dev/peraga kembali kotor setiap kali seeding dijalankan.
 *
 * Migrasi ini mengembalikan normalisasi yang dilakukan migrasi
 * 2026_09_25_000100 (yang datanya tercemar ulang oleh tulisan lama).
 * Bukti di lingkungan dev: 9 baris 'Aktif' dan 1 baris 'ACTIVE'.
 *
 * Alias legacy TETAP dipertahankan pada definisi enum (kompatibilitas data
 * lama), sehingga `down()` tidak perlu mengubah struktur kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('anggota', 'status_anggota')) {
            return;
        }

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
        // Normalisasi bersifat idempoten & satu arah: nilai kanonik tetap sah
        // pada definisi enum versi mana pun setelah migrasi ini.
    }
};
