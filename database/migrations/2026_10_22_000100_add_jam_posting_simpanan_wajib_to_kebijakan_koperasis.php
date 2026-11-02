<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DYN-17 — Jam posting auto-debit Simpanan Wajib dapat dikonfigurasi.
 *
 * Sebelumnya jam posting tertanam sebagai konstanta kelas
 * `MandatorySavingsService::JAM_POSTING = '00:01'`, sehingga koperasi tidak
 * dapat menggeser jam pencatatan transaksi otomatis tanpa mengubah kode.
 *
 * P4 Default Aman: kolom NULLABLE. Bila kosong, `Setting` memakai `00:01`
 * sehingga perilaku sistem TIDAK berubah.
 */
return new class extends Migration
{
    private const KOLOM = 'jam_posting_simpanan_wajib';

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', self::KOLOM)) {
                $table->string(self::KOLOM, 5)
                    ->nullable()
                    ->comment('Jam posting transaksi auto-debit Simpanan Wajib (HH:MM). Kosong = 00:01.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', self::KOLOM)) {
                $table->dropColumn(self::KOLOM);
            }
        });
    }
};
