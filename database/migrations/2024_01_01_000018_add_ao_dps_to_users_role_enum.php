<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah nilai 'ao' dan 'dps' ke ENUM role
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','teller','manajer','pengurus','anggota','ao','dps') NOT NULL DEFAULT 'anggota'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','teller','manajer','pengurus','anggota') NOT NULL DEFAULT 'anggota'");
    }
};
