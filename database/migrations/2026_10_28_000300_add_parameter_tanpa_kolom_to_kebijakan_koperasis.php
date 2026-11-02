<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom untuk parameter kebijakan yang sebelumnya hanya dibaca
 * `Setting` tanpa kolom DB (temuan kontrak parameter, langkah 1 poin 3).
 * Semua kolom baru memakai default yang sama dengan `Setting::DEFAULTS`
 * sehingga perilaku sistem tidak berubah sebelum nilai diubah Pengurus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'autodebit_wajib_konfirmasi')) {
                $table->boolean('autodebit_wajib_konfirmasi')
                    ->default(true)
                    ->after('simpanan_wajib_ambang_kurang_ringan')
                    ->comment('Auto-debit simpanan wajib wajib dikonfirmasi. Default aman: true.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'toleransi_persen')) {
                $table->decimal('toleransi_persen', 8, 4)
                    ->default(0.0100)
                    ->after('tutup_buku_sampai')
                    ->comment('Toleransi selisih rekonsiliasi (persen). Default 0.01.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'account_dana_kebajikan_id')) {
                $table->unsignedBigInteger('account_dana_kebajikan_id')
                    ->nullable()
                    ->after('account_dana_infak_id')
                    ->comment('COA Dana Kebajikan. Kosong = ikuti standar 2-3001.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'account_dana_sosial_id')) {
                $table->unsignedBigInteger('account_dana_sosial_id')
                    ->nullable()
                    ->after('account_dana_kebajikan_id')
                    ->comment('COA Dana Sosial. Kosong = ikuti standar 2-3003.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'account_titipan_selisih_id')) {
                $table->unsignedBigInteger('account_titipan_selisih_id')
                    ->nullable()
                    ->after('account_dana_sosial_id')
                    ->comment('COA Titipan Selisih. Kosong = ikuti standar 2-3099.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach ([
                'account_titipan_selisih_id',
                'account_dana_sosial_id',
                'account_dana_kebajikan_id',
                'toleransi_persen',
                'autodebit_wajib_konfirmasi',
            ] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
