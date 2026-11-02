<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            $table->decimal('penghasilan_bulanan', 15, 2)->nullable()->after('agunan');
            $table->decimal('pengeluaran_bulanan', 15, 2)->nullable()->after('penghasilan_bulanan');
            $table->unsignedTinyInteger('jumlah_tanggungan')->default(0)->after('pengeluaran_bulanan');
            $table->json('agunan_info')->nullable()->after('jumlah_tanggungan');
            $table->tinyInteger('step_terakhir')->default(1)->after('agunan_info');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            $table->dropColumn([
                'penghasilan_bulanan',
                'pengeluaran_bulanan',
                'jumlah_tanggungan',
                'agunan_info',
                'step_terakhir',
            ]);
        });
    }
};
