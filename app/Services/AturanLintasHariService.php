<?php

namespace App\Services;

use App\Models\KasHarian;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Modul aturan bisnis yang bergantung pada PERBEDAAN TANGGAL (lintas hari).
 *
 * Seluruh perhitungan tanggal dilakukan pada zona waktu operasional koperasi
 * (`config('app.timezone')`, default `Asia/Jakarta`) — bukan zona waktu server
 * maupun zona waktu browser pengguna. Dengan demikian tanggal bisnis yang
 * dipakai guard sesi kas, Buka Kas, dan Closing selalu konsisten.
 *
 * Prinsip yang dijaga:
 *   • "Tanggal bisnis" = tanggal kalender pada zona operasional, dengan opsi
 *     batas tengah malam (cut-off) untuk transaksi shift malam.
 *   • Perbedaan hari dihitung dari AWAL HARI (bukan selisih jam), sehingga
 *     23:59 hari ini ke 00:01 besok tetap berjarak 1 hari.
 *   • Akhir pekan & hari libur mengikuti kalender `hari_liburs` (dinamis),
 *     bukan daftar tanggal yang ditanam di kode.
 *   • Sesi kas yang belum ditutup pada tanggal sebelumnya adalah pelanggaran
 *     lintas hari: sesi itu harus di-Closing sebelum hari bisnis berikutnya
 *     boleh dibuka.
 *
 * Catatan zona waktu: `Asia/Jakarta` tidak menerapkan daylight saving time
 * (offset tetap +07:00), sehingga penyesuaian DST tidak relevan untuk sistem
 * ini. Perilaku tersebut tetap diuji agar asumsi ini tidak berubah diam-diam.
 */
class AturanLintasHariService
{
    /**
     * Batas tengah malam bawaan (menit). 0 = pergantian hari tepat pukul
     * 00:00 waktu operasional (perilaku standar sistem saat ini).
     */
    public const BATAS_TENGAH_MALAM_DEFAULT = 0;

    /** Batas maksimum iterasi pencarian hari kerja (pengaman loop). */
    public const BATAS_GESER_HARI = 31;

    public function __construct(private HariLiburService $hariLibur) {}

    /**
     * Zona waktu operasional koperasi.
     *
     * @return string Nama zona IANA, mis. `Asia/Jakarta`.
     */
    public function zonaWaktu(): string
    {
        return (string) config('app.timezone', 'Asia/Jakarta');
    }

