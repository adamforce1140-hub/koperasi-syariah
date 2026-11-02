<?php

namespace App\Services;

use App\Models\Angsuran;
use App\Models\Transaksi;
use App\Support\Setting;
use Illuminate\Support\Collection;

/**
 * Satu-satunya sumber perhitungan pendapatan operasional modul Laporan
 * Keuangan Syariah.
 *
 * Dipakai bersama oleh:
 *   - Dashboard DPS (kartu 'Pendapatan Margin vs Bagi Hasil')
 *   - Laporan Keuangan Syariah DPS (/dps/laporan-keuangan)
 *
 * ---------------------------------------------------------------------------
 * PENTING — sumber nilai
 * ---------------------------------------------------------------------------
 * Kolom `pembiayaan.margin_nisbah` berisi PERSENTASE nisbah (mis. 12.00 berarti
 * 12%), BUKAN nominal rupiah. Menjumlahkannya sebagai rupiah menghasilkan angka
 * palsu: dashboard DPS sempat menampilkan "Pendapatan Margin Rp 21" dan
 * "Bagi Hasil Rp 18", padahal totalnya hanya akumulasi persen (21 = 12 + 9).
 *
 * Pendapatan riil diambil dari jadwal angsuran yang BENAR-BENAR sudah dibayar,
 * kolom `angsuran.margin_ujroh`. `angsuran.pokok_angsuran` adalah pengembalian
 * pokok sehingga BUKAN pendapatan.
 *
 * Pengelompokan akad (PSAK 107):
 *   - Margin     : akad jual-beli        -> Murabahah, IMBT, Istishna, Salam
 *   - Bagi Hasil : akad kerja sama usaha -> Mudharabah, Musyarakah
 *   - Ujroh akad : akad sewa/jasa        -> Ijarah
 *
 * Semua nominal dikembalikan sebagai float rupiah apa adanya (tanpa pembagian
 * atau perkalian 1.000) sehingga bisa langsung diformat dengan
 * number_format($nilai, 0, ',', '.').
 */
class PendapatanService
{
    public const HAK_MARGIN = 'margin';

    public const HAK_BAGI_HASIL = 'bagi_hasil';

    public const HAK_UJROH = 'ujroh';

    /** Akad jual-beli — imbalannya diakui sebagai MARGIN. */
    public const AKAD_MARGIN = ['Murabahah', 'Ijarah Muntahia Bittamlik', 'Istishna', 'Salam'];

    /** Akad kerja sama usaha — imbalannya diakui sebagai BAGI HASIL. */
    public const AKAD_BAGI_HASIL = ['Mudharabah', 'Musyarakah'];

    /** Akad sewa/jasa — imbalannya diakui sebagai UJROH (bukan margin). */
    public const AKAD_UJROH = ['Ijarah'];

    /** Status angsuran yang dianggap benar-benar sudah dibayar. */
    public const STATUS_ANGSURAN_DIBAYAR = 'Sudah Dibayar';

    /**
     * Kategori akad menurut PSAK 107.
     *
     * Dipakai bersama oleh badge tabel 'Rincian Pendapatan per Akad' dan
     * pengelompokan angka pada ringkasan, agar keduanya tidak bisa menyimpang.
     *
     * Daftar akad dibaca dari parameter kebijakan (`Setting::akadMargin()`,
     * `Setting::akadBagiHasil()`, `Setting::akadUjroh()`) sehingga koperasi
     * dapat menambah/mengubah akad tanpa menyentuh kode. Perbandingan
     * case-insensitive + trim agar input pengurus yang berbeda kapitalisasi
     * tetap dikenali.
     *
     * @return string salah satu: margin | bagi_hasil | ujroh | non_pendapatan
     */
    public static function kategoriAkad(?string $akad): string
    {
        $akad = $akad === null ? null : trim($akad);

        if ($akad === null || $akad === '') {
            return 'non_pendapatan';
        }

        if (self::cocokDaftar($akad, Setting::akadMargin())) {
            return self::HAK_MARGIN;
        }

        if (self::cocokDaftar($akad, Setting::akadBagiHasil())) {
            return self::HAK_BAGI_HASIL;
        }

        if (self::cocokDaftar($akad, Setting::akadUjroh())) {
            return self::HAK_UJROH;
        }

        return 'non_pendapatan';
    }

