<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TAHAP 12 / R1 — Parameter dinamis modul Simpanan Wajib (ZERO-HARDCODING POLICY).
 *
 * Menghapus kekakuan (hardcode) pada modul Simpanan Wajib tanpa mengubah
 * perilaku sistem yang sedang berjalan. Seluruh kolom bersifat ADITIF dan
 * bernilai NULL secara default — artinya selama Pengurus belum mengubahnya,
 * sistem berjalan PERSIS seperti sebelumnya (lihat P4 "Default Aman" pada
 * docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md, milestone M1.3).
 *
 * Parameter yang dibuka:
 *
 *  1. account_simpanan_wajib_id   — akun COA kanonik Simpanan Wajib (PSAK 101).
 *     Menggantikan hardcode `AutoJournalingService::SIMPANAN_WAJIB` ('2-1002')
 *     dan konstanta kelas `MandatorySavingsService::COA_SIMPANAN_WAJIB_MODAL`.
 *     NULL = ikuti rantai resolusi standar (master `jenis_simpanans` → 3-1002).
 *
 *  2. simpanan_wajib_overdue_day  — tanggal jatuh tempo tagihan (temuan H1).
 *     Sebelumnya tertanam sebagai angka `10` di MandatorySavingsService.
 *     Default 10 agar perilaku lama tidak berubah.
 *
 *  3. simpanan_wajib_kanal_default — kanal pembayaran default anggota baru
 *     (temuan H5/H6). Nilai: AUTO_DEBIT | CASH | TRANSFER.
 *     Default AUTO_DEBIT = perilaku sistem saat ini.
 *
 *  4. simpanan_wajib_boleh_ditarik — saklar pembukaan siklus penarikan (R3).
 *     Default FALSE = fitur penarikan NONAKTIF (perilaku sistem saat ini).
 *
 *  5. simpanan_wajib_hari_penarikan — kapan penarikan Simpanan Wajib boleh
 *     dilakukan (temuan H10). Nilai: SETELAH_KELUAR | KAPAN_SAJA.
 *     Default SETELAH_KELUAR = hanya saat anggota keluar (PSAK 101).
 *
 * Regulasi: UU No. 25/1992 Pasal 30, PP No. 7/2021, Permen KUKM No. 09/2018
 * Pasal 24–26 (kewenangan Pengurus menetapkan ketentuan simpanan anggota),
 * PSAK 101 (Penyajian Laporan Keuangan Syariah) & KDPPLKS.
 */
return new class extends Migration
{
    /**
     * Kolom baru beserta komentar basis datanya.
     *
     * @var array<string, string>
     */
    private const KOLOM = [
        'account_simpanan_wajib_id' => 'COA kanonik Simpanan Wajib (PSAK 101). NULL = ikuti master jenis_simpanans / standar 3-1002.',
        'simpanan_wajib_overdue_day' => 'Tanggal jatuh tempo tagihan Simpanan Wajib setiap bulan (1-28).',
        'simpanan_wajib_kanal_default' => 'Kanal pembayaran default anggota baru: AUTO_DEBIT | CASH | TRANSFER.',
        'simpanan_wajib_boleh_ditarik' => 'Izinkan penarikan Simpanan Wajib (mis. saat anggota keluar).',
        'simpanan_wajib_hari_penarikan' => 'Waktu penarikan diizinkan: SETELAH_KELUAR | KAPAN_SAJA.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'account_simpanan_wajib_id')) {
                $table->unsignedBigInteger('account_simpanan_wajib_id')
                    ->nullable()
                    ->after('account_simpanan_sukarela_id')
                    ->comment(self::KOLOM['account_simpanan_wajib_id']);
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_overdue_day')) {
                $table->unsignedTinyInteger('simpanan_wajib_overdue_day')
                    ->nullable()
                    ->default(10)
                    ->after('shu_hold_on_overdue')
                    ->comment(self::KOLOM['simpanan_wajib_overdue_day']);
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_kanal_default')) {
                $table->string('simpanan_wajib_kanal_default', 20)
                    ->nullable()
                    ->default('AUTO_DEBIT')
                    ->after('simpanan_wajib_overdue_day')
                    ->comment(self::KOLOM['simpanan_wajib_kanal_default']);
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_boleh_ditarik')) {
                $table->boolean('simpanan_wajib_boleh_ditarik')
                    ->nullable()
                    ->default(false)
                    ->after('simpanan_wajib_kanal_default')
                    ->comment(self::KOLOM['simpanan_wajib_boleh_ditarik']);
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_hari_penarikan')) {
                $table->string('simpanan_wajib_hari_penarikan', 30)
                    ->nullable()
                    ->default('SETELAH_KELUAR')
                    ->after('simpanan_wajib_boleh_ditarik')
                    ->comment(self::KOLOM['simpanan_wajib_hari_penarikan']);
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
