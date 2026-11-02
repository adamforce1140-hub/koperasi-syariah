<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            // Ubah enum status tambah 'Direview'
            $table->enum('status', ['Menunggu', 'Direview', 'Diproses', 'Disetujui', 'Ditolak'])
                ->default('Menunggu')->change();

            // Tracking review manajer
            $table->unsignedBigInteger('direview_oleh')->nullable()->after('catatan_pengelola');
            $table->timestamp('tanggal_direview')->nullable()->after('direview_oleh');
            $table->text('catatan_manajer')->nullable()->after('tanggal_direview');

            // Tracking approve pengurus
            $table->unsignedBigInteger('disetujui_oleh')->nullable()->after('catatan_manajer');
            $table->timestamp('tanggal_disetujui')->nullable()->after('disetujui_oleh');
            $table->text('catatan_pengurus')->nullable()->after('tanggal_disetujui');

            $table->foreign('direview_oleh')->references('id')->on('users')->nullOnDelete();
            $table->foreign('disetujui_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_pembiayaan', function (Blueprint $table) {
            $table->dropForeign(['direview_oleh']);
            $table->dropForeign(['disetujui_oleh']);
            $table->dropColumn([
                'direview_oleh', 'tanggal_direview', 'catatan_manajer',
                'disetujui_oleh', 'tanggal_disetujui', 'catatan_pengurus',
            ]);
            $table->enum('status', ['Menunggu', 'Diproses', 'Disetujui', 'Ditolak'])
                ->default('Menunggu')->change();
        });
    }
};
