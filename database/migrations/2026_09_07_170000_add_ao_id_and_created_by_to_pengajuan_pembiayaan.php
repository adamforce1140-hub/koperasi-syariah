<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'ao_id')) {
                $table->unsignedBigInteger('ao_id')->nullable()->after('diproses_oleh');
                $table->foreign('ao_id')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('pengajuan_pembiayaan', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('ao_id');
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            if (Schema::hasColumn('pengajuan_pembiayaan', 'ao_id')) {
                $table->dropForeign(['ao_id']);
                $table->dropColumn('ao_id');
            }
            if (Schema::hasColumn('pengajuan_pembiayaan', 'created_by')) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            }
        });
    }
};
