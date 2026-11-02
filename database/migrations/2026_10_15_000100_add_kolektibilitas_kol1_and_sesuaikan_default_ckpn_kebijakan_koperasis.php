<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyelarasan ambang kolektibilitas & persentase CKPN dengan regulasi
 * Kemenkop UKM / KSP Syariah (Permen KUKM No. 74/2024 pola KSP) serta
 * pedoman penurunan nilai PSAK 71/101.
 *
 * Perubahan yang dilakukan:
 *
 *   1. Tambah parameter `kolektibilitas_kol1_hari` (default 10 hari).
 *      Sebelumnya "Lancar" (Kol-1) hanya berlaku ketika tunggakan ≤ 0 hari
 *      karena tidak ada batas atas eksplisit. Spesifikasi regulasi KSP
 *      syariah menetapkan Lancar = 0–10 hari keterlambatan.
 *
 *   2. Ambang tingkat kolektibilitas disesuaikan mengikuti pola:
 *        Kol-1 (Lancar)                 : 0–10 hari    → batas 10
 *        Kol-2 (Dalam Perhatian Khusus) : 11–90 hari   → batas 90
 *        Kol-3 (Kurang Lancar)          : 91–120 hari  → batas 120
 *        Kol-4 (Diragukan)              : 121–180 hari → batas 180
 *        Kol-5 (Macet)                  : > 180 hari
 *      Nilai lama (30 / 90 / 180) mengikuti pola BPRS/BUS, bukan KSP syariah.
 *
 *   3. Persentase cadangan CKPN disesuaikan mengikuti spesifikasi KSP syariah:
 *        Kol-1 Lancar      : 0,5 %
 *        Kol-2 Perhatian   : 10 %
 *        Kol-3 Kurang Lcr  : 50 %
 *        Kol-4 Diragukan   : 75 %
 *        Kol-5 Macet       : 100 %
 *      Nilai lama (0,5 / 5 / 15 / 50 / 100) mengikuti pola BUS (POJK 2/2022).
 *
 * Perubahan default kolom HANYA memengaruhi baris baru / baris yang belum
 * pernah diubah pengurus; baris kebijakan yang sudah tersimpan tidak diubah
 * agar tidak menimpa konfigurasi nyata koperasi (perubahan nilai aktual
 * diserahkan kepada pengurus via UI Kebijakan).
 *
 * Semua operasi dijaga `Schema::hasColumn` sehingga migrasi idempoten dan
 * aman dijalankan berulang.
 */
return new class extends Migration
{
    /**
     * Definisi kolom: [nama => [tipe, default, komentar]].
     *
     * @var array<string, array{0: string, 1: int|float, 2: string}>
     */
    private array $kolom = [
        'kolektibilitas_kol2_hari' => ['unsignedInteger', 90, 'Batas hari tunggakan maksimum Kol-2 (Dalam Perhatian Khusus).'],
        'kolektibilitas_kol3_hari' => ['unsignedInteger', 120, 'Batas hari tunggakan maksimum Kol-3 (Kurang Lancar).'],
        'kolektibilitas_kol4_hari' => ['unsignedInteger', 180, 'Batas hari tunggakan maksimum Kol-4 (Diragukan).'],
        'ckpn_persen_kol2' => ['decimal', 10.00, 'Persentase CKPN untuk pembiayaan dalam perhatian khusus (Kol-2).'],
        'ckpn_persen_kol3' => ['decimal', 50.00, 'Persentase CKPN untuk pembiayaan kurang lancar (Kol-3).'],
        'ckpn_persen_kol4' => ['decimal', 75.00, 'Persentase CKPN untuk pembiayaan diragukan (Kol-4).'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        // ── 1. Batas atas tingkat Lancar (Kol-1) ────────────────────────────
        if (! Schema::hasColumn('kebijakan_koperasis', 'kolektibilitas_kol1_hari')) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->unsignedInteger('kolektibilitas_kol1_hari')
                    ->default(10)
                    ->after('simpanan_wajib_ambang_kurang_ringan')
                    ->comment('Batas hari tunggakan maksimum pembiayaan Lancar (Kol-1). Di atas ini masuk Kol-2.');
            });
        }

        // ── 2 & 3. Sesuaikan default ambang & persentase CKPN ───────────────
        foreach ($this->kolom as $nama => [$tipe, $default, $komentar]) {
            if (! Schema::hasColumn('kebijakan_koperasis', $nama)) {
                continue;
            }

            Schema::table('kebijakan_koperasis', function (Blueprint $table) use ($tipe, $nama, $default, $komentar) {
                $kolom = $tipe === 'decimal'
                    ? $table->decimal($nama, 5, 2)
                    : $table->unsignedInteger($nama);

                $kolom->default($default)->comment($komentar)->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        $asli = [
            'kolektibilitas_kol2_hari' => ['unsignedInteger', 30, 'Batas hari tunggakan maksimum Kol-2 (Dalam Perhatian Khusus).'],
            'kolektibilitas_kol3_hari' => ['unsignedInteger', 90, 'Batas hari tunggakan maksimum Kol-3 (Kurang Lancar).'],
            'kolektibilitas_kol4_hari' => ['unsignedInteger', 180, 'Batas hari tunggakan maksimum Kol-4 (Diragukan).'],
            'ckpn_persen_kol2' => ['decimal', 5.00, 'Persentase CKPN untuk pembiayaan dalam perhatian khusus (Kol-2).'],
            'ckpn_persen_kol3' => ['decimal', 15.00, 'Persentase CKPN untuk pembiayaan kurang lancar (Kol-3).'],
            'ckpn_persen_kol4' => ['decimal', 50.00, 'Persentase CKPN untuk pembiayaan diragukan (Kol-4).'],
        ];

        foreach ($asli as $nama => [$tipe, $default, $komentar]) {
            if (! Schema::hasColumn('kebijakan_koperasis', $nama)) {
                continue;
            }

            Schema::table('kebijakan_koperasis', function (Blueprint $table) use ($tipe, $nama, $default, $komentar) {
                $kolom = $tipe === 'decimal'
                    ? $table->decimal($nama, 5, 2)
                    : $table->unsignedInteger($nama);

                $kolom->default($default)->comment($komentar)->change();
            });
        }

        if (Schema::hasColumn('kebijakan_koperasis', 'kolektibilitas_kol1_hari')) {
            Schema::table('kebijakan_koperasis', function (Blueprint $table) {
                $table->dropColumn('kolektibilitas_kol1_hari');
            });
        }
    }
};
