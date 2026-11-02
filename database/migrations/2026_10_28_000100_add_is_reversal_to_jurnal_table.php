<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda permanen jurnal PEMBALIK (reversal).
 *
 * Gerbang "hanya transaksi Sukses yang boleh masuk buku besar" ditegakkan di
 * `PostingJurnal::post()`. Jurnal pembalik sengaja dikecualikan karena memang
 * dibuat saat transaksi asal sudah berstatus `Pending Void` atau `Voided`.
 * Pengecualian itu dulu hanya berupa parameter sementara `$pembalikan`
 * sehingga rekonsiliasi menyeluruh tidak dapat membedakan:
 *
 *   - jurnal pembalik yang SAH, dan
 *   - jurnal transaksi gagal yang lolos karena kelalaian posting.
 *
 * Kolom `is_reversal` menyimpan fakta tersebut secara permanen sehingga audit
 * dan rekonsiliasi dapat bekerja tanpa menebak dari label `sumber`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('jurnal', 'is_reversal')) {
            Schema::table('jurnal', function (Blueprint $table) {
                $table->boolean('is_reversal')->default(false)->after('jenis_jurnal');
                $table->index(['is_reversal', 'transaksi_id'], 'jurnal_is_reversal_transaksi_index');
            });
        }

        $this->tandaiJurnalReversalLama();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('jurnal', 'is_reversal')) {
            return;
        }

        Schema::table('jurnal', function (Blueprint $table) {
            $table->dropIndex('jurnal_is_reversal_transaksi_index');
            $table->dropColumn('is_reversal');
        });
    }

    /**
     * Backfill jurnal pembalik yang sudah tercatat sebelum kolom ini ada.
     *
     * Pola penanda yang dipakai kode lama:
     *   - label `sumber` diawali kata "Reversal" (seluruh façade `jurnalReversal*`),
     *   - keterangan transaksi void diawali "REVERSAL - " (lihat `ReversalService`),
     *   - keterangan penolakan AML diawali "TOLAK AML - " (`AmlComplianceController`).
     * Jurnal manual berlabel `jenis_jurnal = 'Jurnal Pembalik'` juga ditandai.
     * Nominal, tanggal, dan status TIDAK diubah — hanya penanda.
     */
    private function tandaiJurnalReversalLama(): void
    {
        DB::table('jurnal')
            ->where(function ($q) {
                $q->where('sumber', 'like', 'Reversal%')
                    ->orWhere('keterangan', 'like', 'REVERSAL - %')
                    ->orWhere('keterangan', 'like', 'TOLAK AML - %')
                    ->orWhere('jenis_jurnal', 'Jurnal Pembalik');
            })
            ->update(['is_reversal' => true]);
    }
};
