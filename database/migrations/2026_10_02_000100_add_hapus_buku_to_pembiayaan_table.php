<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CKPN-5 — penanda hapus buku (write-off) piutang pembiayaan macet.
 *
 * PSAK 71 mensyaratkan aset keuangan DIHENTIKAN PENGAKUANNYA (derecognized)
 * ketika tidak ada lagi ekspektasi wajar atas pemulihannya; sisa nilai tercatat
 * dibebankan terhadap cadangan kerugian (akun 1-2100). Tanpa penanda ini
 * pembiayaan macet terus berbaring di neraca dan terus membentuk penyisihan
 * 100% selamanya — tidak ada mekanisme penyelesaian akhir.
 *
 * Kolom `tanggal_hapus_buku` mencatat tanggal efektif hapus buku sekaligus
 * menjadi bukti audit kapan aset dikeluarkan dari neraca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pembiayaan', 'tanggal_hapus_buku')) {
            Schema::table('pembiayaan', function (Blueprint $table) {
                $table->date('tanggal_hapus_buku')->nullable()->after('status_kolektibilitas');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pembiayaan', 'tanggal_hapus_buku')) {
            Schema::table('pembiayaan', function (Blueprint $table) {
                $table->dropColumn('tanggal_hapus_buku');
            });
        }
    }
};
