<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom klasifikasi penyajian Neraca (PSAK 101 — Penyajian Laporan Keuangan Syariah).
 *
 * Kolom `kategori` hanya membagi akun menjadi 5 pos besar (Aset, Kewajiban,
 * Ekuitas, Pendapatan, Beban). Standar penyajian mensyaratkan pengelompokan
 * yang lebih terstruktur:
 *   - Aset            : Aset Lancar / Aset Tidak Lancar
 *   - Kewajiban       : Kewajiban Jangka Pendek / Kewajiban Jangka Panjang
 *   - Dana Syirkah Temporer (disajikan terpisah dari kewajiban, PSAK 101/PSAK 105)
 *   - Ekuitas         : Modal Anggota / Dana Cadangan / Saldo Laba (SHU)
 *   - Pendapatan      : Pendapatan Usaha / Pendapatan Lain-lain
 *   - Beban           : Beban Usaha / Beban Lain-lain
 *
 * Kolom ini juga dipakai untuk verifikasi agar tidak ada akun yang salah
 * kelompok maupun belum terklasifikasi.
 */
return new class extends Migration
{
    /**
     * Pemetaan klasifikasi standar untuk seluruh akun CoA koperasi syariah.
     * Kode akun alias (1-2010/1-2020/1-2030/1-2040) disertakan agar tetap
     * terklasifikasi bila ikut tersedia di basis data.
     */
    private array $peta = [
        // ── ASET (1-XXXX) — seluruhnya aset lancar (belum ada aset tetap) ──
        '1-1001' => 'Aset Lancar',            // Kas Teller
        '1-1002' => 'Aset Lancar',            // Bank / Rekening Utama Koperasi
        '1-2001' => 'Aset Lancar',            // Piutang Pembiayaan Murabahah
        '1-2002' => 'Aset Lancar',            // Pembiayaan Mudharabah
        '1-2003' => 'Aset Lancar',            // Pembiayaan Musyarakah
        '1-2004' => 'Aset Lancar',            // Piutang Ijarah
        '1-2005' => 'Aset Lancar',            // Pinjaman Qardh / Kebajikan
        '1-2010' => 'Aset Lancar',            // alias Piutang Murabahah
        '1-2020' => 'Aset Lancar',            // alias Pembiayaan Musyarakah
        '1-2030' => 'Aset Lancar',            // alias Pembiayaan Mudharabah
        '1-2040' => 'Aset Lancar',            // alias Piutang Qardh

        // ── KEWAJIBAN & DANA SYIRKAH TEMPORER (2-XXXX) ──
        '2-1001' => 'Kewajiban Jangka Pendek', // Simpanan Sukarela / Wadiah (titipan)
        '2-1002' => 'Kewajiban Jangka Pendek', // Simpanan Wajib Anggota
        '2-1003' => 'Dana Syirkah Temporer',   // Simpanan Berjangka / Deposito Mudharabah
        '2-2001' => 'Kewajiban Jangka Pendek', // Utang Dana Pengurus & Karyawan
        '2-3001' => 'Kewajiban Jangka Pendek', // Dana Kebajikan / Social Fund [Ta'zir]

        // ── EKUITAS (3-XXXX) ──
        '3-1001' => 'Modal Anggota',           // Simpanan Pokok Anggota
        '3-2001' => 'Dana Cadangan',           // Dana Cadangan Koperasi
        '3-9000' => 'Saldo Laba / SHU',        // Ikhtisar SHU / Laba Ditahan

        // ── PENDAPATAN (4-XXXX) ──
        '4-1001' => 'Pendapatan Usaha',        // Pendapatan Margin Murabahah
        '4-1002' => 'Pendapatan Usaha',        // Pendapatan Bagi Hasil Mudharabah
        '4-1003' => 'Pendapatan Usaha',        // Pendapatan Bagi Hasil Musyarakah
        '4-1004' => 'Pendapatan Usaha',        // Pendapatan Sewa Ijarah
        '4-2001' => 'Pendapatan Usaha',        // Pendapatan Biaya Administrasi

        // ── BEBAN (5-XXXX) ──
        '5-1001' => 'Beban Usaha',             // Beban Operasional Koperasi
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('coas', 'klasifikasi_neraca')) {
            Schema::table('coas', function (Blueprint $table) {
                $table->string('klasifikasi_neraca', 50)->nullable()->after('tipe_saldo_normal');
            });
        }

        // Backfill klasifikasi standar untuk akun yang sudah ada.
        foreach ($this->peta as $kodeAkun => $klasifikasi) {
            DB::table('coas')
                ->where('kode_akun', $kodeAkun)
                ->whereNull('deleted_at')
                ->update(['klasifikasi_neraca' => $klasifikasi]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('coas', 'klasifikasi_neraca')) {
            Schema::table('coas', function (Blueprint $table) {
                $table->dropColumn('klasifikasi_neraca');
            });
        }
    }
};
