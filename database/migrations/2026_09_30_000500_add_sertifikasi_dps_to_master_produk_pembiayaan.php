<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sertifikasi opini syariah produk pembiayaan oleh DPS.
     *
     * Sebelum ini status "Sertifikasi Syariah Valid" pada halaman DPS hanya
     * badge statis di view — tidak ada data tersimpan, sehingga tidak dapat
     * diaudit. Kolom di bawah merekam hasil review DPS secara permanen
     * (siapa yang mengesahkan dan kapan), sesuai prinsip GCG koperasi syariah.
     */
    public function up(): void
    {
        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->string('status_sertifikasi_dps')->default('Belum Disertifikasi')
                ->after('status_aktif')
                ->comment('Belum Disertifikasi | Disertifikasi | Ditolak');
            $table->unsignedBigInteger('disahkan_oleh')->nullable()->after('status_sertifikasi_dps');
            $table->timestamp('tanggal_disahkan')->nullable()->after('disahkan_oleh');
            $table->text('catatan_dps')->nullable()->after('tanggal_disahkan');

            $table->foreign('disahkan_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_produk_pembiayaan', function (Blueprint $table) {
            $table->dropForeign(['disahkan_oleh']);
            $table->dropColumn([
                'status_sertifikasi_dps',
                'disahkan_oleh',
                'tanggal_disahkan',
                'catatan_dps',
            ]);
        });
    }
};
