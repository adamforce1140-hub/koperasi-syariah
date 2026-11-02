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
        Schema::table('dokumen_pengajuan_pembiayaan', function (Blueprint $table) {
            $table->unsignedBigInteger('diupload_oleh')->nullable()->after('ukuran_file');
            $table->foreign('diupload_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dokumen_pengajuan_pembiayaan', function (Blueprint $table) {
            $table->dropForeign(['diupload_oleh']);
            $table->dropColumn('diupload_oleh');
        });
    }
};
