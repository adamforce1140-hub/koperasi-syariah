<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\AuditLog;
use App\Models\Coa;
use App\Models\DanaKebajikan;
use App\Models\DpsOpiniCatatan;
use App\Models\JenisSimpanan;
use App\Models\Jurnal;
use App\Models\KebijakanKoperasi;
use App\Models\LimitTransaksiRole;
use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\PengajuanPembiayaan;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\ShuCalculatorService;
use App\Services\TahunBukuService;
use App\Support\HierarkiRole;
use App\Support\Izin;
use App\Support\JenisTransaksi;
use App\Support\NomorDokumen;
use App\Support\RentangPeriode;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PengurusController extends Controller
{
    public function index()
    {
        /*
         | Grafik (dataGrafik) hanya dihitung pada render penuh. `ringkas()`
         | tidak memanggilnya agar mesin denyut tidak membebani server dengan
         | ~19 kueri count tiap polling.
         */
        return view('dashboard.pengurus', array_merge($this->data(), $this->dataGrafik()));
    }

    /**
     * Potongan HTML yang disegarkan mesin denyut (real-time tanpa reload penuh).
     *
     * Mengembalikan hanya potongan yang terdampak sehingga bandwith kecil dan
     * latensi rendah (< 2 detik). Setiap kunci pada `html` dipetakan ke atribut
     * `data-denyut-sasaran` pada view dashboard.
     */
    public function ringkas(Request $request): JsonResponse
    {
        $data = $this->data();

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.pengurus-stats', $data)->render(),
                'hold' => view('dashboard.partials.pengurus-hold', $data)->render(),
                'validasi' => view('dashboard.partials.pengurus-validasi', $data)->render(),
                'riwayat' => view('dashboard.partials.pengurus-riwayat', $data)->render(),
                'pengajuan' => view('dashboard.partials.pengurus-pengajuan', $data)->render(),
                'transaksi' => view('dashboard.partials.pengurus-transaksi', $data)->render(),
                'jurnal' => view('dashboard.partials.pengurus-jurnal', $data)->render(),
                'limit' => view('dashboard.partials.pengurus-limit', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /**
     * Satu sumber data untuk index() dan ringkas() — mencegah angka dashboard
     * berbeda antara render penuh dan render potongan (real-time).
     */
    private function data(): array
    {
        // Statistik keseluruhan
        $totalAnggota = Anggota::count();

        /*
         | Pembiayaan berjalan (aktif / cair / disetujui / restrukturisasi).
         |
         | Memakai Pembiayaan::statusBerjalan() — satu sumber kebenaran, bukan
         | daftar status lokal. Sebelumnya daftar lokal hanya memuat
         | aktif+disetujui+dicairkan sehingga 'restrukturisasi' ikut hilang dan
         | kartu "Pembiayaan Aktif" menampilkan 4 dari 5 kontrak yang berjalan.
         */
        $pembiayaanBerjalan = Pembiayaan::berjalan();
        $totalPembiayaanAktif = $pembiayaanBerjalan->count();

        /*
         | Validasi konsistensi: setiap pengajuan yang sudah disetujui wajib
         | sudah punya record Pembiayaan di tabel pembiayaan. Bila ada selisih,
         | dashboard menandainya agar tidak tampak "normal".
         */
        $pengajuanBelumSinkron = PengajuanPembiayaan::belumSinkron()->count();

        $totalPengajuan = Pembiayaan::statusIn([
            Pembiayaan::STATUS_PENDING_AO,
            Pembiayaan::STATUS_PENDING_MANAJER,
            Pembiayaan::STATUS_PENDING_PENGURUS,
            Pembiayaan::STATUS_PENDING_DPS,
        ])->count()
            + PengajuanPembiayaan::statusIn([PengajuanPembiayaan::STATUS_MENUNGGU])->count();

        // Rentang eksplisit (sargable) — whereMonth/whereYear membungkus kolom
        // dengan fungsi sehingga indeks waktu_transaksi tidak terpakai.
        $totalTransaksiBulanIni = RentangPeriode::padaBulan(
            Transaksi::query(),
            'waktu_transaksi',
            (int) now()->month,
            (int) now()->year
        )->count();

        // Riwayat approval transaksi (siapa yang menyetujui)
        $riwayatApproval = ApprovalTransaksi::with(['pengaju', 'approver'])
            ->whereIn('status', ['APPROVED', 'REJECTED'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        /*
         | Pengajuan pembiayaan terbaru.
         |
         | Dibatasi hanya transaksi pembiayaan yang benar-benar ada di tabel
         | pembiayaan, supaya baris dashboard tidak menampilkan pengajuan yang
         | belum tersinkron (mis. masih menggantung tanpa kontrak).
         */
        $pengajuanTerbaru = (clone $pembiayaanBerjalan)
            ->with(['anggota', 'approvedBy', 'approvedFinalBy'])
            ->latest()
            ->limit(8)
            ->get();

        /*
         | ── Ringkasan penahanan SHU (HOLD) ────────────────────────────
         | Anggota yang masih memiliki tagihan Simpanan Wajib OVERDUE pada
         | akhir tahun buku berjalan tidak menerima pencairan SHU-nya
         | (kebijakan `shu_hold_on_overdue`). Sumber data tunggal:
         | ShuCalculatorService::ringkasanPenahanan() — dihitung real-time
         | dari tagihan OVERDUE, sehingga angka dashboard tidak pernah
         | menyimpang dari hasil kalkulasi SHU sebenarnya.
         */
        // Tahun buku mengikuti kebijakan koperasi, bukan tahun takwim. Untuk
        // tahun buku Juli–Juni, Januari 2026 masih tahun buku 2025; memakai
        // `now()->year` membuat penahanan SHU dihitung pada periode yang salah.
        $tahunBukuBerjalan = app(TahunBukuService::class)->tahunBerjalan();
        $kalkulatorShu = app(ShuCalculatorService::class);
        $ringkasanHold = $kalkulatorShu->ringkasanPenahanan($tahunBukuBerjalan);

        // Satu kueri agregat untuk seluruh anggota ber-HOLD (bukan per baris).
        $tunggakanPerHold = $kalkulatorShu->tunggakanPerAnggota(
            $ringkasanHold->pluck('anggota_id')->all()
        );

        $daftarHold = $ringkasanHold->map(function (array $r) use ($tunggakanPerHold) {
            $tunggakan = $tunggakanPerHold[$r['anggota_id']] ?? collect();

            return [
                'anggota_id' => $r['anggota_id'],
                'nama_anggota' => $r['nama_anggota'],
                'no_anggota' => $r['no_anggota'],
                'jumlah_tunggakan' => $tunggakan->count(),
                'total_tunggakan' => Setting::bulatkanUang((float) $tunggakan->sum('amount')),
                'periode_awal' => optional($tunggakan->first())->bill_period,
                'periode_akhir' => optional($tunggakan->last())->bill_period,
                'hold_reason' => $r['hold_reason'] ?? null,
            ];
        })->sortByDesc('total_tunggakan')->values();

        $jumlahAnggotaHold = $daftarHold->count();
        $totalTunggakanHold = Setting::bulatkanUang((float) $daftarHold->sum('total_tunggakan'));

        /* SHU yang tertahan pada periode yang sudah diproses (nilai riil dari
         | distribusi), bukan estimasi — inilah kewajiban koperasi (2-1004). */
        $totalShuHold = Setting::bulatkanUang((float) ShuDistribution::hold()
            ->whereHas('period', fn ($q) => $q->where('tahun_buku', 'like', $tahunBukuBerjalan.'%'))
            ->sum('total_shu'));

        /*
         | ══════════════════════════════════════════════════════════════════
         | KONTEN MIGRASI DARI DASHBOARD "PENGELOLA" (duplikat yang dihapus)
         | Grafik, tabel transaksi/jurnal, dan limit transaksi per role
         | dipindahkan ke sini agar menjadi satu dashboard Pengurus saja.
         | ══════════════════════════════════════════════════════════════════
         */

        /*
         | ══════════════════════════════════════════════════════════════════
         | CATATAN PERFORMA
         | Kalkulasi grafik (6 bulan & 7 hari, ~19 kueri count) DIPISAH ke
         | dataGrafik() dan HANYA dipanggil index(). Grafik dirender sekali oleh
         | Chart.js dan bukan area mesin denyut, sehingga ringkas() tidak perlu
         | menghitung ulang tiap polling (3-10 detik).
         | ══════════════════════════════════════════════════════════════════
         */

        $transaksiTerakhir = Transaksi::with(['rekening.anggota', 'anggota'])
            ->latest('waktu_transaksi')->limit(10)->get()
            ->each(fn ($t) => $t->namaAnggota = $t->rekening?->anggota?->nama_lengkap ?? $t->anggota?->nama_lengkap ?? '-');

        $jurnalTerbaru = Jurnal::with('createdBy')->latest('tanggal_jurnal')->limit(10)->get();
        $limitTransaksi = LimitTransaksiRole::all();

        return [
            'totalAnggota' => $totalAnggota,
            'totalPembiayaanAktif' => $totalPembiayaanAktif,
            'totalPengajuan' => $totalPengajuan,
            'totalTransaksiBulanIni' => $totalTransaksiBulanIni,
            'riwayatApproval' => $riwayatApproval,
            'pengajuanTerbaru' => $pengajuanTerbaru,
            'pengajuanBelumSinkron' => $pengajuanBelumSinkron,
            'jumlahAnggotaHold' => $jumlahAnggotaHold,
            'totalShuHold' => $totalShuHold,
            'totalTunggakanHold' => $totalTunggakanHold,
            'daftarHold' => $daftarHold,
            'transaksiTerakhir' => $transaksiTerakhir,
            'jurnalTerbaru' => $jurnalTerbaru,
            'limitTransaksi' => $limitTransaksi,
        ];
    }

    /**
     * Data grafik ringkasan — hanya dibutuhkan saat render penuh (index()).
     *
     * Dipisah dari data() agar endpoint real-time `ringkas()` tidak ikut
     * menghitung ~19 kueri count pada setiap polling; grafik dirender sekali
     * oleh Chart.js dan bukan target penyegaran mesin denyut.
     */
    private function dataGrafik(): array
    {
        // Bar chart — pembiayaan vs angsuran, 6 bulan terakhir (sargable).
        $bulanLabels = [];
        $dataPembiayaan = [];
        $dataAngsuran = [];
        for ($i = 5; $i >= 0; $i--) {
            $bulan = now()->subMonths($i);
            $bulanLabels[] = $bulan->format('M Y');

            $dataPembiayaan[] = RentangPeriode::padaBulan(
                Pembiayaan::query(),
                'created_at',
                (int) $bulan->month,
                (int) $bulan->year
            )->count();

            $dataAngsuran[] = RentangPeriode::padaBulan(
                Angsuran::query(),
                'created_at',
                (int) $bulan->month,
                (int) $bulan->year
            )->count();
        }

        // Line chart — tren transaksi 7 hari terakhir (sargable).
        $hariLabels = [];
        $dataTransaksi = [];
        for ($i = 6; $i >= 0; $i--) {
            $hari = now()->subDays($i);
            $hariLabels[] = $hari->format('d/m');
            $dataTransaksi[] = RentangPeriode::padaHari(Transaksi::query(), 'waktu_transaksi', $hari)->count();
        }

        // Donut chart — status pembiayaan. normalizeStatus() mengembalikan
        // HURUF KECIL, sehingga legend/JS harus membaca kunci lowercase.
        // Diurutkan menurun agar potongan terbesar tampil lebih dulu.
        $statusCounts = [];
        foreach (Pembiayaan::selectRaw('status_pembiayaan, COUNT(*) as total')
            ->groupBy('status_pembiayaan')->get() as $row) {
            $key = Pembiayaan::normalizeStatus($row->status_pembiayaan) ?? 'lainnya';
            $statusCounts[$key] = ($statusCounts[$key] ?? 0) + (int) $row->total;
        }
        arsort($statusCounts);

        /*
         | Bendera JSON diperkuat agar aman ketika disisipkan ke dalam
         | <script> (view memakai {!! !!}). JSON_HEX_TAG/AMP/APOS/QUOT
         | mencegah "</script>" atau entitas HTML memutus konteks skrip
         | (pertahanan berlapis meski data dinilai tepercaya).
         */
        $benderaJson = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        /*
         | Legend donut dirender server-side dari SATU sumber metadata
         | (Pembiayaan::statusMeta()) agar warna potongan chart dan warna
         | titik legend selalu identik. Warna diturunkan dari kunci status
         | sehingga tidak ada lagi daftar 3 warna hardcode yang tidak sinkron.
         */
        $urutanStatus = array_keys($statusCounts);
        $labelStatus = [];
        $warnaStatus = [];
        foreach ($urutanStatus as $kunci) {
            $meta = Pembiayaan::statusMeta()[$kunci] ?? null;
            $labelStatus[] = $meta['label'] ?? ucwords(str_replace('_', ' ', $kunci));
            $warnaStatus[] = $meta['color'] ?? '#94a3b8';
        }

        return [
            'bulanLabels' => json_encode($bulanLabels, $benderaJson),
            'dataPembiayaan' => json_encode($dataPembiayaan, $benderaJson),
            'dataAngsuran' => json_encode($dataAngsuran, $benderaJson),
            'hariLabels' => json_encode($hariLabels, $benderaJson),
            'dataTransaksi' => json_encode($dataTransaksi, $benderaJson),
            'statusPembiayaan' => json_encode($statusCounts, $benderaJson),
            'urutanStatus' => $urutanStatus,
            'labelStatus' => $labelStatus,
            'warnaStatus' => json_encode($warnaStatus, $benderaJson),
        ];
    }

    public function simpanan()
    {
        // Rekening simpanan: pokok, wajib, sukarela
        $search = request('search');
        $jenis = request('jenis'); // simpanan_pokok | simpanan_wajib | simpanan_sukarela
        $jenisList = array_merge(
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_POKOK),
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_WAJIB),
            JenisSimpanan::aktifBertipe(JenisSimpanan::TIPE_SUKARELA)
        );
        if ($jenis && ! in_array($jenis, $jenisList, true)) {
            $jenis = null;
        }

        $query = Rekening::with('anggota')->latest();
        if ($jenis) {
            $query->where('jenis_rekening', $jenis);
        }
        if ($search) {
            $query->whereHas('anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%")
            );
        }

        $rekening = $query->paginate(25)->withQueryString();

        /* X-4 — agregat saldo hanya menghitung rekening AKTIF.
         | Rekening `Ditutup` (anggota exit), `Jatuh Tempo`, dan `Dibekukan`
         | masih berjejak di tabel `rekening`; bila ikut dijumlahkan, angka
         | total simpanan melebihi kewajiban riil di GL dan membingungkan
         | pengurus. Daftar rekening di bawah tetap menampilkan seluruh baris
         | agar rekening non-aktif masih bisa diaudit. */
        $totalSaldo = Rekening::whereIn('jenis_rekening', $jenisList)->aktif()->sum('saldo');
        $counterJenis = Rekening::selectRaw('jenis_rekening, COUNT(*) as jumlah, SUM(saldo) as total')
            ->whereIn('jenis_rekening', $jenisList)
            ->aktif()
            ->groupBy('jenis_rekening')
            ->get()->keyBy('jenis_rekening');

        return view('pengurus.simpanan', compact(
            'rekening', 'totalSaldo', 'counterJenis', 'jenisList',
            'jenis', 'search'
        ));
    }

    public function angsuran()
    {
        $search = request('search');
        $akadFilter = request('akad');
        $statusFilter = request('status'); // belum | sudah | terlambat
        $akadList = Pembiayaan::daftarAkad();
        if ($akadFilter && ! in_array($akadFilter, $akadList, true)) {
            $akadFilter = null;
        }

        /*
         | Kontrak yang punya jadwal angsuran: seluruh pembiayaan berjalan
         | (termasuk 'restrukturisasi') ditambah yang sudah lunas sebagai riwayat.
         | Sumber status tunggal dari model, bukan daftar lokal.
         */
        $statusBerjalan = array_merge(
            Pembiayaan::statusBerjalan(),
            [Pembiayaan::STATUS_LUNAS]
        );

        $query = Pembiayaan::with([
            'anggota',
            'angsuran' => fn ($q) => $q->orderBy('angsuran_ke'),
        ])
            ->statusIn($statusBerjalan)
            ->latest();

        if ($akadFilter) {
            $query->where('akad_pembiayaan', $akadFilter);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pembiayaan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$search}%")
                    );
            });
        }
        /*
         | Filter tunggakan WAJIB sejalan dengan kartu statistik di bawah.
         | Sebelumnya filter memakai kolom `status_angsuran`, sedangkan kartu
         | memakai tanggal sungguhan — sehingga tabel dan kartu pada halaman
         | yang sama bisa saling bertentangan. Kini keduanya memakai acuan
         | yang sama: `tanggal_bayar` (null = belum dibayar) + `tanggal_jatuh_tempo`.
         */
        $today = Carbon::today();

        if ($statusFilter === 'terlambat') {
            $query->whereHas('angsuran', fn ($q) => $q
                ->whereNull('tanggal_bayar')
                ->where('tanggal_jatuh_tempo', '<', $today)
            );
        } elseif ($statusFilter === 'belum') {
            $query->whereHas('angsuran', fn ($q) => $q
                ->whereNull('tanggal_bayar')
                ->where('tanggal_jatuh_tempo', '>=', $today)
            );
        } elseif ($statusFilter === 'lunas') {
            $query->statusIn([Pembiayaan::STATUS_LUNAS]);
        }

        $pembiayaan = $query->paginate(20)->withQueryString();

        // Pembiayaan aktif = kontrak yang masih berjalan (sumber status tunggal).
        $totalAktif = Pembiayaan::berjalan()->count();
        $totalSisaTagihan = Pembiayaan::berjalan()->sum('sisa_pembiayaan');

        // Statistik angsuran global (real-time berbasis Carbon, bukan kolom status_angsuran yang stale)
        $totalAngsuranTerlambat = Angsuran::whereNull('tanggal_bayar')
            ->where('tanggal_jatuh_tempo', '<', $today)
            ->count();
        $totalAngsuranBelum = Angsuran::whereNull('tanggal_bayar')
            ->where('tanggal_jatuh_tempo', '>=', $today)
            ->count();
        $totalAngsuranLunas = Angsuran::whereNotNull('tanggal_bayar')
            ->count();

        return view('pengurus.angsuran', compact(
            'pembiayaan', 'totalAktif', 'totalSisaTagihan',
            'akadFilter', 'akadList', 'search', 'statusFilter',
            'totalAngsuranBelum', 'totalAngsuranTerlambat', 'totalAngsuranLunas'
        ));
    }

    public function laporan()
    {
        // Ringkasan keuangan untuk laporan: Neraca, L/R, PHU
        $bulan = (int) request('bulan', now()->month);
        $tahun = (int) request('tahun', now()->year);

        // Pemasukan & pengeluaran bulan berjalan — memakai registri tunggal
        // JenisTransaksi agar seluruh varian nama historis ikut terhitung.
        $pemasukanKeys = JenisTransaksi::cashIn();
        $pengeluaranKeys = JenisTransaksi::cashOut();

        $totalPemasukan = RentangPeriode::padaBulan(
            Transaksi::whereIn('jenis_transaksi', $pemasukanKeys),
            'waktu_transaksi', $bulan, $tahun
        )
            ->where('status_transaksi', 'Sukses')
            ->sum('jumlah');

        $totalPengeluaran = RentangPeriode::padaBulan(
            Transaksi::whereIn('jenis_transaksi', $pengeluaranKeys),
            'waktu_transaksi', $bulan, $tahun
        )
            ->where('status_transaksi', 'Sukses')
            ->sum('jumlah');

        /* X-4 — total simpanan anggota dihitung dari rekening aktif saja.
         | Rekening berstatus Ditutup/Jatuh Tempo/Dibekukan adalah jejak
         | historis (mis. sisa dari anggota yang sudah exit) dan TIDAK boleh
         | menambah kewajiban koperasi pada ringkasan pengurus. */
        $totalSimpanan = Rekening::aktif()->sum('saldo');

        // Total portofolio pembiayaan berjalan (sumber status tunggal)
        $totalPembiayaan = Pembiayaan::berjalan()->sum('jumlah_pembiayaan');

        // Trend bulanan 12 bulan terakhir (daftar nilai dari registri tunggal)
        $quoteSql = fn (array $list) => implode(',', array_map(
            fn ($v) => "'".str_replace("'", "''", $v)."'",
            $list
        ));
        $inSql = $quoteSql($pemasukanKeys);
        $outSql = $quoteSql($pengeluaranKeys);

        $trendBulanan = Transaksi::selectRaw(
            "MONTH(waktu_transaksi) as bulan, YEAR(waktu_transaksi) as tahun,
             SUM(CASE WHEN jenis_transaksi IN ($inSql) AND status_transaksi='Sukses' THEN jumlah ELSE 0 END) as pemasukan,
             SUM(CASE WHEN jenis_transaksi IN ($outSql) AND status_transaksi='Sukses' THEN jumlah ELSE 0 END) as pengeluaran"
        )->where('waktu_transaksi', '>=', now()->subMonths(11)->startOfMonth())
            ->groupByRaw('YEAR(waktu_transaksi), MONTH(waktu_transaksi)')
            ->orderByRaw('YEAR(waktu_transaksi), MONTH(waktu_transaksi)')
            ->get();

        return view('pengurus.laporan', compact(
            'totalPemasukan', 'totalPengeluaran', 'totalSimpanan',
            'totalPembiayaan', 'trendBulanan', 'bulan', 'tahun'
        ));
    }

    public function kas()
    {
        // Arus kas operasional: saldo rekening + transaksi harian
        $tglMulai = request('tgl_mulai', now()->startOfMonth()->toDateString());
        $tglSelesai = request('tgl_selesai', now()->toDateString());

        $transaksi = Transaksi::with(['anggota', 'pembiayaan.anggota', 'rekening.anggota', 'dibuatOleh'])
            ->whereDate('waktu_transaksi', '>=', $tglMulai)
            ->whereDate('waktu_transaksi', '<=', $tglSelesai)
            ->latest('waktu_transaksi')
            ->paginate(30)->withQueryString();

        // Ringkasan kas periode
        $ringkasan = Transaksi::selectRaw(
            'jenis_transaksi, COUNT(*) as jumlah_transaksi, SUM(jumlah) as total'
        )->whereDate('waktu_transaksi', '>=', $tglMulai)
            ->whereDate('waktu_transaksi', '<=', $tglSelesai)
            ->groupBy('jenis_transaksi')
            ->get()->keyBy('jenis_transaksi');

        // Agregasi memakai registri tunggal JenisTransaksi agar seluruh varian
        // nama historis (mis. "Setoran Simpanan Sukarela" vs "Simpanan Sukarela",
        // "Bayar Angsuran" vs "Pembayaran Angsuran") ikut terhitung.
        $sum = fn (array $keys) => (float) collect($keys)
            ->sum(fn ($k) => (float) ($ringkasan->get($k)->total ?? 0));
        $cnt = fn (array $keys) => (int) collect($keys)
            ->sum(fn ($k) => (int) ($ringkasan->get($k)->jumlah_transaksi ?? 0));

        $totalMasuk = $sum(JenisTransaksi::cashIn());
        $totalKeluar = $sum(JenisTransaksi::cashOut());

        $kartu = [
            ['label' => 'Setoran Tunai',        'color' => '#059669', 'total' => $sum([JenisTransaksi::SETORAN_TUNAI]),  'jumlah' => $cnt([JenisTransaksi::SETORAN_TUNAI])],
            ['label' => 'Simpanan Wajib',       'color' => '#0891b2', 'total' => $sum(JenisTransaksi::wajib()),          'jumlah' => $cnt(JenisTransaksi::wajib())],
            ['label' => 'Simpanan Pokok',       'color' => '#7c3aed', 'total' => $sum(JenisTransaksi::pokok()),          'jumlah' => $cnt(JenisTransaksi::pokok())],
            ['label' => 'Simpanan Sukarela',    'color' => '#0284c7', 'total' => $sum(JenisTransaksi::sukarela()),       'jumlah' => $cnt(JenisTransaksi::sukarela())],
            ['label' => 'Pembayaran Angsuran',  'color' => '#2563eb', 'total' => $sum(JenisTransaksi::angsuran()),       'jumlah' => $cnt(JenisTransaksi::angsuran())],
            ['label' => 'Penarikan Tunai',      'color' => '#dc2626', 'total' => $sum([JenisTransaksi::PENARIKAN_TUNAI]), 'jumlah' => $cnt([JenisTransaksi::PENARIKAN_TUNAI])],
            ['label' => 'Pencairan Pembiayaan', 'color' => '#d97706', 'total' => $sum([JenisTransaksi::PENCAIRAN_PEMBIAYAAN]), 'jumlah' => $cnt([JenisTransaksi::PENCAIRAN_PEMBIAYAAN])],
        ];

        // X-4 — sama: hanya rekening aktif yang merupakan kewajiban berjalan.
        $totalRekening = Rekening::aktif()->sum('saldo');

        return view('pengurus.kas', compact(
            'transaksi', 'ringkasan', 'totalRekening', 'tglMulai', 'tglSelesai',
            'totalMasuk', 'totalKeluar', 'kartu'
        ));
    }

    public function laporanSyariah()
    {
        $opinis = DpsOpiniCatatan::with('user')->latest()->paginate(20);

        return view('pengurus.laporan_syariah.index', compact('opinis'));
    }

    /**
     * Laporan Sumber & Penyaluran Dana Kebajikan (PSAK 109)
     * Akun 2-3001 — ta'zir TIDAK mempengaruhi pendapatan operasional / SHU
     */
    public function laporanDanaKebajikan(Request $request)
    {
        $dari = $request->dari ?? now()->startOfMonth()->toDateString();
        $sampai = $request->sampai ?? now()->toDateString();

        $query = DanaKebajikan::query()
            ->whereDate('created_at', '>=', $dari)
            ->whereDate('created_at', '<=', $sampai)
            ->orderBy('created_at');

        $transaksi = $query->get();

        $totalPenerimaan = $transaksi->where('jenis', 'Penerimaan')->sum('jumlah');
        $totalPenyaluran = $transaksi->where('jenis', '!=', 'Penerimaan')->sum('jumlah');

        // Pisah ta'zir vs infaq
        $totalTazir = $transaksi->where('jenis', 'Penerimaan')->filter(function ($r) {
            return str_contains(strtolower($r->sumber_dana ?? ''), "ta'zir") ||
                   str_contains(strtolower($r->keterangan ?? ''), 'denda') ||
                   str_contains(strtolower($r->no_referensi ?? ''), 'tazir');
        })->sum('jumlah');

        $totalInfaq = $transaksi->where('jenis', 'Penerimaan')->filter(function ($r) {
            return str_contains(strtolower($r->sumber_dana ?? ''), 'infaq') ||
                   str_contains(strtolower($r->sumber_dana ?? ''), 'sedekah') ||
                   str_contains(strtolower($r->keterangan ?? ''), 'infaq');
        })->sum('jumlah');

        // Saldo awal = semua penerimaan sebelum periode - semua penyaluran sebelum periode
        $penerimaanSebelum = DanaKebajikan::where('jenis', 'Penerimaan')
            ->whereDate('created_at', '<', $dari)->sum('jumlah');
        $penyaluranSebelum = DanaKebajikan::where('jenis', '!=', 'Penerimaan')
            ->whereDate('created_at', '<', $dari)->sum('jumlah');
        $saldoAwal = max(0, $penerimaanSebelum - $penyaluranSebelum);

        $saldoAkhir = $saldoAwal + $totalPenerimaan - $totalPenyaluran;

        return view('pengurus.laporan.dana-kebajikan', compact(
            'transaksi', 'dari', 'sampai',
            'saldoAwal', 'saldoAkhir',
            'totalPenerimaan', 'totalPenyaluran',
            'totalTazir', 'totalInfaq'
        ));
    }

    public function approval()
    {
        $search = request('search');
        $status = request('status');
        // Nilai filter UI tetap PENDING/APPROVED/REJECTED, tapi PENDING mencakup
        // seluruh status pending yang sebenarnya (diturunkan dari hierarki peran).
        $statusList = ['PENDING', 'APPROVED', 'REJECTED'];
        if ($status && ! in_array($status, $statusList, true)) {
            $status = null;
        }

        $query = ApprovalTransaksi::with(['transaksi.rekening.anggota', 'pengaju', 'approver'])->latest('updated_at');
        if ($status === 'PENDING') {
            $query->whereIn('status', HierarkiRole::semuaStatusMenunggu());
        } elseif ($status) {
            $query->where('status', $status);
        }
        if ($search) {
            $query->whereHas('transaksi.rekening.anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%")
            );
        }

        $approvals = $query->paginate(25)->withQueryString();
        $totalPending = ApprovalTransaksi::whereIn('status', HierarkiRole::semuaStatusMenunggu())->count();
        $totalApproved = ApprovalTransaksi::where('status', 'APPROVED')->count();
        $totalRejected = ApprovalTransaksi::where('status', 'REJECTED')->count();

        return view('pengurus.approval', compact(
            'approvals', 'totalPending', 'totalApproved', 'totalRejected',
            'search', 'status'
        ));
    }

    public function pembiayaan()
    {
        $search = request('search');
        $statusFilter = request('status');

        /*
         | Daftar status = satu sumber kebenaran.
         | Gabungan seluruh status berjalan + status yang sudah final ditolak,
         | supaya tidak ada kontrak yang tersembunyi hanya karena daftar lokal
         | tidak memuatnya (mis. 'aktif' dan 'restrukturisasi').
         */
        $statusList = array_values(array_unique(array_merge(
            Pembiayaan::statusBerjalan(),
            [Pembiayaan::STATUS_DITOLAK, Pembiayaan::STATUS_DIBATALKAN, Pembiayaan::STATUS_LUNAS]
        )));

        $statusFilter = Pembiayaan::normalizeStatus($statusFilter);
        if ($statusFilter && ! in_array($statusFilter, $statusList, true)) {
            $statusFilter = null;
        }

        /*
         | Tabel tanpa filter status default yang terlalu ketat: seluruh
         | pembiayaan berjalan langsung tampil. Filter status hanya aktif bila
         | pengguna memilihnya sendiri.
         */
        $query = Pembiayaan::with(['anggota'])
            ->statusIn($statusFilter ? [$statusFilter] : Pembiayaan::statusBerjalan())
            ->latest();

        if ($search) {
            $query->whereHas('anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%")
            );
        }

        $pengajuan = $query->paginate(25)->withQueryString();
        $totalMenunggu = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_PENGURUS])->count();
        $totalDisetujui = Pembiayaan::berjalan()->count();
        $totalDitolak = Pembiayaan::statusIn([Pembiayaan::STATUS_DITOLAK])->count();

        return view('pengurus.pembiayaan', compact(
            'pengajuan', 'totalMenunggu', 'totalDisetujui', 'totalDitolak',
            'statusList', 'search', 'statusFilter'
        ));
    }

    public function pembiayaanShow(int $id)
    {
        $item = Pembiayaan::with(['anggota', 'dokumen'])->findOrFail($id);

        return view('pengurus.pembiayaan-show', compact('item'));
    }

    public function pembiayaanApprove(Request $request, int $id)
    {
        $item = Pembiayaan::findOrFail($id);
        if (! $item->isStatus(Pembiayaan::STATUS_PENDING_PENGURUS)) {
            return redirect()->route('pengurus.pembiayaan.index')
                ->with('error', 'Pengajuan tidak dalam status menunggu approval pengurus.');
        }
        $item->update([
            'status_pembiayaan' => Pembiayaan::STATUS_PENDING_DPS,
            'disetujui_oleh' => auth()->id(),
            'tanggal_disetujui' => now(),
            'keterangan' => $request->input('catatan'),
        ]);

        return redirect()->route('pengurus.pembiayaan.index')
            ->with('success', 'Pengajuan berhasil disetujui dan diteruskan ke DPS untuk audit syariah.');
    }

    public function pembiayaanReject(Request $request, int $id)
    {
        $request->validate(['catatan_tolak' => 'required|string|max:500']);
        $item = Pembiayaan::findOrFail($id);

        // Hanya pengajuan yang masih menunggu keputusan Pengurus yang boleh
        // ditolak. Tanpa guard ini, pembiayaan yang sudah AKTIF / CAIR dapat
        // "ditolak" tanpa pembalikan jurnal & tanpa pembatalan jadwal angsuran.
        if (! $item->isStatus(Pembiayaan::STATUS_PENDING_PENGURUS)) {
            return redirect()->route('pengurus.pembiayaan.index')
                ->with('error', 'Pengajuan tidak dalam status menunggu approval pengurus sehingga tidak dapat ditolak.');
        }

        $item->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'keterangan' => $request->input('catatan_tolak'),
            'tanggal_disetujui' => now(),
        ]);

        return redirect()->route('pengurus.pembiayaan.index')
            ->with('success', 'Pengajuan pembiayaan telah ditolak.');
    }

    public function pengesahanShu(Request $request)
    {
        $tahun = (int) $request->input('tahun', now()->year);

        // Rentang tahun BUKU (bukan tahun takwim) — sumbernya sama dengan
        // ShuCalculatorService & ShuPeriod, sehingga koperasi dengan tahun
        // buku non-kalender tetap menghitung pendapatan pada rentang benar.
        $tahunBuku = app(TahunBukuService::class);
        $totalPendapatan = (float) Transaksi::where('jenis_transaksi', 'Pembayaran Angsuran')
            ->whereBetween('waktu_transaksi', [
                $tahunBuku->awalPeriode($tahun),
                $tahunBuku->akhirPeriode($tahun),
            ])
            ->sum('jumlah');

        // SSOT alokasi SHU adalah tabel `shu_periods` (jalur produksi
        // Pengurus\ShuController). Bila periode tahun ini sudah dibuat,
        // tampilkan alokasi yang BENAR-BENAR dipakai; bila belum, tampilkan
        // usulan draf yang bersumber dari kebijakan koperasi — sehingga kedua
        // sistem SHU tidak lagi terputus (temuan audit K-03).
        $periode = ShuPeriod::where('tahun_buku', (string) $tahun)
            ->orderByDesc('created_at')
            ->first();

        $pos = [
            'cadangan' => 'Dana Cadangan Koperasi',
            'jasa_modal' => 'SHU Jasa Modal Anggota',
            'jasa_transaksi' => 'SHU Jasa Transaksi',
            'pengurus' => 'Dana Pengurus & Karyawan',
            'sosial' => 'Dana Pendidikan & Sosial',
        ];

        $alokasi = [];

        if ($periode) {
            foreach ($pos as $kunci => $label) {
                $alokasi[] = [
                    'label' => $label,
                    'persen' => (float) $periode->{'persen_'.$kunci},
                    'nominal' => (float) $periode->{'nominal_'.$kunci},
                ];
            }
        } else {
            $persen = Setting::alokasiShuDrafDefault();

            foreach ($pos as $kunci => $label) {
                $p = (float) $persen['persen_'.$kunci];
                $alokasi[] = [
                    'label' => $label,
                    'persen' => $p,
                    'nominal' => Setting::bulatkanUang($totalPendapatan * $p / 100),
                ];
            }
        }

        $totalPersen = Setting::bulatkanUang(array_sum(array_column($alokasi, 'persen')));
        $totalNominal = Setting::bulatkanUang(array_sum(array_column($alokasi, 'nominal')));

        return view('pengurus.pengesahan-shu', compact(
            'tahun', 'totalPendapatan', 'alokasi', 'totalPersen', 'totalNominal', 'periode'
        ));
    }

    /**
     * Manajemen user, matriks hak akses, dan override batas operasional peran.
     *
     * Matriks PETA IZIN bersifat READ-ONLY: sumbernya `config/izin.php` (lewat
     * App\Support\Izin). Sengaja tidak ada tabel override izin agar peta
     * wewenang tetap satu sumber, dapat diaudit dari kode, dan tidak dapat
     * dilonggarkan diam-diam dari antarmuka.
     *
     * Yang dapat diubah dari halaman ini: `limit_transaksi_role` (angka
     * operasional) dan PERAN seorang pengguna (`ubahPeran`). Daftar peran
     * yang ditawarkan disaring `HierarkiRole::bolehMengubahPeran()` sehingga
     * peran puncak administratif tidak pernah muncul bagi aktor lain.
     */
    public function users(Request $request)
    {
        $users = User::latest()->paginate(15);

        // Kelompokkan izin per modul (awalan sebelum titik) agar matriks
        // mudah dibaca; nama izin diurutkan dalam tiap modul.
        $matriksIzin = [];

        foreach (Izin::peta() as $izin => $peran) {
            $modul = str_contains($izin, '.') ? explode('.', $izin, 2)[0] : 'umum';

            $matriksIzin[$modul][$izin] = array_values(array_map('strval', (array) $peran));
        }

        ksort($matriksIzin);

        foreach ($matriksIzin as $modul => $daftar) {
            ksort($matriksIzin[$modul]);
        }

        $peranTersedia = HierarkiRole::peran();
        $jenisTersedia = (array) (HierarkiRole::konfigurasi()['jenis_transaksi'] ?? []);

        // Peran yang boleh DITETAPKAN oleh aktor ini. Peran puncak
        // administratif hanya muncul bagi Super Admin (langkah #3).
        $aktor = $request->user()?->role;
        $peranDapatDitetapkan = array_values(array_filter(
            $peranTersedia,
            fn (string $p): bool => HierarkiRole::bolehMengubahPeran($aktor, $p)
        ));

        // Override batas operasional. Baris yang tidak ada di sini memakai
        // default `config/role_hierarki.php`.
        $limitTransaksi = LimitTransaksiRole::query()
            ->orderBy('role')
            ->orderBy('jenis_transaksi')
            ->get();

        // Nilai yang BENAR-BENAR berlaku: config → override → kolom kebijakan.
        // Ditampilkan berdampingan agar selisih (mis. teller yang limitnya
        // diatur dari Kebijakan Koperasi) tidak tampak sebagai kesalahan data.
        $batasEfektif = [];

        foreach ($peranTersedia as $peran) {
            $batasEfektif[$peran] = HierarkiRole::batasPeran($peran);
        }

        return view('pengurus.users', compact(
            'users',
            'matriksIzin',
            'peranTersedia',
            'peranDapatDitetapkan',
            'jenisTersedia',
            'limitTransaksi',
            'batasEfektif'
        ));
    }

    /** Tambah override batas operasional untuk satu pasangan (peran, jenis). */
    public function storeLimit(Request $request)
    {
        $data = $this->validasiLimit($request);

        LimitTransaksiRole::create($data);

        return redirect()->route('pengurus.users.index')
            ->with('success', 'Override limit '.HierarkiRole::label($data['role']).' — '
                .HierarkiRole::labelJenis($data['jenis_transaksi']).' berhasil ditambahkan dan langsung berlaku.');
    }

    /** Perbarui override batas operasional. */
    public function updateLimit(Request $request, LimitTransaksiRole $limit_role)
    {
        $data = $this->validasiLimit($request, $limit_role->id);

        $limit_role->update($data);

        return redirect()->route('pengurus.users.index')
            ->with('success', 'Override limit '.HierarkiRole::label($data['role']).' — '
                .HierarkiRole::labelJenis($data['jenis_transaksi']).' berhasil diperbarui.');
    }

    /** Hapus override — batas kembali mengikuti default konfigurasi. */
    public function destroyLimit(LimitTransaksiRole $limit_role)
    {
        $keterangan = HierarkiRole::label($limit_role->role).' — '
            .HierarkiRole::labelJenis($limit_role->jenis_transaksi);

        $limit_role->delete();

        return redirect()->route('pengurus.users.index')
            ->with('success', 'Override limit '.$keterangan
                .' dihapus; batas kembali mengikuti default konfigurasi.');
    }

    /**
     * Validasi override limit. `role`/`approval_role` tidak punya foreign key
     * (kolom VARCHAR bebas), jadi keanggotaannya diperiksa terhadap
     * HierarkiRole::peran(); jenis transaksi terhadap config yang sama dengan
     * yang dipakai mesin batas.
     *
     * @return array<string, mixed>
     */
    private function validasiLimit(Request $request, ?int $abaikanId = null): array
    {
        $peranSah = array_values(array_filter(
            HierarkiRole::peran(),
            fn (string $p): bool => HierarkiRole::bolehMengubahPeran($request->user()?->role, $p)
        ));
        $jenisSah = array_keys((array) (HierarkiRole::konfigurasi()['jenis_transaksi'] ?? []));

        $data = $request->validate([
            'role' => [
                'required',
                Rule::in($peranSah),
                Rule::unique('limit_transaksi_role', 'role')
                    ->where(fn ($query) => $query->where('jenis_transaksi', (string) $request->input('jenis_transaksi')))
                    ->ignore($abaikanId),
            ],
            'jenis_transaksi' => ['required', Rule::in($jenisSah)],
            'limit_per_transaksi' => 'nullable|numeric|min:0',
            'limit_harian' => 'nullable|numeric|min:0',
            'butuh_approval' => 'nullable|boolean',
            'approval_role' => ['nullable', Rule::in($peranSah)],
        ], [
            'role.required' => 'Peran wajib dipilih.',
            'role.in' => 'Peran tidak dikenal sistem.',
            'role.unique' => 'Pasangan peran dan jenis transaksi tersebut sudah punya override. Ubah baris yang ada alih-alih menambah baris baru.',
            'jenis_transaksi.required' => 'Jenis transaksi wajib dipilih.',
            'jenis_transaksi.in' => 'Jenis transaksi tidak dikenal sistem.',
            'limit_per_transaksi.numeric' => 'Limit per transaksi harus berupa angka.',
            'limit_per_transaksi.min' => 'Limit per transaksi tidak boleh negatif.',
            'limit_harian.numeric' => 'Limit harian harus berupa angka.',
            'limit_harian.min' => 'Limit harian tidak boleh negatif.',
            'approval_role.in' => 'Peran pemberi approval tidak dikenal sistem.',
        ]);

        $data['butuh_approval'] = $request->boolean('butuh_approval');

        // Tanpa kebutuhan approval, peran pemberi approval tidak bermakna —
        // simpan NULL agar tidak menyesatkan pembacaan berikutnya.
        if (! $data['butuh_approval']) {
            $data['approval_role'] = null;
        }

        return $data;
    }

    /**
     * Ubah peran (role) seorang pengguna dari halaman Kelola Pengguna & Role.
     *
     * LANGKAH #3 — PELINDUNG PUNCAK ADMINISTRATIF. Form web tidak pernah
     * menawarkan `super_admin` kepada peran lain (menutup JALUR), dan method
     * ini menolak permintaan yang menyasar pengguna berperan puncak (menutup
     * PINTU). Lapisan ketiga ada di model User (`booted`) sehingga request
     * yang melewati gerbang ini pun tetap tidak dapat dicabut.
     *
     * Setiap perubahan dicatat ke `audit_logs` (langkah #5) — siapa mengubah
     * siapa, dari peran apa, menjadi apa.
     */
    public function ubahPeran(Request $request, User $user)
    {
        $aktor = $request->user()?->role;

        // Pintu: hanya puncak yang boleh menyentuh pengguna berperan puncak.
        if (! HierarkiRole::bolehMengubahPeran($aktor, $user->role)) {
            abort(403, 'Hak akses Super Admin hanya dapat diubah oleh Super Admin.');
        }

        $saatIni = $request->user();

        $peranSah = array_values(array_filter(
            HierarkiRole::peran(),
            fn (string $p): bool => HierarkiRole::bolehMengubahPeran($aktor, $p)
        ));

        // Mengubah peran diri sendiri (apalagi turun) adalah jalur tanpa
        // pembalikan: setelahnya tidak ada lagi Super Admin yang tersisa.
        if ($saatIni !== null && $saatIni->id === $user->id && $request->input('role') !== $user->role) {
            return back()->with('error', 'Anda tidak dapat mengubah peran akun Anda sendiri.');
        }

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in($peranSah)],
        ], [
            'role.required' => 'Peran wajib dipilih.',
            'role.in' => 'Peran tersebut tidak boleh ditetapkan dari akun Anda.',
        ]);

        $peranLama = (string) $user->role;

        if ($peranLama === $data['role']) {
            return redirect()->route('pengurus.users.index')
                ->with('success', 'Peran '.$user->name.' tidak berubah.');
        }

        $user->update(['role' => $data['role']]);

        AuditLog::record(
            'role.ubah',
            0,
            null,
            'Peran pengguna #'.$user->id.' ('.$user->email.') diubah dari '
                .HierarkiRole::label($peranLama).' menjadi '.HierarkiRole::label($data['role'])
        );

        return redirect()->route('pengurus.users.index')
            ->with('success', 'Peran '.$user->name.' diubah menjadi '.HierarkiRole::label($data['role']).'.');
    }

    public function kebijakan(Request $request)
    {
        $kebijakan = KebijakanKoperasi::getSetting();
        $riwayat = KebijakanKoperasi::riwayatPerubahan(30);
        // Daftar akun CoA aktif — untuk memilih akun penampung ta'zir (opsional).
        $akunCoa = Coa::query()
            ->where('is_active', true)
            ->orderBy('kode_akun')
            ->get(['id', 'kode_akun', 'nama_akun']);

        // Pemetaan akad → CoA berbasis data (fleksibilitas PSAK).
        $pemetaanAkad = PemetaanAkadCoa::query()
            ->orderByRaw("CASE WHEN kode_produk = '*' THEN 0 ELSE 1 END")
            ->orderBy('akad_pembiayaan')
            ->get();

        $akadTersedia = PemetaanAkadCoa::daftarAkad();

        // Daftar produk pembiayaan untuk pemetaan akad→CoA per produk (Tahap 11).
        // Hanya produk ber-kode yang dapat dipetakan; produk tanpa kode otomatis
        // mengikuti pemetaan universal '*'.
        $produkPemetaan = MasterProdukPembiayaan::query()
            ->whereNotNull('kode_produk')
            ->orderBy('nama_produk')
            ->get(['id', 'kode_produk', 'nama_produk']);

        return view('pengurus.kebijakan', compact(
            'kebijakan',
            'riwayat',
            'akunCoa',
            'pemetaanAkad',
            'akadTersedia',
            'produkPemetaan'
        ));
    }

    /**
     * Simpan pemetaan akad syariah → akun CoA (fleksibilitas PSAK).
     *
     * Pengurus dapat mengubah akun piutang/pendapatan/margin-ditangguhkan per
     * akad, serta MENAMBAH AKAD BARU — tanpa mengubah kode sumber. Nilai akun
     * yang dikosongkan berarti "ikuti pemetaan standar" (kebijakan/registri
     * App\Support\Coa), sehingga tidak ada akun yang wajib diisi.
     */
    public function updatePemetaanAkad(Request $request)
    {
        // Normalisasi kode produk SEBELUM validasi: input huruf kecil / spasi
        // tepi (mis. impor atau pengetikan manual) tidak ditolak sia-sia, dan
        // nilai tersimpan konsisten huruf besar — sama dengan master produk.
        $request->merge([
            'pemetaan_akad' => collect((array) $request->input('pemetaan_akad', []))
                ->map(function ($baris) {
                    if (is_array($baris) && array_key_exists('kode_produk', $baris)) {
                        $baris['kode_produk'] = strtoupper(trim((string) $baris['kode_produk']));
                    }

                    return $baris;
                })
                ->all(),
        ]);

        $data = $request->validate([
            'pemetaan_akad' => 'required|array',
            'pemetaan_akad.*.id' => 'nullable|integer|exists:pemetaan_akad_coa,id',
            'pemetaan_akad.*.akad_pembiayaan' => 'nullable|string|max:50',
            'pemetaan_akad.*.akad_baru' => 'nullable|string|max:50',
            'pemetaan_akad.*.kode_produk' => [
                'nullable', 'string', 'max:30',
                // '*' = berlaku semua produk; selain itu wajib pola kode produk.
                'regex:/^(\*|[A-Z0-9][A-Z0-9\-]*)$/',
            ],
            'pemetaan_akad.*.account_piutang_id' => 'nullable|integer|exists:coas,id',
            'pemetaan_akad.*.account_pendapatan_id' => 'nullable|integer|exists:coas,id',
            'pemetaan_akad.*.account_margin_ditangguhkan_id' => 'nullable|integer|exists:coas,id',
        ], [
            'pemetaan_akad.*.kode_produk.regex' => 'Kode produk hanya boleh berisi huruf, angka, dan tanda hubung (-), atau * untuk semua produk.',
            'pemetaan_akad.*.account_piutang_id.exists' => 'Akun piutang yang dipilih tidak valid.',
            'pemetaan_akad.*.account_pendapatan_id.exists' => 'Akun pendapatan yang dipilih tidak valid.',
            'pemetaan_akad.*.account_margin_ditangguhkan_id.exists' => 'Akun margin ditangguhkan yang dipilih tidak valid.',
        ]);

        $jumlahBaru = 0;

        foreach ($data['pemetaan_akad'] as $baris) {
            $akad = trim((string) ($baris['akad_baru'] ?? ''));

            if ($akad === '') {
                $akad = trim((string) ($baris['akad_pembiayaan'] ?? ''));
            }

            if ($akad === '') {
                continue;
            }

            $id = $baris['id'] ?? null;

            // Normalisasi kode produk: kosong → universal '*' (semua produk),
            // selain itu huruf besar agar konsisten dengan master produk.
            $kodeProduk = strtoupper(trim((string) ($baris['kode_produk'] ?? '')));

            if ($kodeProduk === '') {
                $kodeProduk = PemetaanAkadCoa::SEMUA_PRODUK;
            }

            // Baris dari form dapat datang tanpa salah satu kunci akun (mis.
            // baris yang hanya memetakan kode produk). Nilai kosong = akun
            // tidak diarahkan → disimpan null agar resolver jatuh ke standar.
            $akun = static fn (string $kolom) => ($baris[$kolom] ?? null) ?: null;

            $atribut = [
                'kode_produk' => $kodeProduk,
                'akad_pembiayaan' => $akad,
                'account_piutang_id' => $akun('account_piutang_id'),
                'account_pendapatan_id' => $akun('account_pendapatan_id'),
                'account_margin_ditangguhkan_id' => $akun('account_margin_ditangguhkan_id'),
                'status_aktif' => true,
            ];

            if ($id) {
                PemetaanAkadCoa::whereKey($id)->update($atribut);

                continue;
            }

            PemetaanAkadCoa::updateOrCreate(
                [
                    'kode_produk' => $kodeProduk,
                    'akad_pembiayaan' => $akad,
                ],
                $atribut
            );

            $jumlahBaru++;
        }

        PemetaanAkadCoa::flush();

        return redirect()
            ->route('pengurus.kebijakan.index')
            ->with('success_pemetaan', $jumlahBaru > 0
                ? "Pemetaan akad disimpan. {$jumlahBaru} akad baru ditambahkan dan langsung tersedia tanpa mengubah kode."
                : 'Pemetaan akad syariah berhasil disimpan.');
    }

    /**
     * Simpan seluruh parameter Master Settings koperasi.
     *
     * Parameter modul Simpanan (Simpanan Pokok, Simpanan Wajib bulanan, dan
     * kebijakan penahanan SHU) disimpan di sini sehingga perubahan kebijakan
     * operasional TIDAK memerlukan modifikasi kode inti. Setiap perubahan
     * otomatis tercatat pada riwayat (audit trail) via event model
     * KebijakanKoperasi::saved().
     */
    public function updateKebijakan(Request $request)
    {
        // Nilai nominal dapat dikirim berformat Indonesia ("80.000.000").
        // Hanya field yang benar-benar dikirim yang dinormalkan; field yang
        // tidak dikirim memakai nilai kebijakan yang sedang berlaku sehingga
        // pembaruan sebagian (partial update) tidak mengosongkan parameter.
        $fieldNominal = [
            'mandatory_principal_savings' => 'Nominal Simpanan Pokok',
            'mandatory_monthly_savings' => 'Nominal Simpanan Wajib bulanan',
            'minimum_pembukaan_rekening' => 'Batas minimum nominal pembukaan rekening',
            'maksimum_pembukaan_rekening' => 'Batas maksimum nominal pembukaan rekening',
            'limit_approval_beban' => 'Limit approval beban operasional',
            'minimal_pengajuan_pembiayaan' => 'Nominal minimum pengajuan pembiayaan',
            'teller_max_cash_limit' => 'Batas maksimum kas laci teller',
            'minimum_transaksi_teller' => 'Minimum transaksi tunai teller',
            'limit_penarikan_teller' => 'Limit penarikan tunai teller',
            'limit_setoran_teller' => 'Limit setoran tunai teller',
            'tazir_default_nominal' => "Nominal default ta'zir",
            'financing_late_fee_per_day' => 'Tarif denda keterlambatan angsuran pembiayaan per hari',
            'aml_threshold' => 'Ambang transaksi keuangan mencurigakan (AML)',
            // Modul Member Exit & Closure — sebelumnya hanya dapat diubah
            // lewat basis data langsung (temuan audit modul exit).
            'closure_admin_fee' => 'Biaya administrasi penutupan keanggotaan',
            'high_value_withdrawal_limit' => 'Ambang persetujuan pencairan exit',
        ];

        $nominalBersih = [];
        $galatNominal = [];

        foreach ($fieldNominal as $field => $label) {
            if (! $request->filled($field)) {
                continue;
            }

            // Cegah input sampah ("abc", "-", "Rp") yang akan diam-diam menjadi 0
            // dan tersimpan sebagai parameter keuangan yang salah.
            if (! preg_match('/\d/', (string) $request->input($field))) {
                $galatNominal[$field] = $label.' wajib diisi dengan angka yang valid.';

                continue;
            }

            $nominalBersih[$field] = Setting::parseNominal($request->input($field));
        }

        if ($galatNominal) {
            return redirect()->back()->withInput()->withErrors($galatNominal);
        }

        $request->merge($nominalBersih);

        $request->validate([
            'limit_manajer' => 'required',
            // Plafon pembiayaan skala besar (Pengurus) — terpisah dari limit
            // Manajer agar kedua ambang bisa berbeda nilainya.
            'limit_pembiayaan_manajer' => 'nullable',
            'tazir_mode' => 'required|in:FLAT_HARIAN,FIXED_PER_KEJADIAN,DISABLED',
            'tazir_rate' => 'required|numeric|min:0',
            // Tarif denda keterlambatan angsuran pembiayaan per hari (PSAK 108/109).
            // Opsional agar pembaruan sebagian aman; 0 = fitur denda NONAKTIF.
            'financing_late_fee_per_day' => 'nullable|numeric|min:0|max:99999999999.99',
            'tazir_grace_period_days' => 'required|integer|min:0',
            'tazir_default_method' => 'required|in:AUTODEBET,TUNAI',
            // Nominal default ta'zir (opsional agar pembaruan sebagian aman).
            'tazir_default_nominal' => 'nullable|numeric|min:0|max:99999999999.99',
            'mandatory_principal_savings' => 'required|numeric|min:0|max:99999999999.99',
            'mandatory_monthly_savings' => 'required|numeric|min:0|max:99999999999.99',
            'minimum_pembukaan_rekening' => 'required|numeric|min:0|max:99999999999.99',
            'maksimum_pembukaan_rekening' => 'required|numeric|min:0|max:99999999999.99',
            'shu_hold_on_overdue' => 'nullable|boolean',
            'nama_koperasi' => 'nullable|string|max:150',
            // Awalan nomor dokumen (TRX/RKN/JRN/BBK/TRM/DEP). Kosong = pakai
            // awalan bawaan sehingga perilaku sistem tidak berubah.
            'prefix_nomor_transaksi' => 'nullable|alpha_num|max:8',
            'prefix_nomor_rekening' => 'nullable|alpha_num|max:8',
            'prefix_nomor_jurnal' => 'nullable|alpha_num|max:8',
            'prefix_nomor_pengeluaran_beban' => 'nullable|alpha_num|max:8',
            'prefix_nomor_terminasi' => 'nullable|alpha_num|max:8',
            'prefix_nomor_deposito' => 'nullable|alpha_num|max:8',
            // Parameter fleksibilitas: opsional pada tingkat field agar
            // pembaruan sebagian tetap aman — konsistensinya dijaga oleh
            // validasi lintas-field di bawah (aturan & pesan dari Setting
            // supaya satu sumber kebenaran dengan pemakaian di kode inti).
            ...Setting::aturanValidasiAlokasiShu(false),
            // Metode perhitungan SHU — satu-satunya nilai sah 'SRH'.
            'metode_perhitungan_shu' => 'nullable|string|in:SRH',
            'limit_approval_beban' => 'nullable|numeric|min:0|max:99999999999.99',
            'minimal_pengajuan_pembiayaan' => 'nullable|numeric|min:0|max:99999999999.99',
            // Limit kas & transaksi Teller (opsional agar pembaruan sebagian aman).
            'teller_max_cash_limit' => 'nullable|numeric|min:0|max:99999999999.99',
            'minimum_transaksi_teller' => 'nullable|numeric|min:0|max:99999999999.99',
            'limit_penarikan_teller' => 'nullable|numeric|min:0|max:99999999999.99',
            'limit_setoran_teller' => 'nullable|numeric|min:0|max:99999999999.99',
            'kolektibilitas_kol1_hari' => 'nullable|integer|min:0|max:3650',
            'kolektibilitas_kol2_hari' => 'nullable|integer|min:1|max:3650',
            'kolektibilitas_kol3_hari' => 'nullable|integer|min:1|max:3650',
            'kolektibilitas_kol4_hari' => 'nullable|integer|min:1|max:3650',
            // Parameter PSAK & regulasi (opsional agar pembaruan sebagian aman).
            'deposito_rate_pa' => 'nullable|numeric|min:0|max:1',
            'nisbah_default_persen' => 'nullable|numeric|min:0|max:100',
            'margin_default_persen' => 'nullable|numeric|min:0|max:100',
            'aml_threshold' => 'nullable|numeric|min:0|max:99999999999.99',
            'pembulatan_desimal' => 'nullable|integer|min:0|max:4',
            'toleransi_selisih_jurnal' => 'nullable|numeric|min:0|max:999999.99',
            // Parameter modul exit keanggotaan (opsional agar pembaruan
            // sebagian aman). 0 = biaya admin nihil / approval nonaktif.
            'closure_admin_fee' => 'nullable|numeric|min:0|max:99999999999.99',
            'high_value_withdrawal_limit' => 'nullable|numeric|min:0|max:99999999999.99',
            'ckpn_persen_kol1' => 'nullable|numeric|min:0|max:100',
            'ckpn_persen_kol2' => 'nullable|numeric|min:0|max:100',
            'ckpn_persen_kol3' => 'nullable|numeric|min:0|max:100',
            'ckpn_persen_kol4' => 'nullable|numeric|min:0|max:100',
            'ckpn_persen_kol5' => 'nullable|numeric|min:0|max:100',
            'akun_tazir_id' => 'nullable|integer|exists:coas,id',
            // Parameter dinamis modul Simpanan Wajib (Tahap 12 / R1).
            'simpanan_wajib_kanal_default' => 'nullable|in:AUTO_DEBIT,CASH,TRANSFER',
            'simpanan_wajib_boleh_ditarik' => 'nullable|boolean',
            'simpanan_wajib_hari_penarikan' => 'nullable|in:SETELAH_KELUAR,KAPAN_SAJA',
            // Jam posting auto-debit Simpanan Wajib (DYN-17) — format HH:MM.
            'jam_posting_simpanan_wajib' => ['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'account_simpanan_wajib_id' => 'nullable|integer|exists:coas,id',
            // Pemetaan COA dana zakat & infak/sedekah (PSAK 109 / temuan Z-05).
            'account_dana_zakat_id' => 'nullable|integer|exists:coas,id',
            'account_dana_infak_id' => 'nullable|integer|exists:coas,id',
            // COA Bank Operasional Koperasi (kosong = ikuti standar 1-1002).
            'account_bank_koperasi_id' => 'nullable|integer|exists:coas,id',
            // Porsi/hak amil atas dana zakat (Fatwa DSN-MUI 08 / temuan Z-08).
            'zakat_porsi_amil_persen' => 'nullable|numeric|min:0|max:100',
            // Parameter hisab zakat (temuan audit Z-03): sebelumnya kolom
            // tersedia di skema & dibaca Setting, tetapi tidak dapat diubah
            // lewat UI (nol validasi/simpan). Kini dibuka agar Pengurus dapat
            // menyesuaikan kadar, nishab, harga emas, minimum penyaluran, dan
            // syarat haul tanpa mengubah kode (zero-hardcode).
            'zakat_rate' => 'nullable|numeric|min:0|max:100',
            'zakat_nisab_gram' => 'nullable|numeric|min:0|max:1000000',
            'harga_emas_per_gram' => 'nullable|numeric|min:0|max:999999999999.99',
            'zakat_minimal_tarik' => 'nullable|numeric|min:0|max:999999999999.99',
            'zakat_haul_aktif' => 'nullable|boolean',
            'zakat_haul_hari' => 'nullable|integer|min:1|max:1000',
            // Z-02 — ambang dual control penyaluran dana amanah (Rp). 0 = nonaktif.
            'zakat_ambang_persetujuan' => 'nullable|numeric|min:0|max:999999999999.99',
            // Tahun buku dinamis & integritas periode (fleksibilitas temporal).
            'tahun_buku_mulai_bulan' => 'nullable|integer|min:1|max:12',
            'jumlah_bulan_tahun_buku' => 'nullable|integer|min:1|max:12',
            'periode_cutoff_mode' => 'nullable|in:fail_open,fail_closed',
            // Klasifikasi akad & partisipasi SHU (fleksibilitas PSAK 107-109).
            'akad_margin' => 'nullable|string|max:500',
            'akad_bagi_hasil' => 'nullable|string|max:500',
            'akad_ujroh' => 'nullable|string|max:500',
            'shu_jenis_partisipasi' => 'nullable|string|max:500',
            // Periode & kalender penjadwalan angsuran (opsional agar pembaruan
            // sebagian aman). Tanggal dibatasi 1-28 agar aman untuk Februari.
            'tanggal_jatuh_tempo_default' => 'nullable|integer|min:1|max:28',
            'hari_libur_strategi' => 'nullable|in:MAJU,MUNDUR',
            'hari_libur_aktif' => 'nullable|boolean',
            // Retensi cadangan basis data (pemeliharaan sistem). 1-365 hari
            // agar tidak menghapus cadangan yang baru dibuat atau memenuhi disk.
            'backup_retensi_hari' => 'nullable|integer|min:1|max:365',
        ], [
            'mandatory_principal_savings.required' => 'Nominal Simpanan Pokok wajib diisi.',
            'mandatory_principal_savings.numeric' => 'Nominal Simpanan Pokok harus berupa angka.',
            'mandatory_principal_savings.min' => 'Nominal Simpanan Pokok tidak boleh negatif.',
            'mandatory_monthly_savings.required' => 'Nominal Simpanan Wajib bulanan wajib diisi.',
            'mandatory_monthly_savings.numeric' => 'Nominal Simpanan Wajib bulanan harus berupa angka.',
            'mandatory_monthly_savings.min' => 'Nominal Simpanan Wajib bulanan tidak boleh negatif.',
            'minimum_pembukaan_rekening.required' => 'Batas minimum nominal pembukaan rekening wajib diisi.',
            'minimum_pembukaan_rekening.numeric' => 'Batas minimum nominal pembukaan rekening harus berupa angka.',
            'minimum_pembukaan_rekening.min' => 'Batas minimum nominal pembukaan rekening tidak boleh negatif.',
            'maksimum_pembukaan_rekening.required' => 'Batas maksimum nominal pembukaan rekening wajib diisi.',
            'maksimum_pembukaan_rekening.numeric' => 'Batas maksimum nominal pembukaan rekening harus berupa angka.',
            'maksimum_pembukaan_rekening.min' => 'Batas maksimum nominal pembukaan rekening tidak boleh negatif.',
            ...Setting::pesanValidasiAlokasiShu(),
            'limit_approval_beban.required' => 'Limit approval beban operasional wajib diisi.',
            'limit_approval_beban.numeric' => 'Limit approval beban operasional harus berupa angka.',
            'limit_approval_beban.min' => 'Limit approval beban operasional tidak boleh negatif.',
            'minimal_pengajuan_pembiayaan.numeric' => 'Nominal minimum pengajuan pembiayaan harus berupa angka.',
            'minimal_pengajuan_pembiayaan.min' => 'Nominal minimum pengajuan pembiayaan tidak boleh negatif.',
            'kolektibilitas_kol2_hari.required' => 'Ambang hari Kol-2 wajib diisi.',
            'kolektibilitas_kol3_hari.required' => 'Ambang hari Kol-3 wajib diisi.',
            'kolektibilitas_kol4_hari.required' => 'Ambang hari Kol-4 wajib diisi.',
            'kolektibilitas_kol1_hari.integer' => 'Ambang hari Kol-1 harus berupa bilangan bulat.',
            'kolektibilitas_kol2_hari.integer' => 'Ambang hari Kol-2 harus berupa bilangan bulat.',
            'kolektibilitas_kol3_hari.integer' => 'Ambang hari Kol-3 harus berupa bilangan bulat.',
            'kolektibilitas_kol4_hari.integer' => 'Ambang hari Kol-4 harus berupa bilangan bulat.',
            'kolektibilitas_kol1_hari.min' => 'Ambang hari Kol-1 minimal 0 hari.',
            'kolektibilitas_kol2_hari.min' => 'Ambang hari Kol-2 minimal 1 hari.',
            'kolektibilitas_kol3_hari.min' => 'Ambang hari Kol-3 minimal 1 hari.',
            'kolektibilitas_kol4_hari.min' => 'Ambang hari Kol-4 minimal 1 hari.',
            // Parameter PSAK & regulasi
            'deposito_rate_pa.numeric' => 'Asumsi rate deposito harus berupa angka desimal (contoh 0.08 = 8% p.a.).',
            'deposito_rate_pa.min' => 'Asumsi rate deposito tidak boleh negatif.',
            'deposito_rate_pa.max' => 'Asumsi rate deposito maksimal 1 (100% p.a.).',
            'nisbah_default_persen.numeric' => 'Nisbah bagi hasil default harus berupa angka.',
            'nisbah_default_persen.min' => 'Nisbah bagi hasil default tidak boleh negatif.',
            'nisbah_default_persen.max' => 'Nisbah bagi hasil default maksimal 100%.',
            'margin_default_persen.numeric' => 'Margin default harus berupa angka.',
            'margin_default_persen.min' => 'Margin default tidak boleh negatif.',
            'margin_default_persen.max' => 'Margin default maksimal 100%.',
            'aml_threshold.numeric' => 'Ambang AML harus berupa angka.',
            'aml_threshold.min' => 'Ambang AML tidak boleh negatif.',
            'pembulatan_desimal.integer' => 'Jumlah desimal pembulatan harus bilangan bulat.',
            'pembulatan_desimal.min' => 'Jumlah desimal pembulatan minimal 0.',
            'pembulatan_desimal.max' => 'Jumlah desimal pembulatan maksimal 4.',
            'toleransi_selisih_jurnal.numeric' => 'Toleransi selisih jurnal harus berupa angka.',
            'toleransi_selisih_jurnal.min' => 'Toleransi selisih jurnal tidak boleh negatif.',
            'teller_max_cash_limit.numeric' => 'Batas maksimum kas laci teller harus berupa angka.',
            'teller_max_cash_limit.min' => 'Batas maksimum kas laci teller tidak boleh negatif.',
            'minimum_transaksi_teller.numeric' => 'Minimum transaksi tunai teller harus berupa angka.',
            'minimum_transaksi_teller.min' => 'Minimum transaksi tunai teller tidak boleh negatif.',
            'limit_penarikan_teller.numeric' => 'Limit penarikan tunai teller harus berupa angka.',
            'limit_penarikan_teller.min' => 'Limit penarikan tunai teller tidak boleh negatif.',
            'limit_setoran_teller.numeric' => 'Limit setoran tunai teller harus berupa angka.',
            'limit_setoran_teller.min' => 'Limit setoran tunai teller tidak boleh negatif.',
            'ckpn_persen_kol1.numeric' => 'Persentase CKPN Kol-1 harus berupa angka.',
            'ckpn_persen_kol2.numeric' => 'Persentase CKPN Kol-2 harus berupa angka.',
            'ckpn_persen_kol3.numeric' => 'Persentase CKPN Kol-3 harus berupa angka.',
            'ckpn_persen_kol4.numeric' => 'Persentase CKPN Kol-4 harus berupa angka.',
            'ckpn_persen_kol5.numeric' => 'Persentase CKPN Kol-5 harus berupa angka.',
            'ckpn_persen_kol1.max' => 'Persentase CKPN Kol-1 maksimal 100%.',
            'ckpn_persen_kol2.max' => 'Persentase CKPN Kol-2 maksimal 100%.',
            'ckpn_persen_kol3.max' => 'Persentase CKPN Kol-3 maksimal 100%.',
            'ckpn_persen_kol4.max' => 'Persentase CKPN Kol-4 maksimal 100%.',
            'ckpn_persen_kol5.max' => 'Persentase CKPN Kol-5 maksimal 100%.',
            'akun_tazir_id.integer' => 'Akun penampung ta\'zir tidak valid.',
            'akun_tazir_id.exists' => 'Akun penampung ta\'zir yang dipilih tidak ditemukan pada bagan akun.',
            'tazir_default_nominal.numeric' => 'Nominal default ta\'zir harus berupa angka.',
            // Awalan nomor dokumen
            'prefix_nomor_*.alpha_num' => 'Awalan nomor dokumen hanya boleh berisi huruf dan angka (tanpa spasi/tanda baca).',
            'prefix_nomor_*.max' => 'Awalan nomor dokumen maksimal 8 karakter.',
            'tazir_default_nominal.min' => 'Nominal default ta\'zir tidak boleh negatif.',
            'financing_late_fee_per_day.numeric' => 'Tarif denda keterlambatan angsuran per hari harus berupa angka.',
            'financing_late_fee_per_day.min' => 'Tarif denda keterlambatan angsuran per hari tidak boleh negatif.',
            // Parameter dinamis modul Simpanan Wajib (Tahap 12 / R1).
            'simpanan_wajib_kanal_default.in' => 'Kanal pembayaran default Simpanan Wajib harus salah satu dari AUTO_DEBIT, CASH, atau TRANSFER.',
            'simpanan_wajib_hari_penarikan.in' => 'Waktu penarikan Simpanan Wajib harus SETELAH_KELUAR atau KAPAN_SAJA.',
            'jam_posting_simpanan_wajib.regex' => 'Jam posting Simpanan Wajib harus berformat HH:MM 24 jam, contoh 00:01 atau 08:30.',
            'account_simpanan_wajib_id.integer' => 'Akun Simpanan Wajib tidak valid.',
            'account_simpanan_wajib_id.exists' => 'Akun Simpanan Wajib yang dipilih tidak ditemukan pada bagan akun.',
            'account_dana_zakat_id.integer' => 'Akun Dana Zakat tidak valid.',
            'account_dana_zakat_id.exists' => 'Akun Dana Zakat yang dipilih tidak ditemukan pada bagan akun.',
            'account_dana_infak_id.integer' => 'Akun Dana Infak & Sedekah tidak valid.',
            'account_dana_infak_id.exists' => 'Akun Dana Infak & Sedekah yang dipilih tidak ditemukan pada bagan akun.',
            'zakat_porsi_amil_persen.numeric' => 'Porsi hak amil harus berupa angka.',
            'zakat_porsi_amil_persen.min' => 'Porsi hak amil tidak boleh negatif.',
            'zakat_porsi_amil_persen.max' => 'Porsi hak amil maksimal 100%.',
            // Pesan validasi parameter hisab zakat (temuan Z-03).
            'zakat_rate.numeric' => 'Kadar zakat harus berupa angka.',
            'zakat_rate.min' => 'Kadar zakat tidak boleh negatif.',
            'zakat_rate.max' => 'Kadar zakat maksimal 100%.',
            'zakat_nisab_gram.numeric' => 'Nishab zakat (gram emas) harus berupa angka.',
            'zakat_nisab_gram.min' => 'Nishab zakat (gram emas) tidak boleh negatif.',
            'harga_emas_per_gram.numeric' => 'Harga emas per gram harus berupa angka.',
            'harga_emas_per_gram.min' => 'Harga emas per gram tidak boleh negatif.',
            'zakat_minimal_tarik.numeric' => 'Batas minimum penyaluran zakat harus berupa angka.',
            'zakat_minimal_tarik.min' => 'Batas minimum penyaluran zakat tidak boleh negatif.',
            'zakat_haul_aktif.boolean' => 'Saklar syarat haul harus bernilai aktif/nonaktif.',
            'zakat_haul_hari.integer' => 'Panjang haul harus berupa bilangan bulat (hari).',
            'zakat_haul_hari.min' => 'Panjang haul minimal 1 hari.',
            'zakat_haul_hari.max' => 'Panjang haul maksimal 1000 hari.',
            // Pesan validasi ambang dual control penyaluran (temuan Z-02).
            'zakat_ambang_persetujuan.numeric' => 'Ambang persetujuan penyaluran harus berupa angka.',
            'zakat_ambang_persetujuan.min' => 'Ambang persetujuan penyaluran tidak boleh negatif.',
        ]);

        $limitClean = (int) preg_replace('/[^0-9]/', '', (string) $request->input('limit_manajer', ''));
        $limitPembiayaanClean = (int) preg_replace('/[^0-9]/', '', (string) $request->input('limit_pembiayaan_manajer', ''));
        $kebijakan = KebijakanKoperasi::first() ?? new KebijakanKoperasi;

        // Nilai berlaku saat ini dipakai sebagai fallback untuk field yang tidak
        // dikirim — sehingga pembaruan sebagian tidak mengubah parameter lain.
        $pokok = $request->filled('mandatory_principal_savings') ? (float) $request->mandatory_principal_savings : Setting::simpananPokok();
        $wajib = $request->filled('mandatory_monthly_savings') ? (float) $request->mandatory_monthly_savings : Setting::simpananWajibBulanan();
        $minPembukaan = $request->filled('minimum_pembukaan_rekening') ? (float) $request->minimum_pembukaan_rekening : Setting::minimumPembukaanRekening();
        $maksPembukaan = $request->filled('maksimum_pembukaan_rekening') ? (float) $request->maksimum_pembukaan_rekening : Setting::maksimumPembukaanRekening();
        $limitApprovalBeban = $request->filled('limit_approval_beban') ? (float) $request->limit_approval_beban : Setting::limitApprovalBeban();
        $minimalPengajuan = $request->filled('minimal_pengajuan_pembiayaan') ? (float) $request->minimal_pengajuan_pembiayaan : Setting::minimalPengajuanPembiayaan();

        if ($maksPembukaan < $minPembukaan) {
            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'maksimum_pembukaan_rekening' => 'Batas maksimum nominal pembukaan rekening tidak boleh lebih kecil dari batas minimum (Rp '
                        .number_format($minPembukaan, 2, ',', '.').').',
                ]);
        }

        // ── Validasi lintas-field: total alokasi SHU harus tepat 100% ────
        // Field yang tidak dikirim memakai persentase yang berlaku sekarang,
        // sehingga perubahan sebagian pun tetap dinilai terhadap total akhir.
        $alokasiSaatIni = Setting::alokasiShu();
        $persenShu = [
            'cadangan_modal' => $request->filled('shu_pct_cadangan_modal') ? (float) $request->shu_pct_cadangan_modal : $alokasiSaatIni['cadangan_modal'],
            'jasa_anggota' => $request->filled('shu_pct_jasa_anggota') ? (float) $request->shu_pct_jasa_anggota : $alokasiSaatIni['jasa_anggota'],
            'jasa_modal' => $request->filled('shu_pct_jasa_modal') ? (float) $request->shu_pct_jasa_modal : $alokasiSaatIni['jasa_modal'],
            'dana_pendidikan' => $request->filled('shu_pct_dana_pendidikan') ? (float) $request->shu_pct_dana_pendidikan : $alokasiSaatIni['dana_pendidikan'],
        ];

        if ($pesanTotalShu = Setting::validasiTotalAlokasiShu($persenShu)) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['shu_pct_cadangan_modal' => $pesanTotalShu]);
        }

        // ── Validasi lintas-field: ambang kolektibilitas harus menaik ────
        $ambangSaatIni = Setting::ambangKolektibilitas();
        $kol1 = $request->filled('kolektibilitas_kol1_hari') ? (int) $request->kolektibilitas_kol1_hari : $ambangSaatIni['kol1'];
        $kol2 = $request->filled('kolektibilitas_kol2_hari') ? (int) $request->kolektibilitas_kol2_hari : $ambangSaatIni['kol2'];
        $kol3 = $request->filled('kolektibilitas_kol3_hari') ? (int) $request->kolektibilitas_kol3_hari : $ambangSaatIni['kol3'];
        $kol4 = $request->filled('kolektibilitas_kol4_hari') ? (int) $request->kolektibilitas_kol4_hari : $ambangSaatIni['kol4'];

        if (! ($kol1 < $kol2 && $kol2 < $kol3 && $kol3 < $kol4)) {
            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'kolektibilitas_kol3_hari' => 'Ambang hari kolektibilitas harus menaik (Kol-1 < Kol-2 < Kol-3 < Kol-4). '
                        .'Nilai saat ini: '.$kol1.' / '.$kol2.' / '.$kol3.' / '.$kol4.'.',
                ]);
        }

        // ── Validasi lintas-field: persentase CKPN harus menaik ──────────
        // Semakin buruk kolektibilitas, semakin besar penyisihan (PSAK 101/105/107).
        $ckpnSaatIni = Setting::persenCkpn();
        $ckpn = [];
        foreach (['kol1', 'kol2', 'kol3', 'kol4', 'kol5'] as $tingkat) {
            $field = 'ckpn_persen_'.$tingkat;
            $ckpn[$tingkat] = $request->filled($field)
                ? (float) $request->input($field)
                : $ckpnSaatIni[$tingkat];
        }

        if (! ($ckpn['kol1'] <= $ckpn['kol2']
            && $ckpn['kol2'] <= $ckpn['kol3']
            && $ckpn['kol3'] <= $ckpn['kol4']
            && $ckpn['kol4'] <= $ckpn['kol5'])) {
            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'ckpn_persen_kol1' => 'Persentase CKPN harus menaik seiring memburuknya kolektibilitas '
                        .'(Kol-1 ≤ Kol-2 ≤ Kol-3 ≤ Kol-4 ≤ Kol-5). Nilai saat ini: '
                        .$ckpn['kol1'].' / '.$ckpn['kol2'].' / '.$ckpn['kol3'].' / '
                        .$ckpn['kol4'].' / '.$ckpn['kol5'].'%.',
                ]);
        }

        // Dua limit berbeda dipisahkan: `limit_manajer` = plafon persetujuan
        // pembiayaan oleh Manajer, `limit_pembiayaan_manajer` = plafon
        // pembiayaan skala besar yang wajib naik ke Pengurus. Sebelumnya
        // keduanya ditimpa satu nilai yang sama dan fallback-nya memakai
        // `limitApprovalBeban()` (batas approval BEBAN, bukan pembiayaan),
        // sehingga nilai yang tersimpan bisa turun drastis tanpa disadari.
        $kebijakan->limit_manajer = $limitClean > 0 ? $limitClean : Setting::limitManajerPembiayaan();
        $kebijakan->limit_pembiayaan_manajer = $limitPembiayaanClean > 0
            ? $limitPembiayaanClean
            : ($kebijakan->limit_pembiayaan_manajer ?? Setting::limitManajerPembiayaan());
        $kebijakan->tazir_mode = $request->tazir_mode;
        $kebijakan->tazir_rate = (float) $request->tazir_rate;
        // Tarif denda keterlambatan angsuran pembiayaan per hari — saklar tunggal
        // fitur ta'zir (PSAK 108/109). 0 = fitur denda NONAKTIF.
        $kebijakan->financing_late_fee_per_day = $request->filled('financing_late_fee_per_day')
            ? (float) $request->financing_late_fee_per_day
            : Setting::financingLateFeePerDay();
        $kebijakan->tazir_grace_period_days = (int) $request->tazir_grace_period_days;
        $kebijakan->tazir_default_method = $request->tazir_default_method;
        // Nominal default ta'zir (zero-hardcoding: sumber tunggal untuk Teller).
        $kebijakan->tazir_default_nominal = $request->filled('tazir_default_nominal')
            ? (float) $request->tazir_default_nominal
            : Setting::nominalTazirDefault();

        // ── Parameter modul Simpanan (dinamis, tanpa hardcode) ──────────
        $kebijakan->mandatory_principal_savings = $pokok;
        $kebijakan->mandatory_monthly_savings = $wajib;
        // Form UI selalu mengirim hidden input, sehingga `filled()` = true saat
        // dicentang maupun tidak. Fallback hanya berlaku bila field benar-benar
        // tidak dikirim (pembaruan sebagian) — nilai berlaku dipertahankan.
        $kebijakan->shu_hold_on_overdue = $request->filled('shu_hold_on_overdue')
            ? $request->boolean('shu_hold_on_overdue')
            : Setting::tahanShuSaatMenunggak();

        // ── Parameter dinamis Simpanan Wajib (Tahap 12 / R1) ────────────
        // Semua opsional: bila tidak dikirim (pembaruan sebagian) nilai
        // berlaku dipertahankan (P4 Default Aman).
        $kebijakan->simpanan_wajib_kanal_default = $request->filled('simpanan_wajib_kanal_default')
            ? strtoupper((string) $request->simpanan_wajib_kanal_default)
            : Setting::simpananWajibKanalDefault();
        // Form UI selalu mengirim hidden input, sehingga `filled()` = true saat
        // dicentang maupun tidak — pola sama dengan `shu_hold_on_overdue`.
        $kebijakan->simpanan_wajib_boleh_ditarik = $request->filled('simpanan_wajib_boleh_ditarik')
            ? $request->boolean('simpanan_wajib_boleh_ditarik')
            : Setting::simpananWajibBolehDitarik();
        $kebijakan->simpanan_wajib_hari_penarikan = $request->filled('simpanan_wajib_hari_penarikan')
            ? strtoupper((string) $request->simpanan_wajib_hari_penarikan)
            : Setting::simpananWajibHariPenarikan();
        // Jam posting auto-debit (DYN-17). Kosong = kembali ke 00:01; nilai
        // yang tidak valid sudah ditolak validasi regex di atas.
        $kebijakan->jam_posting_simpanan_wajib = $request->filled('jam_posting_simpanan_wajib')
            ? trim((string) $request->jam_posting_simpanan_wajib)
            : Setting::jamPostingSimpananWajib();

        // Akun kanonik Simpanan Wajib opsional — field dikirim kosong berarti
        // "kembali ikuti master produk / akun standar 3-1002" (PSAK 101).
        if ($request->has('account_simpanan_wajib_id')) {
            $kebijakan->account_simpanan_wajib_id = $request->filled('account_simpanan_wajib_id')
                ? (int) $request->account_simpanan_wajib_id
                : null;
        } else {
            $kebijakan->account_simpanan_wajib_id = Setting::akunSimpananWajibId();
        }

        // ── Batas dinamis nominal pembukaan rekening (tanpa hardcode) ───
        $kebijakan->minimum_pembukaan_rekening = $minPembukaan;
        $kebijakan->maksimum_pembukaan_rekening = $maksPembukaan;

        // ── Limit kas laci & minimum transaksi Teller (tanpa hardcode) ──
        $kebijakan->teller_max_cash_limit = $request->filled('teller_max_cash_limit')
            ? (float) $request->teller_max_cash_limit
            : Setting::tellerMaxCashLimit();
        $kebijakan->minimum_transaksi_teller = $request->filled('minimum_transaksi_teller')
            ? (float) $request->minimum_transaksi_teller
            : Setting::minimumTransaksiTeller();

        // ── Limit per-transaksi setor & tarik tunai Teller (tanpa hardcode) ──
        // Dipakai HierarkiRole untuk menentukan apakah transaksi teller butuh
        // approval Manajer. Dipertahankan bila field tidak dikirim.
        $kebijakan->limit_penarikan_teller = $request->filled('limit_penarikan_teller')
            ? (float) $request->limit_penarikan_teller
            : Setting::limitPenarikanTeller();
        $kebijakan->limit_setoran_teller = $request->filled('limit_setoran_teller')
            ? (float) $request->limit_setoran_teller
            : Setting::limitSetoranTeller();

        // ── Alokasi SHU (persentase, dapat dikonfigurasi tanpa deploy) ──
        // Nilai diambil dari hasil resolusi lintas-field di atas sehingga field
        // yang tidak dikirim tetap mempertahankan persentase yang berlaku.
        $kebijakan->shu_pct_cadangan_modal = $persenShu['cadangan_modal'];
        $kebijakan->shu_pct_jasa_anggota = $persenShu['jasa_anggota'];
        $kebijakan->shu_pct_jasa_modal = $persenShu['jasa_modal'];
        $kebijakan->shu_pct_dana_pendidikan = $persenShu['dana_pendidikan'];
        // Metode perhitungan partisipasi SHU — dinormalkan ke 'SRH'
        // (satu-satunya metode yang didukung sistem).
        $kebijakan->metode_perhitungan_shu = $request->filled('metode_perhitungan_shu')
            ? Setting::metodePerhitunganShu()
            : (Setting::get('metode_perhitungan_shu', 'SRH') ?: 'SRH');

        // ── Limit approval beban operasional & ambang kolektibilitas ────
        $kebijakan->limit_approval_beban = $limitApprovalBeban;
        $kebijakan->minimal_pengajuan_pembiayaan = $minimalPengajuan;
        $kebijakan->kolektibilitas_kol1_hari = $kol1;
        $kebijakan->kolektibilitas_kol2_hari = $kol2;
        $kebijakan->kolektibilitas_kol3_hari = $kol3;
        $kebijakan->kolektibilitas_kol4_hari = $kol4;

        // ── Parameter PSAK & regulasi (dinamis, tanpa hardcode) ─────────
        $kebijakan->deposito_rate_pa = $request->filled('deposito_rate_pa')
            ? (float) $request->deposito_rate_pa
            : Setting::depositoRatePa();
        $kebijakan->nisbah_default_persen = $request->filled('nisbah_default_persen')
            ? (float) $request->nisbah_default_persen
            : Setting::nisbahDefaultPersen();
        $kebijakan->margin_default_persen = $request->filled('margin_default_persen')
            ? (float) $request->margin_default_persen
            : Setting::marginDefaultPersen();
        $kebijakan->aml_threshold = $request->filled('aml_threshold')
            ? (float) $request->aml_threshold
            : Setting::amlThreshold();
        $kebijakan->pembulatan_desimal = $request->filled('pembulatan_desimal')
            ? (int) $request->pembulatan_desimal
            : Setting::pembulatanDesimal();
        $kebijakan->toleransi_selisih_jurnal = $request->filled('toleransi_selisih_jurnal')
            ? (float) $request->toleransi_selisih_jurnal
            : Setting::toleransiSelisihJurnal();

        // ── Parameter modul exit keanggotaan ─────────────────────────────
        // Biaya admin penutupan CIF & ambang approval pencairan. Opsional:
        // bila tidak dikirim (pembaruan sebagian), nilai berlaku dipertahankan.
        $kebijakan->closure_admin_fee = $request->filled('closure_admin_fee')
            ? (float) $request->closure_admin_fee
            : Setting::closureAdminFee();
        $kebijakan->high_value_withdrawal_limit = $request->filled('high_value_withdrawal_limit')
            ? (float) $request->high_value_withdrawal_limit
            : Setting::highValueWithdrawalLimit();

        // Persentase CKPN (PSAK 101/105/107) — hasil resolusi lintas-field di atas.
        $kebijakan->ckpn_persen_kol1 = $ckpn['kol1'];
        $kebijakan->ckpn_persen_kol2 = $ckpn['kol2'];
        $kebijakan->ckpn_persen_kol3 = $ckpn['kol3'];
        $kebijakan->ckpn_persen_kol4 = $ckpn['kol4'];
        $kebijakan->ckpn_persen_kol5 = $ckpn['kol5'];

        // Akun penampung ta'zir opsional — field yang dikirim kosong berarti
        // "kembali ikuti akun Dana Kebajikan (2-3001)".
        if ($request->has('akun_tazir_id')) {
            $kebijakan->akun_tazir_id = $request->filled('akun_tazir_id')
                ? (int) $request->akun_tazir_id
                : null;
        } else {
            $kebijakan->akun_tazir_id = Setting::akunTazirId();
        }

        // Akun COA bank operasional koperasi. Kosong = ikuti standar 1-1002.
        if ($request->has('account_bank_koperasi_id')) {
            $kebijakan->account_bank_koperasi_id = $request->filled('account_bank_koperasi_id')
                ? (int) $request->account_bank_koperasi_id
                : null;
        } else {
            $kebijakan->account_bank_koperasi_id = Setting::akunId('account_bank_koperasi_id');
        }

        // ── Akun COA dana zakat & infak/sedekah (PSAK 109 / temuan Z-05) ──
        // Dana zakat dan infak/sedekah adalah dana TITIPAN (amanah). Pemetaannya
        // dibaca resolver bersama (`CoaResolver`) sehingga buku besar modul zakat
        // DAN penyajian DIT menunjuk akun yang sama. Field dikirim kosong berarti
        // "kembali ikuti registri standar" (2-3002 / 2-3004) — P4 Default Aman.
        if ($request->has('account_dana_zakat_id')) {
            $kebijakan->account_dana_zakat_id = $request->filled('account_dana_zakat_id')
                ? (int) $request->account_dana_zakat_id
                : null;
        } else {
            $kebijakan->account_dana_zakat_id = Setting::akunId('account_dana_zakat_id');
        }

        if ($request->has('account_dana_infak_id')) {
            $kebijakan->account_dana_infak_id = $request->filled('account_dana_infak_id')
                ? (int) $request->account_dana_infak_id
                : null;
        } else {
            $kebijakan->account_dana_infak_id = Setting::akunId('account_dana_infak_id');
        }

        // ── Porsi/hak amil atas dana zakat (Fatwa DSN-MUI 08 / temuan Z-08) ──
        // Opsional: bila tidak dikirim (pembaruan sebagian), nilai berlaku
        // dipertahankan (P4 Default Aman). Porsi 0 = rekap hak amil dinolkan.
        $kebijakan->zakat_porsi_amil_persen = $request->filled('zakat_porsi_amil_persen')
            ? (float) $request->zakat_porsi_amil_persen
            : Setting::zakatPorsiAmilPersen();

        // ── Parameter hisab zakat (temuan audit Z-03) ───────────────────
        // Kadar, nishab (gram emas), harga emas per gram, batas minimum
        // penyaluran, saklar & panjang haul. Semuanya opsional: bila tidak
        // dikirim (pembaruan sebagian) nilai berlaku dipertahankan (P4 Default
        // Aman). Sebelumnya keenam kolom ini hanya dapat diubah lewat akses
        // basis data langsung — kini tercakup tata kelola kebijakan Pengurus.
        $kebijakan->zakat_rate = $request->filled('zakat_rate')
            ? (float) $request->zakat_rate
            : Setting::zakatRate();
        $kebijakan->zakat_nisab_gram = $request->filled('zakat_nisab_gram')
            ? (float) $request->zakat_nisab_gram
            : Setting::zakatNisabGram();
        $kebijakan->harga_emas_per_gram = $request->filled('harga_emas_per_gram')
            ? (float) $request->harga_emas_per_gram
            : Setting::hargaEmasPerGram();
        $kebijakan->zakat_minimal_tarik = $request->filled('zakat_minimal_tarik')
            ? (float) $request->zakat_minimal_tarik
            : Setting::zakatMinimalTarik();
        $kebijakan->zakat_haul_aktif = $request->has('zakat_haul_aktif')
            ? $request->boolean('zakat_haul_aktif')
            : Setting::zakatHaulAktif();
        $kebijakan->zakat_haul_hari = $request->filled('zakat_haul_hari')
            ? (int) $request->zakat_haul_hari
            : Setting::zakatHaulHari();
        // Z-02 — ambang dual control penyaluran (Rp). Opsional: bila tidak
        // dikirim, nilai berlaku dipertahankan (P4 Default Aman).
        $kebijakan->zakat_ambang_persetujuan = $request->filled('zakat_ambang_persetujuan')
            ? (float) $request->zakat_ambang_persetujuan
            : Setting::zakatAmbangPersetujuan();

        // ── Tahun buku dinamis & integritas periode (tanpa hardcode) ────
        // Opsional: bila tidak dikirim (pembaruan sebagian), nilai berlaku
        // dipertahankan (P4 Default Aman).
        $kebijakan->tahun_buku_mulai_bulan = $request->filled('tahun_buku_mulai_bulan')
            ? (int) $request->tahun_buku_mulai_bulan
            : Setting::tahunBukuMulaiBulan();
        $kebijakan->jumlah_bulan_tahun_buku = $request->filled('jumlah_bulan_tahun_buku')
            ? (int) $request->jumlah_bulan_tahun_buku
            : Setting::jumlahBulanTahunBuku();
        $kebijakan->periode_cutoff_mode = $request->filled('periode_cutoff_mode')
            ? strtolower((string) $request->periode_cutoff_mode)
            : Setting::periodeCutoffMode();

        // ── Klasifikasi akad & partisipasi SHU (fleksibilitas PSAK 107-109) ─
        // Daftar berkoma; field kosong = pertahankan nilai berjalan.
        $kebijakan->akad_margin = $request->filled('akad_margin')
            ? trim((string) $request->akad_margin)
            : (string) Setting::get('akad_margin', 'Murabahah,Ijarah Muntahia Bittamlik,Istishna,Salam');
        $kebijakan->akad_bagi_hasil = $request->filled('akad_bagi_hasil')
            ? trim((string) $request->akad_bagi_hasil)
            : (string) Setting::get('akad_bagi_hasil', 'Mudharabah,Musyarakah');
        $kebijakan->akad_ujroh = $request->filled('akad_ujroh')
            ? trim((string) $request->akad_ujroh)
            : (string) Setting::get('akad_ujroh', 'Ijarah');
        $kebijakan->shu_jenis_partisipasi = $request->filled('shu_jenis_partisipasi')
            ? trim((string) $request->shu_jenis_partisipasi)
            : (string) Setting::get('shu_jenis_partisipasi', 'Pembayaran Angsuran,Bayar Angsuran,Bagi Hasil Simpanan Berjangka,Bagi Hasil');

        if ($request->filled('nama_koperasi')) {
            $kebijakan->nama_koperasi = $request->nama_koperasi;
        }

        // ── Awalan nomor dokumen ────────────────────────────────────────
        // Field dikirim kosong = kembali ke awalan bawaan (`NomorDokumen`),
        // bukan mempertahankan nilai lama, agar Pengurus dapat menghapus
        // kustomisasi. Validasi `alpha_num|max:8` sudah menjaga formatnya;
        // di sini hanya diseragamkan menjadi huruf besar.
        foreach (array_keys(NomorDokumen::BAWAAN) as $jenis) {
            $kolom = 'prefix_nomor_'.$jenis;
            $nilai = strtoupper(trim((string) $request->input($kolom, '')));

            $kebijakan->{$kolom} = $nilai === '' ? null : substr($nilai, 0, 8);
        }

        // ── Periode & kalender penjadwalan angsuran ────────────────────
        // Opsional: bila tidak dikirim (pembaruan sebagian), nilai berlaku
        // dipertahankan (P4 Default Aman).
        $kebijakan->tanggal_jatuh_tempo_default = $request->filled('tanggal_jatuh_tempo_default')
            ? (int) $request->tanggal_jatuh_tempo_default
            : Setting::tanggalJatuhTempoDefault();
        $kebijakan->hari_libur_strategi = $request->filled('hari_libur_strategi')
            ? strtoupper(trim((string) $request->hari_libur_strategi))
            : Setting::hariLiburStrategi();
        $kebijakan->hari_libur_aktif = $request->has('hari_libur_aktif')
            ? $request->boolean('hari_libur_aktif')
            : Setting::hariLiburAktif();

        // ── Pemeliharaan & cadangan basis data ──────────────────────────
        // Opsional (P4 Default Aman): bila tidak dikirim, nilai berlaku
        // dipertahankan sehingga pembaruan sebagian tidak mereset retensi.
        $kebijakan->backup_retensi_hari = $request->filled('backup_retensi_hari')
            ? (int) $request->backup_retensi_hari
            : Setting::backupRetensiHari();

        $kebijakan->save();

        // Nilai terbaru langsung tersedia real-time bagi Controller/Service/Scheduler.
        Setting::flush();

        return redirect()->back()->with(
            'success',
            'Master Settings berhasil diperbarui. Simpanan Pokok Rp '
            .number_format($pokok, 0, ',', '.').' • Simpanan Wajib Rp '
            .number_format($wajib, 0, ',', '.').'/bulan • Nominal pembukaan rekening Rp '
            .number_format($minPembukaan, 0, ',', '.').' – Rp '
            .number_format($maksPembukaan, 0, ',', '.').' • Penahanan SHU: '
            .($kebijakan->shu_hold_on_overdue ? 'Aktif' : 'Nonaktif')
            .' • Alokasi SHU: '.number_format(Setting::totalPersenAlokasiShu(), 0, ',', '.').'%'
            .' • Limit approval beban: Rp '.number_format($limitApprovalBeban, 0, ',', '.')
            .' • Kolektibilitas: '.$kol2.'/'.$kol3.'/'.$kol4.' hari'
            .' • Rate deposito: '.number_format((float) $kebijakan->deposito_rate_pa * 100, 2, ',', '.').'% p.a.'
            .' • CKPN Kol-5: '.number_format((float) $kebijakan->ckpn_persen_kol5, 2, ',', '.').'%'
            .' • Pembulatan jurnal: '.(int) $kebijakan->pembulatan_desimal.' desimal'
            .' • Simpanan Wajib: kanal '.$kebijakan->simpanan_wajib_kanal_default
            .', penarikan '.($kebijakan->simpanan_wajib_boleh_ditarik
                ? 'diizinkan ('.$kebijakan->simpanan_wajib_hari_penarikan.')'
                : 'tidak diizinkan')
            .'. Perubahan tercatat pada riwayat.'
        );
    }
}