    /**
     * Titik waktu "sekarang" pada zona operasional (immutable).
     *
     * @return CarbonImmutable Instan sekarang di zona operasional.
     */
    public function sekarang(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zonaWaktu());
    }

    /**
     * Tanggal bisnis (Y-m-d) untuk sebuah titik waktu.
     *
     * Alur logika:
     *   1. Normalisasi input ke zona operasional (null = sekarang).
     *   2. Bila `$batasTengahMalamMenit` > 0 dan jam lokal masih di bawah
     *      batas tersebut, transaksi dianggap milik hari bisnis SEBELUMNYA
     *      (aturan shift malam).
     *   3. Selain itu, kembalikan tanggal kalender lokal.
     *
     * @param  CarbonInterface|string|null  $waktu
     *                                              Titik waktu. String tanpa offset dianggap waktu lokal operasional.
     * @param  int  $batasTengahMalamMenit
     *                                      Cut-off 0..1439 menit. Contoh: 180 → transaksi pukul 00:00–02:59
     *                                      tetap dihitung sebagai tanggal kemarin.
     * @return string Tanggal bisnis berformat `Y-m-d`.
     *
     * Kasus penggunaan: guard sesi kas, Buka Kas, Closing, atribusi transaksi
     * yang terjadi melewati tengah malam.
     */
    public function tanggalBisnis(
        CarbonInterface|string|null $waktu = null,
        int $batasTengahMalamMenit = self::BATAS_TENGAH_MALAM_DEFAULT
    ): string {
        $titik = $this->keZonaWaktu($waktu);
        $batas = max(0, min(24 * 60 - 1, $batasTengahMalamMenit));
        $menit = ($titik->hour * 60) + $titik->minute;

        if ($batas > 0 && $menit < $batas) {
            return $titik->subDay()->toDateString();
        }

        return $titik->toDateString();
    }

    /**
     * Selisih hari kalender antara dua tanggal/titik waktu.
     *
     * Perhitungan memakai AWAL HARI pada zona operasional sehingga jam,
     * menit, dan offset input tidak memengaruhi hasil.
     *
     * @param  CarbonInterface|string  $dari  Tanggal acuan.
     * @param  CarbonInterface|string  $sampai  Tanggal pembanding.
     * @return int Positif bila `$sampai` setelah `$dari`, negatif bila
     *             sebelum, dan 0 bila tanggal kalendernya sama.
     *
     * Kasus penggunaan: validasi umur sesi kas, penentuan sesi tertunda,
     * pengujian tahun kabisat / pergantian bulan & tahun.
     */
    public function selisihHari(CarbonInterface|string $dari, CarbonInterface|string $sampai): int
    {
        return (int) $this->awalHari($dari)->diffInDays($this->awalHari($sampai), false);
    }

    /**
     * Apakah tanggal merupakan hari kerja koperasi?
     *
     * Hari kerja = bukan Sabtu/Minggu dan tidak terdaftar pada `hari_liburs`
     * yang aktif. Sumber hari libur bersifat dinamis (dapat dipelihara).
     *
     * @return bool `true` bila operasional koperasi berjalan pada tanggal itu.
     */
    public function adalahHariKerja(CarbonInterface|string $tanggal): bool
    {
        return $this->hariLibur->isHariKerja($this->awalHari($tanggal));
    }

    /**
     * Hari kerja pertama SESUDAH (atau sama dengan) tanggal acuan.
     *
     * @return CarbonImmutable Hari kerja terdekat ke depan, pukul 00:00.
     *
     * Kasus penggunaan: batas tanggal akhir pekan — transaksi/jatuh tempo
     * yang jatuh pada Sabtu/Minggu digeser ke Senin (atau hari kerja
     * berikutnya bila Senin libur).
     */
    public function hariKerjaBerikutnya(CarbonInterface|string $tanggal): CarbonImmutable
    {
        return $this->cariHariKerja($this->awalHari($tanggal), 1);
    }

    /**
     * Hari kerja pertama SEBELUM (atau sama dengan) tanggal acuan.
     *
     * @return CarbonImmutable Hari kerja terdekat ke belakang, pukul 00:00.
     *
     * Kasus penggunaan: strategi MUNDUR pada penjadwalan jatuh tempo.
     */
    public function hariKerjaSebelumnya(CarbonInterface|string $tanggal): CarbonImmutable
    {
        return $this->cariHariKerja($this->awalHari($tanggal), -1);
    }

    /**
     * Apakah tanggal sesi kas sah untuk diproses (Opening/Closing)?
     *
     * Syarat: format `Y-m-d` yang benar-benar ada (bukan overflow seperti
     * 2026-02-30) dan tidak melewati tanggal bisnis berjalan. Sesi masa depan
     * ditolak karena kas fisik belum mungkin dihitung.
     *
     * @param  string  $tanggal  Kandidat tanggal `Y-m-d`.
     * @param  string|null  $tanggalBisnis  Acuan; null = tanggal bisnis sekarang.
     */
    public function tanggalSesiSah(string $tanggal, ?string $tanggalBisnis = null): bool
    {
        $acuan = $tanggalBisnis ?? $this->tanggalBisnis();
        $tgl = $this->parseTanggal($tanggal);

        return $tgl !== null && $tgl->toDateString() <= $acuan;
    }

    /**
     * Normalisasi parameter tanggal dari pengguna.
     *
     * Nilai kosong, format salah, atau tanggal masa depan dikembalikan menjadi
     * tanggal acuan (bawaan: tanggal bisnis berjalan). Dipakai halaman Closing
     * agar parameter URL yang tidak sah tidak menghasilkan halaman kosong.
     *
     * @param  string|null  $tanggal  Nilai mentah dari query/body.
     * @param  string|null  $acuan  Tanggal acuan `Y-m-d`; null = hari ini.
     * @return string Tanggal `Y-m-d` yang aman dipakai.
     */
    public function normalisasiTanggal(?string $tanggal, ?string $acuan = null): string
    {
        $acuan ??= $this->tanggalBisnis();

        if ($tanggal === null || trim($tanggal) === '') {
            return $acuan;
        }

        $tgl = $this->parseTanggal($tanggal);

        if ($tgl === null) {
            return $acuan;
        }

        $nilai = $tgl->toDateString();

        return $nilai > $acuan ? $acuan : $nilai;
    }

    /**
     * Sesi kas milik pengguna yang MASIH TERBUKA pada tanggal sebelum acuan.
     *
     * Sesi berstatus OPEN atau PENDING_APPROVAL pada hari sebelumnya adalah
     * "sesi tertunda": kas fisiknya belum diserahkan ke Brankas, sehingga hari
     * bisnis baru tidak boleh dibuka sebelum sesi tersebut di-Closing.
     *
     * @param  int|string  $userId  ID teller pemilik laci.
     * @param  string|null  $tanggalBisnis  Tanggal acuan; null = hari ini.
     * @return KasHarian|null Sesi tertunda terbaru, atau null bila bersih.
     */
    public function sesiBelumTutupSebelum(int|string $userId, ?string $tanggalBisnis = null): ?KasHarian
    {
        return $this->querySesiBelumTutup($userId, $tanggalBisnis)->first();
    }

    /**
     * Daftar seluruh sesi kas yang belum ditutup sebelum tanggal acuan.
     *
     * Dipakai halaman Closing untuk menampilkan tautan "sesi tertunda" agar
     * teller dapat menyelesaikan closing lintas hari tanpa kehilangan akses.
     *
     * @return Collection<int, KasHarian> Urut tanggal terbaru lebih dahulu.
     */
    public function daftarSesiBelumTutup(int|string $userId, ?string $tanggalBisnis = null): Collection
    {
        return $this->querySesiBelumTutup($userId, $tanggalBisnis)->get();
    }

    /**
     * Query dasar sesi belum ditutup sebelum tanggal acuan.
     *
     * @return Builder<KasHarian>
     */
    private function querySesiBelumTutup(int|string $userId, ?string $tanggalBisnis = null)
    {
        $acuan = $tanggalBisnis ?? $this->tanggalBisnis();

        return KasHarian::query()
            ->where('user_id', $userId)
            ->whereDate('tanggal', '<', $acuan)
            ->whereIn('status_opname', [KasHarian::STATUS_OPEN, KasHarian::STATUS_PENDING_APPROVAL])
            ->orderByDesc('tanggal');
    }

    /**
     * Iterasi mencari hari kerja terdekat pada arah tertentu.
     *
     * @param  CarbonImmutable  $titik  Tanggal awal (pukul 00:00).
     * @param  int  $arah  +1 = ke depan, -1 = ke belakang.
     */
    private function cariHariKerja(CarbonImmutable $titik, int $arah): CarbonImmutable
    {
        if ($this->adalahHariKerja($titik)) {
            return $titik;
        }

        for ($i = 1; $i <= self::BATAS_GESER_HARI; $i++) {
            $kandidat = $titik->addDays($i * $arah);

            if ($this->adalahHariKerja($kandidat)) {
                return $kandidat;
            }
        }

        // Praktis mustahil (libur > 31 hari berturut-turut). Kembalikan titik
        // awal agar pemanggil tidak menerima tanggal yang lebih menyesatkan.
        return $titik;
    }

    /**
     * Normalisasi input waktu ke zona operasional.
     */
    private function keZonaWaktu(CarbonInterface|string|null $waktu): CarbonImmutable
    {
        if ($waktu === null) {
            return $this->sekarang();
        }

        if ($waktu instanceof CarbonInterface) {
            return CarbonImmutable::instance($waktu)->setTimezone($this->zonaWaktu());
        }

        return CarbonImmutable::parse($waktu, $this->zonaWaktu());
    }

    /**
     * Awal hari (00:00:00) pada zona operasional.
     */
    private function awalHari(CarbonInterface|string $tanggal): CarbonImmutable
    {
        return $this->keZonaWaktu($tanggal)->startOfDay();
    }

    /**
     * Urai tanggal `Y-m-d` secara ketat (menolak overflow tanggal).
     *
     * @return CarbonImmutable|null Null bila format/nilai tidak sah.
     */
    private function parseTanggal(string $tanggal): ?CarbonImmutable
    {
        $tanggal = trim($tanggal);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
            return null;
        }

        try {
            $tgl = CarbonImmutable::createFromFormat('!Y-m-d', $tanggal, $this->zonaWaktu());
        } catch (\Throwable) {
            return null;
        }

        if ($tgl === false || $tgl->format('Y-m-d') !== $tanggal) {
            return null;
        }

        return $tgl;
    }
}
