<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transaksi', function (Blueprint $table) {
            $table->boolean('is_aml_high_value')->default(false)->after('status');
            $table->string('aml_sumber_dana')->nullable()->after('is_aml_high_value');
            $table->string('aml_dokumen_pendukung')->nullable()->after('aml_sumber_dana');
            $table->enum('aml_status_verifikasi', ['menunggu', 'terverifikasi', 'ditolak'])->default('menunggu')->after('aml_dokumen_pendukung');
            $table->text('aml_catatan_compliance')->nullable()->after('aml_status_verifikasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaksi', function (Blueprint $table) {
            $table->dropColumn([
                'is_aml_high_value',
                'aml_sumber_dana',
                'aml_dokumen_pendukung',
                'aml_status_verifikasi',
                'aml_catatan_compliance',
            ]);
        });
    }
};
