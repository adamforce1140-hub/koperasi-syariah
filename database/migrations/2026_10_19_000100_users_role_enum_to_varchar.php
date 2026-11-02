<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DYN-01 — Lepaskan `users.role` dari ENUM MySQL.
 *
 * Sebelumnya setiap peran baru menuntut migrasi DDL (`ALTER TABLE ... ENUM`).
 * Daftar peran yang sah kini hidup di `config/role_hierarki.php` (SSOT RBAC)
 * dan divalidasi di aplikasi melalui `HierarkiRole::dikenal()` + guard pada
 * model `User`, sehingga menambah peran tidak lagi memerlukan perubahan skema.
 *
 * Tipe VARCHAR(32) dipilih karena seluruh nama peran yang ada (termasuk
 * `super_admin`) jauh di bawah 32 karakter, dan nilai lama disalin apa adanya
 * oleh MySQL saat ALTER — tidak ada data yang hilang.
 *
 * Catatan portabilitas: ENUM adalah tipe khas MySQL. Pada driver lain kolom
 * sudah berupa string, jadi migrasi ini tidak melakukan apa pun.
 */
return new class extends Migration
{
    /** Nilai ENUM terakhir sebelum kolom diubah — dipakai untuk rollback. */
    private const ENUM_LAMA =
        "ENUM('super_admin','admin','teller','manajer','pengurus','anggota','ao','dps','nasabah')";

    public function up(): void
    {
        if (! Schema::hasTable('users') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE users MODIFY COLUMN role VARCHAR(32) NOT NULL DEFAULT 'anggota'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || DB::getDriverName() !== 'mysql') {
            return;
        }

        // ENUM lama tidak mengenal peran yang ditambahkan setelah migrasi ini.
        // Turunkan nilai tersebut lebih dulu agar ALTER selalu berhasil dan
        // rollback tidak pernah menggantung di tengah jalan.
        DB::table('users')
            ->whereNotIn('role', [
                'super_admin', 'admin', 'teller', 'manajer', 'pengurus',
                'anggota', 'ao', 'dps', 'nasabah',
            ])
            ->update(['role' => 'anggota']);

        DB::statement(
            'ALTER TABLE users MODIFY COLUMN role '.self::ENUM_LAMA." NOT NULL DEFAULT 'anggota'"
        );
    }
};
