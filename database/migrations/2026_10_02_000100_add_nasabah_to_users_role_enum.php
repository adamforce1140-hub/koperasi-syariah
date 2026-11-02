<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OJK-06 — Selaraskan ENUM `users.role` dengan registri peran di
 * `config/role_hierarki.php`.
 *
 * Sebelumnya sistem mendefinisikan 7 peran (admin, teller, manajer, pengurus,
 * anggota, ao, dps) namun konfigurasi/dokumentasi menyebut pula peran
 * `nasabah` (calon anggota / pengguna jasa non-anggota — POJK No. 76/2016).
 * Migrasi ini menjadikan `nasabah` peran yang sah pada basis data sehingga
 * tidak ada ketidaksesuaian dokumentasi vs implementasi otorisasi
 * (OJK-06). Peran ini TIDAK memiliki akses operasional (lihat entri
 * `nasabah` pada config/role_hierarki.php — level 0, non-penyetuju).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement(
            'ALTER TABLE users MODIFY COLUMN role '
            ."ENUM('admin','teller','manajer','pengurus','anggota','ao','dps','nasabah') "
            ."NOT NULL DEFAULT 'anggota'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // Hindari kegagalan bila masih ada baris ber-role 'nasabah'; kembalikan
        // ke 'anggota' lebih dulu agar ENUM lama dapat diterapkan.
        DB::table('users')->where('role', 'nasabah')->update(['role' => 'anggota']);

        DB::statement(
            'ALTER TABLE users MODIFY COLUMN role '
            ."ENUM('admin','teller','manajer','pengurus','anggota','ao','dps') "
            ."NOT NULL DEFAULT 'anggota'"
        );
    }
};
