<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun "Margin Murabahah Ditangguhkan" (PSAK 108).
 *
 * Pada saat pencairan pembiayaan Murabahah, seluruh margin kontrak sudah
 * diketahui secara pasti, tetapi BELUM menjadi pendapatan koperasi karena
 * jasa/jasa penyerahan barang belum direalisasikan. Karena itu margin
 * ditangguhkan terlebih dahulu pada akun tersendiri (Unearned Margin) dan
 * diakui sebagai Pendapatan Margin Murabahah (4-1001) secara bertahap
 * sejalan realisasi angsuran anggota.
 *
 * Akun ini bersaldo normal KREDIT sehingga disajikan sebagai kewajiban /
 * pendapatan diterima di muka sampai direalisasikan.
 */
return new class extends Migration
{
    private const KODE = '2-4001';

    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('coas')) {
            return;
        }

        $sudahAda = DB::table('coas')->where('kode_akun', self::KODE)->exists();

        if ($sudahAda) {
            DB::table('coas')->where('kode_akun', self::KODE)->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('coas')->insert([
            'kode_akun' => self::KODE,
            'nama_akun' => 'Margin Murabahah Ditangguhkan',
            'kategori' => 'Kewajiban',
            'klasifikasi_neraca' => 'Kewajiban Jangka Pendek',
            'tipe_saldo_normal' => 'Kredit',
            'saldo_awal' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $coa = DB::table('coas')->where('kode_akun', self::KODE)->first();

        if ($coa && ! DB::table('jurnal_detail')->where('coa_id', $coa->id)->exists()) {
            DB::table('coas')->where('id', $coa->id)->delete();
        }
    }
};
