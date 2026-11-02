<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PARAMETER KEBIJAKAN PENANGANAN DINAMIS SIMPANAN WAJIB (Tahap 12 / R4).
 *
 * Menambah kolom kebijakan yang dibaca App\Support\Setting untuk modul:
 *   1. Saklar induk Auto-Debet (`simpanan_wajib_auto_debit_aktif`).
 *   2. Saklar penanganan dinamis (`simpanan_wajib_penanganan_dinamis`).
 *   3. Batas cicilan & reschedule tanpa denda (prinsip syariah).
 *   4. Anonimisasi notifikasi (PDP / UU 27/2022).
 *
 * Nilai default kolom DISAMAKAN dengan default App\Support\Setting
 * (P4 Default Aman): sistem berperilaku identik sampai pengurus mengubahnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_auto_debit_aktif')) {
                $table->boolean('simpanan_wajib_auto_debit_aktif')
                    ->default(true)
                    ->after('account_simpanan_wajib_id')
                    ->comment('Saklar induk modul Auto-Debet Simpanan Wajib. Nonaktif = tanpa potong saldo Sukarela.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_penanganan_dinamis')) {
                $table->boolean('simpanan_wajib_penanganan_dinamis')
                    ->default(true)
                    ->after('simpanan_wajib_auto_debit_aktif')
                    ->comment('Aktifkan jadwal bertahap & reschedule tanpa denda saat saldo Sukarela kurang.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_maks_cicilan')) {
                $table->unsignedTinyInteger('simpanan_wajib_maks_cicilan')
                    ->default(6)
                    ->after('simpanan_wajib_penanganan_dinamis')
                    ->comment('Maksimum jumlah cicilan jadwal bertahap. 0 = tanpa batas.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_reschedule_bulan')) {
                $table->unsignedTinyInteger('simpanan_wajib_reschedule_bulan')
                    ->default(1)
                    ->after('simpanan_wajib_maks_cicilan')
                    ->comment('Tambahan bulan tenggat tiap pengajuan reschedule (0-12).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_maks_reschedule')) {
                $table->unsignedTinyInteger('simpanan_wajib_maks_reschedule')
                    ->default(3)
                    ->after('simpanan_wajib_reschedule_bulan')
                    ->comment('Maksimum jumlah reschedule per tagihan. 0 = tanpa batas.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_notif_anonim')) {
                $table->boolean('simpanan_wajib_notif_anonim')
                    ->default(true)
                    ->after('simpanan_wajib_maks_reschedule')
                    ->comment('Samarkan identitas anggota pada notifikasi (PDP / UU 27/2022).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'simpanan_wajib_ambang_kurang_ringan')) {
                $table->decimal('simpanan_wajib_ambang_kurang_ringan', 12, 2)
                    ->default(25000.00)
                    ->after('simpanan_wajib_notif_anonim')
                    ->comment('Ambang kekurangan (Rp) yang masih dianggap ringan untuk reschedule.');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach ([
                'simpanan_wajib_ambang_kurang_ringan',
                'simpanan_wajib_notif_anonim',
                'simpanan_wajib_maks_reschedule',
                'simpanan_wajib_reschedule_bulan',
                'simpanan_wajib_maks_cicilan',
                'simpanan_wajib_penanganan_dinamis',
                'simpanan_wajib_auto_debit_aktif',
            ] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
