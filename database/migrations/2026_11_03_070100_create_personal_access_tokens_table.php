<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-2 — tabel token akses API (Laravel Sanctum).
 *
 * `User` sudah memakai trait `HasApiTokens` dan kanal mobile memakai guard
 * `auth:sanctum`, tetapi migrasi bawaan Sanctum tidak pernah dipasang sehingga
 * tabel ini TIDAK ADA: `createToken()` gagal dengan QueryException dan token
 * personal tidak dapat diterbitkan maupun diverifikasi. Migrasi ini memulihkan
 * fitur tersebut; dapat dibalik tanpa kehilangan data lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
