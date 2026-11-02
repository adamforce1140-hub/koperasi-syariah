<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PELAPORAN PPATK — LTKT / LTKM / STR (temuan audit OJK-01).
 *
 * TEMUAN: sistem hanya memverifikasi transaksi high-value secara internal
 * (AmlComplianceController) dan tidak memiliki jalur EKSPOR laporan kepada
 * PPATK. Koperasi sebagai penyedia jasa keuangan wajib menyampaikan LTKT
 * (transaksi tunai), LTKM (transaksi mencurigakan), dan STR melalui goAML —
 * UU No. 8 Tahun 2010 & POJK No. 27/POJK.03/2022.
 *
 * KONSEP: seluruh parameter pelaporan menjadi PARAMETER KEBIJAKAN sehingga
 * perubahan ambang/kategori tunai di masa mendatang cukup lewat UI Pengurus
 * tanpa mengubah kode — dibaca `PpatkReportService` melalui:
 *   - Setting::nominal('ltkt_threshold')       → ambang tunai wajib lapor
 *   - Setting::jenisTransaksiTunai()           → kategori transaksi tunai
 *   - Setting::get('ppatk_kode_pelapor') dll.  → identitas pelapor
 *
 * Nilai default kolom DISAMAKAN dengan default App\Support\Setting
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
            if (! Schema::hasColumn('kebijakan_koperasis', 'ltkt_threshold')) {
                $table->decimal('ltkt_threshold', 18, 2)
                    ->nullable()
                    ->after('aml_threshold')
                    ->comment('Ambang transaksi tunai wajib lapor PPATK (LTKT). Default Rp 500 juta.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ppatk_jenis_tunai')) {
                $table->string('ppatk_jenis_tunai', 500)
                    ->nullable()
                    ->after('ltkt_threshold')
                    ->comment('Daftar jenis transaksi tunai untuk LTKT (dipisah koma).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ppatk_nama_pelapor')) {
                $table->string('ppatk_nama_pelapor', 255)
                    ->nullable()
                    ->after('ppatk_jenis_tunai')
                    ->comment('Nama koperasi sebagai pelapor PPATK (kop laporan).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ppatk_kode_pelapor')) {
                $table->string('ppatk_kode_pelapor', 100)
                    ->nullable()
                    ->after('ppatk_nama_pelapor')
                    ->comment('Kode pelapor / ID goAML koperasi pada aplikasi PPATK.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'npwp_koperasi')) {
                $table->string('npwp_koperasi', 50)
                    ->nullable()
                    ->after('ppatk_kode_pelapor')
                    ->comment('NPWP koperasi — identitas pelapor berkas PPATK.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['npwp_koperasi', 'ppatk_kode_pelapor', 'ppatk_nama_pelapor', 'ppatk_jenis_tunai', 'ltkt_threshold'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
