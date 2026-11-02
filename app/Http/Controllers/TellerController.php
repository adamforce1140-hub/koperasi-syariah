<?php

namespace App\Http\Controllers;

use App\Actions\Teller\AjukanVoidAction;
use App\Actions\Teller\BayarAngsuranAction;
use App\Actions\Teller\BukaDepositoAction;
use App\Actions\Teller\BukaKasAction;
use App\Actions\Teller\CairkanDepositoAction;
use App\Actions\Teller\ProsesTazirAction;
use App\Actions\Teller\SetorKasKeBrankasAction;
use App\Actions\Teller\SetorTunaiAction;
use App\Actions\Teller\TambahModalKasAction;
use App\Actions\Teller\TarikTunaiAction;
use App\Actions\Teller\TutupKasAction;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\DepositoProduct;
use App\Models\JenisSimpanan;
use App\Models\KasHarian;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Models\TellerOpname;
use App\Models\Transaksi;
use App\Services\AturanLintasHariService;
use App\Services\TazirService;
use App\Support\GuardSimpananModal;
use App\Support\JenisTransaksi;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TellerController extends Controller
{
    public function __construct(
        private TazirService $tazirService,
        private AturanLintasHariService $lintasHari,
        private BukaKasAction $bukaKasAction,
        private SetorTunaiAction $setorTunaiAction,
        private TarikTunaiAction $tarikTunaiAction,
        private TambahModalKasAction $tambahModalKasAction,
        private SetorKasKeBrankasAction $setorKasKeBrankasAction,
        private TutupKasAction $tutupKasAction,
        private BukaDepositoAction $bukaDepositoAction,
        private CairkanDepositoAction $cairkanDepositoAction,
        private BayarAngsuranAction $bayarAngsuranAction,
        private ProsesTazirAction $prosesTazirAction,
        private AjukanVoidAction $ajukanVoidAction
    ) {}

    /* ===================== SETOR TUNAI ===================== */

    public function setorForm(Request $request)
    {
        /* Populasi setoran: anggota yang SAH menyetor di loket — ACTIVE (rutin)
         | maupun PENDING (belum melunasi setoran awal Pokok + Wajib bulan 1).
         | Anggota PENDING memang menyelesaikan setoran awalnya di Teller, jadi
         | WAJIB ikut ditampilkan. Sebelumnya mereka tersaring keluar oleh
         | `aktif()`, sehingga nama anggota baru tidak pernah muncul pada
         | formulir setoran tunai. Perhatikan: rute `teller.setor.store` sengaja
         | TIDAK memakai middleware `anggota.aktif` (berbeda dengan tarik/deposito),
         | yang menegaskan setoran untuk anggota PENDING memang diizinkan. */
        $anggota = Anggota::query()
            ->whereIn('status_anggota', [Anggota::STATUS_ACTIVE, 'Aktif', Anggota::STATUS_PENDING])
            ->whereHas('rekening', fn ($q) => $q->where('status_rekening', 'Aktif'))
            ->orderBy('nama_lengkap')->get();

        /* Praseleksi dari tautan "Setoran Tunai" pada halaman profil anggota
         | (`?anggota_id=...`). Anggota yang diminta SELALU disertakan agar nama
         | tampil pada formulir — termasuk bila statusnya di luar populasi di
         | atas — sehingga teller tidak pernah menemui formulir kosong.
         | Bila ID tidak valid/tidak ditemukan, kembalikan pesan yang jelas. */
        $anggotaTerpilih = null;

        if ($request->filled('anggota_id')) {
            $anggotaTerpilih = Anggota::find($request->integer('anggota_id'));

            if (! $anggotaTerpilih) {
                return redirect()->route('teller.setor')
                    ->with('error', 'Anggota tidak ditemukan. Silakan pilih anggota dari daftar.');
            }

            if (! $anggota->contains('id', $anggotaTerpilih->id)) {
                $anggota->prepend($anggotaTerpilih);
            }
        }

        /* Daftar rekening anggota terpilih — dirender LANGSUNG di server agar
         | dropdown Rekening tidak pernah menampilkan placeholder
         | "-- Pilih Anggota Dulu --" ketika `?anggota_id=` sudah terisi.
         | Mode SETOR menyertakan seluruh rekening (Pokok, Wajib, Sukarela). */
        $rekeningTerpilih = collect();

        if ($anggotaTerpilih) {
            $rekeningTerpilih = Rekening::where('anggota_id', $anggotaTerpilih->id)
                ->where('status_rekening', 'Aktif')
                ->get(['id', 'no_rekening', 'jenis_rekening', 'saldo']);
        }

        return view('teller.setor', compact('anggota', 'anggotaTerpilih', 'rekeningTerpilih'));
    }

    public function setorStore(Request $request)
    {
        $minimum = Setting::minimumTransaksiTeller();

        $request->validate([
            'rekening_id' => 'required|exists:rekening,id',
            'jumlah' => 'required|numeric|min:'.$minimum,
            'catatan' => 'nullable|string|max:255',
        ], [
            'jumlah.min' => 'Nominal setoran minimal Rp '.number_format($minimum, 0, ',', '.').'.',
        ]);

        return $this->setorTunaiAction->execute($request);
    }

    public function getRekeningByAnggota(Request $request)
    {
        $query = Rekening::where('anggota_id', $request->anggota_id)
            ->where('status_rekening', 'Aktif');

        /* Mode SETOR (menu Setoran Tunai) menyertakan SELURUH rekening simpanan
         | — termasuk Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002) — karena
         | setoran awal keanggotaan memang diproses di loket teller.
         |
         | Mode lain (default, dipakai menu Penarikan) menerapkan ALLOWLIST KETAT:
         | HANYA rekening Simpanan Sukarela (CoA 2-1001) yang ditampilkan — karena
         | penarikan tunai harian hanya sah untuk Simpanan Sukarela. Rekening
         | Simpanan Pokok/Wajib adalah Modal/Ekuitas anggota (PSAK 101 ¶ 31) dan
         | hanya dapat dikembalikan melalui modul pengunduran diri anggota. */
        if ($request->input('mode') !== 'setor') {
            $query->whereIn('jenis_rekening', GuardSimpananModal::jenisRekeningDapatDitarik());
        }

        return response()->json(
            $query->get(['id', 'no_rekening', 'jenis_rekening', 'saldo'])
        );
    }

    /* ===================== TARIK TUNAI ===================== */

    public function tarikForm()
    {
        // ALLOWLIST KETAT — hanya anggota yang memiliki rekening Simpanan
        // Sukarela (CoA 2-1001) yang ditampilkan. Penarikan tunai harian tidak
        // sah untuk Simpanan Pokok/Wajib (Modal/Ekuitas anggota, PSAK 101 ¶ 31).
        $anggota = Anggota::aktif()
            ->whereHas('rekening', fn ($q) => $q->where('status_rekening', 'Aktif')
                ->whereIn('jenis_rekening', GuardSimpananModal::jenisRekeningDapatDitarik()))
            ->orderBy('nama_lengkap')->get();

        return view('teller.tarik', compact('anggota'));
    }

    public function tarikStore(Request $request)
    {
        $minimum = Setting::minimumTransaksiTeller();

        $request->validate([
            'rekening_id' => 'required|exists:rekening,id',
            'jumlah' => 'required|numeric|min:'.$minimum,
            'catatan' => 'nullable|string|max:255',
        ], [
            'jumlah.min' => 'Nominal penarikan minimal Rp '.number_format($minimum, 0, ',', '.').'.',
        ]);

        return $this->tarikTunaiAction->execute($request);
    }

    /* ===================== BAYAR ANGSURAN ===================== */

    public function angsuranForm(Request $request)
    {
        // Anggota yang punya pembiayaan aktif (dicairkan, disetujui, restrukturisasi, aktif) dan masih punya sisa pembiayaan atau angsuran belum lunas
        $statusBerjalan = [
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::STATUS_AKTIF,
        ];

        $anggota = Anggota::aktif()
            ->whereHas('pembiayaan', function ($q) use ($statusBerjalan) {
                $q->statusIn($statusBerjalan)
                    ->where('sisa_pembiayaan', '>', 0);
            })
            ->orderBy('nama_lengkap')->get();

        $pembiayaan = null;
        $angsuranList = collect();

        if ($request->filled('anggota_id')) {
            $pembiayaan = Pembiayaan::where('anggota_id', $request->anggota_id)
                ->statusIn($statusBerjalan)
                ->where('sisa_pembiayaan', '>', 0)
                ->with('anggota')
                ->get();
        }

        if ($request->filled('pembiayaan_id')) {
            $angsuranList = Angsuran::where('pembiayaan_id', $request->pembiayaan_id)
                ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])
                ->orderBy('angsuran_ke')
                ->get();
        }

        return view('teller.angsuran', compact('anggota', 'pembiayaan', 'angsuranList'));
    }

    /**
     * AJAX: Ambil data pembiayaan aktif milik anggota beserta info tagihan.
     *
     * K-02 (a) — Jalur ini hanya MEMBACA (HTTP GET wajib idempoten). Efek
     * samping pembuatan jadwal angsuran dipindahkan ke endpoint POST
     * `teller.angsuran.generate-jadwal`. Apabila pembiayaan belum memiliki
     * jadwal, respons menyertakan `perlu_generate_jadwal = true` agar klien
     * memicunya lewat POST (sekali, dengan token CSRF) alih-alih melalui GET.
     */
    public function getPembiayaanAngsuran(int $anggotaId)
    {
        $pb = Pembiayaan::where('anggota_id', $anggotaId)
            ->statusIn([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_RESTRUKTURISASI,
                Pembiayaan::STATUS_AKTIF,
            ])
            ->where('sisa_pembiayaan', '>', 0)
            ->latest()
            ->first();

        if (! $pb) {
            return response()->json(null, 404);
        }

        // Angsuran berikutnya yang belum dibayar
        $angsuranNext = Angsuran::where('pembiayaan_id', $pb->id)
            ->whereIn('status_angsuran', ['Belum Jatuh Tempo', 'Jatuh Tempo', 'Terlambat'])
            ->orderBy('angsuran_ke')
            ->first();

        // Penanda (tanpa efek samping) bahwa jadwal angsuran belum terbentuk.
        $perluGenerateJadwal = ! $angsuranNext
            && Angsuran::where('pembiayaan_id', $pb->id)->count() === 0;

        $angsuranPerBulan = $pb->jumlah_angsuran > 0
            ? (float) $pb->jumlah_angsuran
            : ($pb->tenor > 0 ? Setting::bulatkanUang((float) $pb->total_pembiayaan / $pb->tenor) : 0);

        $angsuranId = $angsuranNext?->id;
        $angsuranKe = $angsuranNext?->angsuran_ke ?? (($pb->angsuran_ke ?? 0) + 1);

        // Semua jadwal angsuran untuk ditampilkan di tabel
        $jadwal = Angsuran::where('pembiayaan_id', $pb->id)
            ->orderBy('angsuran_ke')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'angsuran_ke' => $a->angsuran_ke,
                'tanggal_jatuh_tempo' => $a->tanggal_jatuh_tempo?->format('d/m/Y'),
                'jumlah_angsuran' => (float) $a->jumlah_angsuran,
                'tanggal_bayar' => $a->tanggal_bayar?->format('d/m/Y'),
                'status_angsuran' => $a->status_angsuran,
                'denda' => $this->tazirService->calculate($a),
            ]);

        // Hitung ta'zir dinamis via TazirService
        $tazirDetail = $angsuranNext
            ? $this->tazirService->detail($angsuranNext)
            : ['nominal' => 0, 'hari_terlambat' => 0, 'hari_efektif' => 0, 'rate' => 0, 'mode' => 'DISABLED', 'grace_period' => 0, 'default_method' => 'TUNAI'];

        // Tunggakan ta'zir dari pengajuan keringanan yang DITOLAK Manajer (belum dibayar)
        $tunggakanRecords = TazirPenangguhan::belumDibayar()
            ->where('pembiayaan_id', $pb->id)
            ->with('angsuran')
            ->orderBy('created_at')
            ->get();

        $totalTunggakan = (float) $tunggakanRecords->sum('nominal_tazir');

        $tunggakanDetail = $tunggakanRecords->map(fn ($t) => [
            'id' => $t->id,
            'angsuran_ke' => $t->angsuran?->angsuran_ke,
            'tanggal' => $t->created_at?->format('d/m/Y'),
            'nominal' => (float) $t->nominal_tazir,
            'catatan' => $t->catatan,
        ])->values();

        return response()->json([
            'pembiayaan_id' => $pb->id,
            'angsuran_id' => $angsuranId,
            'no_pembiayaan' => $pb->no_pembiayaan,
            'total_pembiayaan' => (float) $pb->total_pembiayaan,
            'sisa_pembiayaan' => (float) $pb->sisa_pembiayaan,
            'tenor' => $pb->tenor,
            'angsuran_per_bulan' => $angsuranNext
                ? (float) $angsuranNext->jumlah_angsuran
                : $angsuranPerBulan,
            'angsuran_ke_next' => $angsuranKe,
            'jadwal' => $jadwal,
            'tazir' => $tazirDetail,
            'tunggakan_tazir' => $totalTunggakan,
            'tunggakan_detail' => $tunggakanDetail,
            'pokok_angsuran' => $angsuranNext ? (float) $angsuranNext->pokok_angsuran : 0,
            'margin_ujroh' => $angsuranNext ? (float) $angsuranNext->margin_ujroh : 0,
            'perlu_generate_jadwal' => $perluGenerateJadwal,
        ]);
    }

    /**
     * POST: Bentuk jadwal angsuran bila pembiayaan belum memilikinya.
     *
     * K-02 (b) — Dipisahkan dari jalur GET agar HTTP GET tetap idempoten dan
     * pembentukan data hanya terjadi melalui permintaan eksplisit (POST).
     * Aman dipanggil berulang: bila jadwal sudah ada, tidak ada baris baru.
     */
    public function generateJadwalAngsuran(int $pembiayaanId)
    {
        $pb = Pembiayaan::find($pembiayaanId);

        if (! $pb) {
            return response()->json([
                'success' => false,
                'message' => 'Pembiayaan tidak ditemukan.',
            ], 404);
        }

        $sudahAda = Angsuran::where('pembiayaan_id', $pb->id)->exists();

        if (! $sudahAda) {
            /*
             * BATCH 16 (T-07 / T-08) — jadwal angsuran adalah turunan akad
             * yang SUDAH berjalan. Tanpa gerbang ini, endpoint menerima
             * pembiayaan dalam status apa pun: akad LUNAS dapat kembali
             * memiliki 12 tagihan baru (laporan & dashboard membaca
             * `sisa_pembiayaan`, bukan status), dan akad yang masih menunggu
             * keputusan memperoleh kewajiban yang belum berdasar akad.
             * Daftar status mengikuti `angsuranForm()` — daftar yang sama
             * dengan yang ditampilkan sebagai "pembiayaan berjalan".
             */
            if (! $pb->isStatus([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_RESTRUKTURISASI,
                Pembiayaan::STATUS_AKTIF,
            ])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal angsuran hanya dapat diterbitkan untuk pembiayaan yang sudah berjalan.',
                    'status_pembiayaan' => $pb->status_pembiayaan,
                ], 422);
            }

            $tenor = max(1, (int) $pb->tenor);
            $total = (float) $pb->total_pembiayaan;
            $pokok = Setting::bulatkanUang($total / $tenor);
            $tgl = $pb->tanggal_persetujuan ?? $pb->tanggal_pengajuan ?? today();

            DB::transaction(function () use ($pb, $tenor, $total, $pokok, $tgl) {
                for ($i = 1; $i <= $tenor; $i++) {
                    Angsuran::create([
                        'pembiayaan_id' => $pb->id,
                        'angsuran_ke' => $i,
                        'tanggal_jatuh_tempo' => Carbon::parse($tgl)->addMonths($i),
                        'pokok_angsuran' => $pokok,
                        'margin_ujroh' => 0,
                        'jumlah_angsuran' => $pokok,
                        'sisa_pokok' => max(0, $total - ($pokok * $i)),
                        'status_angsuran' => 'Belum Jatuh Tempo',
                    ]);
                }
            });
        }

        return response()->json([
            'success' => true,
            'pembiayaan_id' => $pb->id,
            'sudah_ada' => $sudahAda,
            'jumlah_jadwal' => Angsuran::where('pembiayaan_id', $pb->id)->count(),
        ]);
    }

    public function angsuranStore(Request $request, Angsuran $angsuran)
    {
        return $this->bayarAngsuranAction->execute($request, $angsuran);
    }

    public function prosesTazir(Request $request)
    {
        return $this->prosesTazirAction->execute($request);
    }

    /* ===================== NOTA TRANSAKSI ===================== */

    public function notaShow(Transaksi $transaksi)
    {
        $transaksi->load(['rekening.anggota', 'anggota', 'dibuatOleh', 'angsuran.pembiayaan', 'pembiayaan']);
        $rekening = $transaksi->rekening;
        $anggota = $rekening?->anggota ?? $transaksi->anggota ?? $transaksi->angsuran?->pembiayaan?->anggota;
        $saldoAkhir = $rekening?->saldo ?? $transaksi->saldo_setelah ?? 0;

        return view('teller.nota', compact('transaksi', 'rekening', 'anggota', 'saldoAkhir'));
    }

    /* ===================== RIWAYAT TRANSAKSI ===================== */

    public function riwayat(Request $request)
    {
        if ($request->has('ajax_check') || $request->wantsJson()) {
            $transaksiPending = Transaksi::where('user_id', Auth::id())
                ->whereDate('waktu_transaksi', today())
                ->get(['id', 'status', 'status_transaksi']);

            return response()->json(['transaksi' => $transaksiPending]);
        }

        $query = Transaksi::with(['anggota', 'rekening', 'dibuatOleh', 'angsuran.pembiayaan'])
            ->orderByDesc('waktu_transaksi')
            // Tiebreaker id: transaksi dengan waktu identik selalu tampil
            // dalam urutan yang sama dengan tabel Transaksi Terakhir di dashboard.
            ->orderByDesc('id');

        // Filter tanggal — default 30 hari terakhir agar semua transaksi terlihat
        $tanggalAwal = $request->filled('dari')
            ? $request->dari.' 00:00:00'
            : now()->subDays(30)->startOfDay();
        $tanggalAkhir = $request->filled('sampai')
            ? $request->sampai.' 23:59:59'
            : now()->endOfDay();

        $query->whereBetween('waktu_transaksi', [$tanggalAwal, $tanggalAkhir]);

        // Filter pencarian (ID/Nama/No. Rekening)
        if ($request->filled('cari')) {
            $cari = $request->cari;
            $query->where(function ($q) use ($cari) {
                $q->where('no_transaksi', 'like', "%{$cari}%")
                    ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$cari}%")
                        ->orWhere('no_anggota', 'like', "%{$cari}%"))
                    ->orWhereHas('rekening', fn ($r) => $r->where('no_rekening', 'like', "%{$cari}%"));
            });
        }

        // Filter jenis transaksi
        if ($request->filled('jenis')) {
            $query->where('jenis_transaksi', $request->jenis);
        }

        // Filter status
        if ($request->filled('status')) {
            $query->where('status_transaksi', $request->status);
        }

        // Filter AML
        if ($request->filled('aml')) {
            if ($request->aml === 'high_value') {
                $query->where(function ($q) {
                    $q->where('is_aml_high_value', true)
                        ->orWhere('is_large_transaction', true);
                });
            } elseif (in_array($request->aml, ['menunggu', 'terverifikasi', 'ditolak'])) {
                $query->where('is_aml_high_value', true)
                    ->where('aml_status_verifikasi', $request->aml);
            }
        }

        $transaksi = $query->paginate(20)->withQueryString();

        // Hitung ringkasan hari ini untuk tampilan summary.
        // Hanya transaksi berstatus Sukses yang dihitung — transaksi Pending/Ditolak
        // tidak mengubah kas sehingga tidak boleh masuk ringkasan.
        // Angsuran dicatat pada kartunya sendiri, bukan sebagai setoran tunai.
        //
        // SARGAble: memakai rentang `whereBetween(startOfDay..endOfDay)` alih-alih
        // `whereDate()` yang membungkus kolom dengan `DATE(...)` sehingga indeks
        // `transaksi_waktu_transaksi_index` tidak terpakai (full table scan).
        $hariIni = [today()->startOfDay(), today()->endOfDay()];

        $totalSetorHariIni = Transaksi::whereBetween('waktu_transaksi', $hariIni)
            ->where('jenis_transaksi', 'Setoran Tunai')
            ->where('status_transaksi', 'Sukses')
            ->sum('jumlah');
        $totalTarikHariIni = Transaksi::whereBetween('waktu_transaksi', $hariIni)
            ->where('jenis_transaksi', 'Penarikan Tunai')
            ->where('status_transaksi', 'Sukses')
            ->sum('jumlah');
        $totalAngsuranHariIni = Transaksi::whereBetween('waktu_transaksi', $hariIni)
            ->whereIn('jenis_transaksi', JenisTransaksi::angsuran())
            ->where('status_transaksi', 'Sukses')
            ->sum('jumlah');
        $totalTransaksiHariIni = Transaksi::whereBetween('waktu_transaksi', $hariIni)->count();

        $jenisOptions = Transaksi::select('jenis_transaksi')
            ->distinct()->orderBy('jenis_transaksi')->pluck('jenis_transaksi');

        // Posisi Kas Header — konsisten dengan Laporan Kas Harian (rekap) & halaman opening:
        // Kas Awal (modal_awal) + Total Cash In - Total Cash Out, hanya transaksi Sukses.
        try {
            $kasTeller = KasHarian::hitungSaldoKasTeller(Auth::id());
        } catch (\Throwable $e) {
            report($e);
            $kasTeller = ['kas_awal' => 0.0, 'cash_in' => 0.0, 'cash_out' => 0.0, 'saldo' => 0.0];
        }

        $kasAwal = $kasTeller['kas_awal'];
        $totalCashIn = $kasTeller['cash_in'];
        $totalCashOut = $kasTeller['cash_out'];
        $kasAkhirSystem = $kasTeller['saldo'];

        return view('teller.riwayat', compact(
            'transaksi',
            'totalSetorHariIni',
            'totalTarikHariIni',
            'totalAngsuranHariIni',
            'totalTransaksiHariIni',
            'jenisOptions',
            'kasAwal',
            'totalCashIn',
            'totalCashOut',
            'kasAkhirSystem'
        ));
    }

    /* ===================== VOID REQUEST ===================== */

    public function voidRequest(Request $request, Transaksi $transaksi)
    {
        return $this->ajukanVoidAction->execute($request, $transaksi);
    }

    /* ===================== CETAK SLIP ===================== */

    public function slipShow(Transaksi $transaksi)
    {
        $transaksi->load(['anggota', 'rekening', 'dibuatOleh', 'angsuran.pembiayaan']);

        return view('teller.slip', compact('transaksi'));
    }

    /* ===================== BUKA KAS / OPENING ===================== */

    public function openingForm()
    {
        $today = $this->lintasHari->tanggalBisnis();
        $sudahOpening = KasHarian::where('user_id', Auth::id())
            ->whereDate('tanggal', $today)->first();

        // Aturan lintas hari: sesi kas pada tanggal sebelumnya yang masih
        // OPEN/PENDING_APPROVAL harus diselesaikan lebih dulu. Daftar ini
        // ditampilkan di halaman Buka Kas sebagai peringatan proaktif.
        $sesiTertunda = $this->lintasHari->daftarSesiBelumTutup(Auth::id(), $today);

        // Ringkasan posisi kas — SATU-SATUNYA sumber perhitungan, dipakai bersama
        // oleh halaman Opening, Rekap/Closing, dan header Teller.
        // Bila perhitungan gagal, tampilkan pesan error yang jelas ke pengguna
        // alih-alih menampilkan Rp 0 tanpa penjelasan.
        try {
            $posisiKas = KasHarian::ringkasanPosisiKas(Auth::id(), $today);
        } catch (\Throwable $e) {
            report($e);

            return view('teller.opening', array_merge(
                compact('sudahOpening', 'today', 'sesiTertunda'),
                $this->posisiKasKosong(),
                [
                    'sudahClosing' => (bool) ($sudahOpening?->sudahClosing()),
                    'errorKas' => 'Gagal memuat posisi kas: '.$e->getMessage(),
                ]
            ));
        }

        $kasTeller = $posisiKas;
        $sudahClosing = (bool) ($sudahOpening?->sudahClosing());
        $riwayatKas = KasHarian::queryTransaksiKas(Auth::id(), $today)
            ->orderBy('waktu_transaksi')
            ->get();

        return view('teller.opening', array_merge(
            compact('sudahOpening', 'sudahClosing', 'today', 'kasTeller', 'posisiKas', 'riwayatKas', 'sesiTertunda'),
            [
                'totalSetorHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], [JenisTransaksi::SETORAN_TUNAI]),
                'totalSimpananPokokHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::pokok()),
                'totalSimpananWajibHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::wajib()),
                'totalAngsuranHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::angsuran()),
                'totalTarikHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_out'] ?? [], [JenisTransaksi::PENARIKAN_TUNAI]),
                'totalPencairanHariIni' => JenisTransaksi::jumlah($posisiKas['rincian_out'] ?? [], [JenisTransaksi::PENCAIRAN_PEMBIAYAAN]),
                'errorKas' => null,
            ]
        ));
    }

    /** Nilai nol yang aman dipakai view bila perhitungan posisi kas gagal. */
    private function posisiKasKosong(): array
    {
        return [
            'kasTeller' => [
                'tanggal' => now()->toDateString(),
                'kas_awal' => 0.0,
                'cash_in' => 0.0,
                'cash_out' => 0.0,
                'saldo' => 0.0,
            ],
            'posisiKas' => null,
            'riwayatKas' => collect(),
            'totalSetorHariIni' => 0,
            'totalSimpananPokokHariIni' => 0,
            'totalSimpananWajibHariIni' => 0,
            'totalAngsuranHariIni' => 0,
            'totalTarikHariIni' => 0,
            'totalPencairanHariIni' => 0,
        ];
    }

    public function openingStore(Request $request)
    {
        // Modal awal WAJIB diisi manual oleh teller dan harus angka positif.
        // Nilai default Rp 0 tidak diizinkan agar saldo aktual kas fisik
        // benar-benar tercatat, bukan sekadar nilai bawaan sistem.
        $request->validate([
            'modal_awal' => 'required|numeric|gt:0|max:999999999999',
            'catatan' => 'nullable|string|max:255',
        ], [
            'modal_awal.required' => 'Modal awal wajib diisi sesuai saldo kas fisik yang dibawa ke loket.',
            'modal_awal.numeric' => 'Modal awal harus berupa angka tanpa karakter selain digit.',
            'modal_awal.gt' => 'Modal awal harus lebih besar dari Rp 0.',
            'modal_awal.max' => 'Modal awal melebihi batas wajar yang dapat dicatat.',
        ]);

        return $this->bukaKasAction->execute($request);
    }

    /**
     * Injeksi Kas: Brankas Utama (Vault) → Laci Teller.
     *
     * Guard anti-deficit: transaksi ditolak bila saldo Vault (CoA 1-1003)
     * tidak mencukupi. Batas maksimum kas laci juga dijaga memakai parameter
     * dinamis `setting('teller_max_cash_limit')` supaya uang tunai tidak
     * menumpuk berlebihan di loket.
     */
    public function tambahModal(Request $request)
    {
        $minimum = Setting::minimumTransaksiTeller();

        $request->validate([
            'nominal_tambah' => 'required|numeric|min:'.$minimum,
            'keterangan' => 'nullable|string|max:255',
        ], [
            'nominal_tambah.required' => 'Nominal tambahan wajib diisi.',
            'nominal_tambah.numeric' => 'Nominal harus berupa angka.',
            'nominal_tambah.min' => 'Nominal minimal Rp '.number_format($minimum, 0, ',', '.').'.',
        ]);

        return $this->tambahModalKasAction->execute($request);
    }

    /**
     * Setoran Hasil Kas Harian: Laci Teller → Brankas Utama (Vault).
     *
     * Guard anti-deficit: tidak boleh menyetor melebihi saldo kas laci yang
     * benar-benar ada. Auto-journal: D 1-1003 Kas Brankas / K 1-1001 Kas Teller.
     */
    public function setorKeBrankas(Request $request)
    {
        $minimum = Setting::minimumTransaksiTeller();

        $request->validate([
            'nominal_setor' => 'required|numeric|min:'.$minimum,
            'keterangan' => 'nullable|string|max:255',
        ], [
            'nominal_setor.required' => 'Nominal setoran wajib diisi.',
            'nominal_setor.numeric' => 'Nominal harus berupa angka.',
            'nominal_setor.min' => 'Nominal minimal Rp '.number_format($minimum, 0, ',', '.').'.',
        ]);

        return $this->setorKasKeBrankasAction->execute($request);
    }

    /* ===================== BUKA REKENING DEPOSITO ===================== */

    public function depositoForm()
    {
        $anggota = Anggota::aktif()->orderBy('nama_lengkap')->get();

        // Batas nominal pembukaan rekening bersumber dari parameter kebijakan koperasi
        // sehingga pengurus dapat mengubahnya tanpa modifikasi kode inti.
        $batasPembukaan = KebijakanKoperasi::batasPembukaanRekening();

        // Nisbah & tenor diambil dari master produk deposito (akad Mudharabah),
        // BUKAN dari input bebas — mencegah manipulasi nisbah oleh teller.
        $produkDeposito = DepositoProduct::active()
            ->deposito()
            ->orderBy('tenor_months')
            ->get();

        return view('teller.deposito', compact('anggota', 'batasPembukaan', 'produkDeposito'));
    }

    public function depositoStore(Request $request)
    {
        $request->validate([
            'anggota_id' => 'required|exists:anggota,id',
            // Batas minimum/maksimum nominal pembukaan rekening bersumber dari
            // parameter kebijakan koperasi (dapat diubah pengurus; tanpa hardcode).
            'nominal' => KebijakanKoperasi::aturanNominalPembukaan(),
            'deposito_product_id' => 'required|exists:deposito_products,id',
            'catatan' => 'nullable|string|max:255',
        ], KebijakanKoperasi::pesanValidasiNominalPembukaan('Nominal deposito'));

        return $this->bukaDepositoAction->execute($request);
    }

    /* ===================== DAFTAR & DETAIL DEPOSITO ===================== */

    public function depositoIndex(Request $request)
    {
        $query = Rekening::with('anggota')
            ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA))
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('no_rekening', 'like', "%{$s}%")
                    ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$s}%")
                        ->orWhere('no_anggota', 'like', "%{$s}%"));
            });
        }

        if ($request->filled('status')) {
            $query->where('status_rekening', $request->status);
        }

        // Tandai jatuh tempo secara MASSAL dalam satu UPDATE.
        // Sebelumnya kode memuat SELURUH rekening berjangka ke memori lalu
        // meng-update satu per satu, dan karena dipanggil pada query yang sudah
        // difilter, status hanya ikut terbarui bila tidak ada filter pencarian.
        // UPDATE massal di bawah bersifat global (independen dari filter) dan
        // memakai rentang `<=` yang sargable sehingga indeks tanggal terpakai.
        Rekening::whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA))
            ->where('status_rekening', 'Aktif')
            ->whereNotNull('tanggal_jatuh_tempo')
            ->where('tanggal_jatuh_tempo', '<=', now())
            ->update(['status_rekening' => 'Jatuh Tempo']);

        $deposito = $query->paginate(15)->withQueryString();

        // Ringkasan agregat dalam SATU query (SUM + CASE) alih-alih empat
        // query terpisah yang masing-masing memindai tabel.
        $ringkasan = Rekening::whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA))
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status_rekening = 'Aktif' THEN saldo ELSE 0 END), 0) AS total_nominal,
                SUM(CASE WHEN status_rekening = 'Aktif' THEN 1 ELSE 0 END) AS total_aktif,
                SUM(CASE WHEN status_rekening = 'Jatuh Tempo' THEN 1 ELSE 0 END) AS total_jatuh_tempo,
                SUM(CASE WHEN status_rekening = 'Ditutup' THEN 1 ELSE 0 END) AS total_ditutup
            ")
            ->first();

        $totalNominal = (float) ($ringkasan->total_nominal ?? 0);
        $totalAktif = (int) ($ringkasan->total_aktif ?? 0);
        $totalJatuhTempo = (int) ($ringkasan->total_jatuh_tempo ?? 0);
        $totalDitutup = (int) ($ringkasan->total_ditutup ?? 0);

        return view('teller.deposito-list', compact(
            'deposito', 'totalNominal', 'totalAktif', 'totalJatuhTempo', 'totalDitutup'
        ));
    }

    public function depositoShow(Rekening $rekening)
    {
        abort_unless(in_array($rekening->jenis_rekening, JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA), true), 404);
        $rekening->load('anggota');

        $mutasi = Transaksi::where('rekening_id', $rekening->id)
            ->orderByDesc('waktu_transaksi')->get();

        $bagiHasilTotal = $mutasi->where('jenis_transaksi', 'Bagi Hasil Deposito')->sum('jumlah');

        return view('teller.deposito-show', compact('rekening', 'mutasi', 'bagiHasilTotal'));
    }

    public function depositoCair(Request $request, Rekening $rekening)
    {
        return $this->cairkanDepositoAction->execute($request, $rekening);
    }

    /* ===================== REKAP KAS & CLOSING ===================== */

    public function rekapIndex()
    {
        $today = now()->toDateString();
        $tellerId = Auth::id();

        $openingData = KasHarian::where('user_id', $tellerId)->where('tanggal', $today)->first();
        $pecahan = TellerOpname::PECAHAN;

        // Riwayat & ringkasan memakai query yang sama dengan halaman Opening
        // supaya angka "Saldo Kas Sistem" tidak pernah berbeda dengan kartu ringkasan.
        try {
            $posisiKas = KasHarian::ringkasanPosisiKas($tellerId, $today);
            $transaksiHariIni = KasHarian::queryTransaksiKas($tellerId, $today)
                ->orderBy('waktu_transaksi')
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return view('teller.rekap', [
                'today' => $today,
                'openingData' => $openingData,
                'transaksiHariIni' => collect(),
                'totalSetor' => 0,
                'totalSimpananPokok' => 0,
                'totalSimpananWajib' => 0,
                'totalTarik' => 0,
                'totalAngsuran' => 0,
                'totalPencairan' => 0,
                'modalAwal' => $openingData?->modal_awal ?? 0,
                'saldoSistem' => 0,
                'pecahan' => $pecahan,
                'errorKas' => 'Gagal memuat rekap kas: '.$e->getMessage(),
            ]);
        }

        return view('teller.rekap', [
            'today' => $today,
            'openingData' => $openingData,
            'transaksiHariIni' => $transaksiHariIni,
            'totalSetor' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], [JenisTransaksi::SETORAN_TUNAI]),
            'totalSimpananPokok' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::pokok()),
            'totalSimpananWajib' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::wajib()),
            'totalAngsuran' => JenisTransaksi::jumlah($posisiKas['rincian_in'] ?? [], JenisTransaksi::angsuran()),
            'totalTarik' => JenisTransaksi::jumlah($posisiKas['rincian_out'] ?? [], [JenisTransaksi::PENARIKAN_TUNAI]),
            'totalPencairan' => JenisTransaksi::jumlah($posisiKas['rincian_out'] ?? [], [JenisTransaksi::PENCAIRAN_PEMBIAYAAN]),
            'modalAwal' => $posisiKas['kas_awal'],
            'saldoSistem' => $posisiKas['saldo'],
            'pecahan' => $pecahan,
            'errorKas' => null,
        ]);
    }

    public function rekapStore(Request $request)
    {
        $request->validate([
            'fisik_kas' => 'required|numeric|min:0',
            'catatan' => 'nullable|string|max:500',
        ]);

        return $this->tutupKasAction->execute($request);
    }
}
