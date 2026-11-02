<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menonaktifkan akun alias piutang pembiayaan yang duplikat dan tidak terpakai.
 *
 * LATAR BELAKANG
 * COA lama memuat empat akun kembar dari akun piutang utama, dengan penamaan
 * silang yang membingungkan:
 *
 *   | Alias (dinonaktifkan) | Nama                      | Akun utama (tetap aktif) |
 *   |-----------------------|---------------------------|--------------------------|
 *   | 1-2010                | Piutang Pembiayaan Murabahah | 1-2001                |
 *   | 1-2020                | Pembiayaan Musyarakah     | 1-2003                   |
 *   | 1-2030                | Pembiayaan Mudharabah     | 1-2002                   |
 *   | 1-2040                | Piutang Qardh             | 1-2005                   |
 *
 * Perhatikan 1-2020 dan 1-2030: nomornya justru tertukar dibanding konvensi
 * 1-200x yang dipakai seluruh kode aplikasi — inilah sumber risiko salah
 * posting yang hendak dihilangkan.
 *
 * KEAMANAN
 * Penonaktifan HANYA dilakukan pada akun yang benar-benar belum terpakai:
 * tidak punya baris jurnal_detail sama sekali. Bila sebuah akun ternyata sudah
 * terpakai, akun itu DILEWATI dan tidak diubah, sehingga tidak ada jurnal
 * historis yang kehilangan akunnya. Akun tidak pernah dihapus (is_active=false
 * saja) agar jejak audit COA tetap utuh dan perubahan ini dapat dibalik.
 *
 * Pemetaan tunggal akun piutang per akad ada di
 * AutoJournalingService::piutangByAkad() -> 1-2001..1-2005.
 */
return new class extends Migration
{
    /** Akun alias yang dinonaktifkan beserta akun utama penggantinya. */
    private array $alias = [
        '1-2010' => '1-2001',
        '1-2020' => '1-2003',
        '1-2030' => '1-2002',
        '1-2040' => '1-2005',
    ];

    public function up(): void
    {
        foreach (array_keys($this->alias) as $kode) {
            $akun = DB::table('coas')->where('kode_akun', $kode)->first();

            if (! $akun) {
                continue; // akun tidak ada (mis. instalasi baru) — tidak ada yang perlu dilakukan
            }

            // PENGAMAN: jangan sentuh akun yang sudah terpakai transaksi.
            $terpakai = DB::table('jurnal_detail')->where('coa_id', $akun->id)->exists();

            if ($terpakai) {
                continue;
            }

            DB::table('coas')
                ->where('id', $akun->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Pulihkan status aktif akun alias (tidak mengembalikan baris yang terlanjur dihapus manual).
        DB::table('coas')
            ->whereIn('kode_akun', array_keys($this->alias))
            ->update([
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }
};
