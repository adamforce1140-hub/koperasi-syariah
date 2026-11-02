<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARAMETER DINAMIS modul Member Exit & Closure pada `kebijakan_koperasis`.
 *
 * - `closure_admin_fee`          : biaya administrasi penutupan CIF (Rp).
 * - `high_value_withdrawal_limit`: ambang total net yang mewajibkan persetujuan
 *                                  Manajer sebelum dana dicairkan (Rp).
 *
 * P4 Default Aman: kedua kolom NULLABLE. `closure_admin_fee` kosong = 0
 * (tidak membebani anggota sampai Pengurus menetapkan tarif), dan
 * `high_value_withdrawal_limit` kosong = 0 (approval Manajer nonaktif,
 * perilaku lama tetap berjalan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'closure_admin_fee')) {
                $table->decimal('closure_admin_fee', 18, 2)->nullable()
                    ->after('limit_approval_beban')
                    ->comment('Biaya administrasi penutupan CIF saat anggota keluar (Rp). 0 = tanpa biaya.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'high_value_withdrawal_limit')) {
                $table->decimal('high_value_withdrawal_limit', 18, 2)->nullable()
                    ->after('closure_admin_fee')
                    ->comment('Ambang total net pencairan exit yang wajib persetujuan Manajer (Rp). 0 = nonaktif.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['high_value_withdrawal_limit', 'closure_admin_fee'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
