<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mode ARO (Automatic Roll Over) pada bilyet Deposito Mudharabah.
 *
 * Deposito berjangka syariah dapat diperpanjang otomatis saat jatuh tempo.
 * Tiga mode yang didukung:
 *  - NON_ARO   : pokok + akumulasi bagi hasil dicairkan ke Simpanan Sukarela,
 *                bilyet ditutup (status CLOSED).
 *  - ARO_POKOK : akumulasi bagi hasil ditransfer ke Simpanan Sukarela,
 *                pokok diperpanjang untuk tenor berikutnya.
 *  - ARO_PLUS  : akumulasi bagi hasil DITAMBAHKAN ke pokok (kapitalisasi),
 *                lalu seluruh pokok baru diperpanjang.
 *
 * Kolom pendukung:
 *  - `aro_mode`      : mode perpanjangan (menggantikan makna boolean `is_aro`)
 *  - `roll_count`    : berapa kali bilyet sudah diperpanjang otomatis
 *  - `last_rolled_at`: waktu eksekusi perpanjangan terakhir (audit trail)
 *
 * Kolom `is_aro` lama TETAP dipertahankan dan diisi otomatis sebagai cermin
 * boolean dari `aro_mode` (lihat Deposito::booted()), sehingga tampilan/query
 * lama tidak perlu diubah dan tidak ada dua sumber kebenaran yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depositos', function (Blueprint $table) {
            if (! Schema::hasColumn('depositos', 'aro_mode')) {
                $table->enum('aro_mode', ['NON_ARO', 'ARO_POKOK', 'ARO_PLUS'])
                    ->default('NON_ARO')
                    ->after('is_aro');
            }

            if (! Schema::hasColumn('depositos', 'roll_count')) {
                $table->unsignedInteger('roll_count')->default(0)->after('aro_mode');
            }

            if (! Schema::hasColumn('depositos', 'last_rolled_at')) {
                $table->dateTime('last_rolled_at')->nullable()->after('roll_count');
            }
        });

        // Bilyet lama yang ditandai ARO diperlakukan sebagai ARO_POKOK —
        // perilaku paling konservatif: pokok berlanjut, bagi hasil dibayarkan.
        if (Schema::hasColumn('depositos', 'aro_mode')) {
            DB::table('depositos')
                ->where('is_aro', true)
                ->where(function ($q) {
                    $q->whereNull('aro_mode')->orWhere('aro_mode', 'NON_ARO');
                })
                ->update(['aro_mode' => 'ARO_POKOK']);
        }

        // Mempercepat query scheduler: deposito jatuh tempo per mode.
        Schema::table('depositos', function (Blueprint $table) {
            $table->index(['status', 'aro_mode', 'maturity_date'], 'depositos_aro_jatuh_tempo_index');
        });
    }

    public function down(): void
    {
        Schema::table('depositos', function (Blueprint $table) {
            $table->dropIndex('depositos_aro_jatuh_tempo_index');
        });

        Schema::table('depositos', function (Blueprint $table) {
            foreach (['last_rolled_at', 'roll_count', 'aro_mode'] as $kolom) {
                if (Schema::hasColumn('depositos', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
