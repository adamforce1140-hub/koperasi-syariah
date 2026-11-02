<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal default Ta'zir pada kebijakan koperasi (ZERO-HARDCODING POLICY).
 *
 * Sebelumnya nominal ta'zir default ditanam langsung di kode inti:
 *
 *     TellerController::prosesTazir()
 *         $nominalTazir = (float) ($request->nominal ?? 250000); // Rp 250.000 default
 *
 * Nilai tersebut kini dapat diubah pengurus dari UI `Pengaturan Kebijakan
 * Koperasi` tanpa deploy ulang, konsisten dengan parameter operasional lain.
 *
 * PSAK terkait: 101 (penyajian laporan keuangan syariah) — denda/ta'zir tidak
 * boleh diakui sebagai pendapatan lembaga; alokasinya wajib ke Dana Kebajikan
 * (2-3001) dan nominalnya wajib ditetapkan dalam kebijakan tertulis.
 *
 * Kolom ditambahkan dengan guard `Schema::hasColumn` sehingga migrasi
 * idempoten dan aman dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'tazir_default_nominal')) {
                $table->decimal('tazir_default_nominal', 18, 2)
                    ->default(250000.00)
                    ->after('tazir_default_method')
                    ->comment("Nominal default Ta'zir (denda keterlambatan angsuran) dalam Rp.");
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'tazir_default_nominal')) {
                $table->dropColumn('tazir_default_nominal');
            }
        });
    }
};
