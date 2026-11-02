<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom deposito back-to-back ke tabel pembiayaan
        Schema::table('pembiayaan', function (Blueprint $table) {
            if (! Schema::hasColumn('pembiayaan', 'agunan_tipe')) {
                $table->string('agunan_tipe', 50)->nullable()->after('agunan')
                    ->comment('Tipe agunan: deposito_backtoback, bpkb, sertifikat, dll');
            }
            if (! Schema::hasColumn('pembiayaan', 'deposito_id')) {
                $table->unsignedBigInteger('deposito_id')->nullable()->after('agunan_tipe')
                    ->comment('FK ke rekening deposito yang dijadikan agunan back-to-back');
            }
            if (! Schema::hasColumn('pembiayaan', 'is_disbursed')) {
                $table->boolean('is_disbursed')->default(false)->after('deposito_id')
                    ->comment('Mencegah double pencairan');
            }
            if (! Schema::hasColumn('pembiayaan', 'is_low_risk')) {
                $table->boolean('is_low_risk')->default(false)->after('is_disbursed')
                    ->comment('Flag low-risk untuk agunan deposito back-to-back');
            }
        });

        // Tambah kolom is_locked ke tabel rekening (untuk hold bilyet deposito)
        Schema::table('rekening', function (Blueprint $table) {
            if (! Schema::hasColumn('rekening', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('status_rekening')
                    ->comment('Hold/kunci rekening deposito yang jadi agunan back-to-back');
            }
            if (! Schema::hasColumn('rekening', 'locked_reason')) {
                $table->string('locked_reason', 255)->nullable()->after('is_locked')
                    ->comment('Alasan penguncian rekening');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pembiayaan', function (Blueprint $table) {
            $table->dropColumn(['agunan_tipe', 'deposito_id', 'is_disbursed', 'is_low_risk']);
        });
        Schema::table('rekening', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'locked_reason']);
        });
    }
};
