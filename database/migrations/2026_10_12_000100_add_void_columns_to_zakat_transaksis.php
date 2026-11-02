<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jejak audit pembatalan (void) transaksi Zakat & Infak/Sedekah.
 *
 * Temuan audit Z-01: sebelumnya transaksi zakat TIDAK dapat dikoreksi lewat
 * alur terkontrol — `zakat_transaksis` tidak punya status/jejak pembatalan,
 * dan ReversalService tidak mengenal zakat. Koreksi hanya mungkin lewat jurnal
 * manual di luar modul, tanpa rekam jejak.
 *
 * Kolom baru bersifat NULLABLE / DEFAULT AMAN (P4): baris lama otomatis
 * berstatus 'posted', sehingga perilaku existing tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zakat_transaksis', function (Blueprint $table) {
            if (! Schema::hasColumn('zakat_transaksis', 'status')) {
                /** 'posted' (berlaku) | 'void' (dibatalkan, TIDAK dihapus). */
                $table->string('status', 20)->default('posted')->after('jenis');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'void_at')) {
                $table->timestamp('void_at')->nullable()->after('keterangan');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'void_by')) {
                $table->unsignedBigInteger('void_by')->nullable()->after('void_at');
            }

            if (! Schema::hasColumn('zakat_transaksis', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('void_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zakat_transaksis', function (Blueprint $table) {
            foreach (['void_reason', 'void_by', 'void_at', 'status'] as $kolom) {
                if (Schema::hasColumn('zakat_transaksis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
