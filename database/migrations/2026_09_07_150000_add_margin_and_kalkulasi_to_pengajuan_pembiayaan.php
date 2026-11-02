<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'margin_persen')) {
                $table->decimal('margin_persen', 5, 2)->default(12.00)->after('jenis_akad');
            }
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'total_pembiayaan')) {
                $table->decimal('total_pembiayaan', 15, 2)->nullable()->after('jumlah_pengajuan');
            }
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'angsuran_per_bulan')) {
                $table->decimal('angsuran_per_bulan', 15, 2)->nullable()->after('total_pembiayaan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            $table->dropColumn(['margin_persen', 'total_pembiayaan', 'angsuran_per_bulan']);
        });
    }
};
