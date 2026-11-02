<?php

namespace App\Support;

use App\Services\TahunBukuService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * RENTANG PERIODE — SARGAble FILTER TANGGAL.
 *
 * ## Masalah yang diselesaikan
 *
 * Pola umum `whereMonth('kolom', $bulan)->whereYear('kolom', $tahun)` membuat
 * MySQL membungkus kolom dengan fungsi:
 *
 *     WHERE MONTH(`waktu_transaksi`) = 9 AND YEAR(`waktu_transaksi`) = 2026
 *
 * Ekspresi seperti itu **tidak sargable**: MySQL tidak dapat memakai indeks
 * pada `waktu_transaksi` sehingga setiap kueri laporan menjadi FULL TABLE SCAN.
 * Dampaknya tidak terasa pada data demo, tetapi tumbuh linier terhadap jumlah
 * transaksi — pada koperasi dengan ratusan ribu transaksi seluruh halaman
 * laporan (yang memanggil agregat 12–20 kali per halaman) menjadi lambat.
 *
 * Bentuk yang ekuivalen secara hasil namun **sargable** adalah rentang:
 *
 *     WHERE `waktu_transaksi` >= '2026-09-01 00:00:00'
 *       AND `waktu_transaksi` <= '2026-09-30 23:59:59'
 *
 * Dengan bentuk ini indeks `transaksi_waktu_transaksi_index` (dan indeks
 * tanggal lain yang setara) langsung dipakai (`type=range`), bukan lagi
 * full table scan.
 *
 * ## Jaminan ekuivalensi
 *
 * Batas bawah memakai `startOfMonth()` (inklusif) dan batas atas memakai
 * `endOfMonth()` (inklusif). Karena kolom `tanggal`/`waktu_transaksi` bertipe
 * DATETIME, batas atas inklusif masih mencakup transaksi pada detik terakhir
 * bulan tersebut, sehingga himpunan baris yang dikembalikan **identik** dengan
 * `whereMonth + whereYear`.
 */
final class RentangPeriode
{
    /** Awal bulan (00:00:00) — batas bawah inklusif. */
    public static function awalBulan(int $tahun, int $bulan): CarbonImmutable
    {
        return CarbonImmutable::create($tahun, $bulan, 1)->startOfMonth();
    }

    /** Akhir bulan (23:59:59) — batas atas inklusif. */
    public static function akhirBulan(int $tahun, int $bulan): CarbonImmutable
    {
        return CarbonImmutable::create($tahun, $bulan, 1)->endOfMonth();
    }

    /**
     * Awal TAHUN BUKU koperasi (inklusif) sebagai CarbonImmutable.
     *
     * Bukan 1 Januari: koperasi boleh menetapkan tahun buku yang tidak
     * mengikuti kalender (mis. mulai Juli). Sumber kebijakan hanya satu —
     * {@see TahunBukuService} yang membaca
     * `kebijakan_koperasis.tahun_buku_mulai_bulan` — sehingga laporan/filter
     * yang memakai helper ini tetap selaras dengan perhitungan SHU.
     */
    public static function awalTahun(int $tahun): CarbonImmutable
    {
        return CarbonImmutable::instance(app(TahunBukuService::class)->awalPeriode($tahun));
    }

    /** Akhir TAHUN BUKU koperasi (23:59:59) — batas atas inklusif. */
    public static function akhirTahun(int $tahun): CarbonImmutable
    {
        return CarbonImmutable::instance(app(TahunBukuService::class)->akhirPeriode($tahun));
    }

    /**
     * Batasi kueri pada satu bulan kalender secara sargable.
     *
     * Menggantikan `->whereMonth($kolom, $bulan)->whereYear($kolom, $tahun)`.
     *
     * Menerima builder Eloquent maupun query polos (dipakai scope model yang
     * menerima `Illuminate\Database\Query\Builder`).
     */
    public static function padaBulan(EloquentBuilder|QueryBuilder $query, string $kolom, int $bulan, int $tahun): EloquentBuilder|QueryBuilder
    {
        return $query->whereBetween($kolom, [
            self::awalBulan($tahun, $bulan),
            self::akhirBulan($tahun, $bulan),
        ]);
    }

    /**
     * Batasi kueri pada satu TAHUN BUKU koperasi secara sargable.
     *
     * Menggantikan `->whereYear($kolom, $tahun)`. Memakai setengah rentang
     * tertutup `[awalTahunBuku, akhirTahunBuku]` yang bersumber dari
     * kebijakan `tahun_buku_mulai_bulan` (lihat TahunBukuService), bukan
     * asumsi Januari–Desember. Untuk koperasi bertahun buku kalender hasilnya
     * identik dengan `whereYear`.
     */
    public static function padaTahun(EloquentBuilder|QueryBuilder $query, string $kolom, int $tahun): EloquentBuilder|QueryBuilder
    {
        return $query->whereBetween($kolom, [
            self::awalTahun($tahun),
            self::akhirTahun($tahun),
        ]);
    }

    /**
     * Batasi kueri pada satu hari kalender secara sargable.
     *
     * Menggantikan `->whereDate($kolom, today())`. Perhatikan bahwa
     * `whereDate()` membungkus kolom dengan `DATE(...)` sehingga indeks tidak
     * terpakai — pada dasbor real-time yang kuerinya dieksekusi terus-menerus,
     * biaya full table scan itu terasa jauh lebih besar daripada di laporan
     * bulanan. Bentuk rentang di bawah memakai indeks (`type=range`).
     *
     * Bila `$tanggal` kosong, hari ini (zona waktu aplikasi) yang dipakai.
     */
    public static function padaHari(EloquentBuilder|QueryBuilder $query, string $kolom, mixed $tanggal = null): EloquentBuilder|QueryBuilder
    {
        $hari = $tanggal === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($tanggal);

        return $query->whereBetween($kolom, [
            $hari->startOfDay(),
            $hari->endOfDay(),
        ]);
    }

    /**
     * Batasi kueri pada bulan bila `$bulan` diberikan; bila tidak, batasi
     * pada satu tahun penuh. Dipakai filter opsional (bila keduanya kosong,
     * pemanggil tidak perlu memanggil helper ini).
     */
    public static function padaBulanAtauTahun(EloquentBuilder|QueryBuilder $query, string $kolom, int $tahun, ?int $bulan = null): EloquentBuilder|QueryBuilder
    {
        if ($bulan === null) {
            return self::padaTahun($query, $kolom, $tahun);
        }

        return self::padaBulan($query, $kolom, $bulan, $tahun);
    }
}
