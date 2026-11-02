<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun CoA Modul Zakat (PSAK 109 — Akuntansi Zakat, Infak, dan Sedekah).
 *
 * PSAK 109 mewajibkan dana zakat DISAJIKAN TERPISAH dari dana infak/sedekah
 * dan dari dana kebajikan (ta'zir). Karena itu modul ini memakai akun
 * kewajiban tersendiri, bukan menumpang 2-3001/2-3003:
 *
 *  - 2-3002 "Dana Zakat" — dana titipan (amanah) yang HARUS disalurkan
 *    kepada 8 asnaf. Bukan pendapatan koperasi dan bukan bagian SHU.
 *  - 2-3004 "Dana Infak & Sedekah" — dana sosial sukarela, terpisah dari
 *    zakat karena mustahik dan batas penyalurannya berbeda.
 *
 * Kedua akun berkategori 'Kewajiban' + klasifikasi 'Kewajiban Jangka Pendek'
 * (DANA TITIPAN, bukan Dana Syirkah Temporer dan bukan Ekuitas) sehingga
 * persamaan dasar akuntansi tetap Aset = Kewajiban + DST + Ekuitas + Laba.
 *
 * Pola mengikuti 2026_09_26_000600_add_ckpn_and_shu_ditahan_coa.php:
 * tidak menimpa akun yang sudah ada, dan hanya menghapus pada `down()` bila
 * belum pernah dipakai pada baris jurnal.
 */
return new class extends Migration
{
    /** Akun baru: [kode, nama, kategori, klasifikasi_neraca, tipe_saldo_normal] */
    private array $akun = [
        ['2-3002', 'Dana Zakat', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
        ['2-3004', 'Dana Infak & Sedekah', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
    ];

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('coas')) {
            return;
        }

        foreach ($this->akun as [$kode, $nama, $kategori, $klasifikasi, $tipe]) {
            $ada = DB::table('coas')->where('kode_akun', $kode)->exists();

            if ($ada) {
                // Akun sudah dibuat manual/oleh seeder → cukup pastikan aktif.
                DB::table('coas')->where('kode_akun', $kode)->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('coas')->insert([
                'kode_akun' => $kode,
                'nama_akun' => $nama,
                'kategori' => $kategori,
                'klasifikasi_neraca' => $klasifikasi,
                'tipe_saldo_normal' => $tipe,
                'saldo_awal' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('coas')) {
            return;
        }

        foreach ($this->akun as [$kode]) {
            $coa = DB::table('coas')->where('kode_akun', $kode)->first();

            // Hanya hapus bila belum pernah dipakai pada baris jurnal mana pun,
            // supaya jurnal historis tidak kehilangan akunnya.
            if ($coa && ! DB::table('jurnal_detail')->where('coa_id', $coa->id)->exists()) {
                DB::table('coas')->where('id', $coa->id)->delete();
            }
        }
    }
};
