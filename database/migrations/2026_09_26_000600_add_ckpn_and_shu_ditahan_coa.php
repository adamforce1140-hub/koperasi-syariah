<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun CoA tambahan untuk penyajian PSAK koperasi syariah.
 *
 * Tiga akun yang sebelumnya dipakai kode (parsial) tetapi belum ada di bagan
 * akun, sehingga jurnal terkait berisiko gagal posting:
 *
 *  - 2-1004 "Utang SHU Ditahan" — kewajiban kepada anggota atas SHU yang
 *    ditahan karena tunggakan Simpanan Wajib (kebijakan `shu_hold_on_overdue`).
 *    Disajikan sebagai KEWAJIBAN (PSAK 101), bukan Ekuitas, karena sudah
 *    menjadi hak anggota dan akan dibayarkan.
 *  - 1-2100 "Penyisihan Kerugian Piutang Pembiayaan (CKPN)" — akun kontra-aset
 *    penurunan nilai piutang pembiayaan (PSAK 101/105/106/107).
 *  - 5-4001 "Beban Penyisihan Kerugian Piutang Pembiayaan" — beban pembentuk
 *    CKPN pada laporan laba rugi (PSAK 101).
 *
 * Pola mengikuti 2026_09_17_100200_add_coa_selisih_kas_opname.php: tidak
 * menimpa akun yang sudah ada, dan hanya menghapus pada `down()` bila belum
 * pernah dipakai pada baris jurnal.
 */
return new class extends Migration
{
    /** Akun baru: [kode, nama, kategori, klasifikasi_neraca, tipe_saldo_normal] */
    private array $akun = [
        ['2-1004', 'Utang SHU Ditahan', 'Kewajiban', 'Kewajiban Jangka Pendek', 'Kredit'],
        ['1-2100', 'Penyisihan Kerugian Piutang Pembiayaan (CKPN)', 'Aset', 'Aset Lancar', 'Kredit'],
        ['5-4001', 'Beban Penyisihan Kerugian Piutang Pembiayaan', 'Beban', 'Beban Usaha', 'Debet'],
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