    /**
     * Apakah `$akad` termasuk salah satu entri `$daftar` (case-insensitive)?
     *
     * @param  array<int, string>  $daftar
     */
    private static function cocokDaftar(string $akad, array $daftar): bool
    {
        foreach ($daftar as $item) {
            if (strcasecmp($akad, trim((string) $item)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Agregat pendapatan dari seluruh angsuran yang sudah dibayar,
     * dikelompokkan per akad pembiayaan.
     *
     * Tanpa argumen -> akumulasi seluruh periode (dipakai dashboard DPS).
     * Dengan rentang tanggal -> hanya angsuran yang dibayar pada rentang itu.
     *
     * @param  string|null  $dari  tanggal awal (Y-m-d), inklusif
     * @param  string|null  $sampai  tanggal akhir (Y-m-d), inklusif
     * @return Collection<int, object>
     */
    public function agregatPerAkad(?string $dari = null, ?string $sampai = null)
    {
        $query = Angsuran::query()
            ->join('pembiayaan', 'pembiayaan.id', '=', 'angsuran.pembiayaan_id')
            ->whereNull('angsuran.deleted_at')
            ->where('angsuran.status_angsuran', self::STATUS_ANGSURAN_DIBAYAR)
            ->selectRaw('pembiayaan.akad_pembiayaan as akad')
            ->selectRaw('COUNT(*) as jumlah_angsuran')
            ->selectRaw('COALESCE(SUM(angsuran.pokok_angsuran), 0) as total_pokok')
            ->selectRaw('COALESCE(SUM(angsuran.margin_ujroh), 0) as total_margin')
            ->selectRaw('COALESCE(SUM(angsuran.total_bayar), 0) as total_bayar')
            ->groupBy('pembiayaan.akad_pembiayaan');

        $this->batasiRentang($query, $dari, $sampai);

        return $query->get();
    }

    /**
     * Pendapatan Ujroh / Fee dari transaksi kas berdiri sendiri.
     *
     * Menangkap transaksi yang imbalannya dicatat pada akun 4-2001 (Pendapatan
     * Biaya Administrasi) — mis. biaya administrasi/fee pengelolaan yang
     * dipungut TERPISAH dari jadwal angsuran (label jenis_transaksi memuat
     * 'Biaya Administrasi', 'Ujroh', 'Ujrah', atau 'Fee').
     *
     * PENTING — bukan tempat ujroh akad Ijarah:
     * Ujroh akad Ijarah tersimpan pada `angsuran.margin_ujroh` dan diakui pada
     * akun 4-1004 (Pendapatan Ijarah) lewat jurnalAngsuran(); nilainya SUDAH
     * terhitung pada `ujroh_akad` di ringkasan(). Menghitungnya lagi di sini
     * akan DOUBLE-COUNT. Transaksi pembayaran angsuran berjenis
     * 'Pembayaran Angsuran' (bukan '%Ujroh%'), sehingga tidak tersentuh filter
     * di bawah — invariant ini wajib dipertahankan.
     *
     * Ta'zir/denda SENGAJA dikecualikan: sesuai jurnalTazir(), dana ta'zir
     * masuk ke Dana Kebajikan (2-3001) dan BUKAN pendapatan koperasi.
     */
    public function ujrohFee(?string $dari = null, ?string $sampai = null): float
    {
        $query = Transaksi::query()
            ->where('status_transaksi', 'Sukses')
            ->where(function ($q) {
                $q->where('jenis_transaksi', 'like', '%Biaya Administrasi%')
                    ->orWhere('jenis_transaksi', 'like', '%Ujroh%')
                    ->orWhere('jenis_transaksi', 'like', '%Ujrah%')
                    ->orWhere('jenis_transaksi', 'like', '%Fee%');
            });

        if ($dari !== null && $dari !== '') {
            $query->where('waktu_transaksi', '>=', $dari.' 00:00:00');
        }

        if ($sampai !== null && $sampai !== '') {
            $query->where('waktu_transaksi', '<=', $sampai.' 23:59:59');
        }

        return (float) $query->sum('jumlah');
    }

    /**
     * Ringkasan pendapatan siap pakai untuk kartu dashboard & laporan.
     *
     * `total` = margin + bagi hasil + ujroh, identik dengan total pendapatan
     * pada halaman Laporan Keuangan Syariah karena keduanya membaca method ini.
     *
     * @return array{
     *     margin: float, bagi_hasil: float, ujroh: float, ujroh_akad: float,
     *     ujroh_fee: float, pengembalian_pokok: float, total_setoran: float,
     *     total_angsuran: int, margin_dan_bagi_hasil: float, total: float
     * }
     */
    public function ringkasan(?string $dari = null, ?string $sampai = null): array
    {
        $agregat = $this->agregatPerAkad($dari, $sampai);

        $margin = (float) $agregat->filter(fn ($r) => self::kategoriAkad($r->akad) === self::HAK_MARGIN)->sum('total_margin');
        $bagiHasil = (float) $agregat->filter(fn ($r) => self::kategoriAkad($r->akad) === self::HAK_BAGI_HASIL)->sum('total_margin');
        $ujrohAkad = (float) $agregat->filter(fn ($r) => self::kategoriAkad($r->akad) === self::HAK_UJROH)->sum('total_margin');
        $ujrohFee = $this->ujrohFee($dari, $sampai);
        $ujroh = $ujrohAkad + $ujrohFee;

        return [
            'margin' => $margin,
            'bagi_hasil' => $bagiHasil,
            'ujroh' => $ujroh,
            'ujroh_akad' => $ujrohAkad,
            'ujroh_fee' => $ujrohFee,
            'pengembalian_pokok' => (float) $agregat->sum('total_pokok'),
            'total_setoran' => (float) $agregat->sum('total_bayar'),
            'total_angsuran' => (int) $agregat->sum('jumlah_angsuran'),
            'margin_dan_bagi_hasil' => $margin + $bagiHasil,
            'total' => $margin + $bagiHasil + $ujroh,
        ];
    }

    /** Batasi query ke rentang tanggal bayar (bila diberikan). */
    private function batasiRentang($query, ?string $dari, ?string $sampai): void
    {
        // SARGAble: `angsuran.tanggal_bayar` bertipe DATE, sehingga perbandingan
        // langsung setara dengan `whereDate()` namun indeks tanggal tetap dipakai
        // (whereDate membungkus kolom dengan DATE() → indeks tidak terpakai).
        if ($dari !== null && $dari !== '') {
            $query->where('angsuran.tanggal_bayar', '>=', $dari);
        }

        if ($sampai !== null && $sampai !== '') {
            $query->where('angsuran.tanggal_bayar', '<=', $sampai);
        }
    }

    /**
     * R8 — Pendapatan investasi riil koperasi pada satu bulan kalender.
     *
     * Dipakai sebagai dasar bagi hasil deposito Mudharabah Muthlaqah: porsi
     * anggota dihitung dari LABA/PENDAPATAN RIIL yang benar-benar diakui pada
     * periode tersebut (bukan angka yang diketik manual oleh teller).
     *
     * Sumber: `ringkasan()` yang membaca angsuran berstatus "Sudah Dibayar"
     * (margin/bagi hasil/ujroh akad) + ujroh/fee dari transaksi. Ta'zir/denda
     * otomatis dikecualikan karena masuk Dana Kebajikan (2-3001), bukan
     * pendapatan — sesuai Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000.
     *
     * @return array{margin: float, bagi_hasil: float, ujroh: float, total: float}
     */
    public function pendapatanInvestasiBulanan(int $year, int $month): array
    {
        $tahunBuku = app(TahunBukuService::class);

        // Bulan periode divalidasi terhadap rentang TAHUN BUKU (bukan asumsi
        // Januari–Desember), sehingga tahun buku non-kalender pun sah.
        $jumlahBulan = $tahunBuku->jumlahBulan();
        if ($month < 1 || $month > $jumlahBulan) {
            throw new \InvalidArgumentException(
                "Bulan periode harus antara 1 dan {$jumlahBulan} (jumlah bulan tahun buku)."
            );
        }

        $awal = $tahunBuku->bulanKe($year, $month);
        $akhir = $awal->copy()->endOfMonth();

        $ringkasan = $this->ringkasan($awal->toDateString(), $akhir->toDateString());

        return [
            'margin' => Setting::bulatkanUang((float) $ringkasan['margin']),
            'bagi_hasil' => Setting::bulatkanUang((float) $ringkasan['bagi_hasil']),
            'ujroh' => Setting::bulatkanUang((float) $ringkasan['ujroh']),
            'total' => Setting::bulatkanUang((float) $ringkasan['total']),
        ];
    }
}
