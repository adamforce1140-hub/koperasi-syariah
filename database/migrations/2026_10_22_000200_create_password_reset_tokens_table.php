<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1 — Tabel token "Lupa Kata Sandi".
 *
 * `config/auth.php` sudah menyiapkan broker `users` (tabel
 * `password_reset_tokens`, kedaluwarsa 60 menit, throttle 60 detik), tetapi
 * tabelnya belum pernah dibuat sehingga tautan atur ulang kata sandi tidak
 * dapat disimpan sama sekali.
 *
 * Skema mengikuti bawaan Laravel: `email` sebagai primary key sehingga satu
 * email hanya memiliki satu token aktif (token lama otomatis tertimpa),
 * `token` disimpan dalam bentuk HASH oleh `DatabaseTokenRepository`, dan
 * `created_at` dipakai untuk memeriksa masa berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens')) {
            return;
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
