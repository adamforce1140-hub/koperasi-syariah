<?php

namespace App\Support;

use App\Services\DenyutMonitor;
use Illuminate\Support\Facades\Cache;

/**
 * DenyutData — buku besar versi data (data version ledger).
 *
 * AKAR MASALAH YANG DISELESAIKAN
 * ------------------------------
 * Dashboard koperasi ini adalah aplikasi berbasis sesi (server-rendered).
 * Tidak ada WebSocket/Pusher (BROADCAST_DRIVER=log) maupun queue asinkron
 * (QUEUE_CONNECTION=sync), sehingga tidak ada jalur "push" dari server ke
 * peramban. Akibatnya angka pada dashboard (saldo, transaksi hari ini,
 * antrean approval) hanya berubah setelah pengguna menekan refresh (F5).
 *
 * SOLUSI
 * ------
 * Alih-alih mengirim ulang seluruh data (berat) atau menyegarkan halaman
 * penuh (berkedip), server menyimpan sebuah *tanda versi* ringan per domain
 * data. Klien cukup meminta tanda itu secara berkala (atau lewat SSE);
 * bila tanda berubah, klien memuat ulang hanya bagian yang terdampak.
 *
 * Setiap kali terjadi mutasi data (transaksi, rekening, approval, jurnal,
 * anggota, dsb.) kode pemanggil menaikkan versi domain terkait lewat
 * `bump()`. Operasi ini sangat murah: satu penulisan cache integer.
 *
 * DESAIN ANTI-RACE (monoton naik)
 * -------------------------------
 * Nilai versi dihitung dari hasil `increment()`, bukan `time()`, agar:
 *   1. Dua mutasi pada detik yang sama tetap menghasilkan versi berbeda.
 *   2. Tidak ada jendela waktu (clock skew) yang membuat perubahan terlewat.
 * Penulisan tetap O(1) karena `increment` atomik pada store apa pun.
 *
 * KOMPATIBILITAS DRIVER CACHE
 * ---------------------------
 * - `array` (dipakai saat pengujian): hidup selama satu proses — cukup.
 * - `file`  (bawaan produksi saat ini): turun ke disk, tetap tipikal < 10 ms.
 * Bila kelak beralih ke `redis`/`database`, kelas ini bekerja tanpa perubahan.
 *
 * ISI DOMAIN
 * ----------
 * | Domain       | Mencakup perubahan                                     |
 * |--------------|--------------------------------------------------------|
 * | transaksi    | INSERT/UPDATE transaksi & transaksi pending            |
 * | rekening     | saldo, status, pembukaan/penutupan rekening            |
 * | approval     | pengajuan & keputusan approval (maker-checker)         |
 * | pembiayaan   | akad, pencairan, angsuran                              |
 * | anggota      | data induk anggota (registrasi, perubahan status)      |
 * | jurnal       | jurnal umum & buku besar                               |
 * | shu          | perhitungan & pengesahan Sisa Hasil Usaha              |
 * | deposito     | deposito berjangka & bagi hasil                        |
 */
final class DenyutData
{
    /**
     * Domain data yang diverifikasi pada dashboard/portal.
     *
     * Konstanta dipakai agar tidak ada salah ketik string di pemanggil dan
     * agar daftar domain dapat diiterasi (mis. saat pembersihan cache).
     */
    public const TRANSAKSI = 'transaksi';

    public const REKENING = 'rekening';

    public const APPROVAL = 'approval';

    public const PEMBIAYAAN = 'pembiayaan';

    public const ANGGOTA = 'anggota';

    public const JURNAL = 'jurnal';

    public const SHU = 'shu';

    public const DEPOSITO = 'deposito';

    public const LIMIT = 'limit';

    /**
     * Awalan kunci cache, diberi namespace versi agar aman saat format berubah.
     */
    private const AWALAN = 'denyut:v1:';

    /**
     * Daftar seluruh domain yang sah.
     *
     * @return list<string>
     */
    public static function domain(): array
    {
        return [
            self::TRANSAKSI,
            self::REKENING,
            self::APPROVAL,
            self::PEMBIAYAAN,
            self::ANGGOTA,
            self::JURNAL,
            self::SHU,
            self::DEPOSITO,
            self::LIMIT,
        ];
    }

    /**
     * Baca versi terkini satu domain.
     *
     * Domain yang belum pernah disentuh bernilai 0, sehingga klien baru
     * (memulai dari versi 0) tidak akan salah menganggap ada perubahan.
     */
    public static function versi(string $domain): int
    {
        try {
            return (int) Cache::get(self::kunci($domain), 0);
        } catch (\Throwable $e) {
            // Jalur baca juga tidak boleh menjatuhkan endpoint snapshot.
            DenyutMonitor::catat('versi', $e, ['domain' => $domain]);

            return 0;
        }
    }

    /**
     * Naikkan versi satu domain dan kembalikan nilai baru.
     *
     * Dipanggil dari jalur tulis. Aman dipanggil berulang (idempoten secara
     * makna: hanya menaikkan, tidak pernah menurunkan).
     *
     * DEGRADASI ANGGUN: `bump()` dipanggil dari dalam observer model, artinya
     * ia berjalan di TENGAH transaksi keuangan (setoran, angsuran, jurnal).
     * Kegagalan penyimpanan cache — disk penuh, permission salah, Redis mati —
     * TIDAK BOLEH menggagalkan transaksi itu. Karena itu seluruh operasi cache
     * dibungkus try/catch; kegagalan hanya dicatat ke kanal log `denyut`
     * (maksimum sekali per menit) dan dilewati.
     */
    public static function bump(string ...$domain): int
    {
        $nilai = 0;

        try {
            foreach ($domain as $satu) {
                $nilai = (int) Cache::increment(self::kunci($satu));
            }
        } catch (\Throwable $e) {
            DenyutMonitor::catat('bump', $e, ['domain' => $domain]);
        }

        return $nilai;
    }

    /**
     * Sidik jari gabungan seluruh domain — satu tanda ringan untuk polling.
     *
     * Klien menyimpan tanda ini; bila tanda berubah, klien membaca rincian
     * per-domain dari `peta()` untuk memutuskan bagian mana yang dimuat ulang.
     *
     * @return array{tanda: string, versi: array<string,int>}
     */
    public static function peta(): array
    {
        $versi = [];
        foreach (self::domain() as $domain) {
            $versi[$domain] = self::versi($domain);
        }

        return [
            'tanda' => self::tanda($versi),
            'versi' => $versi,
        ];
    }

    /**
     * Hitung sidik jari dari peta versi.
     *
     * Memakai sha256 atas pasangan domain=nilai yang DIURUT (ksort) agar
     * urutan iterasi tidak memengaruhi hasil — dua proses berbeda
     * menghasilkan tanda yang sama untuk data yang sama.
     *
     * @param  array<string,int>  $versi
     */
    public static function tanda(array $versi): string
    {
        ksort($versi);

        $bagian = [];
        foreach ($versi as $domain => $nilai) {
            $bagian[] = $domain.'='.$nilai;
        }

        return substr(hash('sha256', implode('|', $bagian)), 0, 32);
    }

    /**
     * Bersihkan seluruh tanda versi.
     *
     * Dipakai saat reset/seed agar tidak ada sisa versi lama yang membuat
     * klien menganggap data belum berubah.
     */
    public static function reset(): void
    {
        foreach (self::domain() as $domain) {
            Cache::forget(self::kunci($domain));
        }
    }

    /**
     * Kunci cache untuk satu domain.
     */
    private static function kunci(string $domain): string
    {
        return self::AWALAN.$domain;
    }
}
