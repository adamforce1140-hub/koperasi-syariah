<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter ENUM untuk tambah nilai 'pengurus'
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','teller','manajer','pengurus','anggota') NOT NULL DEFAULT 'anggota'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin','teller','manajer','anggota') NOT NULL DEFAULT 'anggota'");
    }
};
