<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PREFIX NOMOR DOKUMEN yang dapat dikonfigurasi Pengurus.
 *
 * Sebelumnya awalan nomor dokumen ditulis langsung di kode (`TRX-`, `JRN-`,
 * `RKN-`, `BBK-`, `TRM-`, `DEP-`), sehingga koperasi yang ingin memakai
 * kode sendiri (mis. `KSP-`, `BKM-`) harus mengubah kode program.
 *
 * P4 Default Aman: seluruh kolom NULLABLE. Bila kosong, `NomorDokumen`
 * memakai awalan lama sehingga perilaku sistem TIDAK berubah.
 */
return new class extends Migration
{
    /** Kolom prefix → komentar (dipakai up & down agar konsisten). */
    private const KOLOM = [
        'prefix_nomor_transaksi' => 'Awalan nomor transaksi (bawaan TRX).',
        'prefix_nomor_rekening' => 'Awalan nomor rekening (bawaan RKN).',
        'prefix_nomor_jurnal' => 'Awalan nomor jurnal (bawaan JRN).',
        'prefix_nomor_pengeluaran_beban' => 'Awalan bukti kas/bank keluar (bawaan BBK).',
        'prefix_nomor_terminasi' => 'Awalan nomor terminasi anggota (bawaan TRM).',
        'prefix_nomor_deposito' => 'Awalan nomor bilyet deposito (bawaan DEP).',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom => $komentar) {
                if (! Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->string($kolom, 8)->nullable()->comment($komentar);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (array_keys(self::KOLOM) as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
