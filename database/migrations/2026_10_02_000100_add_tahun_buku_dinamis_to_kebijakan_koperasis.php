<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FLEKSIBILITAS PERIODE — TAHUN BUKU DINAMIS (temuan audit K-01 / K-02).
 *
 * TEMUAN: awal/akhir tahun buku ditulis keras sebagai 1 Januari s.d.
 * 31 Desember (batas 12 bulan) di banyak service, model, dan perintah
 * console. Koperasi syariah yang memakai tahun buku non-kalender — praktik
 * yang lazim dan dibenarkan (mis. Juli–Juni mengikuti tahun anggaran) —
 * tidak dapat memakainya tanpa mengubah kode program.
 *
 * KONSEP: tahun buku menjadi PARAMETER KEBIJAKAN, bukan konstanta:
 *   - `tahun_buku_mulai_bulan`  (1-12, default 1 = Januari)
 *   - `jumlah_bulan_tahun_buku` (1-12, default 12 = setahun penuh)
 *
 * Nilai default kolom DISAMAKAN dengan default di App\Support\Setting
 * (ZERO-HARDCODING POLICY & P4 Default Aman), sehingga perilaku sistem
 * identik baik saat kolom belum pernah diisi maupun saat baris kebijakan
 * belum ada — sistem tetap berjalan Januari–Desember sampai pengurus
 * mengubahnya dari UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'tahun_buku_mulai_bulan')) {
                $table->unsignedTinyInteger('tahun_buku_mulai_bulan')
                    ->default(1)
                    ->after('tutup_buku_sampai')
                    ->comment('Bulan pertama tahun buku (1-12). 1 = Januari. Contoh 7 = Juli untuk tahun buku non-kalender.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'jumlah_bulan_tahun_buku')) {
                $table->unsignedTinyInteger('jumlah_bulan_tahun_buku')
                    ->default(12)
                    ->after('tahun_buku_mulai_bulan')
                    ->comment('Jumlah bulan dalam satu tahun buku (1-12). 12 = setahun penuh.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'periode_cutoff_mode')) {
                $table->string('periode_cutoff_mode', 20)
                    ->default('fail_open')
                    ->after('jumlah_bulan_tahun_buku')
                    ->comment('Perilaku gerbang cut-off periode saat nilai tidak terurai: fail_closed (tolak) atau fail_open (izinkan).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['periode_cutoff_mode', 'jumlah_bulan_tahun_buku', 'tahun_buku_mulai_bulan'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
