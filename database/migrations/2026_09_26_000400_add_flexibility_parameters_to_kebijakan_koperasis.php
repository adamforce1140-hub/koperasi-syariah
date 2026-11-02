<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter fleksibilitas operasional pada kebijakan koperasi.
 *
 * Menghapus kekakuan (hardcode) yang sebelumnya menempel di kode inti:
 *
 *   1. Alokasi SHU  — sebelumnya `* 0.30 / 0.40 / 0.20 / 0.10` di
 *      PengurusController::pengesahanShu().
 *   2. Limit approval beban — sebelumnya `PengeluaranBeban::LIMIT_APPROVAL`
 *      (konstanta 1.000.000) sehingga tidak dapat diubah tanpa deploy.
 *   3. Ambang kolektibilitas NPF — sebelumnya `<= 30 / <= 90 / <= 180` hari
 *      di ManajerController::npf().
 *
 * Semua nilai dibaca melalui App\Support\Setting sehingga pengurus dapat
 * mengubahnya dari halaman Profil & Kebijakan tanpa mengubah kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            // ── Alokasi SHU (persentase, total diharapkan 100) ──────────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_pct_cadangan_modal')) {
                $table->decimal('shu_pct_cadangan_modal', 5, 2)
                    ->default(30.00)
                    ->after('shu_hold_on_overdue')
                    ->comment('Persentase alokasi SHU untuk Cadangan Modal.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_pct_jasa_anggota')) {
                $table->decimal('shu_pct_jasa_anggota', 5, 2)
                    ->default(40.00)
                    ->after('shu_pct_cadangan_modal')
                    ->comment('Persentase alokasi SHU untuk Jasa Anggota.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_pct_jasa_modal')) {
                $table->decimal('shu_pct_jasa_modal', 5, 2)
                    ->default(20.00)
                    ->after('shu_pct_jasa_anggota')
                    ->comment('Persentase alokasi SHU untuk Jasa Modal.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'shu_pct_dana_pendidikan')) {
                $table->decimal('shu_pct_dana_pendidikan', 5, 2)
                    ->default(10.00)
                    ->after('shu_pct_jasa_modal')
                    ->comment('Persentase alokasi SHU untuk Dana Pendidikan.');
            }

            // ── Limit approval beban operasional ────────────────────────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'limit_approval_beban')) {
                $table->decimal('limit_approval_beban', 15, 2)
                    ->default(1000000.00)
                    ->after('limit_setoran_teller')
                    ->comment('Nominal di atas ini wajib approval Manajer (menggantikan konstanta LIMIT_APPROVAL).');
            }

            // ── Ambang hari kolektibilitas pembiayaan (NPF) ─────────────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'kolektibilitas_kol2_hari')) {
                $table->unsignedInteger('kolektibilitas_kol2_hari')
                    ->default(30)
                    ->after('limit_approval_beban')
                    ->comment('Batas hari tunggakan untuk Kol-2 (Dalam Perhatian Khusus).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'kolektibilitas_kol3_hari')) {
                $table->unsignedInteger('kolektibilitas_kol3_hari')
                    ->default(90)
                    ->after('kolektibilitas_kol2_hari')
                    ->comment('Batas hari tunggakan untuk Kol-3 (Kurang Lancar).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'kolektibilitas_kol4_hari')) {
                $table->unsignedInteger('kolektibilitas_kol4_hari')
                    ->default(180)
                    ->after('kolektibilitas_kol3_hari')
                    ->comment('Batas hari tunggakan untuk Kol-4 (Diragukan); di atas ini Kol-5 (Macet).');
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
                'kolektibilitas_kol4_hari',
                'kolektibilitas_kol3_hari',
                'kolektibilitas_kol2_hari',
                'limit_approval_beban',
                'shu_pct_dana_pendidikan',
                'shu_pct_jasa_modal',
                'shu_pct_jasa_anggota',
                'shu_pct_cadangan_modal',
            ] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
