<?php

namespace App\Http\Controllers;

use App\Models\Coa;
use App\Models\PengeluaranBeban;
use App\Services\AutoJournalingService;
use App\Support\HierarkiRole;
use App\Support\PemisahanTugas;
use App\Support\RentangPeriode;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Pengeluaran Beban Operasional — terhubung CoA kelompok Beban (5-XXXX).
 *
 * Input & daftar: Teller + Manajer (kelompok `pengelola_beban`).
 * AO TIDAK berwenang — dialihkan ke dasbornya dengan pesan ramah.
 * Approval di atas limit: Manajer | Audit read-only: DPS.
 */
class PengeluaranBebanController extends Controller
{
    public function __construct(private AutoJournalingService $journalService)
    {
        // Restriksi role: hanya Teller / Manajer yang boleh mengelola beban
        // operasional (kelompok `pengelola_beban` di config/role_hierarki.php).
        // Role AO ditolak dengan redirect + alert khusus.
        $this->middleware(function ($request, $next) {
            $role = Auth::user()?->role;

            if (HierarkiRole::dalamKelompok($role, 'pengelola_beban')) {
                return $next($request);
            }

            // Peran yang ditolak tetapi punya pengalihan ramah (mis. AO →
            // dasbornya) dialihkan dengan pesan; sisanya 403. Pemetaannya hidup
            // di `role_hierarki.kelompok_tolak`, bukan literal di sini.
            $tolak = HierarkiRole::penolakanKelompok($role, 'pengelola_beban');

            if ($tolak !== null) {
                return redirect()->route($tolak['route'])->with('error', $tolak['pesan']);
            }

            abort(403, 'Akses ditolak. Anda tidak memiliki izin mengelola beban operasional.');
        })->only(['index', 'store']);
    }

