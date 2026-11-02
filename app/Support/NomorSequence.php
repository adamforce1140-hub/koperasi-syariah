<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NomorSequence — generator urutan nomor dokumen/transaksi yang AMAN RACE.
 *
 * Akar masalah (K-03): pola lama `MAX(no_transaksi) + 1` di dalam transaksi
 * tidak mengunci baris, sehingga dua proses paralel dapat menghasilkan nomor
 * yang sama → pelanggaran unique index `transaksi.no_transaksi` → kegagalan
 * transaksi utama.
 *
 * Solusi: satu baris penanda urutan per PREFIX (`nomor_sequences.prefix`)
 * yang diakses dengan `SELECT ... FOR UPDATE`. Proses paralel terserialisasi
 * pada baris yang sama; proses kedua menunggu commit proses pertama lalu
 * membaca nilai terbaru. Seeding otomatis dari MAX(no_transaksi) yang sudah
 * ada menjaga kompatibilitas data lama (tanpa menimpa nomor eksisting).
 *
 * PENTING: metode ini WAJIB dipanggil di dalam `DB::transaction()` pemanggil
 * agar penguncian efektif dan nilai urutan ikut rollback bila transaksi gagal.
 */
class NomorSequence
{
    /**
     * Ambil nomor urut berikutnya untuk sebuah prefix (mis. `TRX-20261009-`).
     *
     * @param  string  $prefix  Prefix dokumen, sudah termasuk pemisah akhir.
     * @param  int  $panjang  Jumlah digit urutan (default 6).
     * @param  string  $tabelAcuan  Tabel acuan untuk seeding (default `transaksi`).
     * @param  string  $kolomAcuan  Kolom acuan untuk seeding (default `no_transaksi`).
     * @return string Nomor lengkap: prefix + urutan ber-pad.
     */
    public static function berikutnya(
        string $prefix,
        int $panjang = 6,
        string $tabelAcuan = 'transaksi',
        string $kolomAcuan = 'no_transaksi'
    ): string {
        $prefix = (string) $prefix;

        // Lapis pengaman: bila tabel penanda urutan belum dimigrasi (mis. pada
        // instalasi lama atau lingkungan yang belum `migrate`), jangan sampai
        // alur transaksi utama gagal total — pakai jalur cadangan deterministik
        // dengan retry. Kondisi ini dicatat agar dapat ditindaklanjuti.
        if (! self::tabelTersedia()) {
            Log::warning('NomorSequence: tabel `nomor_sequences` tidak tersedia — memakai jalur cadangan.', [
                'prefix' => $prefix,
                'tabel' => $tabelAcuan,
            ]);

            return self::berikutnyaCadangan($prefix, $panjang, $tabelAcuan, $kolomAcuan);
        }

        return DB::transaction(function () use ($prefix, $panjang, $tabelAcuan, $kolomAcuan) {
            // Kunci baris penanda urutan prefix ini (buat bila belum ada).
            $baris = DB::table('nomor_sequences')->where('prefix', $prefix)->lockForUpdate()->first();

            if (! $baris) {
                // Seed dari data yang sudah ada agar tidak menimpa nomor lama.
                // `insertOrIgnore` aman terhadap balapan pembuatan baris pertama.
                $awal = self::hitungUrutanMaksimum($prefix, $tabelAcuan, $kolomAcuan);

                DB::table('nomor_sequences')->insertOrIgnore([
                    'prefix' => $prefix,
                    'urutan' => $awal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Baca ulang DENGAN KUNCI: bila proses paralel lebih dulu
                // menyisipkan baris, kita melihat nilai miliknya (bukan $awal).
                $baris = DB::table('nomor_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            }

            $urutanMaks = (int) ($baris->urutan ?? 0);

            // Cari kandidat bebas berikutnya (lindungi terhadap record
            // terhapus/kolisi manual yang masih memakai nomor sama).
            do {
                $urutanMaks++;
                $kandidat = $prefix.str_pad((string) $urutanMaks, $panjang, '0', STR_PAD_LEFT);
            } while (
                DB::table($tabelAcuan)
                    ->where($kolomAcuan, $kandidat)
                    ->exists()
            );

            DB::table('nomor_sequences')
                ->where('prefix', $prefix)
                ->update(['urutan' => $urutanMaks, 'updated_at' => now()]);

            return $kandidat;
        });
    }

    /** Cek keberadaan tabel penanda urutan (di-cache per request). */
    private static function tabelTersedia(): bool
    {
        static $tersedia = null;

        if ($tersedia === null) {
            $tersedia = Schema::hasTable('nomor_sequences');
        }

        return $tersedia;
    }

    /**
     * Hitung urutan tertinggi dari data existing untuk prefix tsb.
     * Memakai CAST numerik (bukan perbandingan string) agar benar saat
     * melewati batas panjang digit.
     */
    private static function hitungUrutanMaksimum(string $prefix, string $tabelAcuan, string $kolomAcuan): int
    {
        $mulai = strlen($prefix) + 1;

        $baris = DB::table($tabelAcuan)
            ->where($kolomAcuan, 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING('.$kolomAcuan.', ?) AS UNSIGNED)) AS maks_urut', [$mulai])
            ->first();

        return (int) ($baris->maks_urut ?? 0);
    }

    /**
     * Jalur cadangan tanpa tabel penanda: deterministik + pencarian kandidat
     * bebas + retry singkat. Tetap lebih kuat dari pola lama karena memakai
     * CAST numerik dan loop anti-kolisi.
     */
    private static function berikutnyaCadangan(string $prefix, int $panjang, string $tabelAcuan, string $kolomAcuan): string
    {
        $urut = self::hitungUrutanMaksimum($prefix, $tabelAcuan, $kolomAcuan);

        do {
            $urut++;
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
        } while (
            DB::table($tabelAcuan)
                ->where($kolomAcuan, $kandidat)
                ->exists()
        );

        return $kandidat;
    }
}
