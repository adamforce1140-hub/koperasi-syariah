<?php

namespace App\Http\Controllers\Dashboard;

use App\Contracts\KinerjaStafRepositoryInterface;
use App\Contracts\PembiayaanBermasalahRepositoryInterface;
use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\AuditLog;
use App\Models\JenisSimpanan;
use App\Models\JurnalDetail;
use App\Models\KebijakanKoperasi;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengeluaranBeban;
use App\Models\Rekening;
use App\Models\RestrukturisasiRequest;
use App\Models\Transaksi;
use App\Services\RestrukturisasiService;
use App\Support\Coa;
use App\Support\HierarkiRole;
use App\Support\JenisTransaksi;
use App\Support\MatriksApproval;
use App\Support\RentangPeriode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ManajerController extends Controller
{
    /**
     * Fitur yang DISEMBUNYIKAN dari peran Manajer.
     *
     * Halaman-halaman ini sengaja dihilangkan dari sidebar (lihat
     * NavigationService::menuManajer()) dan tidak boleh dibuka langsung lewat
     * address bar. Percobaan akses diarahkan ulang dengan aman ke dashboard
     * Manajer disertai pesan kesalahan — bukan sekadar 403 — agar tidak ada
     * jalan masuk tersembunyi melalui URL.
     *
     * `audit` (Audit Log) dan `bagiHasil` (Perhitungan Bagi Hasil / SHU)
     * ditambahkan menyusul penghapusan section 'REKAM JEJAK & AUDIT' dan
     * 'LAPORAN & LIKUIDITAS' dari menu Manajer.
     */
    private const FITUR_DISEMBUNYIKAN = ['kinerjaStaf', 'laporan', 'kas', 'audit', 'bagiHasil'];

    public function __construct(
        private KinerjaStafRepositoryInterface $kinerjaStaf,
        private PembiayaanBermasalahRepositoryInterface $pembiayaanBermasalah,
    ) {
        $this->middleware(function ($request, $next) {
            $tolak = HierarkiRole::penolakanKelompok(
                Auth::user()?->role,
                'manajer_fitur_tersembunyi'
            );

            if ($tolak !== null) {
                return redirect()->route($tolak['route'])->with('error', $tolak['pesan']);
            }

            return $next($request);
        })->only(self::FITUR_DISEMBUNYIKAN);
    }

    public function index()
    {
        return view('dashboard.manajer', $this->data());
    }

    /**
     * Endpoint ringkas untuk sinkronisasi real-time: mengembalikan HANYA
     * potongan HTML yang terdampak, sehingga sisi klien dapat memuat ulang
     * bagian tertentu tanpa reload halaman penuh.
     */
    public function ringkas(): JsonResponse
    {
        $data = $this->data();

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.manajer-stats', $data)->render(),
                'approval' => view('dashboard.partials.manajer-approval', $data)->render(),
                'pembiayaan' => view('dashboard.partials.manajer-pembiayaan', $data)->render(),
                'keputusan' => view('dashboard.partials.manajer-keputusan', $data)->render(),
                'beban' => view('dashboard.partials.manajer-beban', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /**
     * Satu sumber data untuk halaman penuh maupun endpoint ringkas, agar
     * keduanya selalu konsisten.
     */
    private function data(): array
    {
        // Approval transaksi pending untuk manajer — status menunggu tingkat
        // manajer dibaca dari hierarki, bukan dipatok literal.
        $statusManajer = HierarkiRole::statusMenunggu('manajer');

        $approvalPending = ApprovalTransaksi::where('status', $statusManajer)
            ->with(['pengaju'])
            ->latest()
            ->limit(5)
            ->get();

        $totalApprovalPending = ApprovalTransaksi::where('status', $statusManajer)->count();

        // Antrean approval pembiayaan untuk manajer (tahap menunggu kewenangan manajer)
        $pengajuanPembiayaan = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_MANAJER])
            ->with(['anggota', 'produk'])
            ->latest()
            ->limit(5)
            ->get();

        $totalPengajuanPembiayaan = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_MANAJER])->count();

        // Statistik transaksi hari ini (rentang sargable agar indeks waktu_transaksi terpakai)
        $transaksiHariIni = RentangPeriode::padaHari(Transaksi::query(), 'waktu_transaksi')->count();
        $totalAnggota = Anggota::count();

        // Keputusan approval pembiayaan yang sudah diambil manajer (5 terakhir)
        $keputusanTerakhir = Pembiayaan::statusIn([
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_DITOLAK,
            Pembiayaan::STATUS_PENDING_PENGURUS,
            Pembiayaan::STATUS_PENDING_DPS,
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_DICAIRKAN,
        ])
            ->with(['anggota', 'produk'])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        // Approval beban yang sudah diputuskan (5 terakhir)
        $bebanKeputusan = PengeluaranBeban::whereIn('status', ['Approved', 'Ditolak'])
            ->with(['penginput', 'coaBeban'])
            ->latest('updated_at')
            ->limit(5)
            ->get();

        return compact(
            'approvalPending', 'totalApprovalPending',
            'pengajuanPembiayaan', 'totalPengajuanPembiayaan',
            'transaksiHariIni', 'totalAnggota',
            'keputusanTerakhir', 'bebanKeputusan'
        );
    }

    public function approvalPembiayaan(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        $validStatus = Pembiayaan::statusFilterApproval();

        if ($status && ! in_array($status, $validStatus, true)) {
            $status = null;
        }

        // Sinkronisasi: jika ada PengajuanPembiayaan yang sudah diajukan/disurvei AO (status Direview/menunggu)
        // tapi belum ada record di tabel Pembiayaan, buatkan record Pembiayaannya secara otomatis (1 per pengajuan)
        $pengajuanPending = PengajuanPembiayaan::statusIn([PengajuanPembiayaan::STATUS_DIREVIEW])
            ->whereNull('pembiayaan_id')
            ->get();

        foreach ($pengajuanPending as $p) {
            // ZERO-HARDCODING: satu titik pembuatan Pembiayaan dari pengajuan
            // (PengajuanPembiayaan::sinkronkanKePembiayaan) sehingga nomor,
            // resolusi akun, dan snapshot akad tidak diduplikasi di sini.
            // Status dipertahankan 'pending_manajer' — kontrak ini muncul di
            // antrian review Manajer, belum masuk tahap pengesahan DPS.
            // `periksaStatus: false` karena pengajuan masih 'Direview'
            // (survei AO selesai) dan memang harus tampil untuk di-review.
            $p->sinkronkanKePembiayaan(Pembiayaan::STATUS_PENDING_MANAJER, false);
        }

        $query = Pembiayaan::with(['anggota'])->latest();
        if ($search) {
            $query->whereHas('anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%"));
        }
        if ($status) {
            $query->statusIn([$status]);
        } else {
            // Default tampilkan semua kecuali pending_ao (sudah di AO)
            $query->statusIn($validStatus);
        }

        $pengajuan = $query->paginate(20)->withQueryString();
        $totalMenunggu = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_MANAJER])->count();
        $totalDisetujui = Pembiayaan::statusIn([Pembiayaan::STATUS_DISETUJUI, Pembiayaan::STATUS_DICAIRKAN])->count();
        $totalDitolak = Pembiayaan::statusIn([Pembiayaan::STATUS_DITOLAK])->count();

        return view('manajer.approval-pembiayaan', compact(
            'pengajuan', 'totalMenunggu', 'totalDisetujui', 'totalDitolak',
            'search', 'status'
        ));
    }

    public function approvalPembiayaanShow(int $id)
    {
        $item = Pembiayaan::with(['anggota', 'dokumen'])->findOrFail($id);

        return view('manajer.approval-pembiayaan-show', compact('item'));
    }

    public function approvalPembiayaanApprove(Request $request, int $id)
    {
        $item = Pembiayaan::findOrFail($id);
        if (! $item->isStatus(Pembiayaan::STATUS_PENDING_MANAJER)) {
            return redirect()->route('manajer.approval-pembiayaan.index')
                ->with('error', 'Pengajuan tidak dalam status menunggu approval manajer.');
        }

        // Ambang kewenangan Manajer & cabang status dibaca dari matriks
        // persetujuan (config/alur_pembiayaan.php via App\Support\MatriksApproval),
        // bukan literal di controller — agar kebijakan berjenjang dapat diubah
        // tanpa menyentuh kode.
        $keputusan = MatriksApproval::keputusan('manajer', (float) $item->jumlah_pembiayaan);
        $limitMax = $keputusan['limit'];
        $newStatus = $keputusan['status'];
        $jumlahPembiayaan = (float) $item->jumlah_pembiayaan;

        $msg = $keputusan['final']
            ? 'Pengajuan pembiayaan berhasil disetujui penuh oleh Manajer.'
            : 'Jumlah pembiayaan (Rp '.number_format($jumlahPembiayaan, 0, ',', '.').') melebihi limit kewenangan Manajer (Rp '.number_format((float) $limitMax, 0, ',', '.').'). Pengajuan otomatis diteruskan ke Pengurus.';

        $item->update([
            'status_pembiayaan' => $newStatus,
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
            'keterangan' => $request->input('catatan') ?? $keputusan['label'],
        ]);

        // Sinkronisasi status pengajuan pembiayaan terkait.
        // PENTING: `latest()` = ORDER BY created_at DESC. Kolom created_at
        // hanya berpresisi DETIK, sehingga bila dua pengajuan dibuat pada detik
        // yang sama urutannya tidak deterministik — MySQL bahkan dapat
        // mengembalikan baris TERLAMA. Karena itu tie-breaker id eksplisit.
        $pengajuan = PengajuanPembiayaan::where('anggota_id', $item->anggota_id)
            ->latest('id')
            ->first();

        if ($pengajuan) {
            $statusPengajuan = match ($newStatus) {
                Pembiayaan::STATUS_DISETUJUI => PengajuanPembiayaan::STATUS_DISETUJUI,
                Pembiayaan::STATUS_PENDING_PENGURUS => PengajuanPembiayaan::STATUS_DIREVIEW,
                default => PengajuanPembiayaan::STATUS_DIREVIEW,
            };
            $pengajuan->update([
                'status' => $statusPengajuan,
                'pembiayaan_id' => $item->id,
                'diproses_oleh' => auth()->id(),
                'tanggal_diproses' => now(),
                'catatan_pengelola' => $request->input('catatan') ?? ($newStatus === Pembiayaan::STATUS_PENDING_PENGURUS ? 'Diteruskan ke Pengurus (Skala Besar)' : 'Disetujui oleh Manajer'),
            ]);
        }

        return redirect()->route('manajer.approval-pembiayaan.index')
            ->with('success', $msg);
    }

    public function approvalPembiayaanForward(Request $request, int $id)
    {
        // Tujuan eskalasi yang sah berasal dari matriks peran (DYN-05).
        // Sebelumnya nilai apa pun selain 'dps' diam-diam dianggap 'pengurus'.
        $tujuanSah = HierarkiRole::kelompok('eskalasi_pembiayaan');

        $request->validate([
            'target' => ['nullable', 'string', Rule::in($tujuanSah)],
        ], [
            'target.in' => 'Tujuan eskalasi tidak dikenal. Pilihan yang sah: '
                .implode(', ', array_map(fn ($peran) => HierarkiRole::label($peran), $tujuanSah)).'.',
        ]);

        $target = (string) $request->input('target', 'pengurus');

        // Status diturunkan dari matriks peran, bukan ternary per nama peran,
        // sehingga tahap approval baru otomatis berlaku di jalur ini.
        $newStatus = HierarkiRole::statusPembiayaanMenunggu($target);

        abort_if($newStatus === null, 422, 'Tujuan eskalasi tidak memiliki status menunggu.');

        $item = Pembiayaan::findOrFail($id);

        $item->update([
            'status_pembiayaan' => $newStatus,
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
            'keterangan' => 'Diteruskan oleh Manajer: '.($request->input('catatan') ?: 'Melebihi limit kewenangan'),
        ]);

        return redirect()->route('manajer.approval-pembiayaan.index')
            ->with('success', 'Pengajuan berhasil diteruskan ke '.HierarkiRole::label($target).'.');
    }

    public function approvalPembiayaanRevisi(Request $request, int $id)
    {
        $request->validate(['catatan_revisi' => 'required|string|max:500']);
        $catatanRevisi = $request->input('catatan_revisi');

        $item = Pembiayaan::findOrFail($id);

        // BATCH 15: permintaan revisi hanya sah pada tahap Manajer. Tanpa ini,
        // pembiayaan yang sudah dicairkan dapat dikembalikan ke AO dan
        // analisis 5C pengajuan yang sudah diputus ikut terhapus.
        if (! $item->isStatus(Pembiayaan::STATUS_PENDING_MANAJER)) {
            return redirect()->route('manajer.approval-pembiayaan.index')
                ->with('error', 'Pengajuan tidak dalam status menunggu approval manajer.');
        }

        $item->update([
            'status_pembiayaan' => Pembiayaan::STATUS_PENDING_AO,
            'keterangan' => 'Minta Revisi Survei: '.$catatanRevisi,
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
        ]);

        // Sinkronisasi ke PengajuanPembiayaan agar statusnya 'Menunggu' dan membuka kembali form survei AO.
        // Tie-breaker `id` wajib — lihat catatan di approvalPembiayaanApprove().
        $pengajuan = PengajuanPembiayaan::where('anggota_id', $item->anggota_id)
            ->latest('id')
            ->first();

        if ($pengajuan) {
            $pengajuan->update([
                'status' => PengajuanPembiayaan::STATUS_MENUNGGU,
                'analisis_5c' => null, // Reset agar form survei terbuka kembali dan tombol simpan aktif
                'catatan_pengelola' => 'Catatan Revisi Manajer: '.$catatanRevisi,
                'tanggal_diproses' => now(),
            ]);
        }

        return redirect()->route('manajer.approval-pembiayaan.index')
            ->with('success', 'Permintaan revisi survei berhasil dikembalikan ke AO.');
    }

    public function approvalPembiayaanReject(Request $request, int $id)
    {
        $request->validate(['catatan_tolak' => 'required|string|max:500']);
        $item = Pembiayaan::findOrFail($id);

        // BATCH 15: tanpa penjagaan ini, pembiayaan yang sudah dicairkan /
        // lunas / berada di tahap lain dapat ditimpa menjadi `ditolak`.
        // Samakan dengan `approvalPembiayaanApprove()` yang sudah menjaga status.
        if (! $item->isStatus(Pembiayaan::STATUS_PENDING_MANAJER)) {
            return redirect()->route('manajer.approval-pembiayaan.index')
                ->with('error', 'Pengajuan tidak dalam status menunggu approval manajer.');
        }

        $item->update([
            'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
            'keterangan' => $request->input('catatan_tolak'),
            'direview_oleh' => auth()->id(),
            'tanggal_direview' => now(),
        ]);

        return redirect()->route('manajer.approval-pembiayaan.index')
            ->with('success', 'Pengajuan pembiayaan telah ditolak.');
    }

    public function approvalTransaksi(Request $request)
    {
        $search = $request->input('search');
        $status = $request->input('status');
        // Tahap menunggu untuk peran ini diturunkan dari hierarki peran, bukan
        // dipatok di sini — menambah tingkat otorisasi baru tidak mengubah kode.
        $statusMenunggu = HierarkiRole::statusMenunggu('manajer');
        $validStatus = array_values(array_filter([$statusMenunggu, 'APPROVED', 'REJECTED']));

        if ($status && ! in_array($status, $validStatus, true)) {
            $status = null;
        }

        $query = ApprovalTransaksi::with(['pengaju', 'approver'])->latest();
        if ($search) {
            $query->whereHas('pengaju', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }
        if ($status) {
            $query->where('status', $status);
        }

        $approvals = $query->paginate(20)->withQueryString();
        $totalPending = $statusMenunggu
            ? ApprovalTransaksi::where('status', $statusMenunggu)->count()
            : 0;
        $totalDisetujui = ApprovalTransaksi::where('status', 'APPROVED')->count();
        $totalDitolak = ApprovalTransaksi::where('status', 'REJECTED')->count();

        return view('manajer.approval-transaksi', compact(
            'approvals', 'totalPending', 'totalDisetujui', 'totalDitolak',
            'search', 'status'
        ));
    }

    public function approvalRestrukturisasi(Request $request)
    {
        $search = $request->input('search');
        $statusFilter = $request->input('status', 'PENDING_APPROVAL');
        $validStatus = [RestrukturisasiRequest::STATUS_PENDING_APPROVAL, RestrukturisasiRequest::STATUS_APPROVED, RestrukturisasiRequest::STATUS_REJECTED];
        if (! in_array($statusFilter, $validStatus)) {
            $statusFilter = RestrukturisasiRequest::STATUS_PENDING_APPROVAL;
        }

        $pengajuan = RestrukturisasiRequest::with(['pembiayaan.anggota', 'ao'])
            ->where('status', $statusFilter)
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('pembiayaan', function ($qb) use ($search) {
                        $qb->where('no_pembiayaan', 'like', "%{$search}%")
                            ->orWhereHas('anggota', function ($qa) use ($search) {
                                $qa->where('nama_lengkap', 'like', "%{$search}%")
                                    ->orWhere('no_anggota', 'like', "%{$search}%");
                            });
                    });
                });
            })
            ->latest()
            ->paginate(15)->withQueryString();

        $totalPending = RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_PENDING_APPROVAL)->count();
        $totalApproved = RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_APPROVED)->count();
        $totalRejected = RestrukturisasiRequest::where('status', RestrukturisasiRequest::STATUS_REJECTED)->count();

        foreach ($pengajuan as $req) {
            if (! empty($req->angsuran_lama_snapshot) && $req->tenor_baru > 0) {
                $req->simulasi_angsuran_baru = RestrukturisasiService::simulasiAngsuranBaru(
                    (float) $req->sisa_piutang_snapshot,
                    (int) $req->tenor_baru
                );
            } else {
                $req->simulasi_angsuran_baru = null;
            }
        }

        return view('manajer.approval-restrukturisasi', compact(
            'pengajuan', 'search', 'statusFilter', 'totalPending', 'totalApproved', 'totalRejected'
        ));
    }

    public function approveRestrukturisasi(Request $request, int $id)
    {
        $request->validate([
            'catatan_manajer' => 'nullable|string|max:1000',
        ], [
            'catatan_manajer.max' => 'Catatan Manajer maksimal 1000 karakter.',
        ]);

        try {
            $item = RestrukturisasiService::approve(
                $id,
                (int) Auth::id(),
                $request->input('catatan_manajer')
            );
        } catch (\Exception $e) {
            return redirect()->route('manajer.approval-restrukturisasi.index')
                ->with('error', 'Approval gagal: '.$e->getMessage());
        }

        return redirect()->route('manajer.approval-restrukturisasi.index')
            ->with('success', 'Pengajuan '.$item->skema_restrukturisasi.' untuk '.($item->pembiayaan->no_pembiayaan ?? '-').' disetujui. Jadwal angsuran baru telah di-generate (Addendum '.$item->addendum_no.').');
    }

    public function rejectRestrukturisasi(Request $request, int $id)
    {
        $request->validate([
            'catatan_manajer' => 'required|string|max:1000',
        ], [
            'catatan_manajer.required' => 'Catatan/alasan penolakan wajib diisi.',
            'catatan_manajer.max' => 'Catatan Manajer maksimal 1000 karakter.',
        ]);

        try {
            $item = RestrukturisasiService::reject(
                $id,
                (int) Auth::id(),
                $request->input('catatan_manajer')
            );
        } catch (\Exception $e) {
            return redirect()->route('manajer.approval-restrukturisasi.index')
                ->with('error', 'Penolakan gagal: '.$e->getMessage());
        }

        return redirect()->route('manajer.approval-restrukturisasi.index')
            ->with('success', 'Pengajuan '.$item->skema_restrukturisasi.' untuk '.($item->pembiayaan->no_pembiayaan ?? '-').' ditolak. Jadwal angsuran lama tetap berlaku.');
    }

    /**
     * Dokumen Addendum Akad (print-friendly — simpan sebagai PDF via browser).
     */
    public function addendumRestrukturisasi(int $id)
    {
        $req = RestrukturisasiRequest::with(['pembiayaan.anggota', 'ao', 'manajer'])
            ->findOrFail($id);

        if ($req->status !== RestrukturisasiRequest::STATUS_APPROVED) {
            return redirect()->route('manajer.approval-restrukturisasi.index')
                ->with('error', 'Dokumen Addendum hanya tersedia untuk pengajuan yang telah disetujui.');
        }

        $jadwalBaru = Angsuran::where('pembiayaan_id', $req->pembiayaan_id)
            ->where('status_angsuran', '!=', 'Sudah Dibayar')
            ->orderBy('angsuran_ke')
            ->get();

        return view('manajer.addendum-restrukturisasi', compact('req', 'jadwalBaru'));
    }

    public function kinerjaStaf(Request $request)
    {
        $bulan = (int) $request->input('bulan', now()->month);
        $tahun = (int) $request->input('tahun', now()->year);

        /* ------------------------------------------------------------------
         | Daftar SELURUH staf AO & Teller (aktif) sebagai basis baris laporan,
         | supaya staf tanpa aktivitas tetap tercatat dengan nilai 0 —
         | bukan hilang dari laporan (pencatatan merata).
         | ---------------------------------------------------------------- */
        $semuaAO = $this->kinerjaStaf->penggunaKelompok('kinerja_ao');
        $semuaTeller = $this->kinerjaStaf->penggunaKelompok('kinerja_teller');

        // Peta id => agregat, agar penggabungan tidak bergantung pada urutan baris.
        $agregatAO = $this->kinerjaStaf->agregatPengajuan($bulan, $tahun);
        $agregatTeller = $this->kinerjaStaf->agregatTransaksi($bulan, $tahun);

        // Gabungkan setiap staf dengan capaiannya; staf tanpa aktivitas tetap tampil (0).
        $mapAO = $semuaAO->map(fn ($u) => (object) [
            'user_id' => $u->id,
            'nama' => $u->name,
            'peran' => null,
            'total_pengajuan' => $agregatAO[$u->id]->total_pengajuan ?? 0,
            'total_nominal' => $agregatAO[$u->id]->total_nominal ?? 0,
        ])->sortByDesc('total_nominal')->values();

        $mapTeller = $semuaTeller->map(fn ($u) => (object) [
            'user_id' => $u->id,
            'nama' => $u->name,
            'peran' => null,
            'total_transaksi' => $agregatTeller[$u->id]->total_transaksi ?? 0,
            'volume_transaksi' => $agregatTeller[$u->id]->volume_transaksi ?? 0,
        ])->sortByDesc('volume_transaksi')->values();

        // Sertakan petugas di luar role ao/teller yang benar-benar menangani
        // pengajuan / transaksi, agar aktivitasnya tidak hilang dari laporan.
        // Kolom 'peran' menandai bahwa baris tersebut bukan staf ber-role baku.
        $kinerjaAO = $mapAO->concat($this->luarRole($agregatAO, $semuaAO->pluck('id'), 'total_pengajuan', 'total_nominal'))
            ->sortByDesc('total_nominal')->values();

        $kinerjaTeller = $mapTeller->concat($this->luarRole($agregatTeller, $semuaTeller->pluck('id'), 'total_transaksi', 'volume_transaksi'))
            ->sortByDesc('volume_transaksi')->values();

        // Total baris untuk baris ringkasan di view.
        $totalPengajuan = $kinerjaAO->sum('total_pengajuan');
        $totalNominalPengajuan = $kinerjaAO->sum('total_nominal');
        $totalTransaksi = $kinerjaTeller->sum('total_transaksi');
        $totalVolumeTransaksi = $kinerjaTeller->sum('volume_transaksi');

        return view('manajer.kinerja-staf', compact(
            'kinerjaAO', 'kinerjaTeller', 'bulan', 'tahun',
            'totalPengajuan', 'totalNominalPengajuan', 'totalTransaksi', 'totalVolumeTransaksi'
        ));
    }

    /**
     * Staf ber-role lain yang tercatat menangani pengajuan / melayani transaksi
     * kasir, tetap ditampilkan agar pencatatan kinerja tidak ada yang hilang.
     *
     * Data diambil dari agregat repository yang sama, lalu disaring agar hanya
     * memuat user di LUAR kelompok peran baku — sehingga satu kueri agregat
     * melayani baris staf baku maupun petugas luar role.
     *
     * @param  Collection<int, object>  $agregat
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, object>
     */
    private function luarRole($agregat, $userIds, string $kolomJumlah, string $kolomNominal)
    {
        $luar = $agregat->reject(fn ($r) => $userIds->contains((int) $r->user_id));

        $users = $this->kinerjaStaf->identitasPengguna($luar->pluck('user_id'));

        return $luar->map(fn ($r) => (object) [
            'user_id' => (int) $r->user_id,
            'nama' => $users[$r->user_id]->name ?? 'Staf Tidak Dikenal',
            'peran' => $users[$r->user_id]->role ?? null,
            $kolomJumlah => $r->{$kolomJumlah},
            $kolomNominal => $r->{$kolomNominal},
        ])->values();
    }

    public function bagiHasil(Request $request)
    {
        $bulan = (int) $request->input('bulan', now()->month);
        $tahun = (int) $request->input('tahun', now()->year);

        // Estimasi Pendapatan Operasional Koperasi
        $pendapatanPembiayaan = RentangPeriode::padaBulan(
            Transaksi::whereIn('jenis_transaksi', JenisTransaksi::angsuran()),
            'waktu_transaksi', $bulan, $tahun
        )->sum('jumlah');

        $totalSimpananMudharabah = Rekening::whereIn(
            'jenis_rekening',
            JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA)
        )->sum('saldo');

        return view('manajer.bagi-hasil', compact(
            'bulan', 'tahun', 'pendapatanPembiayaan', 'totalSimpananMudharabah'
        ));
    }

    public function pembiayaanAktif(Request $request)
    {
        $search = $request->input('search');

        // Status pembiayaan yang dianggap aktif / berjalan.
        // Wajib memakai Pembiayaan::statusBerjalan() sebagai satu sumber
        // kebenaran: daftar lokal sebelumnya menghilangkan 'disahkan',
        // 'pending_pencairan', dan 'pending_restrukturisasi' sehingga akad
        // yang sudah disahkan tetapi belum cair tidak tampil di halaman ini
        // padahal dihitung modul lain.
        $activeStatuses = Pembiayaan::statusBerjalan();

        $query = Pembiayaan::with(['anggota', 'produk'])
            ->statusIn($activeStatuses)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('no_pembiayaan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', fn ($sub) => $sub->where('nama_lengkap', 'like', "%{$search}%")
                        ->orWhere('no_anggota', 'like', "%{$search}%"));
            });
        }

        $pembiayaan = $query->paginate(20)->withQueryString();

        // Hitung total statistik pembiayaan aktif dan lunas dari seluruh database
        $totalAktif = Pembiayaan::statusIn($activeStatuses)->count();
        $totalLunas = Pembiayaan::statusIn([Pembiayaan::STATUS_LUNAS])->count();

        return view('manajer.pembiayaan-aktif', compact(
            'pembiayaan', 'totalAktif', 'totalLunas', 'search'
        ));
    }

    /**
     * Detail pembiayaan (read-only) untuk monitoring Manajer —
     * dipakai dari halaman NPF / Pembiayaan Aktif.
     */
    public function pembiayaanShow(int $id)
    {
        $item = Pembiayaan::with([
            'anggota',
            'produk',
            'jadwalAngsuran',
            'dokumen',
            'restrukturisasiRequests.ao',
            'restrukturisasiRequests.manajer',
            'reviewedBy',
            'approvedFinalBy',
            'disahkanOleh',
        ])->findOrFail($id);

        $today = Carbon::today();

        /* Tunggakan aktif memakai BASIS `tanggal_bayar` (null = belum dibayar),
         | sama seperti `PembiayaanBermasalahRepository::menunggak()` dan kartu
         | statistik Pengurus. Kolom `status_angsuran` bisa basi (jadwal dibuat
         | berstatus 'Jatuh Tempo' lalu tidak pernah disinkronkan), sehingga
         | memfilter dengan kolom itu membuat NPF halaman ini berbeda dari
         | daftar NPF. 'Dibayar Sebagian' tetap dihitung menunggak karena
         | pokoknya belum tertagih penuh walau `tanggal_bayar` sudah terisi. */
        $angsuranTunggakan = $item->jadwalAngsuran
            ->filter(fn ($a) => $a->tanggal_jatuh_tempo
                && $a->tanggal_jatuh_tempo->lt($today)
                && ($a->tanggal_bayar === null || $a->status_angsuran === 'Dibayar Sebagian'))
            ->values();

        $angsuranTerlama = $angsuranTunggakan->sortBy('tanggal_jatuh_tempo')->first();
        $hariTunggakan = $angsuranTerlama
            ? (int) max(0, round($angsuranTerlama->tanggal_jatuh_tempo->diffInDays($today)))
            : 0;
        $totalTunggakan = $angsuranTunggakan->sum('jumlah_angsuran');

        // Kolektibilitas: prioritas ke kolom status_kolektibilitas, fallback ke perhitungan.
        // Ambang hari dibaca dari kebijakan koperasi (dapat dikonfigurasi) —
        // bukan lagi hardcode 30/90/180 di kode inti.
        // Nilai override disimpan sebagai kode (mis. `kol5`); view menampilkan label
        // manusiawi, jadi kode harus dipetakan — kode asing jatuh ke `default`.
        $kolektibilitas = $item->status_kolektibilitas
            ? self::labelKolektibilitas($item->status_kolektibilitas)
            : null;
        if (! $kolektibilitas && $hariTunggakan > 0) {
            $kolektibilitas = self::labelKolektibilitas(
                KebijakanKoperasi::kolektibilitasDariHari($hariTunggakan)
            );
        }

        return view('manajer.pembiayaan-show', compact(
            'item', 'angsuranTunggakan', 'hariTunggakan', 'totalTunggakan', 'kolektibilitas'
        ));
    }

    public function npf(Request $request)
    {
        $search = $request->input('search');
        $today = Carbon::today();

        $pembiayaanBermasalah = $this->pembiayaanBermasalah->menunggak($today, $search, 20);

        $pembiayaanBermasalah->getCollection()->transform(function ($item) use ($today) {
            $angsuranTerlama = $item->jadwalAngsuran->sortBy('tanggal_jatuh_tempo')->first();
            // diffInDays bersifat signed: hitung dari tanggal jatuh tempo ke hari ini agar bernilai positif.
            $hariTunggakan = $angsuranTerlama && $angsuranTerlama->tanggal_jatuh_tempo
                ? (int) max(0, round($angsuranTerlama->tanggal_jatuh_tempo->diffInDays($today)))
                : 0;

            $totalTunggakan = $item->jadwalAngsuran->sum('jumlah_angsuran');

            // Klasifikasi memakai ambang hari dari kebijakan koperasi sehingga
            // dapat disesuaikan tanpa mengubah kode (bukan hardcode 30/90/180).
            [$kolektibilitas, $badgeColor, $badgeStyle] = self::gayaKolektibilitas(
                KebijakanKoperasi::kolektibilitasDariHari($hariTunggakan)
            );

            $item->angsuran_terlama = $angsuranTerlama;
            $item->hari_tunggakan = $hariTunggakan;
            $item->total_tunggakan = $totalTunggakan;
            $item->kolektibilitas = $kolektibilitas;
            $item->badge_color = $badgeColor;
            $item->badge_style = $badgeStyle;

            return $item;
        });

        $totalNpf = $pembiayaanBermasalah->total();

        return view('manajer.npf', compact('pembiayaanBermasalah', 'totalNpf', 'search'));
    }

    /**
     * Kode kolektibilitas yang dikenal peta label (DYN-06).
     *
     * Kode asing dipetakan ke `default` agar perilaku lama — kode tak dikenal
     * ditampilkan sebagai Macet — tetap sama.
     */
    private static function kodeKolektibilitas(string $kode): string
    {
        $label = (array) config('kolektibilitas.label', []);

        return array_key_exists($kode, $label)
            ? $kode
            : (string) config('kolektibilitas.default', 'kol5');
    }

    /**
     * Label manusiawi tiap kode kolektibilitas.
     *
     * Sumber label ada di `config/kolektibilitas.php` sehingga menambah atau
     * menggabung kategori tidak memerlukan perubahan kode (DYN-06). Ambang
     * hari tetap dibaca dari `Setting::ambangKolektibilitas()`.
     */
    public static function labelKolektibilitas(string $kode): string
    {
        return (string) config('kolektibilitas.label.'.self::kodeKolektibilitas($kode));
    }

    /**
     * Label + gaya badge tiap kode kolektibilitas.
     *
     * @return array{0: string, 1: string, 2: string} [label, badgeColor, badgeStyle]
     */
    public static function gayaKolektibilitas(string $kode): array
    {
        $kode = self::kodeKolektibilitas($kode);

        $gaya = (array) config(
            'kolektibilitas.badge.'.$kode,
            config('kolektibilitas.badge.'.config('kolektibilitas.default', 'kol5'))
        );

        return [self::labelKolektibilitas($kode), $gaya[0] ?? '', $gaya[1] ?? ''];
    }

    public function laporan(Request $request)
    {
        $bulan = (int) $request->input('bulan', now()->month);
        $tahun = (int) $request->input('tahun', now()->year);

        /* ------------------------------------------------------------------
         | Dasar perhitungan laporan.
         |
         | 1. Semua angka memakai kolom `waktu_transaksi` (tanggal kejadian
         |    sebenarnya), bukan `created_at`. Pada sistem ini `created_at`
         |    hanya menandai kapan baris di-input sehingga transaksi bertanggal
         |    Maret–Agustus ikut menumpuk di bulan input (September) dan
         |    laporan bulan lain tampak kosong.
         |
         | 2. Hanya transaksi berstatus 'Sukses' yang dihitung. Transaksi
         |    Voided/Gagal/Dibatalkan tidak boleh menambah nominal laporan.
         | ---------------------------------------------------------------- */
        $queryTransaksi = fn () => RentangPeriode::padaBulan(
            Transaksi::query()->where('status_transaksi', 'Sukses'),
            'waktu_transaksi', $bulan, $tahun
        );

        // Ringkasan transaksi per bulan
        $totalTransaksi = $queryTransaksi()->count();
        $totalNominal = $queryTransaksi()->sum('jumlah');

        /* Pembiayaan cair dibaca dari transaksi 'Pencairan Pembiayaan' pada
         | periode berjalan. Sebelumnya memakai `Pembiayaan::created_at` yang
         | selalu 0 karena akad lama dibuat ulang di bulan input — sehingga
         | kartu "Pembiayaan Cair" selalu Rp 0 di semua periode. */
        $totalPembiayaanCair = RentangPeriode::padaBulan(
            Transaksi::query()
                ->where('status_transaksi', 'Sukses')
                ->where('jenis_transaksi', 'Pencairan Pembiayaan'),
            'waktu_transaksi', $bulan, $tahun
        )->sum('jumlah');

        $totalAngsuranMasuk = RentangPeriode::padaBulan(
            Angsuran::query()->where('status_angsuran', 'Sudah Dibayar'),
            'tanggal_bayar', $bulan, $tahun
        )->sum('total_bayar');

        // Jumlah berkas transaksi bermasalah pada periode ini (transparansi,
        // karena nominalnya tidak dihitung sebagai pendapatan).
        $transaksiBermasalah = RentangPeriode::padaBulan(
            Transaksi::query()->where('status_transaksi', '!=', 'Sukses'),
            'waktu_transaksi', $bulan, $tahun
        );
        $jumlahTransaksiBermasalah = $transaksiBermasalah->count();
        $nominalTransaksiBermasalah = (clone $transaksiBermasalah)->sum('jumlah');

        /* Rincian arus kas per jenis transaksi — inti "pencatatan merata":
         | setiap jenis transaksi pada periode ini terlihat kontribusinya,
         | bukan hanya satu angka total gabungan. */
        $rincianTransaksi = RentangPeriode::padaBulan(
            Transaksi::query()->where('status_transaksi', 'Sukses'),
            'waktu_transaksi', $bulan, $tahun
        )
            ->selectRaw('jenis_transaksi, posisi, count(*) as jumlah_transaksi, COALESCE(sum(jumlah), 0) as total_nominal')
            ->groupBy('jenis_transaksi', 'posisi')
            ->orderByDesc('total_nominal')
            ->get();

        /* Rekap 12 bulan pada tahun terpilih, supaya periode yang tidak
         | ditampilkan tetap tercatat dan dapat dibandingkan (merata). */
        $rekapBulanan = collect(range(1, 12))->map(function ($m) use ($tahun) {
            $q = fn () => RentangPeriode::padaBulan(
                Transaksi::query()->where('status_transaksi', 'Sukses'),
                'waktu_transaksi', $m, $tahun
            );

            return (object) [
                'bulan' => $m,
                'label' => Carbon::create()->month($m)->translatedFormat('F'),
                'jumlah' => $q()->count(),
                'nominal' => (float) $q()->sum('jumlah'),
                'angsuran' => (float) RentangPeriode::padaBulan(
                    Angsuran::query()->where('status_angsuran', 'Sudah Dibayar'),
                    'tanggal_bayar', $m, $tahun
                )->sum('total_bayar'),
            ];
        });

        return view('manajer.laporan', compact(
            'bulan', 'tahun',
            'totalTransaksi', 'totalNominal',
            'totalPembiayaanCair', 'totalAngsuranMasuk',
            'jumlahTransaksiBermasalah', 'nominalTransaksiBermasalah',
            'rincianTransaksi', 'rekapBulanan'
        ));
    }

    public function kas(Request $request)
    {
        /* Label kartu menyebut "rekening aktif", jadi rekening non-aktif
         | (tutup/beku) tidak boleh ikut dijumlahkan. */
        $rekening = Rekening::with('anggota')
            ->where('status_rekening', 'Aktif')
            ->orderBy('no_rekening')
            ->get();

        /* PENTING: seluruh rekening pada tabel `rekening` adalah simpanan
         | anggota (Pokok/Wajib/Sukarela/Berjangka). Nilainya merupakan
         | KEWAJIBAN koperasi kepada anggota — bukan kas milik koperasi.
         | Sebelumnya angka ini ditampilkan sebagai "Total Saldo Kas Koperasi",
         | sehingga kas terlihat jauh lebih besar dari yang benar-benar tersedia. */
        $totalSimpanan = (float) $rekening->sum('saldo');

        /* Posisi kas riil diambil dari jurnal (COA 1-1001 Kas Teller dan
         | 1-1002 Bank/Rekening Utama), hanya jurnal berstatus Posted.
         | Arah saldo diturunkan dari Kepala kode akun (Aset = Debet →
         | debet − kredit), bukan kolom mentah `tipe_saldo_normal`, supaya
         | konsisten dengan Coa::saldoBerjalan() dan akun kontra. */
        $posisiKas = JurnalDetail::query()
            ->join('jurnal', 'jurnal.id', '=', 'jurnal_detail.jurnal_id')
            ->join('coas', 'coas.id', '=', 'jurnal_detail.coa_id')
            ->where('jurnal.status', 'Posted')
            ->whereNull('jurnal.deleted_at')
            ->whereIn('coas.kode_akun', Coa::AKUN_KAS_LIKUID)
            ->selectRaw('coas.kode_akun, coas.nama_akun')
            ->selectRaw('COALESCE(SUM(jurnal_detail.debet), 0) as total_debet')
            ->selectRaw('COALESCE(SUM(jurnal_detail.kredit), 0) as total_kredit')
            ->groupBy('coas.kode_akun', 'coas.nama_akun')
            ->orderBy('coas.kode_akun')
            ->get()
            ->map(function ($row) {
                $debet = (float) $row->total_debet;
                $kredit = (float) $row->total_kredit;
                $row->saldo = \App\Models\Coa::posisiSaldoDariKode($row->kode_akun) === 'Debet'
                    ? $debet - $kredit
                    : $kredit - $debet;

                return $row;
            });

        $totalKas = (float) $posisiKas->sum('saldo');

        /* Hanya transaksi berstatus 'Sukses' yang benar-benar memindahkan dana.
         | Transaksi Voided/Gagal/Dibatalkan tidak boleh muncul di monitoring kas. */
        $transaksiTerbaru = Transaksi::with(['anggota', 'pembiayaan.anggota', 'rekening.anggota'])
            ->where('status_transaksi', 'Sukses')
            ->latest('waktu_transaksi')
            ->limit(15)
            ->get();

        return view('manajer.kas', compact(
            'rekening', 'totalSimpanan', 'posisiKas', 'totalKas', 'transaksiTerbaru'
        ));
    }

    public function audit(Request $request)
    {
        $search = $request->input('search');
        $bulan = $request->filled('bulan') ? (int) $request->input('bulan') : null;
        $tahun = $request->filled('tahun') ? (int) $request->input('tahun') : null;

        $auditLogExists = AuditLog::exists();

        if ($auditLogExists) {
            $query = AuditLog::with(['user', 'rekening.anggota'])->latest();

            if ($tahun) {
                // Filter sargable: tahun+bulan → rentang satu bulan; tahun saja → rentang satu tahun.
                RentangPeriode::padaBulanAtauTahun($query, 'created_at', (int) $tahun, $bulan);
            } elseif ($bulan) {
                // Bulan tanpa tahun tidak dapat dijadikan rentang sargable; perilaku dipertahankan.
                $query->whereMonth('created_at', $bulan);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('no_rekening', 'like', "%{$search}%")
                        ->orWhere('jenis_transaksi', 'like', "%{$search}%")
                        ->orWhere('keterangan', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('rekening.anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$search}%"));
                });
            }

            $total = (clone $query)->count();
            $log = $query->paginate(20)->withQueryString();
        } else {
            // Fallback ke tabel Transaksi jika audit_logs belum ada entri baru
            $query = Transaksi::with(['dibuatOleh', 'anggota', 'rekening.anggota', 'pembiayaan.anggota'])->latest('waktu_transaksi');

            if ($tahun) {
                // Filter sargable: tahun+bulan → rentang satu bulan; tahun saja → rentang satu tahun.
                RentangPeriode::padaBulanAtauTahun($query, 'waktu_transaksi', (int) $tahun, $bulan);
            } elseif ($bulan) {
                // Bulan tanpa tahun tidak dapat dijadikan rentang sargable; perilaku dipertahankan.
                $query->whereMonth('waktu_transaksi', $bulan);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('no_transaksi', 'like', "%{$search}%")
                        ->orWhere('jenis_transaksi', 'like', "%{$search}%")
                        ->orWhere('catatan', 'like', "%{$search}%")
                        ->orWhereHas('dibuatOleh', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$search}%"))
                        ->orWhereHas('rekening', fn ($r) => $r->where('no_rekening', 'like', "%{$search}%"));
                });
            }

            $total = (clone $query)->count();
            $log = $query->paginate(20)->withQueryString();
        }

        $logs = $log; // alias

        return view('manajer.audit', compact('log', 'logs', 'total', 'search', 'bulan', 'tahun', 'auditLogExists'));
    }
}
