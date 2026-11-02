<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metode pencairan (dropping) pembiayaan + referensi rekening tujuan.
 *
 * CASH             → uang fisik keluar dari Laci Teller (kas 1-1001).
 * TRANSFER_BANK    → transfer keluar dari rekening bank operasional koperasi.
 * TRANSFER_SAVINGS → pindah buku ke rekening Simpanan Sukarela anggota.
 *
 * Kolom sengaja disimpan sebagai VARCHAR(20) + kolom pendamping bertipe ID,
 * bukan ENUM MySQL, agar penambahan metode baru di masa depan cukup lewat
 * konfigurasi aplikasi tanpa ALTER TABLE (zero-hardcoding).
 * Nilai valid tetap dijaga ketat oleh App\Enums\DisbursementMethod.
 *
 * `margin_deferred_amount` menyimpan porsi margin yang ditangguhkan saat
 * pencairan (PSAK 108) sebagai jejak audit terpisah dari `total_ujroh` agar
 * nilai kontrak tetap dapat direkonstruksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pembiayaan')) {
            return;
        }

        Schema::table('pembiayaan', function (Blueprint $table) {
            if (! Schema::hasColumn('pembiayaan', 'disbursement_method')) {
                $table->string('disbursement_method', 20)
                    ->nullable()
                    ->after('tanggal_cair')
                    ->comment('CASH | TRANSFER_BANK | TRANSFER_SAVINGS');
            }

            if (! Schema::hasColumn('pembiayaan', 'bank_account_id')) {
                $table->unsignedBigInteger('bank_account_id')
                    ->nullable()
                    ->after('disbursement_method')
                    ->comment('COA bank operasional koperasi tujuan transfer (wajib untuk TRANSFER_BANK).');
            }

            if (! Schema::hasColumn('pembiayaan', 'savings_account_id')) {
                $table->unsignedBigInteger('savings_account_id')
                    ->nullable()
                    ->after('bank_account_id')
                    ->comment('Rekening simpanan sukarela anggota tujuan pindah buku (wajib untuk TRANSFER_SAVINGS).');
            }

            if (! Schema::hasColumn('pembiayaan', 'margin_deferred_amount')) {
                $table->decimal('margin_deferred_amount', 15, 2)
                    ->nullable()
                    ->after('savings_account_id')
                    ->comment('Porsi margin ditangguhkan pada saat pencairan (PSAK 108).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pembiayaan')) {
            return;
        }

        Schema::table('pembiayaan', function (Blueprint $table) {
            foreach (['margin_deferred_amount', 'savings_account_id', 'bank_account_id', 'disbursement_method'] as $kolom) {
                if (Schema::hasColumn('pembiayaan', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
