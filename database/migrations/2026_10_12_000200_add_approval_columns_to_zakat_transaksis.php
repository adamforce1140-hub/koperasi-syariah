<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DUAL CONTROL (maker-checker) penyaluran dana Zakat & Infak/Sedekah.
 *
 * Temuan audit Z-02: penyaluran dana amanah dapat dilakukan SEPIHAK — tidak
 * ada alur persetujuan, tidak ada penanda penyetuju, dan `PemisahanTugas`
 * tidak diterapkan. Dana titipan berisiko disalurkan tanpa otorisasi kedua.
 *
 * Kolom baru bersifat NULLABLE / DEFAULT AMAN (P4): baris lama tetap berstatus
 * 'posted' tanpa penyetuju, sehingga perilaku existing tidak berubah. Kolom
 * `status` (dari Z-01) kini juga menampung nilai:
 *   - 'menunggu_persetujuan' : penyaluran diajukan, JURNAL BELUM diposting;
 *   - 'ditolak'              : pengajuan ditolak, tidak pernah diposting.
 *
 * `kode_kas` disimpan agar akun kas/bank tujuan penyaluran tetap diketahui saat
 * persetujuan diproses (form memilih Kas Teller / Bank — jurnal baru dibuat
 * pada saat penyetuju menyetujui).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zakat_transaksis', function (Blueprint $table) {
            if (! Schema::hasColumn('zakat_transaksis', 'disetujui_oleh')) {
                $table->unsignedBigInteger('disetujui_oleh')->nullable()->after('void_reason');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'disetujui_pada')) {
                $table->timestamp('disetujui_pada')->nullable()->after('disetujui_oleh');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'alasan_tolak')) {
                $table->string('alasan_tolak', 500)->nullable()->after('disetujui_pada');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'kode_kas')) {
                $table->string('kode_kas', 20)->nullable()->after('alasan_tolak');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zakat_transaksis', function (Blueprint $table) {
            foreach (['kode_kas', 'alasan_tolak', 'disetujui_pada', 'disetujui_oleh'] as $kolom) {
                if (Schema::hasColumn('zakat_transaksis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
