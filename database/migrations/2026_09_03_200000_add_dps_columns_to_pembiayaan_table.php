<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembiayaan', function (Blueprint $table) {
            // Kolom DPS — pengesahan akad & penentuan tanggal pencairan
            $table->unsignedBigInteger('disahkan_oleh')->nullable()->after('catatan_pengurus');
            $table->timestamp('tanggal_disahkan')->nullable()->after('disahkan_oleh');
            $table->text('catatan_dps')->nullable()->after('tanggal_disahkan');
            $table->date('tgl_pencairan')->nullable()->after('catatan_dps');
            $table->timestamp('tgl_dicairkan')->nullable()->after('tgl_pencairan');

            $table->foreign('disahkan_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pembiayaan', function (Blueprint $table) {
            $table->dropForeign(['disahkan_oleh']);
            $table->dropColumn([
                'disahkan_oleh',
                'tanggal_disahkan',
                'catatan_dps',
                'tgl_pencairan',
                'tgl_dicairkan',
            ]);
        });
    }
};
