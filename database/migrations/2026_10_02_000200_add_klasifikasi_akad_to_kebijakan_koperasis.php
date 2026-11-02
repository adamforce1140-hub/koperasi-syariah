<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FLEKSIBILITAS KLASIFIKASI AKAD & PARTISIPASI SHU (temuan audit K-06 / K-09).
 *
 * TEMUAN: pengelompokan akad menurut PSAK 107 dan daftar jenis transaksi yang
 * dihitung sebagai partisipasi usaha anggota (basis Jasa Transaksi SHU)
 * ditulis keras sebagai konstanta kelas (`PendapatanService::AKAD_MARGIN`,
 * `ShuCalculatorService::JENIS_TRANSAKSI_PARTISIPASI`). Koperasi yang memakai
 * akad lain (mis. Musawamah, Istishna Paralel) atau istilah jenis transaksi
 * yang berbeda tidak dapat menyesuaikannya tanpa mengubah kode — melanggar
 * prinsip zero-hardcoding dan menyulitkan adaptasi regulasi.
 *
 * KONSEP: keempat daftar menjadi PARAMETER KEBIJAKAN (berkoma), dibaca lewat
 * `Setting::akadMargin()`, `Setting::akadBagiHasil()`, `Setting::akadUjroh()`,
 * dan `Setting::jenisPartisipasiShu()`.
 *
 * Nilai default kolom DISAMAKAN dengan default di App\Support\Setting
 * (P4 Default Aman): sistem berperilaku identik sampai pengurus mengubahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'akad_margin')) {
                $table->string('akad_margin', 500)
                    ->nullable()
                    ->after('periode_cutoff_mode')
                    ->comment('Daftar akad jual-beli (margin), dipisah koma. PSAK 107.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'akad_bagi_hasil')) {
                $table->string('akad_bagi_hasil', 500)
                    ->nullable()
                    ->after('akad_margin')
                    ->comment('Daftar akad kerja sama usaha (bagi hasil), dipisah koma. PSAK 105/106.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'akad_ujroh')) {
                $table->string('akad_ujroh', 500)
                    ->nullable()
                    ->after('akad_bagi_hasil')
                    ->comment('Daftar akad sewa/jasa (ujroh), dipisah koma. PSAK 107.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_jenis_partisipasi')) {
                $table->string('shu_jenis_partisipasi', 500)
                    ->nullable()
                    ->after('akad_ujroh')
                    ->comment('Daftar jenis transaksi partisipasi usaha anggota (basis Jasa Transaksi SHU), dipisah koma.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['shu_jenis_partisipasi', 'akad_ujroh', 'akad_bagi_hasil', 'akad_margin'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
