<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun CoA tambahan untuk selisih kas opname (PSAK 101/109).
 *
 * CATATAN PENTING:
 * - 2-3001 di proyek ini SUDAH DIPAKAI untuk "Dana Kebajikan / Social Fund [Ta'zir]",
 *   sehingga TIDAK boleh ditimpa. Akun titipan selisih kas memakai kode baru 2-3099.
 * - 1-1002 di proyek ini bernama "Bank / Rekening Utama Koperasi". Agar jurnal
 *   pemindahan kas tidak menyesatkan (kas fisik masuk ke brankas, bukan ke bank),
 *   dibuat akun baru 1-1003 "Kas Brankas Utama / Vault".
 */
return new class extends Migration
{
    /** Akun baru: [kode, nama, kategori, klasifikasi, tipe_saldo_normal] */
    private array $akun = [
        ['1-1003', 'Kas Brankas Utama / Vault', 'Aset', 'Aset Lancar', 'Debet'],
        ['1-1099', 'Piutang Teller / Selisih Kas', 'Aset', 'Aset Lancar', 'Debet'],
        ['2-3099', 'Titipan Selisih Kas / Kewajiban Lain-Lain', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
    ];

    public function up(): void
    {
        foreach ($this->akun as [$kode, $nama, $kategori, $klasifikasi, $tipe]) {
            // Jangan timpa akun yang sudah ada
            if (DB::table('coas')->where('kode_akun', $kode)->exists()) {
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
        DB::table('coas')->whereIn('kode_akun', ['1-1003', '1-1099', '2-3099'])->delete();
    }
};
