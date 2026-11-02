<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pertegas kolom penyegel rantai hash.
     *
     * `hash` dan `hash_sebelumnya` menyimpan SHA-256 dalam bentuk heksadesimal
     * 64 karakter. Sebelumnya keduanya `varchar(64)` dengan kolasi
     * `utf8mb4_unicode_ci` — kolasi itu tidak peka huruf besar/kecil, sehingga
     * dua hash yang hanya berbeda kapitalisasi akan dianggap sama bila
     * dibandingkan lewat SQL. Panjangnya pun tidak dipatok.
     *
     * Dengan `char(64)` berkolasi biner, nilai selalu tepat 64 karakter dan
     * setiap perbandingan dilakukan byte-per-byte, sesuai sifat nilai hash.
     */
    public function up(): void
    {
        Schema::table('laporan_keuangan_logs', function (Blueprint $table) {
            $table->char('hash', 64)->collation('utf8mb4_bin')->change();
            $table->char('hash_sebelumnya', 64)->nullable()->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('laporan_keuangan_logs', function (Blueprint $table) {
            $table->string('hash', 64)->collation('utf8mb4_unicode_ci')->change();
            $table->string('hash_sebelumnya', 64)->nullable()->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
