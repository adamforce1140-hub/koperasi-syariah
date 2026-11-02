<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RELASI PIHAK TERSTRUKTUR PADA BUKU BESAR ZAKAT (temuan audit Z-07 / rekomendasi R-08).
 *
 * TEMUAN: kolom `pihak` pada `zakat_transaksis` hanya string bebas (max:150),
 * tidak terhubung ke master `anggota`. Akibatnya penerima (asnaf) tidak dapat
 * diverifikasi secara terstruktur dan tidak ada rekam jejak penerima berulang.
 *
 * KONSEP: tambahkan relasi OPSIONAL `pihak_id` → `anggota.id`. Kolom `pihak`
 * tetap dipertahankan sebagai:
 *   - fallback teks bila penerima BUKAN anggota koperasi (mis. muzakki/mustahik
 *     eksternal), dan
 *   - label tersimpan (snapshot) demi ketahanan laporan historis.
 *
 * P4 Default Aman: kolom dibuat NULLABLE dan TIDAK mengubah baris lama
 * (`pihak_id` tetap NULL). Tanpa `pihak_id`, seluruh alur lama berjalan identik.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zakat_transaksis')) {
            return;
        }

        Schema::table('zakat_transaksis', function (Blueprint $table) {
            if (! Schema::hasColumn('zakat_transaksis', 'pihak_id')) {
                $table->unsignedBigInteger('pihak_id')
                    ->nullable()
                    ->after('pihak')
                    ->comment('Relasi opsional ke master `anggota`. NULL = pihak eksternal (teks bebas pada kolom `pihak`).');

                // Pihak boleh dihapus (soft delete) tanpa menghapus jejak buku besar;
                // relasi cukup dinolkan (nullOnDelete) agar laporan historis tetap utuh.
                $table->foreign('pihak_id', 'zakat_transaksis_pihak_id_foreign')
                    ->references('id')->on('anggota')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('zakat_transaksis')) {
            return;
        }

        Schema::table('zakat_transaksis', function (Blueprint $table) {
            if (Schema::hasColumn('zakat_transaksis', 'pihak_id')) {
                $table->dropForeign('zakat_transaksis_pihak_id_foreign');
                $table->dropColumn('pihak_id');
            }
        });
    }
};
