<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferensi metode pembayaran Simpanan Wajib per anggota.
 *
 *   is_auto_debit = true  → tagihan bulanan dipotong otomatis dari saldo
 *                           Simpanan Sukarela (akun 2-1001) oleh scheduler.
 *   is_auto_debit = false → tagihan dibayar manual via Teller (tunai/transfer).
 *
 * Default false agar tidak ada pemotongan saldo tanpa persetujuan anggota.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anggota')) {
            return;
        }

        Schema::table('anggota', function (Blueprint $table) {
            if (! Schema::hasColumn('anggota', 'is_auto_debit')) {
                $table->boolean('is_auto_debit')
                    ->default(false)
                    ->after('status_anggota');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('anggota')) {
            return;
        }

        Schema::table('anggota', function (Blueprint $table) {
            if (Schema::hasColumn('anggota', 'is_auto_debit')) {
                $table->dropColumn('is_auto_debit');
            }
        });
    }
};
