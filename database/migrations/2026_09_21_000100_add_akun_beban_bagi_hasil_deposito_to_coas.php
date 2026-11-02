<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambah akun Beban Bagi Hasil Deposito (5-3001) ke Bagan Akun.
 *
 * Deposito Mudharabah memakai akad bagi hasil, bukan bunga. Porsi bagi hasil
 * yang menjadi hak anggota diakui koperasi sebagai BEBAN bagi hasil (5-3001),
 * terpisah dari Beban Operasional (5-1001) supaya penyajian laba/rugi sesuai
 * PSAK 101/108 dan tidak mencampur beban operasional dengan beban bagi hasil
 * dana syirkah temporer.
 */
return new class extends Migration
{
    private const KODE = '5-3001';

    public function up(): void
    {
        $sudahAda = DB::table('coas')->where('kode_akun', self::KODE)->exists();

        if ($sudahAda) {
            // Akun sudah dibuat manual / oleh seeder → cukup pastikan aktif.
            DB::table('coas')->where('kode_akun', self::KODE)->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('coas')->insert([
            'kode_akun' => self::KODE,
            'nama_akun' => 'Beban Bagi Hasil Deposito Mudharabah',
            'kategori' => 'Beban',
            'klasifikasi_neraca' => 'Beban Usaha',
            'tipe_saldo_normal' => 'Debet',
            'saldo_awal' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Hanya hapus bila belum pernah dipakai pada baris jurnal mana pun,
        // supaya jurnal historis tidak kehilangan akunnya.
        $coa = DB::table('coas')->where('kode_akun', self::KODE)->first();

        if ($coa && ! DB::table('jurnal_detail')->where('coa_id', $coa->id)->exists()) {
            DB::table('coas')->where('id', $coa->id)->delete();
        }
    }
};
