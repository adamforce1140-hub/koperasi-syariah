<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan penalti pencairan dini pada master produk Deposito.
 *
 * Deposito Mudharabah bersifat simpanan berjangka; pencairan sebelum jatuh
 * tempo (break deposito) dikenakan penalti dan umumnya membatalkan bagi hasil
 * bulan berjalan. Seluruh parameter disimpan di database agar kebijakan dapat
 * diubah tanpa mengubah kode.
 *
 * Lima kolom baru pada tabel `deposito_products`:
 *  - `allow_early_withdrawal`  : izin pencairan sebelum jatuh tempo
 *  - `penalty_fee_type`        : fixed (nominal rupiah) | percentage (persen pokok)
 *  - `penalty_fee_amount`      : nominal rupiah ATAU angka persen, sesuai tipe
 *  - `forfeit_monthly_profit`  : penanda bagi hasil bulan berjalan gugur
 *  - `penalty_coa_id`          : kode COA penerima biaya penalti (4-2001
 *                                Pendapatan Biaya Administrasi). Menyimpan KODE
 *                                (bukan numeric id) mengikuti referensi COA lain
 *                                di proyek ini, mis. `coa_kode` pada tabel yang
 *                                sama dan `Deposito::COA_*`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposito_products', function (Blueprint $table) {
            if (! Schema::hasColumn('deposito_products', 'allow_early_withdrawal')) {
                $table->boolean('allow_early_withdrawal')->default(true)->after('min_amount');
            }

            if (! Schema::hasColumn('deposito_products', 'penalty_fee_type')) {
                $table->enum('penalty_fee_type', ['fixed', 'percentage'])
                    ->default('fixed')
                    ->after('allow_early_withdrawal');
            }

            if (! Schema::hasColumn('deposito_products', 'penalty_fee_amount')) {
                $table->decimal('penalty_fee_amount', 10, 2)
                    ->default(50000)
                    ->after('penalty_fee_type');
            }

            if (! Schema::hasColumn('deposito_products', 'forfeit_monthly_profit')) {
                $table->boolean('forfeit_monthly_profit')->default(true)->after('penalty_fee_amount');
            }

            if (! Schema::hasColumn('deposito_products', 'penalty_coa_id')) {
                $table->string('penalty_coa_id', 20)->default('4-2001')->after('forfeit_monthly_profit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposito_products', function (Blueprint $table) {
            foreach ([
                'penalty_coa_id',
                'forfeit_monthly_profit',
                'penalty_fee_amount',
                'penalty_fee_type',
                'allow_early_withdrawal',
            ] as $kolom) {
                if (Schema::hasColumn('deposito_products', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
