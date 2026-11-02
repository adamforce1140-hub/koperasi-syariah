<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan COA pencairan pembiayaan yang DINAMIS (ZERO-HARDCODING POLICY).
 *
 * Sebelumnya nomor akun piutang/margin/kas/bank/simpanan ditanam di kode inti
 * (App\Support\Coa). Mulai sekarang pengurus dapat mengatur pemetaannya lewat
 * Kebijakan Koperasi, sehingga perubahan bagan akun tidak memerlukan perubahan
 * kode. Bila kolom di bawah dibiarkan kosong (NULL), resolver otomatis jatuh
 * kembali (fallback) ke pemetaan standar App\Support\Coa — jadi perilaku lama
 * tetap utuh.
 *
 * PSAK 108: akad Murabahah menangguhkan margin pada akun tersendiri
 * (Margin Murabahah Ditangguhkan / Unearned Margin) dan mengakuinya sebagai
 * pendapatan secara bertahap sejalan realisasi angsuran.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> nama kolom => komentar
     */
    private const KOLOM = [
        'account_kas_teller_id' => 'COA Kas Teller (1-1001) — sumber dana pencairan tunai.',
        'account_kas_brankas_id' => 'COA Kas Brankas (1-1003).',
        'account_piutang_murabahah_id' => 'COA Piutang Murabahah (1-2001).',
        'account_piutang_mudharabah_id' => 'COA Piutang/Penyertaan Mudharabah (1-2002).',
        'account_piutang_musyarakah_id' => 'COA Piutang/Penyertaan Musyarakah (1-2003).',
        'account_piutang_ijarah_id' => 'COA Piutang Ijarah (1-2004).',
        'account_piutang_qardh_id' => 'COA Pinjaman Qardh (1-2005).',
        'account_investasi_mudharabah_id' => 'COA Investasi Mudharabah — dipakai bila skema investasi dipilih.',
        'account_investasi_musyarakah_id' => 'COA Investasi Musyarakah — dipakai bila skema investasi dipilih.',
        'account_margin_deferred_id' => 'COA Margin Ditangguhkan (Unearned Margin) — PSAK 108.',
        'account_simpanan_sukarela_id' => 'COA Simpanan Sukarela (2-1001).',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom => $komentar) {
                if (! Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->unsignedBigInteger($kolom)->nullable()->comment($komentar);
                }
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
