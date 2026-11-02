<?php

namespace App\Services;

use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Pembiayaan;
use App\Models\ZakatTransaksi;
use App\Support\Coa as Akun;
use App\Support\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Agregator Laporan Keuangan Syariah — PSAK 101 (penyajian laporan keuangan
 * entitas syariah) beserta standar turunannya.
 *
 * Sebelumnya perhitungan laporan tersebar: Neraca hidup di
 * `DanaCadanganController`; Laporan Laba Rugi hanya tersirat sebagai satu baris
 * "Laba/Rugi Berjalan" di dalam Neraca; Arus Kas dilaporkan dalam basis
 * kas-transaksi (`Transaksi::waktu_transaksi`) yang tidak selalu sama dengan
 * basis akrual jurnal; dan tiga komponen PSAK 101 (Perubahan Ekuitas, Perubahan
 * Dana Investasi Terikat, Catatan atas Laporan Keuangan) tidak pernah ada.
 *
 * Akibatnya muncul dua angka berbeda untuk satu realitas ekonomi yang sama, dan
 * laporan tidak dapat direkonsiliasi satu terhadap yang lain.
 *
 * Service ini menjadikan jurnal (tabel `jurnal` + `jurnal_detail`, kolom kanonik
 * `coa_id`) sebagai SATU-SATUNYA sumber kebenaran nilai uang, sehingga kelima
 * komponen laporan dihasilkan dari himpunan data yang sama:
 *
 *   1. Laporan Posisi Keuangan (Neraca)
 *   2. Laporan Laba Rugi
 *   3. Laporan Perubahan Ekuitas
 *   4. Laporan Arus Kas
 *   5. Laporan Perubahan Dana Investasi Terikat (DIT)
 *   + Catatan atas Laporan Keuangan (CaLK) sebagai pengungkap kepatuhan
 *
 * Dua prinsip yang dipegang di seluruh kelas ini:
 *
 *   a. BATAS WAKTU memakai `tanggal_jurnal` (tanggal kejadian), bukan
 *      `created_at` (tanggal input). Jurnal pembuka koperasi bertanggal
 *      2026-03-10 tetapi diinput 2026-09-10; memakai `created_at` membuat
 *      laporan Maret kosong dan laporan September menggelembung.
 *
 *   b. SETIAP penjumlahan dibulatkan per akun (`Setting::bulatkanUang(...)`) lalu dijumlahkan
 *      dan dibulatkan sekali lagi. Menjumlahkan nilai mentah menghasilkan
 *      "selisih palsu Rp 1" antar komponen yang tidak berasal dari kesalahan
 *      data apa pun.
 */
final class LaporanKeuanganSyariahService
{
    /** Urutan penyajian kelompok pada Laporan Posisi Keuangan. */
    private const URUTAN_ASET = ['Aset Lancar', 'Aset Tidak Lancar'];

    private const URUTAN_KEWAJIBAN = ['Kewajiban Jangka Pendek', 'Kewajiban Jangka Panjang'];

    private const URUTAN_EKUITAS = ['Modal Anggota', 'Dana Cadangan', 'Saldo Laba / SHU'];

    /** Urutan penyajian komponen DIT. */
    private const URUTAN_DIT = [
        'dana_zakat' => 'Dana Zakat',
        'dana_infak' => 'Dana Infak & Sedekah',
        'dana_kebajikan' => 'Dana Kebajikan',
        'dana_sosial' => 'Dana Sosial',
        'titipan_selisih' => 'Titipan Selisih Kas',
    ];

    /* =====================================================================
     | RESOLUSI AKUN DINAMIS
     ===================================================================== */

    /**
     * Kode akun dana titipan (PSAK 109), diresolusi DINAMIS.
     *
     * Kelimanya dimiliki koperasi sebagai amanah — BUKAN pendapatan dan BUKAN
     * bagian ekuitas — sehingga wajib disajikan sebagai komponen tersendiri.
     * Pengurus dapat mengarahkan tiap dana ke akun CoA lain lewat parameter
     * kebijakan `account_dana_*_id` / `account_titipan_selisih_id`. Bila
     * parameter kosong atau akunnya tidak ada/nonaktif, resolusi jatuh ke
     * registri standar App\Support\Coa sehingga perilaku bawaan tidak berubah.
     *
     * @return array<string, string> kunci DIT => kode akun CoA
     */
    private function akunDit(): array
    {
        // Dana Zakat & Infak diresolusi lewat CoaResolver — resolver yang SAMA
        // dipakai pencatatan modul zakat (ZakatService::akunDana()), sehingga
        // buku besar zakat dan DIT tidak dapat menunjuk akun berbeda (Z-05).
        $resolver = app(CoaResolver::class);

        return [
            'dana_zakat' => $resolver->kodeDanaZakat(),
            'dana_infak' => $resolver->kodeDanaInfakSedekah(),
            'dana_kebajikan' => $this->kodeAkunDariSetting('account_dana_kebajikan_id') ?? Akun::DANA_KEBAJIKAN,
            'dana_sosial' => $this->kodeAkunDariSetting('account_dana_sosial_id') ?? Akun::DANA_SOSIAL,
            'titipan_selisih' => $this->kodeAkunDariSetting('account_titipan_selisih_id') ?? Akun::TITIPAN_SELISIH_KAS,
        ];
    }

    /**
     * Kode akun piutang pembiayaan per akad untuk catatan rincian, DINAMIS.
     *
     * Akad diambil dari master dinamis (kanonik + akad tambahan hasil pemetaan
     * pengurus) dan tiap akun diresolusi lewat CoaResolver (pemetaan data →
     * kebijakan → registri standar). Bila beberapa akad menunjuk akun yang SAMA
     * (mis. IMBT mengikuti piutang Murabahah), akad-akad itu digabung pada satu
     * baris agar saldonya tidak terhitung dua kali.
     *
     * @return array<int, array{akad: string, kode: string}>
     */
    private function akunPiutang(): array
    {
        $resolver = app(CoaResolver::class);
        $hasil = [];

        foreach (Pembiayaan::daftarAkad() as $akad) {
            $kode = $resolver->kodePiutangByAkad($akad);

            if ($kode === null || $kode === '') {
                continue;
            }

            if (isset($hasil[$kode])) {
                $hasil[$kode]['akad'] .= ', '.$akad;

                continue;
            }

            $hasil[$kode] = ['akad' => $akad, 'kode' => $kode];
        }

        return array_values($hasil);
    }

    /**
     * Kode akun CoA AKTIF yang ditunjuk sebuah parameter kebijakan
     * `account_*_id`. Mengembalikan null bila parameter kosong atau akun yang
     * ditunjuk tidak ada / nonaktif, sehingga pemanggil dapat beralih ke
     * registri standar.
     */
    private function kodeAkunDariSetting(string $setting): ?string
    {
        $id = Setting::akunId($setting);

        if ($id === null) {
            return null;
        }

        return Coa::query()->where('id', $id)->where('is_active', true)->value('kode_akun');
    }

    /* =====================================================================
     | KERANGKA PERIODE
     ===================================================================== */

