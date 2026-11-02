<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom pendukung kalkulasi SHU Prorated & sistem penahanan (HOLD) SHU.
 *
 * `shu_distributions`:
 *   - monthly_average_balance : rata-rata saldo pengendapan Simpanan Pokok +
 *                               Simpanan Wajib selama tahun buku; dasar Jasa
 *                               Modal menggantikan basis saldo statis.
 *   - tenure_months           : jumlah bulan keanggotaan AKTIF pada tahun buku
 *                               berjalan (dasar prorata).
 *   - is_hold / hold_reason   : penanda penahanan pencairan SHU karena masih
 *                               ada tagihan Simpanan Wajib berstatus OVERDUE.
 *
 * `shu_periods`:
 *   - total_shu_hold          : akumulasi SHU yang ditahan pada periode ini
 *   - jumlah_anggota_hold     : berapa anggota yang SHU-nya ditahan
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shu_distributions')) {
            Schema::table('shu_distributions', function (Blueprint $table) {
                if (! Schema::hasColumn('shu_distributions', 'monthly_average_balance')) {
                    $table->decimal('monthly_average_balance', 18, 2)->default(0)->after('total_simpanan_modal');
                }

                if (! Schema::hasColumn('shu_distributions', 'tenure_months')) {
                    $table->unsignedSmallInteger('tenure_months')->default(0)->after('monthly_average_balance');
                }

                if (! Schema::hasColumn('shu_distributions', 'is_hold')) {
                    $table->boolean('is_hold')->default(false)->after('total_shu');
                }

                if (! Schema::hasColumn('shu_distributions', 'hold_reason')) {
                    $table->string('hold_reason', 255)->nullable()->after('is_hold');
                }
            });
        }

        if (Schema::hasTable('shu_periods')) {
            Schema::table('shu_periods', function (Blueprint $table) {
                if (! Schema::hasColumn('shu_periods', 'total_shu_hold')) {
                    $table->decimal('total_shu_hold', 18, 2)->default(0)->after('total_shu_bersih');
                }

                if (! Schema::hasColumn('shu_periods', 'jumlah_anggota_hold')) {
                    $table->unsignedInteger('jumlah_anggota_hold')->default(0)->after('total_shu_hold');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shu_distributions')) {
            Schema::table('shu_distributions', function (Blueprint $table) {
                foreach (['hold_reason', 'is_hold', 'tenure_months', 'monthly_average_balance'] as $kolom) {
                    if (Schema::hasColumn('shu_distributions', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }

        if (Schema::hasTable('shu_periods')) {
            Schema::table('shu_periods', function (Blueprint $table) {
                foreach (['jumlah_anggota_hold', 'total_shu_hold'] as $kolom) {
                    if (Schema::hasColumn('shu_periods', $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
