<?php

namespace App\Services;

use App\Contracts\PeriodResolverInterface;
use App\Support\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * TAHAP — Fleksibilitas periode (TAHUN BUKU DINAMIS).
 *
 * MASALAH YANG DITUTUP (temuan audit K-01 / K-02)
 * -----------------------------------------------
 * Sebelum service ini ada, awal/akhir tahun buku ditulis keras sebagai
 * 1 Januari s.d. 31 Desember di banyak tempat (PendapatanService,
 * ShuCalculatorService, LaporanKeuanganSyariahService, Anggota, perintah
 * console, dsb.). Akibatnya:
 *   - Koperasi dengan tahun buku non-kalender (mis. Juli–Juni) tidak dapat
 *     memakai sistem tanpa mengubah kode.
 *   - Batas 12 bulan terkunci sehingga periode pendek (mis. tahun buku
 *     pertama yang hanya 6 bulan) tidak dapat diwakili.
 *
 * SUMBER NILAI (zero-hardcoding)
 * ------------------------------
 *   - `tahun_buku_mulai_bulan`  (1-12, default 1 = Januari)
 *   - `jumlah_bulan_tahun_buku` (1-12, default 12)
 *
 * Keduanya parameter kebijakan di `kebijakan_koperasis` dan dibaca lewat
 * Setting sehingga dapat diubah pengurus dari UI tanpa modifikasi kode.
 *
 * DESAIN
 * ------
 * Seluruh perhitungan bersifat MURNI (tidak menyentuh DB selain membaca
 * parameter kebijakan yang sudah di-cache) dan mengembalikan objek Carbon
 * agar mudah dipakai lintas modul.
 */
class TahunBukuService implements PeriodResolverInterface
{
    /**
     * Bulan pertama tahun buku (1-12).
     */
    public function bulanMulai(): int
    {
        return Setting::tahunBukuMulaiBulan();
    }

    /**
     * Jumlah bulan dalam satu tahun buku (1-12).
     */
    public function jumlahBulan(): int
    {
        return Setting::jumlahBulanTahunBuku();
    }

    /**
     * Apakah konfigurasi setara tahun buku kalender (Januari–Desember penuh)?
     *
     * Dipakai modul yang ingin mempertahankan perilaku lama persis, namun
     * tetap benar bila kebijakan diubah.
     */
    public function kalender(): bool
    {
        return $this->bulanMulai() === 1 && $this->jumlahBulan() === 12;
    }

    /**
     * Tanggal & waktu awal tahun buku (inklusif) sebagai Carbon.
     */
    public function awalPeriode(int $tahun): Carbon
    {
        return Carbon::create($tahun, $this->bulanMulai(), 1)->startOfMonth();
    }

    /**
     * Tanggal awal tahun buku sebagai string (Y-m-d) — praktis untuk `where`.
     */
    public function awalPeriodeStr(int $tahun): string
    {
        return $this->awalPeriode($tahun)->toDateString();
    }

    /**
     * Tanggal & waktu akhir tahun buku (inklusif) sebagai Carbon.
     *
     * Akhir = hari terakhir bulan ke-`jumlahBulan()` dihitung dari bulan awal.
     */
    public function akhirPeriode(int $tahun): Carbon
    {
        return $this->bulanAkhir($tahun)->endOfMonth()->endOfDay();
    }

    /**
     * Tanggal akhir tahun buku sebagai string (Y-m-d).
     */
    public function akhirPeriodeStr(int $tahun): string
    {
        return $this->akhirPeriode($tahun)->toDateString();
    }

    /**
     * Bulan awal PASTI sebagai Carbon (startOfMonth).
     */
    public function bulanAwal(int $tahun): Carbon
    {
        return $this->awalPeriode($tahun);
    }

    /**
     * Bulan TERAKHIR tahun buku (startOfMonth) — bulan ke-`jumlahBulan()`.
     */
    public function bulanAkhir(int $tahun): Carbon
    {
        return $this->bulanKe($tahun, $this->jumlahBulan());
    }

    /**
     * Bulan ke-N dalam tahun buku (1..jumlahBulan) sebagai Carbon (startOfMonth).
     *
     * @param  int  $urutan  1 = bulan pertama tahun buku.
     */
    public function bulanKe(int $tahun, int $urutan): Carbon
    {
        $urutan = max(1, min($this->jumlahBulan(), $urutan));

        return $this->awalPeriode($tahun)->copy()->addMonthsNoOverflow($urutan - 1);
    }

    /**
     * Seluruh bulan dalam tahun buku sebagai daftar Carbon (startOfMonth),
     * urut dari bulan pertama hingga bulan terakhir.
     *
     * @return list<Carbon>
     */
    public function daftarBulan(int $tahun): array
    {
        $hasil = [];
        for ($i = 1; $i <= $this->jumlahBulan(); $i++) {
            $hasil[] = $this->bulanKe($tahun, $i);
        }

        return $hasil;
    }

    /**
     * Rentang [awal, akhir] tahun buku — bentuk siap pakai.
     *
     * @return array{awal: Carbon, akhir: Carbon}
     */
    public function rentang(int $tahun): array
    {
        return ['awal' => $this->awalPeriode($tahun), 'akhir' => $this->akhirPeriode($tahun)];
    }

    /**
     * Tahun buku (angka) yang memuat sebuah tanggal.
     *
     * Bila tahun buku dimulai bulan ke-B (mis. 7 = Juli), tanggal 2026-03-15
     * berada pada tahun buku 2025 (Juli 2025–Juni 2026).
     */
    public function tahunBukuDari(CarbonInterface|string $tanggal): int
    {
        $tgl = $tanggal instanceof CarbonInterface ? $tanggal->copy() : Carbon::parse($tanggal);

        return $tgl->month >= $this->bulanMulai()
            ? (int) $tgl->year
            : (int) $tgl->year - 1;
    }

    /**
     * Tahun buku yang sedang berjalan saat ini.
     *
     * Pengganti `now()->year` pada perhitungan yang berorientasi periode
     * (mis. penahanan SHU). Untuk koperasi bertahun buku kalender hasilnya
     * sama dengan tahun berjalan; untuk tahun buku Juli–Juni, Januari 2026
     * tetap berada pada tahun buku 2025.
     */
    public function tahunBerjalan(): int
    {
        return $this->tahunBukuDari(Carbon::now());
    }

    /**
     * Jumlah bulan antara dua tanggal (dibulatkan, minimum 0) — membantu
     * menghitung lama keanggotaan yang tidak lagi mengasumsikan Januari–Desember.
     */
    public function bulanAntara(CarbonInterface|string $dari, CarbonInterface|string $sampai): int
    {
        $a = $dari instanceof CarbonInterface ? $dari->copy() : Carbon::parse($dari);
        $b = $sampai instanceof CarbonInterface ? $sampai->copy() : Carbon::parse($sampai);

        if ($b->lessThan($a)) {
            return 0;
        }

        return max(0, (int) $a->diffInMonths($b));
    }

    /**
     * Label ringkas tahun buku, mis. "2026" atau "2025/2026" untuk tahun buku
     * non-kalender (berguna di laporan & UI).
     */
    public function label(int $tahun): string
    {
        if ($this->kalender()) {
            return (string) $tahun;
        }

        $akhir = $this->akhirPeriode($tahun);

        return $tahun.'/'.$akhir->year;
    }
}
