<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter kepatuhan PSAK & eliminasi sisa hardcode pada kebijakan koperasi.
 *
 * Sebelumnya nilai-nilai di bawah ini ditanam di kode inti sehingga perubahan
 * standar/regulasi memaksa deploy ulang:
 *
 *   1. Rate simulasi bagi hasil deposito — `DepositoService::ASUMSI_RATE_PA`
 *      dan `DepositoController::hitungBagiHasil()` (`0.08`).
 *   2. Ambang laporan transaksi keuangan mencurigakan (AML/PPATK) —
 *      `TransactionLimitService` dan `AmlComplianceController` (`100.000.000`).
 *   3. Margin pembiayaan default — `?? 12.00` pada AOController,
 *      PengajuanPembiayaanController, dan PengajuanPembiayaanIntegController.
 *   4. Nisbah bagi hasil default — `?? 60` / hardcode 60 pada
 *      DepositoController, TellerController, RekeningController, CifController.
 *   5. Kebijakan pembulatan & toleransi selisih jurnal — sebelumnya terpisah
 *      (`round(..., 0)` di AutoJournalingService, `round(..., 2)` di Coa,
 *      `TOLERANSI = 0.01` di IkhtisarShuService).
 *   6. Parameter CKPN (PSAK 101/105/107/110): penyisihan kerugian piutang
 *      pembiayaan per tingkat kolektibilitas + batas hari tunggakan kol-1.
 *   7. `akun_tazir_id` belum ada di DEFAULTS — dipastikan kolomnya ada agar
 *      konfigurasi ta'zir dapat diarahkan ke akun dana kebajikan mana pun.
 *
 * Semua kolom ditambahkan dengan guard `Schema::hasColumn` sehingga migrasi
 * bersifat idempoten dan aman dijalankan berulang pada database yang sudah
 * memiliki sebagian kolom (mis. hasil `php artisan migrate:fresh` sebagian).
 *
 * PSAK terkait: 101 (penyajian laporan keuangan syariah), 105 (mudharabah),
 * 107 (ijarah), 110 (sukuk — tidak dipakai), 111 (wakalah), dan pedoman
 * akuntansi koperasi syariah (CKPN mengikuti PSAK 71/101 penurunan nilai).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            // ── 1. Bagi hasil deposito (PSAK 105 — Mudharabah) ──────────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'deposito_rate_pa')) {
                $table->decimal('deposito_rate_pa', 6, 4)
                    ->default(0.0800)
                    ->after('akun_tazir_id')
                    ->comment('Asumsi rate bagi hasil deposito per tahun (desimal, mis. 0.08 = 8%) untuk simulasi/kalkulator.');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'nisbah_default_persen')) {
                $table->decimal('nisbah_default_persen', 5, 2)
                    ->default(60.00)
                    ->after('deposito_rate_pa')
                    ->comment('Nisbah bagi hasil default untuk anggota (persen dari porsi nasabah).');
            }

            // ── 2. Ambang pelaporan AML (PPATK) ─────────────────────────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'aml_threshold')) {
                $table->decimal('aml_threshold', 18, 2)
                    ->default(100000000.00)
                    ->after('nisbah_default_persen')
                    ->comment('Nominal transaksi yang wajib ditandai sebagai transaksi keuangan mencurigakan.');
            }

            // ── 3. Margin pembiayaan default (PSAK 102/105/106/107) ─────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'margin_default_persen')) {
                $table->decimal('margin_default_persen', 5, 2)
                    ->default(12.00)
                    ->after('aml_threshold')
                    ->comment('Margin (murabahah/istishna/salam) atau ujrah (ijarah) default per tahun bila produk belum menetapkannya.');
            }

            // ── 4. Kebijakan pembulatan & toleransi selisih jurnal ──────────
            if (! Schema::hasColumn('kebijakan_koperasis', 'pembulatan_desimal')) {
                $table->unsignedTinyInteger('pembulatan_desimal')
                    ->default(0)
                    ->after('margin_default_persen')
                    ->comment('Jumlah angka desimal pembulatan nominal jurnal (0 = rupiah penuh, 2 = sen).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'toleransi_selisih_jurnal')) {
                $table->decimal('toleransi_selisih_jurnal', 8, 2)
                    ->default(0.01)
                    ->after('pembulatan_desimal')
                    ->comment('Toleransi selisih debet-kredit (floating point) yang masih dianggap seimbang.');
            }

            // ── 5. Parameter CKPN (penurunan nilai piutang pembiayaan) ──────
            if (! Schema::hasColumn('kebijakan_koperasis', 'ckpn_persen_kol1')) {
                $table->decimal('ckpn_persen_kol1', 5, 2)
                    ->default(0.50)
                    ->after('toleransi_selisih_jurnal')
                    ->comment('Persentase CKPN untuk pembiayaan lancar (Kol-1).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ckpn_persen_kol2')) {
                $table->decimal('ckpn_persen_kol2', 5, 2)
                    ->default(5.00)
                    ->after('ckpn_persen_kol1')
                    ->comment('Persentase CKPN untuk pembiayaan dalam perhatian khusus (Kol-2).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ckpn_persen_kol3')) {
                $table->decimal('ckpn_persen_kol3', 5, 2)
                    ->default(15.00)
                    ->after('ckpn_persen_kol2')
                    ->comment('Persentase CKPN untuk pembiayaan kurang lancar (Kol-3).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ckpn_persen_kol4')) {
                $table->decimal('ckpn_persen_kol4', 5, 2)
                    ->default(50.00)
                    ->after('ckpn_persen_kol3')
                    ->comment('Persentase CKPN untuk pembiayaan diragukan (Kol-4).');
            }

            if (! Schema::hasColumn('kebijakan_koperasis', 'ckpn_persen_kol5')) {
                $table->decimal('ckpn_persen_kol5', 5, 2)
                    ->default(100.00)
                    ->after('ckpn_persen_kol4')
                    ->comment('Persentase CKPN untuk pembiayaan macet (Kol-5).');
            }

            // ── 6. Ta'zir dapat diarahkan ke akun dana kebajikan mana pun ───
            if (! Schema::hasColumn('kebijakan_koperasis', 'akun_tazir_id')) {
                $table->unsignedBigInteger('akun_tazir_id')
                    ->nullable()
                    ->after('tazir_default_method')
                    ->comment('ID akun CoA penampung denda ta\'zir (default: Dana Kebajikan).');
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
                'akun_tazir_id',
                'ckpn_persen_kol5',
                'ckpn_persen_kol4',
                'ckpn_persen_kol3',
                'ckpn_persen_kol2',
                'ckpn_persen_kol1',
                'toleransi_selisih_jurnal',
                'pembulatan_desimal',
                'margin_default_persen',
                'aml_threshold',
                'nisbah_default_persen',
                'deposito_rate_pa',
            ] as $kolom) {
                if (Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->dropColumn($kolom);
                }
            }
        });
    }
};
