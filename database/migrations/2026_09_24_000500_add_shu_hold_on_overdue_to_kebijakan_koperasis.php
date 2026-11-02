<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan penahanan SHU bagi anggota yang menunggak Simpanan Wajib.
 *
 * `shu_hold_on_overdue` = true (default) → pada penutupan buku akhir tahun,
 * seluruh pencairan SHU anggota yang masih memiliki tagihan Simpanan Wajib
 * berstatus OVERDUE ditahan hingga tunggakan dilunasi. Bila diset false,
 * SHU tetap dicairkan meski ada tunggakan (kebijakan lunak).
 *
 * Nilai dibaca runtime oleh ShuCalculatorService::statusPenahanan() dan
 * dapat diubah pengurus dari Dashboard Kebijakan tanpa deploy ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_hold_on_overdue')) {
                $table->boolean('shu_hold_on_overdue')
                    ->default(true)
                    ->after('mandatory_monthly_savings');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'shu_hold_on_overdue')) {
                $table->dropColumn('shu_hold_on_overdue');
            }
        });
    }
};
