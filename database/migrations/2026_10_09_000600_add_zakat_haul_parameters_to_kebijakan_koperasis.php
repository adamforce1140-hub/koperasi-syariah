<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SYARAT HAUL ZAKAT MAAL (temuan audit Z-04 / rekomendasi R-03).
 *
 * TEMUAN: syarat wajib zakat maal menurut Fatwa DSN-MUI 08 adalah
 * NISHAB **DAN** HAUL (kepemilikan penuh selama satu tahun hijriah, 354 hari).
 * `Setting::hitungZakat()` sebelumnya hanya memeriksa nishab, sehingga harta
 * yang belum genap haul dapat dinyatakan "wajib zakat" — berpotensi tidak sah
 * secara syariah.
 *
 * KONSEP: jadikan syarat haul sebagai KEBIJAKAN yang dapat dikonfigurasi
 * Pengurus tanpa mengubah kode. Dibaca `App\Support\Setting` melalui:
 *   - Setting::zakatHaulAktif()  → saklar penegakan haul (default: NONAKTIF)
 *   - Setting::zakatHaulHari()   → panjang haul dalam hari (default 354)
 *   - Setting::hitungZakat($harta, $tanggalKepemilikan) → haul_terpenuhi
 *
 * P4 Default Aman: `zakat_haul_aktif` default NULL/NONAKTIF sehingga
 * membiarkannya kosong TIDAK mengubah perilaku hisab yang sudah berjalan
 * (tetap berbasis nishab). Pengurus mengaktifkannya lewat UI Kebijakan bila
 * ingin menegakkan syarat haul.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_haul_aktif')) {
                $table->boolean('zakat_haul_aktif')
                    ->nullable()
                    ->after('zakat_minimal_tarik')
                    ->comment('Saklar penegakan syarat haul (kepemilikan 1 tahun hijriah) pada hisab zakat maal.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_haul_hari')) {
                $table->unsignedSmallInteger('zakat_haul_hari')
                    ->nullable()
                    ->after('zakat_haul_aktif')
                    ->comment('Panjang satu haul dalam hari (standar 354 hari hijriah).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['zakat_haul_hari', 'zakat_haul_aktif'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
