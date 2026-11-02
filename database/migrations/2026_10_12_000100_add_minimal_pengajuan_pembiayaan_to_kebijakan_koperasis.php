<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nominal minimum pengajuan pembiayaan baru — menghapus hardcode `min:1000000`
 * pada validasi pengajuan AO agar ambang dapat diubah pengurus dari halaman
 * Profil & Kebijakan tanpa mengubah kode.
 *
 * Dibaca melalui App\Support\Setting::minimalPengajuanPembiayaan().
 * Default = perilaku sistem saat ini (Rp 1.000.000), sehingga membiarkan
 * konfigurasi apa adanya TIDAK mengubah apa pun (P4 Default Aman).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'minimal_pengajuan_pembiayaan')) {
                $table->decimal('minimal_pengajuan_pembiayaan', 15, 2)
                    ->default(1000000.00)
                    ->after('limit_approval_beban')
                    ->comment('Nominal minimum pengajuan pembiayaan baru (menggantikan literal min:1000000).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'minimal_pengajuan_pembiayaan')) {
                $table->dropColumn('minimal_pengajuan_pembiayaan');
            }
        });
    }
};
