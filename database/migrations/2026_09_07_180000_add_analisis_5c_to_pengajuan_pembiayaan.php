<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'analisis_5c')) {
                $table->json('analisis_5c')->nullable()->after('catatan_pengelola');
            }
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'catatan_survei')) {
                $table->text('catatan_survei')->nullable()->after('analisis_5c');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            if (Schema::hasColumn('pengajuan_pembiayaan', 'analisis_5c')) {
                $table->dropColumn('analisis_5c');
            }
            if (Schema::hasColumn('pengajuan_pembiayaan', 'catatan_survei')) {
                $table->dropColumn('catatan_survei');
            }
        });
    }
};