    /**
     * Kerangka periode laporan: tanggal mulai, tanggal akhir, dan tanggal
     * pembanding (periode sama tahun sebelumnya) untuk kolom komparatif.
     *
     * Periode tidak boleh melewati hari ini — menyajikan posisi masa depan
     * adalah kekeliruan penyajian, bukan sekadar tampilan yang aneh.
     *
     * @return array{
     *     dari: Carbon, sampai: Carbon, dariLalu: Carbon, sampaiLalu: Carbon,
     *     dariStr: string, sampaiStr: string, dariLaluStr: string, sampaiLaluStr: string,
     *     tahun: int, bulan: int, mode: string, tanggalInput: string
     * }
     */
    public function periode(string $input = '', string $mode = 'akhir_bulan'): array
    {
        $mode = $mode === 'tanggal' ? 'tanggal' : 'akhir_bulan';

        try {
            $acuan = $input !== '' ? Carbon::parse($input) : now();
        } catch (\Throwable $e) {
            // Tanggal acuan tidak dapat diurai → pakai hari ini, tetapi catat
            // agar input tak sah tidak senyap berubah menjadi laporan hari ini.
            Log::warning('LaporanKeuanganSyariahService: tanggal acuan periode tidak dapat diurai, memakai hari ini.', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            $acuan = now();
        }

        if ($acuan->gt(now())) {
            $acuan = now();
        }

        $sampai = $mode === 'akhir_bulan'
            ? $acuan->copy()->endOfMonth()
            : $acuan->copy();

        // Awal periode laporan mengikuti awal TAHUN BUKU berjalan (fleksibilitas
        // tahun buku non-kalender) — bukan hardcode 1 Januari (startOfYear).
        $tahunBukuSvc = app(TahunBukuService::class);
        $dari = $tahunBukuSvc->awalPeriode($tahunBukuSvc->tahunBukuDari($acuan));

        return [
            'dari' => $dari,
            'sampai' => $sampai,
            'dariLalu' => $dari->copy()->subYear(),
            'sampaiLalu' => $sampai->copy()->subYear(),
            'dariStr' => $dari->toDateString(),
            'sampaiStr' => $sampai->toDateString(),
            'dariLaluStr' => $dari->copy()->subYear()->toDateString(),
            'sampaiLaluStr' => $sampai->copy()->subYear()->toDateString(),
            'tahun' => (int) $sampai->year,
            'bulan' => (int) $sampai->month,
            'mode' => $mode,
            'tanggalInput' => $acuan->toDateString(),
        ];
    }

    /* =====================================================================
     | PRIMITIF MUTASI JURNAL
     ===================================================================== */

    /**
     * Registri mutasi seluruh akun antara dua tanggal (inklusif), dengan SATU
     * query agregat sehingga tidak menimbulkan N+1.
     *
     * Batas BAWAH dan batas ATAS dipakai bersama di sini; primitif
     * `Coa::totalDebet/totalKredit` hanya mendukung batas atas sehingga tidak
     * dapat dipakai untuk laporan arus kas periode.
     *
     * @return Collection<string, array{kode: string, nama: string, kategori: string,
     *                                 klasifikasi: ?string, saldo_awal: float,
     *                                 debet: float, kredit: float, saldo_awal_periode: float}>
     */
    public function registriPeriode(?string $dari, ?string $sampai): Collection
    {
        $rows = JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->join('coas', 'coas.id', '=', 'jurnal_detail.coa_id')
            ->whereNull('jurnal.deleted_at')
            ->where('jurnal.status', 'Posted')
            // Basis laporan: jurnal penutup tidak diikutkan, agar kolom
            // debet/kredit periode konsisten dengan `saldo_awal_periode`
            // yang dihitung dari `saldoBerjalanLaporan()`.
            ->where('jurnal.jenis_jurnal', '!=', Coa::JENIS_JURNAL_PENUTUP)
            ->when($dari !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '<=', $sampai))
            ->groupBy('coas.kode_akun', 'coas.nama_akun', 'coas.kategori', 'coas.klasifikasi_neraca', 'coas.saldo_awal')
            ->selectRaw('coas.kode_akun AS kode')
            ->selectRaw('coas.nama_akun AS nama')
            ->selectRaw('coas.kategori AS kategori')
            ->selectRaw('coas.klasifikasi_neraca AS klasifikasi')
            ->selectRaw('coas.saldo_awal AS saldo_awal')
            ->selectRaw('COALESCE(SUM(jurnal_detail.debet), 0) AS debet')
            ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) AS kredit')
            ->get();

