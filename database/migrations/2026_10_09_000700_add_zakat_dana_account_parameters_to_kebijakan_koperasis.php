<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PEMETAAN AKUN COA DANA ZAKAT & INFAK/SEDEKAH
 * (temuan audit Z-05 / rekomendasi R-04).
 *
 * TEMUAN: `App\Support\Setting` sudah mengenal parameter
 * `account_dana_zakat_id` dan `account_dana_infak_id`, tetapi KOLOMNYA tidak
 * pernah dibuat di tabel `kebijakan_koperasis`. Akibatnya Pengurus tidak dapat
 * memindahkan akun dana zakat/infak; parameter senantiasa null sehingga
 * pencatatan modul zakat dan penyajian DIT hanya mengandalkan fallback —
 * dan bila kelak diisi lewat jalur lain, keduanya berpotensi menunjuk akun
 * berbeda (divergensi saldo, Z-05).
 *
 * KONSEP: jadikan pemetaan akun dana zakat/infak sebagai KEBIJAKAN yang dapat
 * dikonfigurasi, dengan pola ZERO-HARDCODING dan P4 Default Aman:
 *   - Bila kolom dibiarkan NULL → resolver memakai registri standar
 *     `App\Support\Coa` (2-3002 / 2-3004), sehingga perilaku lama TIDAK
 *     berubah (backward compatible).
 *   - Bila diisi → baik pencatatan modul zakat (`ZakatService::akunDana()`)
 *     maupun penyajian DIT (`LaporanKeuanganSyariahService::akunDit()`)
 *     membaca resolver bersama (`CoaResolver`) sehingga menunjuk akun yang
 *     IDENTIK.
 *
 * CATATAN CAKUPAN: hanya dana zakat & infak/sedekah yang dipetakan di sini
 * karena keduanya SUDAH diunifikasi lewat `CoaResolver`. Dana kebajikan/sosial
 * dan titipan selisih sengaja BELUM dipetakan agar tidak memunculkan
 * divergensi baru: pencatatannya (`DanaKebajikanService`) masih memakai
 * konstanta `AutoJournalingService::DANA_KEBAJIKAN` dan belum dialihkan ke
 * resolver bersama.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> nama kolom => komentar
     */
    private const KOLOM = [
        'account_dana_zakat_id' => 'COA Dana Zakat (PSAK 109). Kosong = ikuti standar 2-3002.',
        'account_dana_infak_id' => 'COA Dana Infak & Sedekah (PSAK 109). Kosong = ikuti standar 2-3004.',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kebijakan_koperasis')) {
            return;
        }

        Schema::table('kebijakan_koperasis', function (Blueprint $table) {
            foreach (self::KOLOM as $kolom => $komentar) {
                if (! Schema::hasColumn('kebijakan_koperasis', $kolom)) {
                    $table->unsignedBigInteger($kolom)
                        ->nullable()
                        ->after('account_simpanan_sukarela_id')
                        ->comment($komentar);
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
