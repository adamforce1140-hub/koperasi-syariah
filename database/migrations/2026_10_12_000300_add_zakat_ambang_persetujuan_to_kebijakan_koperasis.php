<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMBANG PERSETUJUAN penyaluran dana Zakat & Infak/Sedekah (temuan audit Z-02).
 *
 * Penyaluran yang mencapai ambang ini WAJIB disetujui pengurus/manajer LAIN
 * sebelum jurnal diposting (maker-checker). Di bawah ambang, penyaluran dapat
 * diposting langsung dengan jejak audit (efisiensi operasional).
 *
 * Kolom NULLABLE / DEFAULT AMAN (P4): dibiarkan kosong berarti 0 = dual control
 * NONAKTIF, sehingga perilaku existing (auto-post) tidak berubah sampai Pengurus
 * mengaktifkannya lewat Kebijakan. Selaras dengan accessor
 * App\Support\Setting::zakatAmbangPersetujuan().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_ambang_persetujuan')) {
                $table->decimal('zakat_ambang_persetujuan', 18, 2)
                    ->nullable()
                    ->after('zakat_minimal_tarik')
                    ->comment('Ambang nominal penyaluran zakat yang wajib persetujuan (maker-checker). 0 = nonaktif.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'zakat_ambang_persetujuan')) {
                $table->dropColumn('zakat_ambang_persetujuan');
            }
        });
    }
};
