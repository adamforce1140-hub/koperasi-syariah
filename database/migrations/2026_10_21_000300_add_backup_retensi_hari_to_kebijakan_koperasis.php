<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RETENSI CADANGAN BASIS DATA sebagai parameter kebijakan (ZERO-HARDCODING).
 *
 * TEMUAN: kebijakan retensi berkas cadangan ditulis keras sebagai
 * `--simpan-hari=14` langsung di routes/console.php. Koperasi yang wajib
 * menyimpan cadangan lebih lama (mis. audit/internal control) atau yang
 * disknya terbatas harus mengubah kode program.
 *
 * KONSEP: `backup_retensi_hari` menjadi parameter kebijakan yang dibaca
 * scheduler setiap kali `schedule:run` berjalan. Default 14 = perilaku lama,
 * sehingga sistem berjalan identik sampai pengurus mengubahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'backup_retensi_hari')) {
                $table->unsignedSmallInteger('backup_retensi_hari')
                    ->default(14)
                    ->after('high_value_withdrawal_limit')
                    ->comment('Umur maksimum berkas cadangan basis data (hari) sebelum dihapus scheduler.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'backup_retensi_hari')) {
                $table->dropColumn('backup_retensi_hari');
            }
        });
    }
};
