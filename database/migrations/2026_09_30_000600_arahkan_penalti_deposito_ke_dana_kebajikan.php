<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * R7 — Ta'zir penalti pencairan dini deposito WAJIB masuk Dana Kebajikan.
     *
     * Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000: ta'zir adalah dana sosial dan tidak
     * boleh menjadi pendapatan koperasi. Produk lama diarahkan ke akun
     * Pendapatan Biaya Administrasi (4-2001) — migrasi ini memindahkannya ke
     * akun Dana Kebajikan (2-3001).
     *
     * Produk yang belum dikonfigurasi (NULL) sengaja TIDAK diubah agar tetap
     * mengikuti default baru pada `DepositoProduct::PENALTI_COA_DEFAULT`.
     */
    public function up(): void
    {
        DB::table('deposito_products')
            ->where('penalty_coa_id', '4-2001')
            ->update(['penalty_coa_id' => '2-3001']);
    }

    public function down(): void
    {
        DB::table('deposito_products')
            ->where('penalty_coa_id', '2-3001')
            ->update(['penalty_coa_id' => '4-2001']);
    }
};
