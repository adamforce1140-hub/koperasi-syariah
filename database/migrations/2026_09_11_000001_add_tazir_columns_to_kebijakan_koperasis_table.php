<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'tazir_mode')) {
                $table->enum('tazir_mode', ['FLAT_HARIAN', 'FIXED_PER_KEJADIAN', 'DISABLED'])->default('FLAT_HARIAN')->after('nama_koperasi');
            }
            if (! Schema::hasColumn('kebijakan_koperasis', 'tazir_rate')) {
                $table->decimal('tazir_rate', 15, 2)->default(2000.00)->after('tazir_mode');
            }
            if (! Schema::hasColumn('kebijakan_koperasis', 'tazir_grace_period_days')) {
                $table->unsignedInteger('tazir_grace_period_days')->default(0)->after('tazir_rate');
            }
            if (! Schema::hasColumn('kebijakan_koperasis', 'tazir_default_method')) {
                $table->enum('tazir_default_method', ['AUTODEBET', 'TUNAI'])->default('TUNAI')->after('tazir_grace_period_days');
            }
            if (! Schema::hasColumn('kebijakan_koperasis', 'akun_tazir_id')) {
                $table->unsignedBigInteger('akun_tazir_id')->nullable()->after('tazir_default_method');
                $table->foreign('akun_tazir_id')->references('id')->on('coas')->nullOnDelete();
            }
        });

        // Update baris existing agar pakai default yang benar
        DB::table('kebijakan_koperasis')->whereNull('tazir_mode')->update([
            'tazir_mode' => 'FLAT_HARIAN',
            'tazir_rate' => 2000.00,
            'tazir_grace_period_days' => 0,
            'tazir_default_method' => 'TUNAI',
        ]);
    }

    public function down(): void
    {
        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            $table->dropForeign(['akun_tazir_id']);
            $table->dropColumn(['tazir_mode', 'tazir_rate', 'tazir_grace_period_days', 'tazir_default_method', 'akun_tazir_id']);
        });
    }
};
