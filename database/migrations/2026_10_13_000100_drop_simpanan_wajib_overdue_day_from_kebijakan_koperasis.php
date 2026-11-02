<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus kolom `simpanan_wajib_overdue_day` dari `kebijakan_koperasis`.
 *
 * Sebelumnya tanggal jatuh tempo tagihan Simpanan Wajib diatur manual oleh
 * Pengurus lewat kebijakan koperasi (kolom ini). Sistem kini sepenuhnya
 * OTOMATIS: jatuh tempo dihitung per anggota sebagai anniversary bulanan
 * terhitung sejak tanggal anggota bergabung aktif (lihat
 * MandatorySavingsService::tanggalJatuhTempo()). Karena itu parameter
 * kebijakan menjadi usang dan dihapus menyeluruh (UI, validasi, model,
 * Setting, dan penandaan OVERDUE).
 *
 * Down() mengembalikan kolom untuk kebutuhan rollback (default 10 = perilaku
 * historis), sehingga migrasi bersifat reversibel.
 */
return new class extends Migration
{
    private const KOLOM = 'simpanan_wajib_overdue_day';

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        if (Schema::hasColumn('kebijakan_koperasis', self::KOLOM)) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->dropColumn(self::KOLOM);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        if (! Schema::hasColumn('kebijakan_koperasis', self::KOLOM)) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->unsignedTinyInteger(self::KOLOM)
                    ->nullable()
                    ->default(10)
                    ->after('shu_hold_on_overdue')
                    ->comment('Tanggal jatuh tempo tagihan Simpanan Wajib setiap bulan (1-28).');
            });
        }
    }
};
