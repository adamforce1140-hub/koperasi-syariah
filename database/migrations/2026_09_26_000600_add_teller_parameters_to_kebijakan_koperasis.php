<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter modul Teller pada kebijakan koperasi (ZERO-HARDCODING POLICY).
 *
 * Sebelumnya batas-batas berikut ditanam langsung di kode inti sehingga
 * perubahan kebijakan operasional memaksa deploy ulang:
 *
 *   1. Nominal minimum penambahan modal kas teller — hardcode `min:10000`
 *      pada TellerController::tambahModal().
 *   2. Nominal minimum penarikan/setoran tunai — hardcode `min:1000`
 *      pada TellerController::tarikStore() dan setorStore().
 *   3. Batas maksimum kas fisik yang boleh mengendap di laci teller —
 *      sebelumnya tidak ada sama sekali (kas dapat menumpuk tanpa kendali).
 *
 * Kolom ditambahkan dengan guard `Schema::hasColumn` sehingga migrasi
 * idempoten dan aman dijalankan berulang.
 *
 * PSAK terkait: 101 (penyajian laporan keuangan syariah) — pengendalian
 * internal atas kas (kas teller vs kas brankas) wajib terdokumentasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'teller_max_cash_limit')) {
                $table->decimal('teller_max_cash_limit', 18, 2)
                    ->default(25000000.00)
                    ->after('limit_setoran_teller')
                    ->comment('Batas maksimum kas fisik yang boleh mengendap di laci teller (Rp).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'minimum_transaksi_teller')) {
                $table->decimal('minimum_transaksi_teller', 18, 2)
                    ->default(1000.00)
                    ->after('teller_max_cash_limit')
                    ->comment('Nominal minimum transaksi tunai di teller (Rp).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['minimum_transaksi_teller', 'teller_max_cash_limit'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
