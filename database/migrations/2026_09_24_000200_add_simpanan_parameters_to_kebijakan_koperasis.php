<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter nominal Simpanan Pokok & Simpanan Wajib pada kebijakan koperasi.
 *
 * Nilai setoran awal (bundling registrasi) TIDAK boleh di-hardcode di
 * controller. Kedua nominal berikut menjadi sumber kebenaran tunggal dan
 * dapat diubah kapan saja oleh pengurus tanpa deploy ulang:
 *
 *   - mandatory_principal_savings : nominal Simpanan Pokok (sekali bayar)
 *   - mandatory_monthly_savings   : nominal Simpanan Wajib per bulan
 *
 * Default mengikuti AnggotaSeeder / master produk (Rp 100.000 dan Rp 50.000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'mandatory_principal_savings')) {
                $table->decimal('mandatory_principal_savings', 15, 2)->default(100000.00)->after('nama_koperasi');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'mandatory_monthly_savings')) {
                $table->decimal('mandatory_monthly_savings', 15, 2)->default(50000.00)->after('mandatory_principal_savings');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['mandatory_monthly_savings', 'mandatory_principal_savings'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
