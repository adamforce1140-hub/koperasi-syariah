<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah status FORFEITED pada deposito_profit_sharings.
 *
 * Saat deposito dicairkan sebelum jatuh tempo (break deposito) dan produk
 * menetapkan `forfeit_monthly_profit = true`, bagi hasil bulanan yang belum
 * dibayarkan hangus sesuai akad. Statusnya tidak dapat memakai 'FAILED'
 * (yang berarti kegagalan teknis) sehingga perlu nilai tersendiri agar
 * laporan bagi hasil tetap jujur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposito_profit_sharings')) {
            return;
        }

        // MySQL: ubah definisi ENUM tanpa doctrine/dbal (didukung Laravel 12).
        DB::statement("ALTER TABLE deposito_profit_sharings MODIFY COLUMN status ENUM('PAID','FAILED','FORFEITED') NOT NULL DEFAULT 'PAID'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('deposito_profit_sharings')) {
            return;
        }

        DB::statement("UPDATE deposito_profit_sharings SET status = 'FAILED' WHERE status = 'FORFEITED'");
        DB::statement("ALTER TABLE deposito_profit_sharings MODIFY COLUMN status ENUM('PAID','FAILED') NOT NULL DEFAULT 'PAID'");
    }
};
