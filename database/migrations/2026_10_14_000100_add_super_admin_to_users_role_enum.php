<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan peran `super_admin` ke ENUM `users.role`.
 *
 * Super Admin adalah administrator SISTEM dengan kewenangan tertinggi di
 * antara seluruh peran administratif (`admin`). Selaras dengan registri peran
 * di `config/role_hierarki.php` (level 15, kategori `sistem`, non-penyetuju)
 * — peran ini TIDAK berada di rantai approval transaksi dan TIDAK memegang
 * hak tulis jurnal (pemisahan tugas maker-checker tetap utuh).
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
            ."ENUM('super_admin','admin','teller','manajer','pengurus','anggota','ao','dps','nasabah') "
            ."NOT NULL DEFAULT 'anggota'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        // Hindari kegagalan bila masih ada baris ber-role 'super_admin';
        // turunkan ke 'admin' lebih dulu agar ENUM lama dapat diterapkan.
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);

        DB::statement(
            'ALTER TABLE users MODIFY COLUMN role '
            ."ENUM('admin','teller','manajer','pengurus','anggota','ao','dps','nasabah') "
            ."NOT NULL DEFAULT 'anggota'"
        );
    }
};
