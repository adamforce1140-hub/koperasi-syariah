<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penambahan kolom untuk handling Autodebet saat penolakan Keringanan Ta'zir.
 *
 * 1) tazir_penangguhans.flag
 *    Menandai tunggakan ta'zir yang GAGAL dipotong autodebet karena saldo
 *    Simpanan Sukarela tidak mencukupi → 'PENDING_AUTODEBET_SUSULAN'.
 *
 * 2) angsuran.status_tazir
 *    Status ta'zir pada jadwal angsuran terkait → 'LUNAS' | 'TUNGGAKAN'.
 *
 * SAFEGUARD: tidak menghapus/mengubah kolom & relasi yang sudah ada.
 * Setiap perubahan dijaga Schema::hasTable() + Schema::hasColumn().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tazir_penangguhans')
            && ! Schema::hasColumn('tazir_penangguhans', 'flag')) {
            Schema::table('tazir_penangguhans', function (Blueprint $table) {
                $table->string('flag', 50)->nullable()->after('is_tazir_paid');
                $table->index('flag');
            });
        }

        if (Schema::hasTable('angsuran')
            && ! Schema::hasColumn('angsuran', 'status_tazir')) {
            Schema::table('angsuran', function (Blueprint $table) {
                $table->string('status_tazir', 20)->nullable()->after('denda');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tazir_penangguhans')
            && Schema::hasColumn('tazir_penangguhans', 'flag')) {
            Schema::table('tazir_penangguhans', function (Blueprint $table) {
                $table->dropIndex(['flag']);
                $table->dropColumn('flag');
            });
        }

        if (Schema::hasTable('angsuran')
            && Schema::hasColumn('angsuran', 'status_tazir')) {
            Schema::table('angsuran', function (Blueprint $table) {
                $table->dropColumn('status_tazir');
            });
        }
    }
};