        return $rows->mapWithKeys(function ($r): array {
            $saldoAwal = Setting::bulatkanUang((float) $r->saldo_awal);
            $debet = Setting::bulatkanUang((float) $r->debet);
            $kredit = Setting::bulatkanUang((float) $r->kredit);

            return [(string) $r->kode => [
                'kode' => (string) $r->kode,
                'nama' => (string) $r->nama,
                'kategori' => (string) $r->kategori,
                'klasifikasi' => $r->klasifikasi !== null ? (string) $r->klasifikasi : null,
                'saldo_awal' => $saldoAwal,
                'debet' => $debet,
                'kredit' => $kredit,
                'saldo_awal_periode' => $this->saldoAwalPeriode((string) $r->kode, $saldoAwal, $debet, $kredit),
            ]];
        });
    }

    /**
     * Saldo berjalan satu akun sampai satu tanggal — BASIS LAPORAN.
     *
     * Memakai `Coa::saldoBerjalanLaporan()` agar identik dengan Neraca,
     * Laba Rugi, dan `jumlahPresisi()` yang juga mengecualikan jurnal penutup.
     * Memakai `saldoBerjalan()` (basis buku) di sini akan membuat angka akun
     * yang sama berbeda antara catatan rincian dan Neraca setelah tutup buku.
     */
    public function saldoAkun(string $kode, ?string $sampai = null): float
    {
        $coa = Coa::findByKode($kode);

        return $coa ? Setting::bulatkanUang((float) $coa->saldoBerjalanLaporan($sampai)) : 0.0;
    }

    /** Saldo berjalan sekumpulan akun, dibulatkan per akun lebih dulu. */
    public function saldoAkunBanyak(array $kode, ?string $sampai = null): float
    {
        if ($kode === []) {
            return 0.0;
        }

        return $this->jumlahPresisi(Coa::whereIn('kode_akun', $kode)->get(), $sampai);
    }

    /**
     * Mutasi satu akun pada satu periode, dalam bentuk debet & kredit mentah.
     *
     * @param  bool  $kecualikanPenutup  true = jurnal penutup tidak diikutkan.
     *                                   Dipakai laporan Perubahan Ekuitas agar
     *                                   kolom mutasi konsisten dengan saldo
     *                                   awal/akhir yang memakai basis laporan.
     * @return array{debet: float, kredit: float}
     */
    public function mutasiAkun(string $kode, ?string $dari, ?string $sampai, bool $kecualikanPenutup = false): array
    {
        $row = JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->join('coas', 'coas.id', '=', 'jurnal_detail.coa_id')
            ->whereNull('jurnal.deleted_at')
            ->where('jurnal.status', 'Posted')
            ->when($kecualikanPenutup, fn ($q) => $q->where('jurnal.jenis_jurnal', '!=', Coa::JENIS_JURNAL_PENUTUP))
            ->where('coas.kode_akun', $kode)
            ->when($dari !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '<=', $sampai))
            ->selectRaw('COALESCE(SUM(jurnal_detail.debet), 0) AS debet')
            ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) AS kredit')
            ->first();

        return [
            'debet' => Setting::bulatkanUang((float) ($row->debet ?? 0)),
            'kredit' => Setting::bulatkanUang((float) ($row->kredit ?? 0)),
        ];
    }

    /**
     * Total mutasi seluruh jurnal pada satu periode.
     *
     * Dipakai CaLK untuk menegaskan laporan dibangun dari buku yang seimbang —
     * bukan menutupi ketidakseimbangan dengan jurnal penyeimbang.
     *
     * @return array{debet: float, kredit: float, selisih: float, seimbang: bool,
     *               jumlah_jurnal: int, belum_posting: int}
     */
    public function totalMutasi(?string $dari = null, ?string $sampai = null): array
    {
        $agg = JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->whereNull('jurnal.deleted_at')
            ->where('jurnal.status', 'Posted')
            ->when($dari !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '>=', $dari))
            ->when($sampai !== null, fn ($q) => $q->whereDate('jurnal.tanggal_jurnal', '<=', $sampai))
            ->selectRaw('COALESCE(SUM(jurnal_detail.debet), 0) AS debet')
            ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) AS kredit')
            ->first();

        $debet = Setting::bulatkanUang((float) ($agg->debet ?? 0));
        $kredit = Setting::bulatkanUang((float) ($agg->kredit ?? 0));
        $selisih = Setting::bulatkanUang($debet - $kredit);

        return [
            'debet' => $debet,
            'kredit' => $kredit,
            'selisih' => $selisih,
            'seimbang' => abs($selisih) <= Setting::toleransiSelisihJurnal(),
            'jumlah_jurnal' => Jurnal::query()
                ->when($dari !== null, fn ($q) => $q->whereDate('tanggal_jurnal', '>=', $dari))
                ->when($sampai !== null, fn ($q) => $q->whereDate('tanggal_jurnal', '<=', $sampai))
                ->count(),
            'belum_posting' => Jurnal::query()
                ->where('status', '!=', 'Posted')
                ->when($dari !== null, fn ($q) => $q->whereDate('tanggal_jurnal', '>=', $dari))
                ->when($sampai !== null, fn ($q) => $q->whereDate('tanggal_jurnal', '<=', $sampai))
                ->count(),
        ];
    }

    /* =====================================================================
     | 1. LAPORAN POSISI KEUANGAN (NERACA) — PSAK 101 par. 33-45
     ===================================================================== */

    /**
     * Laporan Posisi Keuangan dengan lima unsur penyajian terpisah:
     * Aset, Kewajiban, Dana Syirkah Temporer, Ekuitas, dan Laba/Rugi Berjalan.
     *
     * @return array<string, mixed>
     */
    public function posisiKeuangan(array $periode): array
    {
        $sampai = $periode['sampaiStr'];
        $sampaiLalu = $periode['sampaiLaluStr'];

        $kelompok = fn (string $kategori): Collection => Coa::where('kategori', $kategori)
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->get();

        $aset = $kelompok('Aset');
        $kewajiban = $kelompok('Kewajiban');
        $ekuitas = $kelompok('Ekuitas');
        $pendapatan = $kelompok('Pendapatan');
        $beban = $kelompok('Beban');

        // Dana Syirkah Temporer dipisah di lapisan DATA, bukan hanya tampilan:
        // DST adalah dana investasi pemilik dana (bukan utang), sehingga
        // memasukkannya ke Kewajiban menggelembungkan rasio solvabilitas.
        $dst = $kewajiban->where('klasifikasi_neraca', 'Dana Syirkah Temporer')->values();
        $kewajibanBersih = $kewajiban->where('klasifikasi_neraca', '!=', 'Dana Syirkah Temporer')->values();

        $totalAset = $this->jumlahPresisi($aset, $sampai);
        $totalKewajiban = $this->jumlahPresisi($kewajibanBersih, $sampai);
        $totalDst = $this->jumlahPresisi($dst, $sampai);
        $totalEkuitas = $this->jumlahPresisi($ekuitas, $sampai);
        $totalPendapatan = $this->jumlahPresisi($pendapatan, $sampai);
        $totalBeban = $this->jumlahPresisi($beban, $sampai);
        $labaBerjalan = Setting::bulatkanUang($totalPendapatan - $totalBeban);

        $totalPasiva = Setting::bulatkanUang($totalKewajiban + $totalDst + $totalEkuitas + $labaBerjalan);
        $selisih = Setting::bulatkanUang($totalAset - $totalPasiva);

        // Kolom komparatif: periode sama tahun sebelumnya.
        $totalAsetLalu = $this->jumlahPresisi($aset, $sampaiLalu);
        $totalKewajibanLalu = $this->jumlahPresisi($kewajibanBersih, $sampaiLalu);
        $totalDstLalu = $this->jumlahPresisi($dst, $sampaiLalu);
        $totalEkuitasLalu = $this->jumlahPresisi($ekuitas, $sampaiLalu);
        $labaLalu = Setting::bulatkanUang(
            $this->jumlahPresisi($pendapatan, $sampaiLalu) - $this->jumlahPresisi($beban, $sampaiLalu));
        $totalPasivaLalu = Setting::bulatkanUang($totalKewajibanLalu + $totalDstLalu + $totalEkuitasLalu + $labaLalu);

        return [
            'asetGroups' => $this->kelompokkan($aset, self::URUTAN_ASET, $sampai),
            'kewajibanGroups' => $this->kelompokkan($kewajibanBersih, self::URUTAN_KEWAJIBAN, $sampai),
            'dstGroups' => $this->kelompokkan($dst, ['Dana Syirkah Temporer'], $sampai),
            'ekuitasGroups' => $this->kelompokkan($ekuitas, self::URUTAN_EKUITAS, $sampai),
            'aset' => $aset,
            'kewajiban' => $kewajibanBersih,
            'dst' => $dst,
            'ekuitas' => $ekuitas,
            'totalAset' => $totalAset,
            'totalKewajiban' => $totalKewajiban,
            'totalDanaSyirkahTemporer' => $totalDst,
            'totalEkuitas' => $totalEkuitas,
            'labaBersih' => $labaBerjalan,
            'totalPendapatan' => $totalPendapatan,
            'totalBeban' => $totalBeban,
            'totalPasiva' => $totalPasiva,
            'selisihNeraca' => $selisih,
            'statusLaba' => abs($labaBerjalan) <= Setting::toleransiSelisihJurnal()
                ? 'impas'
                : ($labaBerjalan > 0 ? 'untung' : 'rugi'),
            'seimbang' => abs($selisih) <= Setting::toleransiSelisihJurnal(),
            'totalAsetLalu' => $totalAsetLalu,
            'totalKewajibanLalu' => $totalKewajibanLalu,
            'totalDstLalu' => $totalDstLalu,
            'totalEkuitasLalu' => $totalEkuitasLalu,
            'labaBersihLalu' => $labaLalu,
            'totalPasivaLalu' => $totalPasivaLalu,
        ];
    }

    /* =====================================================================
     | 2. LAPORAN LABA RUGI — PSAK 101 par. 57-63
     ===================================================================== */

    /**
     * Laporan Laba Rugi dengan pemisahan tegas Pendapatan Usaha vs Pendapatan
     * Lain-lain, dan Beban Usaha vs Beban Lain-lain.
     *
     * Akun dana titipan (2-3001…2-3004) SENGAJA tidak masuk perhitungan: dana
     * zakat/infak bukan pendapatan koperasi (PSAK 109).
     *
     * @return array<string, mixed>
     */
    public function labaRugi(array $periode): array
    {
        $sampai = $periode['sampaiStr'];

        $pendapatan = Coa::where('kategori', 'Pendapatan')->where('is_active', true)->orderBy('kode_akun')->get();
        $beban = Coa::where('kategori', 'Beban')->where('is_active', true)->orderBy('kode_akun')->get();

        $pUsaha = $pendapatan->where('klasifikasi_neraca', 'Pendapatan Usaha')->values();
        $pLain = $pendapatan->where('klasifikasi_neraca', 'Pendapatan Lain-lain')->values();
        $bUsaha = $beban->where('klasifikasi_neraca', 'Beban Usaha')->values();
        $bLain = $beban->where('klasifikasi_neraca', 'Beban Lain-lain')->values();

        // Akun yang belum dipetakan klasifikasinya tetap disajikan (tidak boleh
        // hilang dari laporan) sebagai kelompok "Belum Diklasifikasi".
        $pTakKlas = $pendapatan->filter(fn (Coa $c) => ! in_array($c->klasifikasi_neraca, ['Pendapatan Usaha', 'Pendapatan Lain-lain'], true))->values();
        $bTakKlas = $beban->filter(fn (Coa $c) => ! in_array($c->klasifikasi_neraca, ['Beban Usaha', 'Beban Lain-lain'], true))->values();

        $totalPendapatanUsaha = $this->jumlahPresisi($pUsaha, $sampai);
        $totalPendapatanLain = $this->jumlahPresisi($pLain, $sampai);
        $totalPendapatanTakKlas = $this->jumlahPresisi($pTakKlas, $sampai);
        $totalPendapatan = Setting::bulatkanUang($totalPendapatanUsaha + $totalPendapatanLain + $totalPendapatanTakKlas);

        $totalBebanUsaha = $this->jumlahPresisi($bUsaha, $sampai);
        $totalBebanLain = $this->jumlahPresisi($bLain, $sampai);
        $totalBebanTakKlas = $this->jumlahPresisi($bTakKlas, $sampai);
        $totalBeban = Setting::bulatkanUang($totalBebanUsaha + $totalBebanLain + $totalBebanTakKlas);

        $labaKotor = Setting::bulatkanUang($totalPendapatanUsaha - $totalBebanUsaha);
        $labaSebelumPajak = Setting::bulatkanUang($totalPendapatan - $totalBeban);

        return [
            'pendapatanUsaha' => $this->barisAkun($pUsaha, $sampai),
            'pendapatanLain' => $this->barisAkun($pLain, $sampai),
            'pendapatanTakKlasifikasi' => $this->barisAkun($pTakKlas, $sampai),
            'bebanUsaha' => $this->barisAkun($bUsaha, $sampai),
            'bebanLain' => $this->barisAkun($bLain, $sampai),
            'bebanTakKlasifikasi' => $this->barisAkun($bTakKlas, $sampai),
            'totalPendapatanUsaha' => $totalPendapatanUsaha,
            'totalPendapatanLain' => $totalPendapatanLain,
            'totalPendapatanTakKlasifikasi' => $totalPendapatanTakKlas,
            'totalPendapatan' => $totalPendapatan,
            'totalBebanUsaha' => $totalBebanUsaha,
            'totalBebanLain' => $totalBebanLain,
            'totalBebanTakKlasifikasi' => $totalBebanTakKlas,
            'totalBeban' => $totalBeban,
            'labaKotor' => $labaKotor,
            'labaSebelumPajak' => $labaSebelumPajak,
            'labaBersih' => $labaSebelumPajak,
            'marginLabaKotor' => $totalPendapatanUsaha > 0
                ? Setting::bulatkanUang($labaKotor / $totalPendapatanUsaha * 100)
                : 0.0,
            'marginLabaBersih' => $totalPendapatan > 0
                ? Setting::bulatkanUang($labaSebelumPajak / $totalPendapatan * 100)
                : 0.0,
            'statusLaba' => abs($labaSebelumPajak) <= Setting::toleransiSelisihJurnal()
                ? 'impas'
                : ($labaSebelumPajak > 0 ? 'untung' : 'rugi'),
            'adaTakKlasifikasi' => $pTakKlas->isNotEmpty() || $bTakKlas->isNotEmpty(),
        ];
    }

    /* =====================================================================
     | 3. LAPORAN PERUBAHAN EKUITAS — PSAK 101 par. 64-68
     ===================================================================== */

    /**
     * Perubahan setiap komponen ekuitas: saldo awal periode, mutasi penambah,
     * mutasi pengurang, dan saldo akhir.
     *
     * @return array<string, mixed>
     */
    public function perubahanEkuitas(array $periode): array
    {
        $dari = $periode['dariStr'];
        $sampai = $periode['sampaiStr'];
        $hariSebelum = $this->hariSebelum($dari);

        $akun = Coa::where('kategori', 'Ekuitas')->where('is_active', true)->orderBy('kode_akun')->get();

        $baris = $akun->map(function (Coa $c) use ($dari, $sampai, $hariSebelum): array {
            $awal = Setting::bulatkanUang((float) $c->saldoBerjalanLaporan($hariSebelum));
            $akhir = Setting::bulatkanUang((float) $c->saldoBerjalanLaporan($sampai));
            $mutasi = $this->mutasiAkun($c->kode_akun, $dari, $sampai, true);

            return [
                'kode' => $c->kode_akun,
                'nama' => $c->nama_akun,
                'klasifikasi' => $c->klasifikasi_neraca,
                'saldoAwal' => $awal,
                'debet' => $mutasi['debet'],
                'kredit' => $mutasi['kredit'],
                'penambah' => Setting::bulatkanUang(max(0.0, $akhir - $awal)),
                'pengurang' => Setting::bulatkanUang(max(0.0, $awal - $akhir)),
                'saldoAkhir' => $akhir,
            ];
        })->values();

        $laba = $this->labaRugi($periode)['labaBersih'];

        return [
            'baris' => $baris,
            'totalAwal' => Setting::bulatkanUang((float) $baris->sum('saldoAwal')),
            'totalPenambah' => Setting::bulatkanUang((float) $baris->sum('penambah')),
            'totalPengurang' => Setting::bulatkanUang((float) $baris->sum('pengurang')),
            'totalAkhir' => Setting::bulatkanUang((float) $baris->sum('saldoAkhir')),
            'labaBersih' => $laba,
            'totalAkhirTermasukLaba' => Setting::bulatkanUang((float) $baris->sum('saldoAkhir') + $laba),
            'totalAwalTermasukLaba' => Setting::bulatkanUang((float) $baris->sum('saldoAwal') + $laba),
            'perKlasifikasi' => $baris->groupBy(fn (array $r) => $r['klasifikasi'] ?: 'Belum Diklasifikasi'),
        ];
    }

    /* =====================================================================
     | 4. LAPORAN ARUS KAS — PSAK 101 par. 69-73 (metode langsung)
     ===================================================================== */

    /**
     * Laporan Arus Kas berbasis mutasi kas/bank PADA JURNAL — bukan pada tabel
     * transaksi. Basis jurnal dipilih karena inilah yang menjadikan Arus Kas
     * dapat direkonsiliasi dengan Neraca dan Laba Rugi; basis kas-transaksi
     * memakai `waktu_transaksi` yang tidak selalu sama dengan `tanggal_jurnal`.
     *
     * Prinsip penyusunan: yang dilaporkan adalah MUTASI AKUN KAS, dan setiap
     * mutasi diatribusikan ke aktivitas berdasarkan akun lawannya dalam jurnal
     * yang sama. Mutasi antar-kas sendiri (transfer kas–bank) tidak dihitung
     * ganda. Simpanan berjangka (DST) dipisahkan ke aktivitas pendanaan karena
     * ia adalah dana investasi, bukan utang usaha.
     *
     * Identitas yang dijaga ketat:
     *   saldoAkhirKas − saldoAwalKas  ==  Σ(operasi + investasi + pendanaan)
     *
     * Jika tidak sama, selisihnya muncul sebagai baris "Lain-lain" — laporan
     * tidak boleh dipaksa seimbang dengan akun penyeimbang fiktif.
     *
     * @return array<string, mixed>
     */
    public function arusKas(array $periode): array
    {
        $dari = $periode['dariStr'];
        $sampai = $periode['sampaiStr'];

        $saldoAwalKas = $this->saldoAkunBanyak(Akun::AKUN_KAS_ARUS_KAS, $this->hariSebelum($dari));
        $saldoAkhirKas = $this->saldoAkunBanyak(Akun::AKUN_KAS_ARUS_KAS, $sampai);

        $aliran = $this->aliranKas($dari, $sampai);
        $terklasifikasi = $this->kelompokkanArusKas($aliran);

        $operasi = $this->susunBagian($terklasifikasi['operasi'], 'Arus Kas dari Aktivitas Operasi');
        $investasi = $this->susunBagian($terklasifikasi['investasi'], 'Arus Kas dari Aktivitas Investasi');
        $pendanaan = $this->susunBagian($terklasifikasi['pendanaan'], 'Arus Kas dari Aktivitas Pendanaan');
        $lainnya = $this->susunBagian($terklasifikasi['lainnya'], 'Arus Kas dari Aktivitas Lain');

        $kenaikan = Setting::bulatkanUang($operasi['total'] + $investasi['total'] + $pendanaan['total'] + $lainnya['total']);
        $kenaikanMenurutSaldo = Setting::bulatkanUang($saldoAkhirKas - $saldoAwalKas);

        return [
            'operasi' => $operasi,
            'investasi' => $investasi,
            'pendanaan' => $pendanaan,
            'lainnya' => $lainnya,
            'totalOperasi' => $operasi['total'],
            'totalInvestasi' => $investasi['total'],
            'totalPendanaan' => $pendanaan['total'],
            'totalLainnya' => $lainnya['total'],
            'kenaikanKas' => $kenaikan,
            'saldoAwalKas' => $saldoAwalKas,
            'saldoAkhirKas' => $saldoAkhirKas,
            'kenaikanMenurutSaldo' => $kenaikanMenurutSaldo,
            'selisihRekonsiliasi' => Setting::bulatkanUang($kenaikanMenurutSaldo - $kenaikan),
            'rekonsiliasiCocok' => abs(Setting::bulatkanUang($kenaikanMenurutSaldo - $kenaikan)) <= Setting::toleransiSelisihJurnal(),
            'adaLainnya' => $lainnya['baris']->isNotEmpty(),
            'jumlahBaris' => $aliran->count(),
        ];
    }

    /**
     * Diagnostik independen terhadap Laporan Arus Kas.
     *
     * Membuktikan buku BUKAN salinan Laporan Arus Kas: identitas yang diuji
     * berasal langsung dari mutasi jurnal,
     *
     *     mutasi kas bersih  ≡  (mutasi Pendapatan − mutasi Beban)
     *                            + Σ akun neraca non-kas (kredit − debet)
     *
     * Identitas itu berlaku untuk SELURUH kategori akun tanpa pengecualian,
     * karena setiap jurnal selalu seimbang. Sisi kanan memuat:
     *   - akun L/R            → laba (rugi) periode,
     *   - akun neraca selain kas (piutang, DST, simpanan, ekuitas, aset tetap,
     *     dana titipan) → seluruh jembatan non-kas.
     * Keduanya dijumlahkan dari mutasi akun, bukan dari hasil olahan Laba Rugi,
     * sehingga bila ada akun yang tidak terpetakan ke kategori mana pun,
     * selisihnya JUSTRU muncul sebagai temuan (positif palsu tidak mungkin).
     *
     * Versi sebelumnya hanya memantau daftar akun tetap
     * (`piutang + margin ditangguhkan + simpanan berjangka`), sehingga seluruh
     * pendanaan lain — Simpanan Sukarela/Wajib/Pokok (2-1001/2-1002/3-1001/
     * 3-1002), SHU belum dibagi (3-2001), modal penyertaan (3-9000), dan dana
     * kebajikan (2-3001) — terlewat, dan buku yang SEIMBANG dilaporkan
     * "tidak terjelaskan" sebesar jumlah persis akun-akun tersebut.
     *
     * @return array{terdeteksi: bool, selisih: float, keterangan: string,
     *               mutasiKasBersih: float, labaBersih: float, mutasiNonKas: float}
     */
    public function deteksiArusKasTakTerlacak(array $periode): array
    {
        $dari = $periode['dariStr'];
        $sampai = $periode['sampaiStr'];
        $toleransi = Setting::toleransiSelisihJurnal();

        $mutasiKasBersih = 0.0;
        foreach (Akun::AKUN_KAS_ARUS_KAS as $kode) {
            $m = $this->mutasiAkun($kode, $dari, $sampai);
            $mutasiKasBersih = Setting::bulatkanUang($mutasiKasBersih + $m['debet'] - $m['kredit']);
        }

        // Jembatan "pendapatan − beban" (identik dengan laba bersih bila akun
        // L/R sudah lengkap dipetakan).
        $pendapatan = Coa::where('kategori', 'Pendapatan')->where('is_active', true)->get();
        $beban = Coa::where('kategori', 'Beban')->where('is_active', true)->get();

        // Basis BUKU (bukan basis laporan): identitas di bawah hanya seimbang
        // bila jurnal penutup ikut dihitung, karena `mutasiNonKas` juga
        // mencerminkannya. Menyajikan angka basis laporan di sini akan
        // memunculkan "kas tak terjelaskan" palsu sebesar laba yang ditutup.
        $labaBersih = Setting::bulatkanUang(
            $this->jumlahPresisi($pendapatan, $sampai, false) - $this->jumlahPresisi($beban, $sampai, false));

        // Seluruh akun neraca non-kas: kredit menambah kas (sumber), debet
        // mengurangi kas (penggunaan).
        $nonKas = Coa::whereIn('kategori', ['Aset', 'Kewajiban', 'Ekuitas'])
            ->where('is_active', true)
            ->whereNotIn('kode_akun', Akun::AKUN_KAS_ARUS_KAS)
            ->get();

        $mutasiNonKas = 0.0;
        foreach ($nonKas as $akun) {
            $m = $this->mutasiAkun($akun->kode_akun, $dari, $sampai);
            $mutasiNonKas = Setting::bulatkanUang($mutasiNonKas + $m['kredit'] - $m['debet']);
        }

        $selisih = Setting::bulatkanUang($mutasiKasBersih - $labaBersih - $mutasiNonKas);

        return [
            'terdeteksi' => abs($selisih) > $toleransi,
            'selisih' => $selisih,
            'mutasiKasBersih' => $mutasiKasBersih,
            'labaBersih' => $labaBersih,
            'mutasiNonKas' => $mutasiNonKas,
            'keterangan' => abs($selisih) > $toleransi
                ? 'Sebagian mutasi kas periode ini tidak dapat dijelaskan oleh Laba Rugi maupun seluruh akun neraca non-kas. Periksa jurnal tidak seimbang, jurnal koreksi, atau jurnal penutup.'
                : 'Seluruh mutasi kas periode ini terjelaskan oleh Laba Rugi dan seluruh akun neraca non-kas (piutang pembiayaan, margin ditangguhkan, dana syirkah temporer, simpanan, ekuitas, aset tetap, dan dana titipan).',
        ];
    }

    /**
     * Rincian aliran kas: satu baris per akun lawan non-kas, beserta arahnya.
     *
     * Atribusi diambil dari MUTASI NETO akun lawan non-kas pada jurnal yang
     * memuat baris kas (kredit lawan menambah kas, debet lawan mengurangi kas).
     * Karena setiap jurnal seimbang, jumlah neto seluruh akun lawan non-kas
     * tepat sama dengan kenaikan kas — sehingga satu pergerakan kas tidak
     * pernah terhitung dua kali, sekalipun satu jurnal memuat beberapa baris
     * kas dan beberapa baris lawan (mis. angsuran: pokok piutang + margin).
     *
     * @return Collection<int, array{akun: string, nama: string, kategori: string, kas: string, arah: string, nominal: float}>
     */
    private function aliranKas(string $dari, string $sampai): Collection
    {
        $kodeKas = Akun::AKUN_KAS_ARUS_KAS;

        // Subkueri: jurnal berstatus Posted pada periode ini yang benar-benar
        // memuat minimal satu baris akun kas likuid.
        $jurnalKas = function ($query) use ($dari, $sampai, $kodeKas): void {
            $query->select('j.id')
                ->from('jurnal as j')
                ->join('jurnal_detail as kd', 'kd.jurnal_id', '=', 'j.id')
                ->join('coas as kas', 'kas.id', '=', 'kd.coa_id')
                ->whereNull('j.deleted_at')
                ->where('j.status', 'Posted')
                ->whereDate('j.tanggal_jurnal', '>=', $dari)
                ->whereDate('j.tanggal_jurnal', '<=', $sampai)
                ->whereIn('kas.kode_akun', $kodeKas);
        };

        $rows = JurnalDetail::query()
            ->from('jurnal_detail as lawan')
            ->join('coas as co', 'co.id', '=', 'lawan.coa_id')
            ->whereIn('lawan.jurnal_id', $jurnalKas)
            ->whereNotIn('co.kode_akun', $kodeKas)
            ->selectRaw('co.kode_akun AS akun_lawan')
            ->selectRaw('co.nama_akun AS nama_lawan')
            ->selectRaw('co.kategori AS kategori_lawan')
            ->selectRaw('COALESCE(SUM(lawan.kredit), 0) AS kredit_lawan')
            ->selectRaw('COALESCE(SUM(lawan.debet), 0) AS debet_lawan')
            ->groupBy('co.kode_akun', 'co.nama_akun', 'co.kategori')
            ->get();

        $aliran = collect();

        foreach ($rows as $r) {
            // Kredit akun lawan = sumber kas (masuk); debet = penggunaan (keluar).
            $neto = Setting::bulatkanUang((float) $r->kredit_lawan - (float) $r->debet_lawan);

            if (abs($neto) < Setting::toleransiSelisihJurnal()) {
                continue;
            }

            $aliran->push([
                'akun' => (string) $r->akun_lawan,
                'nama' => (string) $r->nama_lawan,
                'kategori' => (string) $r->kategori_lawan,
                'kas' => '',
                'arah' => $neto > 0 ? 'masuk' : 'keluar',
                'nominal' => abs($neto),
            ]);
        }

        // Mutasi kas yang tidak berhasil diatribusikan ke akun lawan non-kas
        // (mis. transfer antar kas, atau jurnal dengan baris kas yatim) tetap
        // harus muncul supaya tidak ada kas yang "hilang" dari laporan.
        $sisa = $this->sisaAliranKas($dari, $sampai, $aliran);

        if (abs($sisa) >= Setting::toleransiSelisihJurnal()) {
            $aliran->push([
                'akun' => '—',
                'nama' => $sisa > 0
                    ? 'Mutasi kas bersih yang belum teratribusi'
                    : 'Mutasi kas bersih yang belum teratribusi',
                'kategori' => 'Lain-lain',
                'kas' => '',
                'arah' => $sisa > 0 ? 'masuk' : 'keluar',
                'nominal' => abs($sisa),
            ]);
        }

        return $aliran->sortBy([['kategori', 'asc'], ['akun', 'asc']])->values();
    }

    /**
     * Selisih antara mutasi kas bersih periode dan jumlah aliran yang berhasil
     * diatribusikan ke akun lawan non-kas. Idealnya nol.
     */
    private function sisaAliranKas(string $dari, string $sampai, Collection $aliran): float
    {
        $netoKas = 0.0;
        foreach (Akun::AKUN_KAS_ARUS_KAS as $kode) {
            $m = $this->mutasiAkun($kode, $dari, $sampai);
            $netoKas = Setting::bulatkanUang($netoKas + $m['debet'] - $m['kredit']);
        }

        $teratribusi = Setting::bulatkanUang((float) $aliran->sum(
            fn (array $r) => $r['arah'] === 'masuk' ? $r['nominal'] : -$r['nominal']
        ));

        return Setting::bulatkanUang($netoKas - $teratribusi);
    }

    /**
     * Kelompokkan aliran kas ke aktivitas operasi / investasi / pendanaan.
     *
     * Pengelompokan memakai KODE akun (bukan nama) supaya tidak rapuh terhadap
     * penggantian label akun.
     *
     * @return array{operasi: Collection, investasi: Collection, pendanaan: Collection, lainnya: Collection}
     */
    private function kelompokkanArusKas(Collection $aliran): array
    {
        $kodeDst = Coa::query()
            ->where('klasifikasi_neraca', 'Dana Syirkah Temporer')
            ->pluck('kode_akun')
            ->all();

        $hasil = ['operasi' => collect(), 'investasi' => collect(), 'pendanaan' => collect(), 'lainnya' => collect()];

        foreach ($aliran as $baris) {
            // Dana Syirkah Temporer → aktivitas pendanaan (dana investasi).
            if (in_array($baris['akun'], $kodeDst, true)) {
                $hasil['pendanaan']->push($baris);

                continue;
            }

            switch ((string) $baris['kategori']) {
                case 'Kewajiban':
                    // Simpanan anggota (2-1xxx) = pendanaan; utang usaha lainnya
                    // (dana pengurus, dana titipan) = operasi.
                    $kelompok = str_starts_with($baris['akun'], '2-1') ? 'pendanaan' : 'operasi';
                    break;

                case 'Ekuitas':
                    $kelompok = 'pendanaan';
                    break;

                case 'Aset':
                    // Aset tetap/terikat (1-xxxx dengan nomor ≥ 1003, mis. KAS_BRANKAS
                    // 1-1003) dianggap investasi; piutang pembiayaan (1-2xxx)
                    // dianggap operasi karena merupakan kegiatan usaha utama.
                    $kelompok = ((int) substr($baris['akun'], 2, 4) >= 1003 && str_starts_with($baris['akun'], '1-1'))
                        ? 'lainnya'
                        : 'operasi';
                    break;

                case 'Pendapatan':
                case 'Beban':
                    $kelompok = 'operasi';
                    break;

                default:
                    $kelompok = 'lainnya';
                    break;
            }

            $hasil[$kelompok]->push($baris);
        }

        return [
            'operasi' => $hasil['operasi']->values(),
            'investasi' => $hasil['investasi']->values(),
            'pendanaan' => $hasil['pendanaan']->values(),
            'lainnya' => $hasil['lainnya']->values(),
        ];
    }

    /**
     * Susun satu bagian arus kas menjadi baris siap saji beserta totalnya.
     *
     * @return array{label: string, baris: Collection, total: float}
     */
    private function susunBagian(Collection $baris, string $label): array
    {
        $disusun = $baris->map(fn (array $r): array => [
            'label' => ($r['arah'] === 'masuk' ? 'Penerimaan dari ' : 'Pengeluaran untuk ').$r['nama'],
            'masuk' => $r['arah'] === 'masuk' ? $r['nominal'] : 0.0,
            'keluar' => $r['arah'] === 'keluar' ? $r['nominal'] : 0.0,
            'neto' => $r['arah'] === 'masuk' ? $r['nominal'] : -$r['nominal'],
            'akun' => $r['akun'],
        ])->values();

        return [
            'label' => $label,
            'baris' => $disusun,
            'total' => Setting::bulatkanUang((float) $disusun->sum('neto')),
        ];
    }

    /* =====================================================================
     | 5. LAPORAN PERUBAHAN DANA INVESTASI TERIKAT — PSAK 109
     ===================================================================== */

    /**
     * Perubahan Dana Investasi Terikat per jenis dana (zakat, infak/sedekah,
     * kebajikan, sosial, titipan selisih kas).
     *
     * Prinsip pokoknya: dana ini MILIK pemberi amanah, bukan milik koperasi.
     * Karena itu seluruh angkanya dihitung DARI MUTASI JURNAL
     * (`totalKredit − totalDebet`), bukan dari akumulasi tabel transaksi modul.
     * Perbedaan antara keduanya justru menjadi temuan audit: menandakan ada
     * penerimaan/penyaluran yang tercatat di modul tetapi jurnalnya belum
     * diposting — atau sebaliknya.
     *
     * @return array<string, mixed>
     */
    public function perubahanDanaInvestasiTerikat(array $periode): array
    {
        $dari = $periode['dariStr'];
        $sampai = $periode['sampaiStr'];
        $hariSebelum = $this->hariSebelum($dari);

        $baris = collect();

        $akunDit = $this->akunDit();

        foreach (self::URUTAN_DIT as $kunci => $label) {
            $kode = $akunDit[$kunci] ?? Akun::DANA_ZAKAT;
            $coa = Coa::findByKode($kode);

            if (! $coa) {
                $baris->push([
                    'kunci' => $kunci,
                    'kode' => $kode,
                    'label' => $label,
                    'akunTersedia' => false,
                    'saldoAwal' => 0.0,
                    'penerimaan' => 0.0,
                    'penyaluran' => 0.0,
                    'saldoAkhir' => 0.0,
                    'mutasiDebet' => 0.0,
                    'mutasiKredit' => 0.0,
                ]);

                continue;
            }

            // Akun dana titipan bersaldo KREDIT (diperbesar kredit).
            $saldoAwal = Setting::bulatkanUang((float) $coa->totalKredit($hariSebelum) - (float) $coa->totalDebet($hariSebelum));
            $mutasi = $this->mutasiAkun($kode, $dari, $sampai);
            $saldoAkhir = Setting::bulatkanUang((float) $coa->totalKredit($sampai) - (float) $coa->totalDebet($sampai));

            $baris->push([
                'kunci' => $kunci,
                'kode' => $kode,
                'label' => $label,
                'akunTersedia' => true,
                'saldoAwal' => $saldoAwal,
                'penerimaan' => $mutasi['kredit'],
                'penyaluran' => $mutasi['debet'],
                'mutasiDebet' => $mutasi['debet'],
                'mutasiKredit' => $mutasi['kredit'],
                'saldoAkhir' => $saldoAkhir,
            ]);
        }

        $totalAwal = Setting::bulatkanUang((float) $baris->sum('saldoAwal'));
        $totalTerima = Setting::bulatkanUang((float) $baris->sum('penerimaan'));
        $totalSalur = Setting::bulatkanUang((float) $baris->sum('penyaluran'));
        $totalAkhir = Setting::bulatkanUang((float) $baris->sum('saldoAkhir'));
        $mutasiSeimbang = abs(Setting::bulatkanUang($totalAwal + $totalTerima - $totalSalur) - $totalAkhir)
            <= Setting::toleransiSelisihJurnal();

        return [
            'baris' => $baris,
            'totalAwal' => $totalAwal,
            'totalPenerimaan' => $totalTerima,
            'totalPenyaluran' => $totalSalur,
            'totalAkhir' => $totalAkhir,
            'mutasiSeimbang' => $mutasiSeimbang,
            'adaAkunHilang' => $baris->contains(fn (array $r) => ! $r['akunTersedia']),
        ];
    }

    /* =====================================================================
     | 6. CATATAN ATAS LAPORAN KEUANGAN (CaLK) — PSAK 101 par. 12, 74-88
     ===================================================================== */

    /**
     * Uji kepatuhan penyajian: apakah seluruh akun aktif sudah dipetakan ke
     * klasifikasi PSAK 101, apakah kelompok wajib terisi, dan apakah laporan
     * dibangun dari buku yang seimbang.
     *
     * @return array{patuh: bool, temuan: list<string>, kelompokDisajikan: array<string,int>,
     *               akunTakKlasifikasi: Collection<int, Coa>, mutasi: array<string,mixed>}
     */
    public function kepatuhan(array $periode): array
    {
        $sampai = $periode['sampaiStr'];
        $temuan = [];

        $aktif = Coa::where('is_active', true)->orderBy('kode_akun')->get();

        $takKlasifikasi = $aktif
            ->filter(fn (Coa $c) => ($c->klasifikasi_neraca ?? '') === '')
            ->values();

        if ($takKlasifikasi->isNotEmpty()) {
            $temuan[] = 'Terdapat '.$takKlasifikasi->count()
                .' akun aktif yang belum memiliki klasifikasi penyajian PSAK 101: '
                .$takKlasifikasi->pluck('kode_akun')->take(10)->implode(', ')
                .($takKlasifikasi->count() > 10 ? ', …' : '').'.';
        }

        $kelompokDisajikan = [];
        foreach (Coa::KLASIFIKASI as $kategori => $daftar) {
            foreach ($daftar as $klasifikasi) {
                $kelompokDisajikan[$klasifikasi] = $aktif
                    ->where('kategori', $kategori)
                    ->where('klasifikasi_neraca', $klasifikasi)
                    ->count();
            }
        }

        // Kelompok penyajian yang wajib ada menurut PSAK 101.
        foreach (['Aset Lancar', 'Kewajiban Jangka Pendek', 'Modal Anggota'] as $kelompok) {
            if (($kelompokDisajikan[$kelompok] ?? 0) === 0) {
                $temuan[] = 'Kelompok penyajian wajib "'.$kelompok.'" tidak memiliki akun aktif.';
            }
        }

        $mutasi = $this->totalMutasi($periode['dariStr'], $sampai);
        if (! $mutasi['seimbang']) {
            $temuan[] = 'Total debet ≠ total kredit pada jurnal periode ini (selisih Rp '
                .number_format(abs($mutasi['selisih']), 2, ',', '.')
                .'). Laporan disusun dari buku yang belum seimbang.';
        }

        $posisi = $this->posisiKeuangan($periode);
        if (! $posisi['seimbang']) {
            $temuan[] = 'Laporan Posisi Keuangan belum seimbang (selisih Rp '
                .number_format(abs($posisi['selisihNeraca']), 2, ',', '.').').';
        }

        return [
            'patuh' => $temuan === [],
            'temuan' => $temuan,
            'kelompokDisajikan' => $kelompokDisajikan,
            'akunTakKlasifikasi' => $takKlasifikasi,
            'mutasi' => $mutasi,
        ];
    }

    /**
     * Kebijakan akuntansi & asumsi yang BENAR-BENAR berlaku, dibaca dari
     * parameter kebijakan sehingga CaLK tidak pernah mendokumentasikan angka
     * yang berbeda dari yang dipakai sistem.
     *
     * @return array<string, mixed>
     */
    public function kebijakanAkuntansi(): array
    {
        return [
            'dasarPenyusunan' => 'Basis akrual, konsep entitas syariah, dan asumsi kesinambungan usaha.',
            'standar' => 'PSAK 101 (penyajian laporan keuangan syariah), PSAK 102 (murabahah), '
                .'PSAK 103 (salam), PSAK 104 (istishna), PSAK 105 (mudharabah), '
                .'PSAK 106 (musyarakah), PSAK 107 (ijarah), '
                .'PSAK 109 (zakat, infak, dan sedekah), dan PSAK 71 (instrumen keuangan). '
                .'Margin murabahah ditangguhkan (1-2101) diakui dan diamortisasi sesuai PSAK 102; '
                .'dana kebajikan dan dana titipan sosial disajikan terpisah dari pendapatan sesuai '
                .'PSAK 101 jo. PSAK 109 dan Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000.',
            'mataUang' => 'Rupiah (IDR), tanpa penyajian ulang mata uang asing.',
            'pembulatan' => 'Perhitungan jurnal memakai '.Setting::pembulatanDesimal()
                .' angka desimal; penyajian laporan memakai rupiah penuh.',
            'toleransiSelisih' => Setting::toleransiSelisihJurnal(),
            'ambangKolektibilitas' => Setting::ambangKolektibilitas(),
            'persenCkpn' => Setting::persenCkpn(),
            'nisbahDefaultPersen' => Setting::nisbahDefaultPersen(),
            'marginDefaultPersen' => Setting::marginDefaultPersen(),
            'sumberKebenaran' => 'Mutasi jurnal berstatus Posted dengan batas waktu tanggal_jurnal '
                .'(tanggal kejadian), bukan created_at (tanggal input).',
            'kolomKanonik' => 'Baris jurnal dipetakan ke akun melalui kolom coa_id (relasi ke tabel coas); '
                .'kolom akun_id adalah warisan yang tidak dipakai lagi.',
            'danaTitipan' => 'Dana zakat, infak/sedekah, kebajikan, dan sosial disajikan sebagai dana '
                .'titipan (amanah) — bukan pendapatan koperasi dan tidak memengaruhi Sisa Hasil Usaha '
                .'(PSAK 109 jo. Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000).',
            'danaSyirkahTemporer' => 'Simpanan berjangka/mudharabah disajikan sebagai Dana Syirkah Temporer '
                .'(bukan kewajiban) karena koperasi tidak berkewajiban mengembalikan pokok sebelum '
                .'tersedianya likuiditas, dan risiko melekat pada pemilik dana.',
            'batasZakat' => 'Penyaluran dana zakat hanya kepada 8 asnaf (QS At-Taubah 60).',
        ];
    }

    /**
     * Rincian pos turunan yang tidak dapat dibaca langsung dari Neraca:
     * piutang pembiayaan per akad, CKPN, margin ditangguhkan, dan kas likuid.
     *
     * @return array<string, mixed>
     */
    public function catatanRincian(array $periode): array
    {
        $sampai = $periode['sampaiStr'];
        $dari = $periode['dariStr'];

        $piutang = collect($this->akunPiutang())->map(fn (array $baris): array => [
            'akad' => $baris['akad'],
            'kode' => $baris['kode'],
            'nama' => Akun::nama($baris['kode']) ?? $baris['kode'],
            'saldo' => $this->saldoAkun($baris['kode'], $sampai),
            'mutasi' => $this->mutasiAkun($baris['kode'], $dari, $sampai),
        ])->values();

        return [
            'piutang' => $piutang,
            'totalPiutang' => Setting::bulatkanUang((float) $piutang->sum('saldo')),
            'ckpn' => [
                'kode' => Akun::CKPN_PIUTANG_PEMBIAYAAN,
                'nama' => Akun::nama(Akun::CKPN_PIUTANG_PEMBIAYAAN) ?? 'CKPN Piutang Pembiayaan',
                'saldo' => $this->saldoAkun(Akun::CKPN_PIUTANG_PEMBIAYAAN, $sampai),
                'mutasi' => $this->mutasiAkun(Akun::CKPN_PIUTANG_PEMBIAYAAN, $dari, $sampai),
                'persen' => Setting::persenCkpn(),
            ],
            'marginDitangguhkan' => [
                'kode' => Akun::MARGIN_DITANGGUHKAN,
                'nama' => Akun::nama(Akun::MARGIN_DITANGGUHKAN) ?? 'Margin Murabahah Ditangguhkan',
                'saldo' => $this->saldoAkun(Akun::MARGIN_DITANGGUHKAN, $sampai),
                'mutasi' => $this->mutasiAkun(Akun::MARGIN_DITANGGUHKAN, $dari, $sampai),
            ],
            'kas' => collect(Akun::AKUN_KAS_ARUS_KAS)->map(fn (string $kode): array => [
                'kode' => $kode,
                'nama' => Akun::nama($kode) ?? $kode,
                'saldo' => $this->saldoAkun($kode, $sampai),
            ])->values(),
        ];
    }

    /**
     * Laporan Sumber & Penggunaan Dana Zakat untuk CaLK (PSAK 109).
     *
     * Z-09: laporan ini sebelumnya hanya tersedia di halaman modul zakat.
     * Pemangku kepentingan eksternal — DPS, pembina, pengawas — tidak pernah
     * memperoleh pengungkapan dana titipan yang memadai di dalam bundel
     * laporan keuangan syariah. Maka ringkasan ini disisipkan sebagai catatan
     * 6.g CaLK.
     *
     * Tiga sumber angka dengan tiga tingkat kehati-hatian:
     *
     *   1. Saldo & mutasi diambil dari JURNAL (`Coa::totalKredit/totalDebet`
     *      + `mutasiAkun()`), konsisten dengan komponen DIT, agar
     *      CaLK tidak mungkin menyajikan angka berbeda dari Laporan Perubahan DIT
     *      untuk baris Dana Zakat yang sama.
     *   2. Rincian per jenis zakat & per asnaf diambil dari buku besar modul
     *      (`zakat_transaksis`) — mustahil diturunkan dari jurnal karena jurnal
     *      hanya memuat akun, bukan kategori mustahik.
     *   3. Selisih antara keduanya dihitung dan diuji terhadap
     *      `Setting::toleransiSelisihJurnal()`; selisih tidak ditutup, tetapi
     *      diekspos sebagai temuan CaLK.
     *
     * Rekonsiliasi hak amil memakai parameter kebijakan
     * (`Setting::zakatPorsiAmilPersen()`), tidak angka tetap, sesuai Fatwa
     * DSN-MUI No. 08/DSN-MUI/IV/2000 yang membatasi amil maksimal 1/8.
     *
     * @return array<string, mixed>
     */
    public function laporanZakat(array $periode): array
    {
        $dari = $periode['dariStr'];
        $sampai = $periode['sampaiStr'];
        $hariSebelum = $this->hariSebelum($dari);

        // Akun DIT Dana Zakat — resolver yang sama dipakai ZakatService (Z-05).
        $kode = $this->akunDit()['dana_zakat'] ?? Akun::DANA_ZAKAT;
        $coa = Coa::findByKode($kode);

        $akunTersedia = $coa !== null;

        // (1) Saldo & mutasi dari jurnal.
        $saldoAwal = $akunTersedia
            ? Setting::bulatkanUang((float) $coa->totalKredit($hariSebelum) - (float) $coa->totalDebet($hariSebelum))
            : 0.0;
        $mutasi = $this->mutasiAkun($kode, $dari, $sampai);
        $penerimaan = $mutasi['kredit'];
        $penyaluran = $mutasi['debet'];
        $saldoAkhir = $akunTersedia
            ? Setting::bulatkanUang((float) $coa->totalKredit($sampai) - (float) $coa->totalDebet($sampai))
            : 0.0;

        $toleransi = Setting::toleransiSelisihJurnal();
        $mutasiSeimbang = abs(Setting::bulatkanUang($saldoAwal + $penerimaan - $penyaluran) - $saldoAkhir) <= $toleransi;

        // (2) Rincian dari buku besar modul — dikunci ke DANA_ZAKAT agar
        // infak/sedekah tidak ikut tercampur: keduanya memakai tabel yang sama
        // dengan kode akun dana yang berbeda.
        $bukuBesar = ZakatTransaksi::query()
            ->where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai);

        $perKategori = (clone $bukuBesar)
            ->where('jenis', ZakatTransaksi::JENIS_PENERIMAAN)
            ->selectRaw('kategori, COALESCE(SUM(jumlah), 0) AS total')
            ->groupBy('kategori')
            ->pluck('total', 'kategori');

        $perAsnaf = (clone $bukuBesar)
            ->where('jenis', ZakatTransaksi::JENIS_PENYALURAN)
            ->selectRaw('kategori, COALESCE(SUM(jumlah), 0) AS total')
            ->groupBy('kategori')
            ->pluck('total', 'kategori');

        $totalPenerimaan = Setting::bulatkanUang((float) $perKategori->sum());
        $totalPenyaluran = Setting::bulatkanUang((float) $perAsnaf->sum());

        $rincianPenerimaan = collect(ZakatTransaksi::KATEGORI_PENERIMAAN)
            ->map(fn (string $label, string $kategori): array => [
                'kode' => $kategori,
                'label' => $label,
                'jumlah' => Setting::bulatkanUang((float) ($perKategori[$kategori] ?? 0)),
            ])
            ->values();

        // Sisanya (kategori di luar daftar baku) tetap disajikan sebagai baris
        // tersendiri: angka yang tak dikenal lebih berbahaya daripada angka yang
        // tidak rapi — dibungkus "Kategori lain" membuat selisih tetap terlihat
        // oleh pembaca laporan.
        $sisaPenerimaan = Setting::bulatkanUang($totalPenerimaan - Setting::bulatkanUang((float) $rincianPenerimaan->sum('jumlah')));

        if ($sisaPenerimaan !== 0.0) {
            $rincianPenerimaan->push([
                'kode' => '__lainnya',
                'label' => 'Kategori lain di luar daftar baku',
                'jumlah' => $sisaPenerimaan,
            ]);
        }

        $rincianPenyaluran = collect(ZakatService::ASNAF)
            ->map(fn (string $label, string $asnaf): array => [
                'kode' => $asnaf,
                'label' => $label,
                'jumlah' => Setting::bulatkanUang((float) ($perAsnaf[$asnaf] ?? 0)),
                'persen' => $totalPenyaluran > 0
                    ? Setting::bulatkanUang((float) ($perAsnaf[$asnaf] ?? 0) / $totalPenyaluran * 100)
                    : 0.0,
            ])
            ->values();

        $sisaPenyaluran = Setting::bulatkanUang($totalPenyaluran - Setting::bulatkanUang((float) $rincianPenyaluran->sum('jumlah')));

        if ($sisaPenyaluran !== 0.0) {
            $rincianPenyaluran->push([
                'kode' => '__lainnya',
                'label' => 'Asnaf/kategori lain di luar 8 golongan',
                'jumlah' => $sisaPenyaluran,
                'persen' => $totalPenyaluran > 0 ? Setting::bulatkanUang($sisaPenyaluran / $totalPenyaluran * 100) : 0.0,
            ]);
        }

        // (3) Rekonsiliasi jurnal vs buku besar modul + integritas asnaf.
        $selisihPenerimaan = Setting::bulatkanUang($totalPenerimaan - $penerimaan);
        $selisihPenyaluran = Setting::bulatkanUang($totalPenyaluran - $penyaluran);

        $asnafDiLuar = ZakatTransaksi::query()
            ->where('dana', ZakatTransaksi::DANA_ZAKAT)
            ->where('jenis', ZakatTransaksi::JENIS_PENYALURAN)
            ->whereDate('tanggal', '>=', $dari)
            ->whereDate('tanggal', '<=', $sampai)
            ->whereNotIn('kategori', array_keys(ZakatService::ASNAF))
            ->count();

        $tanpaJurnal = (clone $bukuBesar)
            ->where(function ($q) {
                $q->whereNull('jurnal_id')->orWhereDoesntHave('jurnal');
            })
            ->count();

        // Rekonsiliasi hak amil (Fatwa DSN-MUI 08 — amil maksimal 1/8).
        $hakAmil = [
            'persen' => Setting::zakatPorsiAmilPersen(),
            'dasar' => $penerimaan,
            'hak' => Setting::zakatHakAmil($penerimaan),
            'realisasi' => Setting::bulatkanUang((float) ($perAsnaf['amil'] ?? 0)),
        ];
        $hakAmil['sisa'] = Setting::bulatkanUang($hakAmil['hak'] - $hakAmil['realisasi']);
        $hakAmil['terpakai'] = $hakAmil['realisasi'] > $toleransi;

        return [
            'akun' => $kode,
            'akunTersedia' => $akunTersedia,
            'saldoAwal' => $saldoAwal,
            'penerimaan' => $penerimaan,
            'penyaluran' => $penyaluran,
            'saldoAkhir' => $saldoAkhir,
            'mutasiSeimbang' => $mutasiSeimbang,
            'rincianPenerimaan' => $rincianPenerimaan,
            'rincianPenyaluran' => $rincianPenyaluran,
            'totalRincianPenerimaan' => $totalPenerimaan,
            'totalRincianPenyaluran' => $totalPenyaluran,
            'amil' => $hakAmil,
            'kontrol' => [
                'selisihPenerimaan' => $selisihPenerimaan,
                'selisihPenyaluran' => $selisihPenyaluran,
                'selaras' => abs($selisihPenerimaan) <= $toleransi && abs($selisihPenyaluran) <= $toleransi,
                'asnafDiLuar' => $asnafDiLuar,
                'tanpaJurnal' => $tanpaJurnal,
            ],
        ];
    }

    /**
     * Paket lengkap kelima komponen + CaLK, siap dikirim ke view.
     *
     * @return array<string, mixed>
     */
    public function semuaKomponen(array $periode): array
    {
        return [
            'periode' => $periode,
            'posisi' => $this->posisiKeuangan($periode),
            'labaRugi' => $this->labaRugi($periode),
            'perubahanEkuitas' => $this->perubahanEkuitas($periode),
            'arusKas' => $this->arusKas($periode),
            'dit' => $this->perubahanDanaInvestasiTerikat($periode),
            'kepatuhan' => $this->kepatuhan($periode),
            'kebijakan' => $this->kebijakanAkuntansi(),
            'catatan' => $this->catatanRincian($periode),
            'diagnostik' => $this->deteksiArusKasTakTerlacak($periode),
            'zakat' => $this->laporanZakat($periode),
        ];
    }

    /* =====================================================================
     | UTILITAS
     ===================================================================== */

    /**
     * Jumlahkan saldo berjalan sekumpulan akun dengan pembulatan per akun.
     *
     * Dibulatkan dua tahap (per akun, lalu total) agar tidak muncul selisih
     * palsu satu rupiah antar komponen laporan.
     *
     * Memakai `saldoBerjalanLaporan()` — jurnal penutup dikecualikan — supaya
     * periode yang sudah ditutup buku tetap menyajikan pendapatan & beban
     * riil, dan laba yang sama tidak dihitung dua kali (3-9000 + Laba Berjalan).
     *
     * @param  bool  $kecualikanPenutup  false = ikutkan efek jurnal penutup.
     *                                   Dipakai uji identitas buku (diagnostik
     *                                   arus kas) yang harus mencerminkan seluruh
     *                                   mutasi apa adanya, bukan penyajian laporan.
     */
    public function jumlahPresisi(Collection $akun, ?string $sampai, bool $kecualikanPenutup = true): float
    {
        return Setting::bulatkanUang((float) $akun->sum(fn (Coa $c) => Setting::bulatkanUang(
            $kecualikanPenutup
                ? (float) $c->saldoBerjalanLaporan($sampai)
                : (float) $c->saldoBerjalan($sampai)
        )));
    }

    /**
     * Kelompokkan akun ke klasifikasi penyajian dan hitung subtotalnya.
     *
     * Kelompok di luar taksonomi tetap disajikan di akhir: akun tidak boleh
     * menghilang dari laporan hanya karena klasifikasinya belum dipetakan.
     *
     * @param  list<string>  $urutan
     * @return array<int, array{label: string, akun: Collection, subtotal: float}>
     */
    public function kelompokkan(Collection $akun, array $urutan, ?string $sampai): array
    {
        $grup = $akun->groupBy(fn (Coa $c) => $c->klasifikasi_neraca ?: 'Belum Diklasifikasi');
        $hasil = [];

        foreach ($urutan as $label) {
            if (! $grup->has($label)) {
                continue;
            }
            $isi = $grup->get($label);
            $hasil[] = [
                'label' => $label,
                'akun' => $isi->values(),
                'subtotal' => $this->jumlahPresisi($isi, $sampai),
            ];
        }

        foreach ($grup as $label => $isi) {
            if (in_array($label, $urutan, true)) {
                continue;
            }
            $hasil[] = [
                'label' => $label,
                'akun' => $isi->values(),
                'subtotal' => $this->jumlahPresisi($isi, $sampai),
            ];
        }

        return $hasil;
    }

    /**
     * Baris akun siap tampil (kode, nama, klasifikasi, saldo) untuk L/R.
     *
     * @return Collection<int, array{kode: string, nama: string, klasifikasi: ?string, saldo: float}>
     */
    private function barisAkun(Collection $akun, ?string $sampai): Collection
    {
        return $akun->map(fn (Coa $c): array => [
            'kode' => $c->kode_akun,
            'nama' => $c->nama_akun,
            'klasifikasi' => $c->klasifikasi_neraca,
            'saldo' => Setting::bulatkanUang((float) $c->saldoBerjalanLaporan($sampai)),
        ])->values();
    }

    /**
     * Saldo akun sebelum periode berjalan, dihitung dari sisa setelah
     * dikurangi mutasi akumulatif periode yang tercatat di registri.
     *
     * Memakai BASIS LAPORAN (`saldoBerjalanLaporan()`) agar konsisten dengan
     * `saldoAkun()`, `barisAkun()`, dan `jumlahPresisi()` — semuanya
     * mengecualikan jurnal penutup. Arah pengurangan mengikuti POSISI akun:
     * akun bersaldo normal debet mengurangi debet, akun kredit mengurangi
     * kredit. Tanpa pembedaan posisi, akun kewajiban/ekuitas/pendapatan
     * menghasilkan saldo awal yang berlawanan tanda.
     */
    private function saldoAwalPeriode(string $kode, float $saldoAwal, float $debet, float $kredit): float
    {
        $coa = Coa::findByKode($kode);

        if (! $coa) {
            return 0.0;
        }

        $saldoAkhir = (float) $coa->saldoBerjalanLaporan();

        return Setting::bulatkanUang(
            $coa->posisiSaldo() === 'Debet'
                ? $saldoAkhir - $debet + $kredit
                : $saldoAkhir - $kredit + $debet
        );
    }

    /** Tanggal H-1 dari sebuah tanggal (Y-m-d). */
    private function hariSebelum(string $tanggal): string
    {
        try {
            return Carbon::parse($tanggal)->subDay()->toDateString();
        } catch (\Throwable $e) {
            // Tanggal dianggap tidak memiliki H-1; kembalikan apa adanya
            // tetapi catat sebabnya agar salah format tidak lewat begitu saja.
            Log::warning('LaporanKeuanganSyariahService: tanggal tidak dapat diurai untuk H-1.', [
                'tanggal' => $tanggal,
                'error' => $e->getMessage(),
            ]);

            return $tanggal;
        }
    }
}
