<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARAMETER HISAB ZAKAT (temuan audit T-02).
 *
 * TEMUAN: nominal zakat dicatat sebagai input manual tanpa perhitungan kadar
 * (2,5%) maupun nishab (85 gram emas), sehingga potensi pelanggaran ketentuan
 * hisab syariah. Sistem hanya menerima angka apa pun yang diisi pengguna.
 *
 * KONSEP: jadikan parameter hisab sebagai KEBIJAKAN yang dapat dikonfigurasi
 * Pengurus tanpa mengubah kode. Dibaca `App\Support\Setting` melalui:
 *   - Setting::zakatRate()          → kadar (%) default 2,5
 *   - Setting::zakatNisabGram()     → nishab (gram emas) default 85
 *   - Setting::hargaEmasPerGram()   → harga emas (Rp) untuk nishab nominal
 *   - Setting::hitungZakat($harta)  → hisab lengkap (kena zakat / 0)
 *
 * Nilai default kolom DISAMAKAN dengan default App\Support\Setting
 * (P4 Default Aman): sistem berperilaku sesuai ketentuan syariah yang berlaku
 * umum, dan perubahan regulasi/kebijakan cukup lewat UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_rate')) {
                $table->decimal('zakat_rate', 5, 2)
                    ->nullable()
                    ->after('account_simpanan_sukarela_id')
                    ->comment('Kadar zakat maal dalam persen (standar syariah 2,5%).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_nisab_gram')) {
                $table->decimal('zakat_nisab_gram', 10, 2)
                    ->nullable()
                    ->after('zakat_rate')
                    ->comment('Nishab zakat dalam gram emas (standar 85 gram).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'harga_emas_per_gram')) {
                $table->decimal('harga_emas_per_gram', 18, 2)
                    ->nullable()
                    ->after('zakat_nisab_gram')
                    ->comment('Harga emas per gram (Rp) untuk menghitung nishab nominal.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'zakat_minimal_tarik')) {
                $table->decimal('zakat_minimal_tarik', 18, 2)
                    ->nullable()
                    ->after('harga_emas_per_gram')
                    ->comment('Nominal minimum penyaluran dana zakat (Rp).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['zakat_minimal_tarik', 'harga_emas_per_gram', 'zakat_nisab_gram', 'zakat_rate'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
