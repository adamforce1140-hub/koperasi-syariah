<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selaraskan presisi nominal tagihan Simpanan Wajib dengan parameter
 * kebijakan koperasi (decimal(15,2)).
 *
 * Kolom `amount` semula decimal(12,2) — cukup untuk nilai rupiah saat ini,
 * namun disamakan dengan `kebijakan_koperasis.mandatory_monthly_savings`
 * (decimal(15,2)) agar tidak ada perbedaan presisi antar tabel sumber.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mandatory_savings_bills')
            || ! Schema::hasColumn('mandatory_savings_bills', 'amount')) {
            return;
        }

        Schema::table('mandatory_savings_bills', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mandatory_savings_bills')
            || ! Schema::hasColumn('mandatory_savings_bills', 'amount')) {
            return;
        }

        Schema::table('mandatory_savings_bills', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable(false)->change();
        });
    }
};
