<?php

namespace App\Http\Controllers\Pengurus;

use App\Events\ShuDidistribusikan;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\ShuPeriod;
use App\Models\Transaksi;
use App\Models\User;
use App\Notifications\ShuDitahanNotification;
use App\Services\AutoJournalingService;
use App\Services\ShuCalculatorService;
use App\Services\TahunBukuService;
use App\Support\Coa;
use App\Support\HierarkiRole;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ShuController extends Controller
{
    /**
     * Akun CoA untuk jurnal distribusi SHU (PSAK 101/109).
     * Debet  : 3-9000 Ikhtisar SHU / Laba Ditahan
     * Kredit : 3-2001 Dana Cadangan Koperasi
     *          2-1001 Simpanan Sukarela Anggota (Jasa Modal + Jasa Transaksi)
     *          2-1004 Utang SHU Ditahan (SHU anggota yang di-HOLD)
     *          2-2001 Utang Dana Pengurus & Karyawan
     *          2-3003 Dana Sosial / Pos Sosial
     */
    private const COA_IKHTISAR_SHU = Coa::IKHTISAR_SHU;

    private const COA_DANA_CADANGAN = Coa::CADANGAN_KOPERASI;

    private const COA_SIMPANAN_SUKARELA = Coa::SIMPANAN_SUKARELA;

    private const COA_SHU_DITAHAN = Coa::SHU_DITAHAN;

    private const COA_DANA_PENGURUS = Coa::UTANG_DANA_PENGURUS;

    private const COA_DANA_SOSIAL = Coa::DANA_SOSIAL;

    /**
     * Kalkulator SHU (Jasa Modal prorated + Jasa Transaksi + penahanan/HOLD).
     * Logika pembagian proporsional ("largest remainder method") berada di
     * ShuCalculatorService::bagiProporsional() agar dipakai bersama modul lain.
     */
    /**
     * Reklasifikasi SHU ditahan menjadi hak anggota: Debet 2-1004 / Kredit 2-1001.
     * Menandai baris distribusi `is_hold = false` dan mencatat mutasi transaksi.
     *
     * T-2 (TINGGI) — jurnal pelepasan WAJIB melewati satu-satunya gerbang
     * posting {@see AutoJournalingService::post()} agar tunduk pada validasi CoA
     * (registri + aktif), aturan PSAK 108/109, gate status transaksi, cut-off
     * periode, dan `pastikanBalance()`. Sebelumnya jurnal dibentuk langsung via
     * `Jurnal::create()`/`JurnalDetail::create()` sehingga jalur ini tidak
     * tunduk pada gerbang tersebut.
     */
    public function __construct(
        private ShuCalculatorService $calculator,
        private AutoJournalingService $journalService,
    ) {}

    // ─── Halaman utama: form input + riwayat ───
    public function index()
    {
        $periods = ShuPeriod::with('user')
            ->orderByDesc('created_at')
            ->get();

        /* ── Notifikasi penahanan SHU (HOLD) pada modul pengelolaan SHU ──
         |
         | Seksi ini menampilkan anggota yang SHU-nya ditahan karena masih
         | menunggak Simpanan Wajib (kebijakan `shu_hold_on_overdue`). Anggota
         | yang punya tunggakan OVERDUE mungkin belum masuk daftar distribusi
         | apa pun (mis. belum ada periode SHU tahun berjalan), sehingga
         | penahanan dihitung REAL-TIME dari status tunggakan — bukan hanya
         | dari baris distribusi yang sudah diproses. */
        // Tahun buku mengikuti kebijakan koperasi (bisa non-kalender), bukan
        // tahun takwim, agar penahanan SHU selaras dengan periode pembagiannya.
        $tahunBerjalan = app(TahunBukuService::class)->tahunBerjalan();
        $ringkasanHold = $this->calculator->ringkasanPenahanan($tahunBerjalan);

        $daftarHold = $ringkasanHold->map(function (array $r) {
            $anggota = Anggota::find($r['anggota_id']);
            $tunggakan = $anggota ? $this->calculator->tunggakanAnggota($anggota) : collect();

            return [
                'anggota_id' => $r['anggota_id'],
                'nama_anggota' => $r['nama_anggota'],
                'no_anggota' => $r['no_anggota'],
                'jumlah_tunggakan' => $tunggakan->count(),
                'total_tunggakan' => Setting::bulatkanUang((float) $tunggakan->sum('amount')),
                'periode_awal' => optional($tunggakan->first())->bill_period,
                'periode_akhir' => optional($tunggakan->last())->bill_period,
                'hold_reason' => $r['hold_reason'] ?? null,
                'url_detail' => $anggota ? route('pengurus.cif.show', $anggota->id) : null,
            ];
        })->sortByDesc('total_tunggakan')->values();

        $jumlahAnggotaHold = $daftarHold->count();
        $totalTunggakanHold = Setting::bulatkanUang((float) $daftarHold->sum('total_tunggakan'));

        // Nilai awal persentase draf SHU diambil dari kebijakan koperasi
        // (Setting) via jembatan 4→5 pos — agar konfigurasi kebijakan dan
        // jalur produksi SHU tidak lagi terputus (temuan audit K-03).
        $defaultAlokasi = Setting::alokasiShuDrafDefault();

        return view('pengurus.shu.index', compact(
            'periods',
            'daftarHold',
            'jumlahAnggotaHold',
            'totalTunggakanHold',
            'tahunBerjalan',
            'defaultAlokasi'
        ));
    }

    // ─── Preview kalkulasi nominal (AJAX) ───
    public function preview(Request $request)
    {
        $total = (float) $request->total_shu_bersih;
        $pCadang = (float) $request->persen_cadangan;
        $pModal = (float) $request->persen_jasa_modal;
        $pTrans = (float) $request->persen_jasa_transaksi;
        $pPengurus = (float) $request->persen_pengurus;
        $pSosial = (float) $request->persen_sosial;

        return response()->json([
            'nominal_cadangan' => Setting::bulatkanUang($total * $pCadang / 100),
            'nominal_jasa_modal' => Setting::bulatkanUang($total * $pModal / 100),
            'nominal_jasa_transaksi' => Setting::bulatkanUang($total * $pTrans / 100),
            'nominal_pengurus' => Setting::bulatkanUang($total * $pPengurus / 100),
            'nominal_sosial' => Setting::bulatkanUang($total * $pSosial / 100),
            'total_persen' => Setting::bulatkanUang($pCadang + $pModal + $pTrans + $pPengurus + $pSosial),
        ]);
    }

    // ─── Simpan Draft SHU ───
    public function store(Request $request)
    {
        $request->validate([
            'tahun_buku' => 'required|string|max:20|unique:shu_periods,tahun_buku',
            'total_shu_bersih' => 'required|numeric|min:1',
            'persen_cadangan' => 'required|numeric|min:0|max:100',
            'persen_jasa_modal' => 'required|numeric|min:0|max:100',
            'persen_jasa_transaksi' => 'required|numeric|min:0|max:100',
            'persen_pengurus' => 'required|numeric|min:0|max:100',
            'persen_sosial' => 'required|numeric|min:0|max:100',
        ], [
            'tahun_buku.unique' => 'SHU untuk tahun buku ini sudah ada.',
        ]);

        $total = (float) $request->total_shu_bersih;
        $pC = (float) $request->persen_cadangan;
        $pM = (float) $request->persen_jasa_modal;
        $pT = (float) $request->persen_jasa_transaksi;
        $pP = (float) $request->persen_pengurus;
        $pS = (float) $request->persen_sosial;

        // Alokasi wajib tepat 100% agar seluruh SHU terdistribusi habis
        // tanpa sisa yang menggantung di akun Ikhtisar SHU.
        $totalPersen = Setting::bulatkanUang($pC + $pM + $pT + $pP + $pS);
        if ($totalPersen !== 100.00) {
            return back()
                ->withErrors(['persen' => 'Total alokasi harus tepat 100%. Saat ini '.number_format($totalPersen, 2, ',', '.').'%.'])
                ->withInput();
        }

        // ── Integritas laba riil (PSAK 101 / anti-over-distribution) ──────
        // Total SHU tidak boleh melebihi saldo akun 3-9000 Ikhtisar SHU, yaitu
        // laba (Pendapatan − Beban) yang sudah dipindahkan Tutup Buku. Validasi
        // ini menjaga SHU tetap bersumber dari laba riil jurnal — bukan sekadar
        // angka input manual — sehingga distribusi tidak melebihi kemampuan.
        $coaIkhtisar = Coa::terdaftar(
            self::COA_IKHTISAR_SHU,
            'validasi SHU terhadap laba riil',
            'account_ikhtisar_shu_id'
        );
        $saldoIkhtisar = (float) $coaIkhtisar->saldoBerjalan();

        if ($total > Setting::bulatkanUang($saldoIkhtisar)) {
            return back()
                ->withErrors(['total_shu_bersih' => 'Total SHU bersih (Rp '
                    .number_format($total, 2, ',', '.')
                    .') melebihi saldo Ikhtisar SHU / laba riil pada buku besar (Rp '
                    .number_format($saldoIkhtisar, 2, ',', '.')
                    .'). Jalankan Tutup Buku tahun buku terkait lebih dulu atau '
                    .'perbaiki nilai SHU agar tidak melebihi laba riil.'])
                ->withInput();
        }

        $shu = ShuPeriod::create([
            'tahun_buku' => $request->tahun_buku,
            'total_shu_bersih' => $total,
            'persen_cadangan' => $pC,
            'persen_jasa_modal' => $pM,
            'persen_jasa_transaksi' => $pT,
            'persen_pengurus' => $pP,
            'persen_sosial' => $pS,
            'nominal_cadangan' => Setting::bulatkanUang($total * $pC / 100),
            'nominal_jasa_modal' => Setting::bulatkanUang($total * $pM / 100),
            'nominal_jasa_transaksi' => Setting::bulatkanUang($total * $pT / 100),
            'nominal_pengurus' => Setting::bulatkanUang($total * $pP / 100),
            'nominal_sosial' => Setting::bulatkanUang($total * $pS / 100),
            'status' => 'Draft',
            'user_id' => Auth::id(),
        ]);

        /* Jejak audit (append-only + rantai hash) — dokumen sumber SHU wajib
         | dapat dilacak ke regulator: siapa membuat draft, kapan, dan berapa
         | alokasi tiap porsi. Nominal yang dicatat = total SHU yang akan
         | dibagikan sesuai persentase yang disetujui pada saat penyimpanan. */
        AuditLog::record(
            'SHU — Draft Periode Dibuat',
            (float) $total,
            'SHU-'.$shu->id,
            'Draft SHU tahun buku '.$request->tahun_buku.' dibuat (total SHU Rp '
            .number_format((float) $total, 2, ',', '.').'). Alokasi: cadangan '
            .$pC.'%, jasa modal '.$pM.'%, jasa transaksi '.$pT
            .'%, pengurus/pengawas '.$pP.'%, dana sosial '.$pS.'%.'
        );

        // Jika user klik "Proses & Distribusikan SHU", langsung proses setelah simpan
        if ($request->input('_proses') === '1') {
            return redirect()->route('pengurus.shu.proses', $shu->id)
                ->with('_auto_proses', true);
        }

        return redirect()->route('pengurus.shu.index')
            ->with('success', 'Draft SHU Tahun Buku '.$request->tahun_buku.' berhasil disimpan.');
    }

    // ─── Detail distribusi per anggota ───
    public function show($id)
    {
        $shu = ShuPeriod::find($id);

        if (! $shu) {
            return redirect()->route('pengurus.shu.index')
                ->with('error', 'Data periode SHU tidak ditemukan.');
        }

        $distributions = $shu->distributions()
            ->with('anggota', 'rekening')
            ->orderByDesc('total_shu')
            ->get();

        /* Baris yang SHU-nya DITAHAN (HOLD) karena anggota masih menunggak
         | Simpanan Wajib. Dipisahkan agar halaman detail menampilkan seksi
         | notifikasi penahanan tersendiri — lengkap dengan alasan, nominal
         | tunggakan, dan tombol pelepas penahanan (setelah lunas). */
        $holdRows = $distributions->where('is_hold', true)->values();

        return view('pengurus.shu.detail', compact('shu', 'distributions', 'holdRows'));
    }

    // ─── Rincian SHU per anggota (JSON, untuk modal Detail di halaman indeks) ───
    /**
     * Mengembalikan daftar rincian SHU per anggota dalam format JSON agar
     * halaman indeks bisa menampilkan modal tanpa reload halaman.
     *
     * Kolom yang dikirim sengaja sudah diformat (rupiah & persen) supaya
     * tampilan di modal konsisten dengan format Indonesia, sekaligus tetap
     * mengirim nilai mentah (raw) untuk keperluan pengurutan di sisi klien.
     */
    public function detailAnggota($id)
    {
        $shu = ShuPeriod::find($id);

        if (! $shu) {
            return response()->json([
                'success' => false,
                'message' => 'Data periode SHU tidak ditemukan.',
            ], 404);
        }

        $distributions = $shu->distributions()
            ->with('anggota:id,no_anggota,nama_lengkap')
            ->get();

        $totalModal = (float) $shu->nominal_jasa_modal;
        $totalTransaksi = (float) $shu->nominal_jasa_transaksi;

        $baris = $distributions->map(function (ShuDistribution $d) {
            $simpanan = (float) $d->total_simpanan_modal;
            $margin = (float) $d->total_transaksi_bagi_hasil;
            $shuModal = (float) $d->shu_jasa_modal;
            $shuTrx = (float) $d->shu_jasa_transaksi;
            $total = (float) $d->total_shu;

            return [
                'anggota_id' => $d->anggota_id,
                'no_anggota' => $d->anggota->no_anggota ?? '-',
                'nama_anggota' => $d->anggota->nama_lengkap ?? '(anggota terhapus)',
                // Nilai mentah untuk sorting & agregasi di sisi klien
                'raw' => [
                    'simpanan' => $simpanan,
                    'margin' => $margin,
                    'shu_modal' => $shuModal,
                    'shu_transaksi' => $shuTrx,
                    'total_shu' => $total,
                ],
                // Nilai terformat untuk ditampilkan langsung
                'simpanan' => 'Rp '.number_format($simpanan, 2, ',', '.'),
                'margin' => 'Rp '.number_format($margin, 2, ',', '.'),
                'shu_modal' => 'Rp '.number_format($shuModal, 2, ',', '.'),
                'shu_transaksi' => 'Rp '.number_format($shuTrx, 2, ',', '.'),
                'total_shu' => 'Rp '.number_format($total, 2, ',', '.'),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'periode' => [
                'id' => $shu->id,
                'tahun_buku' => $shu->tahun_buku,
                'status' => $shu->status,
                'is_distributed' => (bool) $shu->is_distributed,
                'total_shu_bersih' => 'Rp '.number_format((float) $shu->total_shu_bersih, 2, ',', '.'),
                'total_jasa_modal' => 'Rp '.number_format($totalModal, 2, ',', '.'),
                'total_jasa_transaksi' => 'Rp '.number_format($totalTransaksi, 2, ',', '.'),
                'jumlah_anggota' => $baris->count(),
            ],
            // Sudah diurutkan berdasarkan nama anggota (default requirement).
            'data' => $baris->sortBy(
                fn ($r) => mb_strtolower($r['nama_anggota'])
            )->values(),
        ]);
    }

    // ─── Proses & Bagikan SHU ───
    public function proses($id)
    {
        $shu = ShuPeriod::find($id);

        if (! $shu) {
            return redirect()->route('pengurus.shu.index')
                ->with('error', 'Data periode SHU tidak ditemukan.');
        }

        if (! $shu->isDraft()) {
            return back()->with('error', 'SHU ini sudah diproses sebelumnya.');
        }

        // Inisialisasi di luar closure agar nilai tetap terlihat saat menyusun
        // pesan sukses setelah transaksi database selesai.
        $totalSHUHold = 0.0;
        $jumlahHold = 0;
        $anggotaHold = [];

        /* Seluruh proses distribusi berjalan dalam satu transaksi: bila saldo
         | akun 3-9000 Ikhtisar SHU tidak mencukupi (atau ada kegagalan lain),
         | RuntimeException dilempar dari dalam transaksi dan ditangkap di sini
         | agar rollback terjadi dan pengguna menerima pesan yang bisa
         | ditindaklanjuti — bukan halaman error 500. */
        try {
            DB::transaction(function () use ($shu, &$totalSHUHold, &$jumlahHold, &$anggotaHold) {

                /* BUG-19 — Gerbang status dibaca ULANG di dalam transaksi dengan
                 | `lockForUpdate()`. Tanpa kunci, dua pengurus yang menekan
                 | "Proses" hampir bersamaan sama-sama lolos gerbang Draft di
                 | luar transaksi dan SHU tercairkan DUA KALI. */
                $terkunci = ShuPeriod::whereKey($shu->getKey())->lockForUpdate()->first();

                if (! $terkunci || ! $terkunci->isDraft()) {
                    throw new \RuntimeException('SHU ini sudah diproses sebelumnya.');
                }

                $shu->setRawAttributes($terkunci->getAttributes(), true);

                /* ── 1. Hitung basis kalkulasi seluruh anggota aktif ──
                 |
                 | Basis Jasa Modal BUKAN lagi saldo statis `simpanan_pokok +
                 | simpanan_wajib` × bulan aktif, melainkan RATA-RATA SALDO
                 | PENGENDAPAN (`monthly_average_balance`) akun Simpanan Pokok &
                 | Simpanan Wajib sepanjang tahun buku, diprorata dengan
                 | `tenure_months` masa keanggotaan efektif anggota.
                 |
                 | Keputusan penahanan (HOLD) juga ditetapkan di sini: anggota
                 | yang masih memiliki tagihan Simpanan Wajib OVERDUE pada saat
                 | penutupan tahun buku tidak menerima pencairan SHU-nya. */
                $dasar = $this->calculator->hitungDasar((string) $shu->tahun_buku, (string) $shu->id);

                $besaran = [];
                foreach ($dasar['baris'] as $b) {
                    $besaran[] = [
                        'anggota' => $b['anggota'],
                        'monthly_average_balance' => $b['monthly_average_balance'],
                        'tenure_months' => $b['tenure_months'],
                        'total_simpanan_modal' => $b['monthly_average_balance'],
                        'bobot_modal' => $b['bobot_modal'],
                        'transaksi_anggota' => $b['bobot_transaksi'],
                        'is_hold' => $b['is_hold'],
                        'hold_reason' => $b['hold_reason'],
                    ];
                }

                $nominalModal = (float) $shu->nominal_jasa_modal;
                $nominalTransaksi = (float) $shu->nominal_jasa_transaksi;

                // Bagi proporsional dengan "largest remainder method" (pembulatan sen),
                // sehingga SUM(hasil per anggota) === nominal alokasi secara PASTI —
                // bukan sekadar mendekati. Tanpa ini, pembulatan round() per anggota
                // bisa menyisakan/melampaui beberapa sen yang membuat jurnal tidak balance.
                $porsiModal = $this->calculator->bagiProporsional(array_column($besaran, 'bobot_modal'), $nominalModal);
                $porsiTransaksi = $this->calculator->bagiProporsional(array_column($besaran, 'transaksi_anggota'), $nominalTransaksi);

                $totalSHUAnggota = 0;
                $distributions = [];
                // F-06 — seluruh akun SHU diambil dari CoA TERDAFTAR; tidak ada
                // lagi pembuatan akun otomatis dari jalur transaksi.
                $coaSukarela = Coa::terdaftar(self::COA_SIMPANAN_SUKARELA, 'distribusi SHU ke Simpanan Sukarela', 'account_simpanan_sukarela_id');

                /* Akun penampung SHU yang DITAHAN (mode HOLD). SHU milik anggota
                 | yang masih menunggak TIDAK dikreditkan ke Simpanan Sukarela
                 | anggota (2-1001) karena secara substansi belum menjadi hak
                 | anggota sampai tunggakan dilunasi — melainkan menjadi utang
                 | koperasi kepada anggota (kewajiban jangka pendek). */
                $coaHold = Coa::terdaftar(self::COA_SHU_DITAHAN, 'penampung SHU ditahan (HOLD)', 'account_shu_ditahan_id');

                foreach ($besaran as $i => $row) {
                    $anggota = $row['anggota'];
                    $transaksiAnggota = $row['transaksi_anggota'];

                    $shuModal = $porsiModal[$i] ?? 0.0;
                    $shuTransaksi = $porsiTransaksi[$i] ?? 0.0;

                    $totalShu = Setting::bulatkanUang($shuModal + $shuTransaksi);

                    // Anggota tanpa simpanan & tanpa partisipasi transaksi tidak
                    // mendapat bagian — tapi TIDAK memengaruhi keseimbangan jurnal
                    // karena kredit 2-1001 diambil dari akumulasi nyata di bawah.
                    if ($totalShu <= 0) {
                        continue;
                    }

                    /* Mode HOLD — anggota masih punya tagihan Simpanan Wajib
                     | OVERDUE. Distribusi tetap dicatat sebagai dokumen (agar
                     | jejak audit & nominal hak anggota terlihat), namun TIDAK
                     | ada pencairan: rekening tidak diubah dan tidak ada mutasi
                     | transaksi. Kreditnya masuk ke akun utang SHU ditahan. */
                    if ($row['is_hold']) {
                        $distributions[] = [
                            'anggota' => $anggota,
                            'rekening' => null,
                            'monthly_average_balance' => $row['monthly_average_balance'],
                            'tenure_months' => $row['tenure_months'],
                            'transaksi_anggota' => $transaksiAnggota,
                            'shu_modal' => $shuModal,
                            'shu_transaksi' => $shuTransaksi,
                            'total_shu' => $totalShu,
                            'is_hold' => true,
                            'hold_reason' => $row['hold_reason'],
                        ];

                        $totalSHUHold += $totalShu;
                        $jumlahHold++;

                        /* Simpan ringkasan anggota ter-HOLD untuk pengiriman
                         | notifikasi database SETELAH transaksi commit —
                         | notifikasi tidak boleh ikut ter-rollback bersama
                         | transaksi, dan tidak boleh dikirim bila distribusi
                         | gagal di tengah jalan. */
                        $anggotaHold[$anggota->id] = [
                            'anggota' => $anggota,
                            'total_shu' => $totalShu,
                            'tunggakan' => $this->calculator->tunggakanAnggota($anggota),
                            'status' => ['is_hold' => true, 'hold_reason' => $row['hold_reason']],
                        ];

                        continue;
                    }

                    // Cari rekening Simpanan Sukarela anggota — auto-create jika belum ada
                    $rekening = Rekening::where('anggota_id', $anggota->id)
                        ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                        ->where('status_rekening', 'Aktif')
                        ->first();

                    if (! $rekening) {
                        /* BUG-23 — `rekening.akun_id` adalah FK ke tabel `akun`
                         | (legacy chart-of-accounts), BUKAN ke tabel `coas`.
                         | Dulu ditulis `$coaSukarela->id` (= id `coas`), sehingga
                         | rekening menunjuk akun yang tidak ada dan saldo
                         | anggota tampil di bagan akun yang salah. */
                        $akunId = JenisSimpanan::akunLegacyId(
                            JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA)
                        );

                        if ($akunId === null) {
                            throw new \RuntimeException(
                                'Akun legacy "Simpanan Sukarela Anggota" tidak ditemukan — distribusi SHU dibatalkan.'
                            );
                        }

                        $noRekBaru = 'SHU-'.$shu->tahun_buku.'-'.str_pad($anggota->id, 8, '0', STR_PAD_LEFT);
                        $rekening = Rekening::firstOrCreate(
                            ['no_rekening' => $noRekBaru],
                            [
                                'anggota_id' => $anggota->id,
                                'akun_id' => $akunId,
                                'jenis_rekening' => JenisSimpanan::namaUtama(JenisSimpanan::TIPE_SUKARELA),
                                'akad' => 'Wadiah',
                                'saldo' => 0,
                                'batas_minimum' => 0,
                                'tanggal_buka' => now()->toDateString(),
                                'status_rekening' => 'Aktif',
                                'keterangan' => 'Rekening otomatis dibuat saat distribusi SHU '.$shu->tahun_buku,
                            ]
                        );
                    }

                    $distributions[] = [
                        'anggota' => $anggota,
                        'rekening' => $rekening,
                        'monthly_average_balance' => $row['monthly_average_balance'],
                        'tenure_months' => $row['tenure_months'],
                        'transaksi_anggota' => $transaksiAnggota,
                        'shu_modal' => $shuModal,
                        'shu_transaksi' => $shuTransaksi,
                        'total_shu' => $totalShu,
                        'is_hold' => false,
                        'hold_reason' => null,
                    ];

                    $totalSHUAnggota += $totalShu;
                }

                $totalSHUAnggota = Setting::bulatkanUang($totalSHUAnggota);
                $totalSHUHold = Setting::bulatkanUang($totalSHUHold);

                /* ── 2a-bis. Selaraskan ke KEBIJAKAN PEMBULATAN koperasi ──
                 | Seluruh jalur tulis jurnal (AutoJournalingService::post)
                 | membulatkan tiap nominal dengan Setting::pembulatanNominal()
                 | (default: satuan rupiah penuh). Agar nilai yang DISIMPAN pada
                 | dokumen (ShuDistribution, ShuPeriod.total_shu_hold) selalu
                 | sama persis dengan baris jurnal — dan neraca tidak pernah
                 | selisih — pembulatan yang sama diterapkan lebih dulu di sini,
                 | sebelum rekonsiliasi pos. Kebijakan nol-desimal (default)
                 | karena itu menjadi rupiah utuh; bila koperasi memilih
                 | pembulatan 2 desimal, sen tetap dipertahankan. */
                $totalSHUAnggota = Setting::pembulatanNominal($totalSHUAnggota);
                $totalSHUHold = Setting::pembulatanNominal($totalSHUHold);

                // ── 2b. Rekonsiliasi pembulatan agar jurnal SELALU balance ──
                // Setiap kolom nominal_* dibulatkan sendiri-sendiri saat input RAT,
                // sehingga SUM(nominal pos) bisa berbeda ±beberapa sen dari
                // total_shu_bersih. Debet jurnal memakai total_shu_bersih, maka
                // selisih itu harus diserap ke pos cadangan (akun ekuitas) —
                // bukan dibiarkan menggantung sehingga debet ≠ kredit.
                // SHU yang ditahan (HOLD) tetap bagian dari kredit pos anggota —
                // hanya akun lawannya yang berbeda (utang, bukan simpanan).
                // Selaraskan pos lain & debet ke KEBIJAKAN PEMBULATAN yang sama
                // agar penjumlahan kredit tepat sama dengan debet jurnal.
                $nominalPengurus = Setting::pembulatanNominal((float) $shu->nominal_pengurus);
                $nominalSosial = Setting::pembulatanNominal((float) $shu->nominal_sosial);
                $nominalCadanganAwal = Setting::pembulatanNominal((float) $shu->nominal_cadangan);
                $totalDebetShu = Setting::pembulatanNominal((float) $shu->total_shu_bersih);

                $totalKreditPos = Setting::bulatkanUang(
                    $nominalCadanganAwal
                    + $totalSHUAnggota
                    + $totalSHUHold
                    + $nominalPengurus
                    + $nominalSosial);

                $nominalCadangan = Setting::bulatkanUang($nominalCadanganAwal
                    + ($totalDebetShu - $totalKreditPos));

                // Jaga-jaga: jangan sampai cadangan jadi negatif karena anomali data.
                if ($nominalCadangan < 0) {
                    $nominalCadangan = 0.0;
                }

                // ── 3. Auto-Journaling (Double Entry) — SATU JALUR POSTING ──
                // T-2 (TINGGI) — jurnal distribusi SHU kini dibentuk lewat
                // `AutoJournalingService::post()` (bukan `Jurnal::create()`
                // langsung) agar tunduk pada validasi CoA/PSAK 108-109/
                // cut-off/gate transaksi & pengaman balance yang sama.
                $coaIkhtisar = Coa::terdaftar(self::COA_IKHTISAR_SHU, 'distribusi SHU dari Ikhtisar SHU', 'account_ikhtisar_shu_id');

                $saldoIkhtisar = (float) $coaIkhtisar->saldoBerjalan();

                if ($saldoIkhtisar < (float) $shu->total_shu_bersih) {
                    throw new \RuntimeException(
                        'Saldo akun 3-9000 Ikhtisar SHU tidak mencukupi untuk distribusi ini. '
                        .'Saldo tersedia: Rp '.number_format($saldoIkhtisar, 2, ',', '.')
                        .', SHU yang akan dibagikan: Rp '.number_format((float) $shu->total_shu_bersih, 2, ',', '.')
                        .'. Jalankan Tutup Buku tahun buku terkait lebih dulu agar laba (Pendapatan −’ Beban) '
                        .'dipindahkan ke sisi Kredit akun 3-9000.'
                    );
                }

                /* DEBET: 3-9000 Ikhtisar SHU (sebesar total SHU bersih)
                 |
                 | CATATAN AKUNTANSI — akun 3-9000 adalah EKUITAS/MODAL (Kepala 3),
                 | berposisi KREDIT. Mendebet akun ini berarti MENGURANGI ekuitas
                 | (laba ditahan) sebesar SHU yang dibagikan.
                 |
                 | KREDIT: 3-2001 Dana Cadangan (memakai $nominalCadangan yang sudah
                 | direkonsiliasi), 2-1001 Simpanan Sukarela Anggota, 2-1004 Utang SHU
                 | Ditahan (baris HOLD), 2-2001 Dana Pengurus & Karyawan, dan
                 | 2-3003 Dana Sosial / Pendidikan — hanya bila nominalnya > 0. */
                $entriesShu = [
                    ['kode' => $coaIkhtisar->kode_akun, 'debet' => $totalDebetShu, 'kredit' => 0],
                ];

                $coaCadangan = Coa::terdaftar(self::COA_DANA_CADANGAN, 'alokasi Dana Cadangan', 'account_dana_cadangan_id');
                if ($nominalCadangan > 0) {
                    $entriesShu[] = ['kode' => $coaCadangan->kode_akun, 'debet' => 0, 'kredit' => $nominalCadangan];
                }

                // Nilai diambil dari akumulasi nyata hasil pembagian proporsional
                // ($totalSHUAnggota) agar jurnal selalu balance meski ada anggota
                // yang tidak mendapat bagian. Anggota berstatus HOLD tidak termasuk
                // di sini — porsinya dikreditkan ke 2-1004 di bawah.
                if ($totalSHUAnggota > 0) {
                    $entriesShu[] = ['kode' => $coaSukarela->kode_akun, 'debet' => 0, 'kredit' => $totalSHUAnggota];
                }

                // SHU anggota yang masih menunggak Simpanan Wajib ditahan sebagai
                // utang koperasi sampai seluruh tunggakan dilunasi.
                if ($totalSHUHold > 0) {
                    $entriesShu[] = ['kode' => $coaHold->kode_akun, 'debet' => 0, 'kredit' => $totalSHUHold];
                }

                $coaPengurus = Coa::terdaftar(self::COA_DANA_PENGURUS, 'alokasi Dana Pengurus & Karyawan', 'account_dana_pengurus_id');
                if ($nominalPengurus > 0) {
                    $entriesShu[] = ['kode' => $coaPengurus->kode_akun, 'debet' => 0, 'kredit' => $nominalPengurus];
                }

                $coaSosial = Coa::terdaftar(self::COA_DANA_SOSIAL, 'alokasi Dana Sosial & Pendidikan', 'account_dana_sosial_id');
                if ($nominalSosial > 0) {
                    $entriesShu[] = ['kode' => $coaSosial->kode_akun, 'debet' => 0, 'kredit' => $nominalSosial];
                }

                $jurnal = $this->journalService->post(
                    'Pembagian SHU Tahun Buku '.$shu->tahun_buku,
                    $entriesShu,
                    'SHU',
                    null,
                    null,
                    Auth::id()
                );

                // ── 3. Distribusi ke rekening + mutasi anggota ──
                // Baris berstatus HOLD tidak memiliki rekening (null) sehingga
                // otomatis dilewati: saldo tidak bertambah dan tidak ada mutasi.
                foreach ($distributions as $d) {
                    $anggota = $d['anggota'];
                    $rekening = $d['rekening'];
                    $totalShu = $d['total_shu'];

                    $transaksiId = null;

                    if ($rekening) {
                        // BUG-05 — Kunci baris rekening sebelum read-modify-write
                        // saldo. Tanpa lock, dua proses yang menyentuh rekening
                        // sama secara bersamaan dapat saling menimpa (lost update).
                        $rekening = Rekening::whereKey($rekening->id)->lockForUpdate()->first();
                        $saldoBaru = (float) $rekening->saldo + $totalShu;
                        $rekening->update(['saldo' => $saldoBaru]);

                        // Buat mutasi transaksi
                        $noTrx = 'SHU-TRX-'.now()->format('YmdHis').'-'.$anggota->id;
                        $trx = Transaksi::create([
                            'no_transaksi' => $noTrx,
                            'waktu_transaksi' => now(),
                            'rekening_id' => $rekening->id,
                            'anggota_id' => $anggota->id,
                            'jenis_transaksi' => 'Setoran SHU Tahun Buku '.$shu->tahun_buku,
                            'posisi' => 'Debet',
                            'jumlah' => $totalShu,
                            'saldo_setelah' => $saldoBaru,
                            'user_id' => Auth::id(),
                            'sumber_transaksi' => 'SHU',
                            'status_transaksi' => 'Sukses',
                            'status' => 'success',
                            'referensi' => 'SHU-'.$shu->tahun_buku,
                            'catatan' => 'Pembagian SHU Jasa Modal & Jasa Transaksi Tahun '.$shu->tahun_buku,
                        ]);
                        $transaksiId = $trx->id;
                    }

                    // Simpan distribusi
                    ShuDistribution::create([
                        'shu_period_id' => $shu->id,
                        'anggota_id' => $anggota->id,
                        'rekening_id' => $rekening?->id,
                        'total_simpanan_modal' => $d['monthly_average_balance'],
                        'monthly_average_balance' => $d['monthly_average_balance'],
                        'tenure_months' => $d['tenure_months'],
                        'total_transaksi_bagi_hasil' => $d['transaksi_anggota'],
                        'shu_jasa_modal' => $d['shu_modal'],
                        'shu_jasa_transaksi' => $d['shu_transaksi'],
                        'total_shu' => $totalShu,
                        'is_hold' => $d['is_hold'],
                        'hold_reason' => $d['hold_reason'],
                        'transaksi_id' => $transaksiId,
                    ]);
                }

                // ── 4. Update status period ──
                $shu->update([
                    'status' => 'Selesai',
                    'is_distributed' => true,
                    'jurnal_id' => $jurnal->id,
                    'diproses_at' => now(),
                    'total_shu_hold' => $totalSHUHold,
                    'jumlah_anggota_hold' => $jumlahHold,
                ]);
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = 'SHU Tahun Buku '.$shu->tahun_buku.' berhasil diproses dan dibagikan ke seluruh anggota aktif.';

        /* Jejak audit distribusi SHU. Ditulis SETELAH transaksi database
         | ter-commit (bukan di dalamnya) mengikuti pola modul lain: baris audit
         | append-only tidak boleh ikut ter-rollback, dan hanya dicatat bila
         | distribusi benar-benar berhasil. */
        AuditLog::record(
            'SHU — Distribusi ke Anggota',
            (float) $shu->total_shu_bersih,
            'SHU-'.$shu->id,
            'SHU tahun buku '.$shu->tahun_buku.' diproses & didistribusikan '
            .'(bersih Rp '.number_format((float) $shu->total_shu_bersih, 2, ',', '.')
            .'; jurnal #'.($shu->jurnal_id ?? '-').').'
            .($jumlahHold > 0
                ? ' '.$jumlahHold.' anggota ditahan (HOLD) senilai Rp '
                  .number_format($totalSHUHold, 2, ',', '.').'.'
                : ' Tidak ada penahanan.')
        );

        /*
         | Publikasikan peristiwa domain SETELAH distribusi ter-commit.
         | Dibungkus try/catch: kegagalan listener tidak boleh menggagalkan
         | distribusi SHU yang sudah tersimpan (fail-safe, aditif).
         */
        try {
            ShuDidistribusikan::dispatch(
                $shu->refresh(),
                (float) $shu->total_shu_bersih,
                Auth::id(),
            );
        } catch (\Throwable $e) {
            Log::warning(
                'Gagal mempublikasikan peristiwa ShuDidistribusikan: '.$e->getMessage()
            );
        }

        if ($jumlahHold > 0) {
            $pesan .= ' '.$jumlahHold.' anggota ditahan (HOLD) karena masih memiliki tunggakan '
                .'Simpanan Wajib senilai Rp '.number_format($totalSHUHold, 2, ',', '.').'.';

            // Notifikasi database ke seluruh Pengurus (setelah commit agar
            // tidak ikut ter-rollback dan hanya terkirim bila distribusi sukses).
            $this->kirimNotifikasiHold($anggotaHold, (string) $shu->tahun_buku);
        }

        return redirect()->route('pengurus.shu.show', $shu->id)
            ->with('success', $pesan);
    }

    /**
     * Kirim notifikasi database penahanan SHU ke seluruh Pengurus.
     *
     * Kegagalan pengiriman notifikasi TIDAK boleh menggagalkan proses
     * distribusi SHU yang sudah commit — karenanya seluruh exception
     * ditelan dan hanya dicatat pada log.
     *
     * @param  array<int, array{anggota:Anggota,total_shu:float,tunggakan:Collection,status:array{is_hold:bool,hold_reason:?string}}>  $anggotaHold
     */
    private function kirimNotifikasiHold(array $anggotaHold, string $tahunBuku): void
    {
        if (empty($anggotaHold)) {
            return;
        }

        try {
            $penerima = User::whereIn('role', HierarkiRole::kelompok('notifikasi_shu_ditahan'))->get();

            if ($penerima->isEmpty()) {
                return;
            }

            foreach ($anggotaHold as $ringkasan) {
                Notification::send(
                    $penerima,
                    ShuDitahanNotification::dariAnggota(
                        $ringkasan['anggota'],
                        $ringkasan['status'],
                        $ringkasan['tunggakan'],
                        (float) $ringkasan['total_shu'],
                        $tahunBuku
                    )
                );
            }
        } catch (\Throwable $e) {
            Log::warning(
                'Gagal mengirim notifikasi penahanan SHU: '.$e->getMessage()
            );
        }
    }

    /* =====================================================================
     | PELEPASAN PENAHANAN SHU (HOLD RELEASE)
     |
     | Penahanan SHU bersifat SISTEMIK dan SEMENTARA. Begitu anggota melunasi
     | seluruh tagihan Simpanan Wajib yang OVERDUE, hak SHU-nya tidak boleh
     | lagi menggantung di akun utang 2-1004. Pelepasan dapat dilakukan dua
     | cara:
     |
     |  1. OTOMATIS — `otomatis` dipanggil tanpa intervensi manual (mis. dari
     |     scheduler harian setelah tagihan jatuh tempo dilunasi, atau tombol
     |     "Lepas Semua Penahanan") untuk seluruh periode & anggota yang
     |     tunggakannya sudah bersih.
     |  2. MANUAL per anggota — `lepaskanPenahanan` untuk koreksi/eskalasi
     |     oleh pengurus.
     |
     | Jurnal pelepasan: Debet 2-1004 (Utang SHU Ditahan) / Kredit 2-1001
     | (Simpanan Sukarela anggota). Tidak ada beban baru — hanya reklasifikasi
     | kewajiban menjadi hak anggota yang benar-benar dicairkan.
     ===================================================================== */

    /** Lepas penahanan SHU satu anggota pada satu periode. */
    public function lepaskanPenahanan($id, $distribusiId)
    {
        $shu = ShuDistribution::with(['period'])->findOrFail($distribusiId);

        if ((int) $shu->shu_period_id !== (int) $id) {
            return back()->with('error', 'Data distribusi SHU tidak sesuai dengan periode.');
        }

        if (! $shu->is_hold) {
            return back()->with('info', 'SHU anggota ini tidak sedang dalam penahanan.');
        }

        $anggota = Anggota::find($shu->anggota_id);

        if (! $anggota) {
            return back()->with('error', 'Data anggota pemilik SHU tidak ditemukan.');
        }

        /* BUG-20 — Klaim baris secara ATOMIK. Pemeriksaan `is_hold` di atas
         | hanya untuk pesan ramah; keputusan mencairkan diambil dari
         | `lockForUpdate()` di dalam transaksi. Tanpa ini, klik ganda
         | (dua permintaan berurutan) sama-sama lolos dan saldo Sukarela
         | bertambah DUA kali. */
        $berhasil = DB::transaction(function () use ($shu, $anggota) {
            $diklaim = ShuDistribution::whereKey($shu->getKey())
                ->where('is_hold', true)
                ->lockForUpdate()
                ->first();

            if (! $diklaim) {
                return false;
            }

            if ($this->calculator->tunggakanAnggota($anggota)->isNotEmpty()) {
                return false;
            }

            // `cairkanShuDitahan()` mengembalikan false bila baris sudah
            // diklaim pemanggilan lain (klik ganda / permintaan bersamaan),
            // sehingga pesan "tidak sedang dalam penahanan" tetap jujur.
            return $this->cairkanShuDitahan($diklaim, $anggota);
        });

        if (! $berhasil) {
            $tunggakan = $this->calculator->tunggakanAnggota($anggota);

            if ($tunggakan->isNotEmpty()) {
                return back()->with('error',
                    'Penahanan SHU belum dapat dilepas. Anggota masih memiliki '
                    .$tunggakan->count().' tagihan Simpanan Wajib OVERDUE. '
                    .'Selesaikan tunggakan terlebih dahulu melalui Teller.');
            }

            return back()->with('info', 'SHU anggota ini tidak sedang dalam penahanan.');
        }

        AuditLog::record(
            'SHU — Pelepasan Penahanan',
            (float) $shu->total_shu,
            'SHU-'.$shu->shu_period_id,
            'Penahanan SHU dilepas untuk '.$anggota->nama_lengkap
            .' ('.($anggota->no_anggota ?? '-').') dan dicairkan ke Simpanan Sukarela '
            .'sebesar Rp '.number_format((float) $shu->total_shu, 2, ',', '.')
            .' setelah tunggakan Simpanan Wajib dilunasi.'
        );

        return back()->with('success',
            'Penahanan SHU '.$anggota->nama_lengkap.' dilepas dan SHU sebesar Rp '
            .number_format((float) $shu->total_shu, 2, ',', '.')
            .' dicairkan ke rekening Simpanan Sukarela.');
    }

    /**
     * Lepas SELURUH penahanan SHU yang tunggakannya sudah lunas.
     *
     * Idempoten dan aman dijalankan berulang (mis. dari scheduler harian):
     * hanya baris `is_hold = true` milik anggota TANPA tagihan OVERDUE yang
     * diproses, sisanya dilewati.
     */
    public function lepasPenahananOtomatis()
    {
        $terbuka = ShuDistribution::with('period')
            ->where('is_hold', true)
            ->get();

        $dilepas = 0;
        $totalNominal = 0.0;

        foreach ($terbuka as $distribusi) {
            /* P-03 — Klaim baris secara ATOMIK sebelum mencairkan: UPDATE
             | bersyarat `is_hold = true` di dalam transaksi yang sama. Tanpa
             | klaim ini, permintaan "lepas massal" yang datang bersamaan
             | sama-sama membaca baris HOLD yang sama dan saldo Sukarela
             | anggota bertambah DUA KALI. */
            $berhasil = DB::transaction(function () use ($distribusi) {
                $diklaim = ShuDistribution::whereKey($distribusi->getKey())
                    ->where('is_hold', true)
                    ->lockForUpdate()
                    ->first();

                if (! $diklaim) {
                    return false;
                }

                $anggota = Anggota::find($diklaim->anggota_id);

                if (! $anggota || $this->calculator->tunggakanAnggota($anggota)->isNotEmpty()) {
                    return false;
                }

                // Klaim akhir + pencairan satu jalur di service (utang #20/#24).
                // Pemanggilan pembungkus DB::transaction() di sini tetap
                // dipertahankan agar seluruh baris periode diperlakukan
                // sebagai satu unit kerja pada permintaan ini.
                return $this->cairkanShuDitahan($diklaim, $anggota);
            });

            if (! $berhasil) {
                continue;
            }

            $dilepas++;
            $totalNominal += (float) $distribusi->total_shu;
        }

        if ($dilepas === 0) {
            return back()->with('info',
                'Tidak ada penahanan SHU yang dapat dilepas saat ini — '
                .'seluruh anggota yang ditahan masih memiliki tunggakan Simpanan Wajib.');
        }

        AuditLog::record(
            'SHU — Pelepasan Penahanan Massal',
            $totalNominal,
            'SHU-TERBUKA',
            $dilepas.' penahanan SHU dilepas OTOMATIS (tunggakan Simpanan Wajib lunas) '
            .'dan dicairkan ke Simpanan Sukarela anggota, total Rp '
            .number_format($totalNominal, 2, ',', '.').'.'
        );

        return back()->with('success',
            $dilepas.' penahanan SHU dilepas otomatis dan dicairkan ke Simpanan Sukarela anggota, '
            .'total Rp '.number_format($totalNominal, 2, ',', '.').'.');
    }

    /**
     * Reklasifikasi SHU ditahan menjadi hak anggota: Debet 2-1004 / Kredit 2-1001.
     *
     * Utang #20/#24 — Logika pencairan TIDAK disalin di sini. Salinan kedua
     * dulu tertinggal setiap kali klaim atomik atau penomoran dokumen
     * diperbaiki di service. Kini satu-satunya jalur adalah
     * {@see ShuCalculatorService::cairkanBilaDalamPenahanan()}.
     *
     * @return bool true bila baris benar-benar dicairkan oleh pemanggilan ini.
     */
    private function cairkanShuDitahan(ShuDistribution $distribusi, Anggota $anggota): bool
    {
        return $this->calculator->cairkanBilaDalamPenahanan($distribusi, $anggota);
    }

    // ─── Hapus draft ───
    public function destroy($id)
    {
        $shu = ShuPeriod::find($id);

        if (! $shu) {
            return redirect()->route('pengurus.shu.index')
                ->with('error', 'Data periode SHU tidak ditemukan.');
        }

        // Cegah penghapusan periode yang sudah dibagikan: jurnal sudah di-posting
        // dan saldo rekening anggota sudah bertambah, sehingga menghapus di sini
        // akan membuat jurnal yatim tanpa dokumen sumber.
        if (! $shu->isDraft() || $shu->is_distributed) {
            return back()->with('error', 'Hanya SHU berstatus Draft yang belum didistribusikan dapat dihapus.');
        }

        // Jejak audit + penghapusan dibungkus satu transaksi: baris audit
        // append-only tidak boleh menyatakan "dihapus" bila penghapusannya gagal.
        DB::transaction(function () use ($shu) {
            // Catat jejak SEBELUM baris dihapus — setelah delete, atribut sumber
            // (tahun buku & nominal) tidak lagi dapat dibaca dari database.
            AuditLog::record(
                'SHU — Draft Dihapus',
                (float) $shu->total_shu_bersih,
                'SHU-'.$shu->id,
                'Draft SHU tahun buku '.$shu->tahun_buku.' (total Rp '
                .number_format((float) $shu->total_shu_bersih, 2, ',', '.')
                .') dihapus sebelum didistribusikan.'
            );

            // P-04 — `ShuPeriod` memakai SoftDeletes sehingga FK
            // `shu_distributions.shu_period_id` (`onDelete('cascade')`) TIDAK
            // berjalan; baris distribusi akan tertinggal yatim. Hapus eksplisit
            // di transaksi yang sama.
            $shu->distributions()->delete();

            $shu->delete();
        });

        return redirect()->route('pengurus.shu.index')
            ->with('success', 'Draft SHU berhasil dihapus.');
    }

    // ─── Export Excel distribusi per anggota ───
    public function exportExcel($id)
    {
        $shu = ShuPeriod::find($id);

        if (! $shu) {
            return redirect()->route('pengurus.shu.index')
                ->with('error', 'Data periode SHU tidak ditemukan.');
        }

        $hasil = HierarkiRole::ambilDenganBatas(
            $shu->distributions()->with('anggota')->orderByDesc('total_shu'),
            auth()->user()?->role
        );

        if ($hasil['melampaui']) {
            return redirect()->route('pengurus.shu.index')->with(
                'error',
                'Volume data melebihi batas akses peran Anda (maksimal '
                .number_format((int) $hasil['batas'], 0, ',', '.')
                .' baris). Persempit periode atau ajukan permohonan ke peran yang berwenang.'
            );
        }

        $distributions = $hasil['data'];

        $filename = 'SHU_'.str_replace('/', '-', $shu->tahun_buku).'_'.now()->format('Ymd').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($shu, $distributions) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 agar Excel tidak garbled
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['LAPORAN DISTRIBUSI SHU TAHUN BUKU '.$shu->tahun_buku], ';');
            fputcsv($handle, ['Total SHU Bersih', 'Rp '.number_format($shu->total_shu_bersih, 2, ',', '.')], ';');
            fputcsv($handle, ['Tanggal Proses', $shu->diproses_at?->format('d/m/Y H:i') ?? '-'], ';');
            fputcsv($handle, [], ';');
            fputcsv($handle, [
                'No', 'No Anggota', 'Nama Anggota',
                'Simpanan Modal (Rp)', 'Total Angsuran/Bagi Hasil (Rp)',
                'SHU Jasa Modal (Rp)', 'SHU Jasa Transaksi (Rp)', 'Total SHU (Rp)',
            ], ';');
            $no = 1;
            foreach ($distributions as $d) {
                fputcsv($handle, [
                    $no++,
                    $d->anggota->no_anggota ?? '-',
                    $d->anggota->nama_lengkap ?? '-',
                    number_format($d->total_simpanan_modal, 2, ',', '.'),
                    number_format($d->total_transaksi_bagi_hasil, 2, ',', '.'),
                    number_format($d->shu_jasa_modal, 2, ',', '.'),
                    number_format($d->shu_jasa_transaksi, 2, ',', '.'),
                    number_format($d->total_shu, 2, ',', '.'),
                ], ';');
            }
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
