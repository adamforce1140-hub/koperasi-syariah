<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARAMETER INTEGRITAS POSTING & REKONSILIASI BUKU BESAR (temuan audit K-01).
 *
 * TEMUAN (level kritis — integritas data):
 *   `App\Support\Setting` mendeklarasikan dua saklar kebijakan:
 *     - `posting_wajib_transaksi_sukses` (DEFAULTS/TIPE/DESKRIPSI + accessor
 *       Setting::postingWajibTransaksiSukses(), dipakai sebagai GATE di
 *       AutoJournalingService::post()).
 *     - `rekonsiliasi_inline_aktif` (accessor Setting::rekonsiliasiInlineAktif()).
 *   `AdminSettingsController::PARAMETER_UTAMA` juga menampilkannya pada halaman
 *   Pengaturan Kebijakan. Namun TIDAK ADA migrasi yang pernah membuat kedua
 *   kolom tersebut pada tabel `kebijakan_koperasis`.
 *
 *   Akibatnya (root cause):
 *     1. `Setting::all()` memakai `getAttributes()` sehingga key yang tidak ada
 *        TIDAK masuk ke array → `Setting::get()` selalu jatuh ke DEFAULTS.
 *        Fakta ini menyelamatkan sistem dari kegagalan keras: gate tetap
 *        berperilaku sesuai default (AKTIF).
 *     2. Tetapi parameter menjadi TIDAK DAPAT DIKONFIGURASI: setiap nilai yang
 *        ditulis ke kolom ini gagal. Halaman Pengaturan Kebijakan menampilkan
 *        parameter yang bila disimpan/diubah akan memicu
 *        SQLSTATE[42S22] Unknown column — tepat seperti yang terjadi pada
 *        test regresi K-02.
 *     3. Ini melanggar kebijakan ZERO-HARDCODING: parameter "terlihat ada" di
 *        UI dan kode, namun tidak tersimpan.
 *
 * KONSEP: buat kolomnya dengan nilai default DISAMAKAN dengan default
 * App\Support\Setting (P4 Default Aman) sehingga perilaku sistem TIDAK berubah
 * (tetap AKTIF) — perbaikan ini murni memulihkan kemampuan konfigurasi yang
 * selama ini senyap-tidak-berfungsi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'posting_wajib_transaksi_sukses')) {
                $table->boolean('posting_wajib_transaksi_sukses')
                    ->default(true)
                    ->after('toleransi_selisih_jurnal')
                    ->comment('Gate integritas: jurnal hanya boleh dicatat bila transaksi terkait berstatus SUKSES. Cegah transaksi gagal/pending masuk buku besar.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'rekonsiliasi_inline_aktif')) {
                $table->boolean('rekonsiliasi_inline_aktif')
                    ->default(true)
                    ->after('posting_wajib_transaksi_sukses')
                    ->comment('Rekonsiliasi otomatis inline: verifikasi ulang keseimbangan buku besar setiap kali jurnal diposting.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (['rekonsiliasi_inline_aktif', 'posting_wajib_transaksi_sukses'] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
