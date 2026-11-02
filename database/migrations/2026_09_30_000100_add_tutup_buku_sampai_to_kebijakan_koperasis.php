<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 5 — CUT-OFF PERIODE TUTUP BUKU (integritas periode).
 *
 * TEMUAN: `tanggal_jurnal` dapat diisi tanggal apa pun, termasuk tanggal yang
 * berada di dalam periode yang TELAH DITUTUP BUKU. Akibatnya laporan periode
 * lampau dapat berubah setelah disahkan — angka Neraca / Laba-Rugi yang sudah
 * dilaporkan tidak lagi sama dengan isi jurnal, dan rekonsiliasi antar-periode
 * menjadi tidak dapat dibuktikan.
 *
 * KONSEP: tutup buku adalah tindakan **per-periode**, bukan per baris. Karena
 * itu cut-off disimpan sebagai SATU parameter kebijakan dan divalidasi di
 * `App\Services\PeriodeAkuntansiService` yang dipanggil dari seluruh jalur
 * pembuatan jurnal.
 *
 * Mengikuti ZERO-HARDCODING POLICY: nilai default kolom di sini DISAMAKAN
 * dengan default di App\Support\Setting (null = periode terbuka), sehingga
 * perilaku sistem identik baik saat kolom belum pernah diisi maupun saat
 * baris kebijakan belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'tutup_buku_sampai')) {
                $table->date('tutup_buku_sampai')
                    ->nullable()
                    ->after('tanggal_jatuh_tempo_default')
                    ->comment('Cut-off periode tutup buku. Jurnal bertanggal <= tanggal ini ditolak. NULL = periode terbuka.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (Schema::hasColumn('kebijakan_koperasis', 'tutup_buku_sampai')) {
                $table->dropColumn('tutup_buku_sampai');
            }
        });
    }
};
