<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PORSI / HAK AMIL ATAS DANA ZAKAT (temuan audit Z-08 / rekomendasi R-06).
 *
 * TEMUAN: `amil` hanya dikenal sebagai label kategori asnaf
 * (`ZakatService::ASNAF['amil']`). Tidak ada parameter porsi amil, sehingga
 * penetapan hak amil (umumnya maksimum 1/8 = 12,5% menurut Fatwa DSN-MUI 08)
 * sepenuhnya manual tanpa panduan sistem.
 *
 * KONSEP: jadikan porsi hak amil sebagai KEBIJAKAN yang dapat dikonfigurasi
 * Pengurus tanpa mengubah kode. Dibaca `App\Support\Setting` melalui:
 *   - Setting::zakatPorsiAmilPersen()  → porsi amil (%) default 12,5
 *   - Setting::zakatHakAmil($dasar)    → hak amil (Rp) atas penerimaan zakat
 *
 * P4 Default Aman: kolom dibiarkan NULL → `Setting` memakai default 12,5%
 * sehingga perilaku lama TIDAK berubah dan rekap hak amil langsung tersedia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_porsi_amil_persen')) {
                $table->decimal('zakat_porsi_amil_persen', 5, 2)
                    ->nullable()
                    ->after('zakat_haul_hari')
                    ->comment('Porsi hak amil atas dana zakat (%) — batas umum maksimum 1/8 (12,5%) Fatwa DSN-MUI 08. Kosong = 12,5%.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'zakat_porsi_amil_persen')) {
                $table->dropColumn('zakat_porsi_amil_persen');
            }
        });
    }
};
