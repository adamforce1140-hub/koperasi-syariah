<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PEMETAAN AKUN BANK OPERASIONAL KOPERASI.
 *
 * `App\Support\Setting` dan `CoaResolver::kodeBankKoperasi()` sudah membaca
 * parameter `account_bank_koperasi_id`, tetapi kolomnya tidak pernah dibuat di
 * tabel `kebijakan_koperasis`. Akibatnya parameter tidak bisa disimpan.
 *
 * Kolom dibuat nullable: NULL = ikuti akun standar `App\Support\Coa::BANK_KOPERASI`
 * sehingga perilaku yang ada tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'account_bank_koperasi_id')) {
                $table->unsignedBigInteger('account_bank_koperasi_id')
                    ->nullable()
                    ->after('account_kas_teller_id')
                    ->comment('COA Bank Operasional Koperasi. Kosong = ikuti standar 1-1002.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'account_bank_koperasi_id')) {
                $table->dropColumn('account_bank_koperasi_id');
            }
        });
    }
};
