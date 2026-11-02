<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter penjadwalan angsuran pembiayaan (ZERO-HARDCODING POLICY).
 *
 * Dipakai oleh App\Services\HariLiburService saat menetapkan `first_due_date`
 * pencairan pembiayaan:
 *  - `tanggal_jatuh_tempo_default` : tanggal dalam sebulan (dikunci 1-28) yang
 *    dipakai sebagai dasar jatuh tempo angsuran pertama.
 *  - `hari_libur_aktif`            : saklar penyesuaian tanggal jatuh tempo ke
 *    hari kerja (memperhitungkan akhir pekan + tabel `hari_liburs`).
 *  - `hari_libur_strategi`         : arah penggeseran bila jatuh pada hari
 *    libur — 'MAJU' (hari kerja berikutnya) atau 'MUNDUR' (hari kerja sebelumnya).
 *
 * Nilai default kolom di sini DISAMAKAN dengan default di App\Support\Setting
 * agar perilaku sistem identik baik saat baris kebijakan belum diisi maupun
 * saat kolom belum pernah diubah pengurus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'tanggal_jatuh_tempo_default')) {
                $table->unsignedTinyInteger('tanggal_jatuh_tempo_default')
                    ->default(1)
                    ->comment('Tanggal jatuh tempo angsuran dalam sebulan (1-28).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'hari_libur_aktif')) {
                $table->boolean('hari_libur_aktif')
                    ->default(true)
                    ->comment('Geser jatuh tempo ke hari kerja bila jatuh pada hari libur.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'hari_libur_strategi')) {
                $table->string('hari_libur_strategi', 10)
                    ->default('MAJU')
                    ->comment('Arah penggeseran jatuh tempo: MAJU | MUNDUR.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['hari_libur_strategi', 'hari_libur_aktif', 'tanggal_jatuh_tempo_default'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
