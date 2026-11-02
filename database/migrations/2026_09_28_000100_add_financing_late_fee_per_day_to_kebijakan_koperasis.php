<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif denda keterlambatan angsuran pembiayaan per hari
 * (ZERO-HARDCODING POLICY — PSAK 108 / PSAK 109 + Fatwa DSN-MUI 17/DSN-MUI/IX/2000).
 *
 * Parameter ini menjadi SAKLAR TUNGGAL bagi fitur denda Ta'zir / Late Fee pada
 * modul Pembiayaan:
 *   - Nilai > 0 → fitur denda AKTIF, tarif harian mengikuti nilai ini.
 *   - Nilai = 0 → fitur denda NONAKTIF; ta'zir tidak boleh dihitung maupun
 *                 diposting ke Dana Kebajikan (2-3001).
 *
 * Ta'zir bersifat EDUKATIF — bukan sumber pendapatan koperasi. Seluruh nominalnya
 * wajib dialokasikan ke Dana Kebajikan (2-3001) dan DILARANG masuk ke akun
 * Pendapatan Margin (4-100x). Karena itu nilainya wajib ditetapkan pengurus
 * dalam kebijakan tertulis, bukan ditanam di kode inti.
 *
 * Kolom ditambahkan dengan guard `Schema::hasColumn` sehingga migrasi idempoten
 * dan aman dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'financing_late_fee_per_day')) {
                $table->decimal('financing_late_fee_per_day', 18, 2)
                    ->default(0.00)
                    ->after('tazir_rate')
                    ->comment('Tarif denda keterlambatan angsuran pembiayaan per hari (Rp). 0 = fitur denda NONAKTIF.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'financing_late_fee_per_day')) {
                $table->dropColumn('financing_late_fee_per_day');
            }
        });
    }
};
