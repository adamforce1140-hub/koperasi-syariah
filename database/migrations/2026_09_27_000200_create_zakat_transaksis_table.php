<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku besar Modul Zakat & Infak/Sedekah (PSAK 109).
 *
 * Tabel ini mencatat SETIAP peristiwa penerimaan (dari muzakki) dan penyaluran
 * (kepada mustahik/asnaf) dana zakat dan infak/sedekah. Setiap baris terhubung
 * ke jurnal yang diposting sehingga laporan sumber & penggunaan dana dapat
 * direkonsiliasi terhadap mutasi CoA 2-3002 (zakat) dan 2-3004 (infak/sedekah).
 *
 * Mengapa tabel tersendiri, bukan menumpang `dana_kebajikans`?
 *  - PSAK 109 memisahkan zakat dari infak/sedekah dan dari dana kebajikan (ta'zir).
 *  - Penerimaan zakat wajib menyimpan MUZAKKI dan jenis zakat (maal/fitrah/
 *    penghasilan), sedangkan penyaluran wajib menyimpan MUSTAHIK dan ASNAF
 *    (8 golongan). Informasi itu tidak tersedia pada tabel dana kebajikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zakat_transaksis', function (Blueprint $table) {
            $table->id();
            $table->string('no_referensi')->unique();

            /** 'penerimaan' (dari muzakki) | 'penyaluran' (kepada mustahik). */
            $table->string('jenis', 20);

            /** Dana yang dikelola: 'zakat' | 'infak_sedekah'. */
            $table->string('dana', 20)->default('zakat');

            /** Penerimaan: jenis zakat. Penyaluran: kode asnaf (8 golongan). */
            $table->string('kategori', 40);

            /** Nama muzakki (penerimaan) atau mustahik (penyaluran). */
            $table->string('pihak');

            $table->decimal('jumlah', 15, 2);
            $table->text('keterangan')->nullable();
            $table->date('tanggal');

            /** Jurnal yang diposting untuk transaksi ini (jejak audit). */
            $table->unsignedBigInteger('jurnal_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['dana', 'jenis', 'tanggal'], 'zakat_transaksis_dana_jenis_tanggal_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zakat_transaksis');
    }
};