    /* ============================================================
     * TELLER / AO — Input & daftar pengeluaran beban
     * ============================================================ */
    public function index()
    {
        $pengeluarans = PengeluaranBeban::with(['coaBeban', 'coaKas', 'penginput'])
            ->latest('tanggal')->latest('id')
            ->paginate(15);

        $totalBulanIni = RentangPeriode::padaBulan(
            PengeluaranBeban::where('status', 'Approved'),
            'tanggal',
            (int) now()->month,
            (int) now()->year
        )->sum('nominal');

        return view('pengeluaran-beban.input', [
            'pengeluarans' => $pengeluarans,
            'totalBulanIni' => $totalBulanIni,
            'akunBebanList' => $this->akunBebanList(),
            'akunKasList' => $this->akunKasList(),
            'limitApproval' => PengeluaranBeban::limitApproval(),
            // Pemetaan jenis beban -> akun CoA spesifik (untuk dropdown + auto-isi akun)
            'jenisBebanList' => $this->jenisBebanList(),
            'jenisBebanByKodeAkun' => $this->jenisBebanByKodeAkun(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'jenis_beban' => ['required', Rule::in(array_keys(AutoJournalingService::JENIS_BEBAN))],
            'coa_beban' => ['required', Rule::exists('coas', 'id')->where('is_active', true)],
            'coa_kas' => ['required', Rule::exists('coas', 'id')->where('is_active', true)],
            'nominal' => ['required', 'numeric', 'min:1000', 'max:999999999999'],
            'keterangan' => ['required', 'string', 'max:255'],
        ], [
            'tanggal.before_or_equal' => 'Tanggal transaksi tidak boleh di masa depan.',
            'jenis_beban.in' => 'Jenis beban operasional tidak dikenal.',
            'nominal.min' => 'Nominal pengeluaran minimal Rp 1.000.',
        ]);

        // Validasi kategori: beban HARUS kategori Beban, kas HARUS kategori Aset
        $coaBeban = Coa::find($validated['coa_beban']);
        $coaKas = Coa::find($validated['coa_kas']);

        if (! $coaBeban || $coaBeban->kategori !== 'Beban') {
            return back()->withInput()->with('error', 'Akun beban tidak valid — pilih akun dari kelompok Beban (5-XXXX).');
        }
        if ($coaKas->kategori !== 'Aset' || ! in_array($coaKas->kode_akun, \App\Support\Coa::AKUN_KAS_LIKUID, true)) {
            return back()->withInput()->with('error', 'Sumber uang hanya boleh Kas Teller (1-1001) atau Bank (1-1002).');
        }

        /* Akun CoA wajib mengikuti JENIS BEBAN yang dipilih agar setiap jenis
         | pengeluaran tercatat pada akunnya sendiri (5-1001/5-1002/5-1003).
         | Bila kode akun spesifik tidak ditemukan/nonaktif di master CoA,
         | resolver otomatis memakai akun beban general sehingga proses simpan
         | tidak pernah gagal karena master CoA belum lengkap. */
        $kodeAkunBeban = $this->journalService->kodeAkunBebanByJenis(
            $validated['jenis_beban'],
            'input pengeluaran beban'
        );
        $coaBebanTujuan = Coa::findByKode($kodeAkunBeban) ?? $coaBeban;

        if ($coaBebanTujuan->id !== $coaBeban->id) {
            session()->flash('info', "Jenis beban \"{$validated['jenis_beban']}\" dipetakan ke akun {$coaBebanTujuan->kode_akun} — {$coaBebanTujuan->nama_akun}.");
        }

        $pengeluaran = DB::transaction(function () use ($validated, $coaBebanTujuan, $coaKas) {
            $record = PengeluaranBeban::create([
                'no_pengeluaran' => PengeluaranBeban::buatNoPengeluaran(),
                'tanggal' => $validated['tanggal'],
                'coa_beban_id' => $coaBebanTujuan->id,
                'coa_kas_id' => $coaKas->id,
                'nominal' => $validated['nominal'],
                'keterangan' => $validated['keterangan'],
                'created_by' => Auth::id(),
                // <= Rp 1.000.000: langsung Approved + jurnal otomatis terposting
                'status' => 'Pending',
            ]);

            if ($record->butuhApprovalManajer()) {
                // > Rp 1.000.000: menunggu otorisasi Manajer sebelum jurnal terposting
                return $record;
            }

            // <= Rp 1.000.000: langsung Approved + jurnal otomatis terposting
            $record->update([
                'status' => 'Approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);
            $this->postJurnal($record);

            return $record;
        });

        if ($pengeluaran->butuhApprovalManajer()) {
            return redirect()->route('pengeluaran-beban.index')
                ->with('warning', "Pengeluaran {$pengeluaran->no_pengeluaran} di atas limit Rp ".number_format(PengeluaranBeban::limitApproval(), 0, ',', '.').' — menunggu approval Manajer sebelum jurnal terposting.');
        }

        return redirect()->route('pengeluaran-beban.index')
            ->with('success', "Pengeluaran {$pengeluaran->no_pengeluaran} tersimpan & jurnal terposting otomatis (DEBET {$coaBebanTujuan->kode_akun} / KREDIT {$coaKas->kode_akun}).");
    }

    /* ============================================================
     * MANAJER — Approval pengeluaran > Rp 1.000.000
     * ============================================================ */
    public function approvalIndex()
    {
        $pengeluarans = PengeluaranBeban::with(['coaBeban', 'coaKas', 'penginput'])
            ->latest('tanggal')->latest('id')
            ->paginate(15);

        return view('pengeluaran-beban.approval', [
            'pengeluarans' => $pengeluarans,
            'totalPending' => PengeluaranBeban::where('status', 'Pending')->sum('nominal'),
            'totalApproved' => PengeluaranBeban::where('status', 'Approved')->sum('nominal'),
            'limitApproval' => PengeluaranBeban::limitApproval(),
        ]);
    }

    public function approve(PengeluaranBeban $pengeluaran_beban)
    {
        if (! $pengeluaran_beban->isPending()) {
            return back()->with('error', 'Transaksi ini sudah diproses sebelumnya.');
        }

        // GCG — pemisahan tugas: penginput beban tidak boleh menyetujuinya sendiri.
        if (PemisahanTugas::dilanggar($pengeluaran_beban->created_by, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($pengeluaran_beban) {
            $pengeluaran_beban->update([
                'status' => 'Approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'catatan_manajer' => request('catatan'),
            ]);

            $this->postJurnal($pengeluaran_beban);
        });

        return back()->with('success', "Pengeluaran {$pengeluaran_beban->no_pengeluaran} disetujui — jurnal otomatis terposting.");
    }

    public function reject(Request $request, PengeluaranBeban $pengeluaran_beban)
    {
        if (! $pengeluaran_beban->isPending()) {
            return back()->with('error', 'Transaksi ini sudah diproses sebelumnya.');
        }

        // GCG — pemisahan tugas: penginput beban tidak boleh menolaknya sendiri.
        if (PemisahanTugas::dilanggar($pengeluaran_beban->created_by, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        $validated = $request->validate([
            'catatan' => ['required', 'string', 'max:255'],
        ], ['catatan.required' => 'Alasan penolakan wajib diisi.']);

        $pengeluaran_beban->update([
            'status' => 'Ditolak',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'catatan_manajer' => $validated['catatan'],
        ]);

        return back()->with('success', "Pengeluaran {$pengeluaran_beban->no_pengeluaran} ditolak.");
    }

    /* ============================================================
     * DPS — Read-only audit pengeluaran beban
     * ============================================================ */
    public function auditIndex(Request $request)
    {
        /* ===== Filter periode dashboard =====
         | Dua mode yang saling melengkapi:
         |   1. `bulan` (YYYY-MM)  → dipakai tombol pintas bulan berjalan;
         |   2. `dari` + `sampai`  → rentang tanggal bebas.
         | Bila keduanya kosong, seluruh periode ditampilkan (tanpa batas waktu).
         */
        $bulan = trim((string) $request->input('bulan', ''));
        $dari = trim((string) $request->input('dari', ''));
        $sampai = trim((string) $request->input('sampai', ''));

        if ($bulan !== '' && preg_match('/^\d{4}-\d{2}$/', $bulan)) {
            // Mode bulan menang atas rentang bebas agar filter tidak bertabrakan.
            $dari = $bulan.'-01';
            $sampai = date('Y-m-t', strtotime($dari));
        } else {
            $bulan = '';
        }

        /* Sanitasi rentang tanggal bebas: hanya format YYYY-MM-DD yang sah yang
         | diteruskan ke query. Nilai lain (mis. teks bebas) diabaikan agar tidak
         | menghasilkan error maupun filter yang menyesatkan. */
        $dari = $this->tanggalSah($dari);
        $sampai = $this->tanggalSah($sampai);

        // Rentang terbalik (dari > sampai) dinormalkan dengan menukar posisinya.
        if ($dari !== '' && $sampai !== '' && $dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        /* ===== Query dasar tabel mutasi — memakai periode yang sama ===== */
        $query = PengeluaranBeban::with(['coaBeban', 'coaKas', 'penginput', 'jurnal', 'approver'])
            ->latest('tanggal')->latest('id');

        /* ===== Query card 'TOTAL BEBAN DISETUJUI' =====
         | SUM(nominal) eksklusif untuk status 'Approved' dengan filter ketat pada
         | klausa WHERE, mengikuti periode yang dipilih pengguna sehingga angka
         | pada card selalu sepadan dengan baris yang tampil di tabel.
         |
         | Card memakai query builder TERPISAH dari tabel (bukan hasil paginate),
         | agar total tetap utuh walau data terbagi ke beberapa halaman. */
        $queryApproved = PengeluaranBeban::query()->where('status', PengeluaranBeban::STATUS_APPROVED);

        if ($dari !== '') {
            $query->whereDate('tanggal', '>=', $dari);
            $queryApproved->whereDate('tanggal', '>=', $dari);
        }
        if ($sampai !== '') {
            $query->whereDate('tanggal', '<=', $sampai);
            $queryApproved->whereDate('tanggal', '<=', $sampai);
        }

        $pengeluarans = $query->paginate(20)->withQueryString();

        /* Validasi silang: pastikan hanya baris 'Approved' yang terhitung.
         | Nilai card dihitung ulang dari koleksi hasil query dan dibandingkan
         | dengan SUM di basis data — selisih menandakan status liar (mis.
         | 'Pending'/'Ditolak') bocor ke total, sehingga dicatat ke log audit. */
        $daftarApproved = (clone $queryApproved)->get(['nominal', 'status']);
        $totalApproved = (float) $daftarApproved->sum('nominal');
        $statusTakSah = $daftarApproved->where('status', '!=', PengeluaranBeban::STATUS_APPROVED)->count();

        if ($statusTakSah > 0) {
            Log::error('Audit Beban Operasional: transaksi non-Approved terdeteksi pada query total card.', [
                'jumlah' => $statusTakSah,
                'periode' => ['dari' => $dari, 'sampai' => $sampai],
            ]);
        }

        /* Validasi: nilai card WAJIB identik dengan SUM(nominal) langsung di
         | basis data untuk periode terpilih. Ketidaksamaan menandakan ada baris
         | berstatus non-Approved (mis. 'Pending'/'Ditolak') yang bocor ke total. */
        $totalDiBasisData = (float) PengeluaranBeban::query()
            ->where('status', PengeluaranBeban::STATUS_APPROVED)
            ->when($dari !== '', fn ($q) => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai !== '', fn ($q) => $q->whereDate('tanggal', '<=', $sampai))
            ->sum('nominal');

        if (abs($totalDiBasisData - $totalApproved) > Setting::toleransiSelisihJurnal()) {
            Log::error('Audit Beban Operasional: total card tidak konsisten dengan SUM(status=Approved).', [
                'card' => $totalApproved,
                'database' => $totalDiBasisData,
                'periode' => ['dari' => $dari, 'sampai' => $sampai],
            ]);
        }

        /* Ringkasan audit per status pada periode terpilih — memperjelas bahwa
         | angka card hanya memuat 'Approved' dan sisanya dikecualikan. */
        $ringkasanStatus = PengeluaranBeban::query()
            ->selectRaw('status, COUNT(*) as jumlah, SUM(nominal) as total')
            ->when($dari !== '', fn ($q) => $q->whereDate('tanggal', '>=', $dari))
            ->when($sampai !== '', fn ($q) => $q->whereDate('tanggal', '<=', $sampai))
            ->groupBy('status')
            ->pluck('total', 'status');

        /* Breakdown akun CoA beban (5-XXXX) pada periode terpilih — hanya
         | transaksi 'Approved' agar konsisten dengan card. */
        $breakdownAkun = PengeluaranBeban::query()
            ->join('coas', 'coas.id', '=', 'pengeluaran_beban.coa_beban_id')
            ->where('pengeluaran_beban.status', PengeluaranBeban::STATUS_APPROVED)
            ->when($dari !== '', fn ($q) => $q->whereDate('pengeluaran_beban.tanggal', '>=', $dari))
            ->when($sampai !== '', fn ($q) => $q->whereDate('pengeluaran_beban.tanggal', '<=', $sampai))
            ->selectRaw('coas.kode_akun, coas.nama_akun, COUNT(*) as jumlah, SUM(pengeluaran_beban.nominal) as total')
            ->groupBy('coas.kode_akun', 'coas.nama_akun')
            ->orderBy('coas.kode_akun')
            ->get();

        return view('pengeluaran-beban.audit', [
            'pengeluarans' => $pengeluarans,
            'totalApproved' => $totalApproved,
            'limitApproval' => PengeluaranBeban::limitApproval(),
            'ringkasanStatus' => $ringkasanStatus,
            'breakdownAkun' => $breakdownAkun,
            'filter' => ['bulan' => $bulan, 'dari' => $dari, 'sampai' => $sampai],
        ]);
    }

    /* ============================================================
     * Helper
     * ============================================================ */

    /** Dropdown akun Beban (5-XXXX), default 5-1001. */
    private function akunBebanList()
    {
        return Coa::where('kategori', 'Beban')->where('is_active', true)
            ->orderBy('kode_akun')->get();
    }

    /**
     * Normalisasi tanggal filter: kembalikan 'YYYY-MM-DD' bila sah, selain itu ''.
     * Dipakai agar input filter yang tidak wajar tidak sampai ke query/view.
     */
    private function tanggalSah(string $nilai): string
    {
        if ($nilai === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai)) {
            return '';
        }

        [$tahun, $bulan, $hari] = array_map('intval', explode('-', $nilai));

        return checkdate($bulan, $hari, $tahun) ? $nilai : '';
    }

    /**
     * Daftar jenis beban operasional untuk dropdown form input.
     *
     * `kode_akun` adalah hasil resolusi nyata terhadap master CoA — bila akun
     * spesifik belum ada, nilainya sudah otomatis jatuh ke akun general 5-1001
     * sehingga label pada dropdown selalu mencerminkan akun yang benar-benar
     * dipakai saat jurnal terbentuk.
     */
    private function jenisBebanList(): array
    {
        $list = [];

        foreach (AutoJournalingService::JENIS_BEBAN as $kode => $jenis) {
            $list[$kode] = [
                'label' => $jenis['label'],
                'kode_akun' => $this->journalService->kodeAkunBebanByJenis($kode),
            ];
        }

        return $list;
    }

    /** Peta kebalikan kode akun -> jenis beban, untuk auto-isi dropdown akun. */
    private function jenisBebanByKodeAkun(): array
    {
        $peta = [];

        foreach ($this->jenisBebanList() as $kode => $jenis) {
            $peta[$jenis['kode_akun']] = $kode;
        }

        return $peta;
    }

    /** Dropdown sumber uang: Kas Teller (1-1001) & Bank (1-1002). */
    private function akunKasList()
    {
        return Coa::where('kategori', 'Aset')
            ->whereIn('kode_akun', \App\Support\Coa::AKUN_KAS_LIKUID)
            ->where('is_active', true)
            ->orderBy('kode_akun')->get();
    }

    /** Auto-posting jurnal berpasangan untuk satu pengeluaran (DEBET Beban / KREDIT Kas). */
    private function postJurnal(PengeluaranBeban $record): void
    {
        // Cegah double-posting: jangan posting ulang jika sudah ada jurnal
        if ($record->jurnal_id) {
            return;
        }

        $jurnal = $this->journalService->jurnalPengeluaranBeban(
            (float) $record->nominal,
            $record->coaBeban->kode_akun,
            $record->coaKas->kode_akun,
            "Pengeluaran Beban {$record->no_pengeluaran} - {$record->keterangan}",
            $record->created_by,
            $record->no_pengeluaran
        );

        // `post()` mengembalikan null (bukan throw) bila CoA tidak
        // ditemukan/nonaktif. Pengeluaran DILARANG tersimpan tanpa jurnal —
        // lempar agar DB::transaction pemanggil ikut rollback.
        if (! $jurnal) {
            throw new \RuntimeException(
                "Jurnal pengeluaran beban {$record->no_pengeluaran} gagal diposting ".
                '(CoA beban/kas tidak ditemukan atau nonaktif).'
            );
        }

        $record->update(['jurnal_id' => $jurnal->id]);
    }
}
