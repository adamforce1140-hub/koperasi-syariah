<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CKPN-10 / PSAK 102 — Margin Murabahah Ditangguhkan sebagai AKUN KONTRA PIUTANG.
 *
 * PSAK 102 (Akuntansi Murabahah) menegaskan:
 *   "Piutang murabahah disajikan sebesar nilai neto yang dapat direalisasikan,
 *    yaitu saldo piutang murabahah dikurangi penyisihan kerugian piutang."
 *   "Marjin murabahah tangguhan disajikan sebagai pengurang (contra account)
 *    piutang murabahah."
 *
 * Sebelumnya akun margin ditangguhkan berkode '2-4001' dengan kategori
 * 'Kewajiban' / klasifikasi 'Kewajiban Jangka Pendek'. Penyajian itu SALAH:
 * ia menggelembungkan Aset (piutang bruto disajikan penuh, tanpa pengurang
 * margin) SEKALIGUS menggelembungkan Kewajiban, sehingga total Aset dan total
 * Pasiva masing-masing terlalu besar meskipun neraca tetap "seimbang" — karena
 * itu kesalahannya tidak terdeteksi.
 *
 * Perbaikan: ubah kode akun MENJADI '1-2101' — berkepala '1' (kelompok Aset)
 * dengan `tipe_saldo_normal` = 'Kredit'. Coa::isKontra() akan mengenalinya
 * sebagai akun kontra; saldo berjalannya bernilai negatif dan otomatis
 * MENGURANGI total piutang/Aset — persis penyajian "piutang murabahah neto".
 *
 * Kode akun DIUBAH pada baris yang sama (bukan dibuat akun baru) supaya
 * `coa_id` pada seluruh baris jurnal historis tetap menunjuk akun ini dan
 * saldo margin yang sudah terbentuk tidak terbelah.
 */
return new class extends Migration
{
    private const KODE_LAMA = '2-4001';

    private const KODE_BARU = '1-2101';

    public function up(): void
    {
        if (! Schema::hasTable('coas')) {
            return;
        }

        $lama = DB::table('coas')->where('kode_akun', self::KODE_LAMA)->first();

        if ($lama !== null) {
            $tujuan = DB::table('coas')->where('kode_akun', self::KODE_BARU)->first();

            if ($tujuan !== null) {
                // Tabrakan kode (mis. akun sudah dibuat manual): gabungkan saldo
                // dengan memindahkan baris jurnal ke akun tujuan, lalu nonaktifkan
                // akun lama agar tidak ada dua akun untuk satu pos.
                DB::table('jurnal_detail')->where('coa_id', $lama->id)->update(['coa_id' => $tujuan->id]);
                DB::table('coas')->where('id', $lama->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

                return;
            }

            // Ubah kode akun pada baris yang sama → coa_id historis tetap utuh.
            DB::table('coas')->where('id', $lama->id)->update([
                'kode_akun' => self::KODE_BARU,
                'nama_akun' => 'Margin Murabahah Ditangguhkan',
                'kategori' => 'Aset',
                'klasifikasi_neraca' => 'Aset Lancar',
                'tipe_saldo_normal' => 'Kredit',
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        // Akun lama tidak ada (basis data bersih) → siapkan akun kontra baru.
        DB::table('coas')->insert([
            'kode_akun' => self::KODE_BARU,
            'nama_akun' => 'Margin Murabahah Ditangguhkan',
            'kategori' => 'Aset',
            'klasifikasi_neraca' => 'Aset Lancar',
            'tipe_saldo_normal' => 'Kredit',
            'saldo_awal' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('coas')) {
            return;
        }

        $baru = DB::table('coas')->where('kode_akun', self::KODE_BARU)->first();

        if ($baru === null) {
            return;
        }

        $lamaAda = DB::table('coas')->where('kode_akun', self::KODE_LAMA)->exists();

        if ($lamaAda) {
            // Sudah ada akun lama (mis. dibuat ulang) → cukup kembalikan penanda.
            DB::table('coas')->where('kode_akun', self::KODE_LAMA)->update([
                'kategori' => 'Kewajiban',
                'klasifikasi_neraca' => 'Kewajiban Jangka Pendek',
                'tipe_saldo_normal' => 'Kredit',
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        // Kembalikan kode akun ke '2-4001' dengan klasifikasi lama (Kewajiban).
        DB::table('coas')->where('id', $baru->id)->update([
            'kode_akun' => self::KODE_LAMA,
            'nama_akun' => 'Margin Murabahah Ditangguhkan',
            'kategori' => 'Kewajiban',
            'klasifikasi_neraca' => 'Kewajiban Jangka Pendek',
            'tipe_saldo_normal' => 'Kredit',
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }
};
