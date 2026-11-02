<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom kualitas kolektibilitas pembiayaan.
 *
 * Setelah restrukturisasi disetujui Manajer, kolom ini di-set menjadi
 * 'Restrukturisasi (Lancar)' sesuai ketentuan penyajian kolektibilitas
 * pembiayaan yang telah direstrukturisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pembiayaan', 'status_kolektibilitas')) {
            Schema::table('pembiayaan', function (Blueprint $table) {
                $table->string('status_kolektibilitas', 50)->nullable()->after('status_pembiayaan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pembiayaan', 'status_kolektibilitas')) {
            Schema::table('pembiayaan', function (Blueprint $table) {
                $table->dropColumn('status_kolektibilitas');
            });
        }
    }
};
