<?php

namespace App\Http\Controllers;

use App\Models\ApprovalTransaksi;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\ReversalService;
use App\Services\TransactionLimitService;
use App\Support\HierarkiRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApprovalTransaksiController extends Controller
{
    public function __construct(
        private TransactionLimitService $limitService,
        private ReversalService $reversalService,
    ) {}

    /** Daftar approval pending untuk manajer/pengurus */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = ApprovalTransaksi::with(['pengaju', 'approver'])
            ->orderBy('created_at', 'desc');

        // Cakupan peran SELALU diterapkan lebih dulu. Sebelumnya parameter
        // `?status=` menggantikan cakupan (else-if), sehingga pengguna dapat
        // melewati batas antreannya — mis. manajer melihat seluruh keputusan
        // manajer lain. Kini `status` hanya MENYARING di dalam cakupan.
        $this->batasiKeCakupanPeran($query, $user, untukPengurusSemuaRiwayat: true);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $approvals = $query->paginate(20);
        $pendingCount = $this->hitungPending($user);

        return view('pengelola.approval.index', compact('approvals', 'pendingCount'));
    }

    /**
     * Batasi kueri approval pada antrean yang menjadi wewenang pengguna.
     *
     * Aturan diturunkan dari `config/role_hierarki.php` (bukan literal peran)
     * sehingga peran penyetuju baru langsung ikut tanpa mengubah kode:
     *  - pengurus : seluruh antrean (dan riwayat, bila diminta);
     *  - penyetuju: status yang menunggu perannya + yang pernah ia putuskan;
     *  - lainnya  : hanya pengajuan miliknya sendiri.
     *
     * @param  Builder<ApprovalTransaksi>  $query
     */
    private function batasiKeCakupanPeran($query, User $user, bool $untukPengurusSemuaRiwayat): void
    {
        if ($user->isPengurus()) {
            if ($untukPengurusSemuaRiwayat) {
                $query->whereIn('status', array_merge(
                    HierarkiRole::semuaStatusMenunggu(),
                    ['APPROVED', 'REJECTED']
                ));
            }

            return;
        }

        $statusBerwenang = HierarkiRole::penyetuju($user->role)
            ? HierarkiRole::statusMenunggu($user->role)
            : null;

        if ($statusBerwenang !== null) {
            $query->where(function ($q) use ($user, $statusBerwenang) {
                $q->where('status', $statusBerwenang)
                    ->orWhere('disetujui_oleh', $user->id);
            });

            return;
        }

        $query->where('diajukan_oleh', $user->id);
    }

    /**
     * Jumlah antrean yang relevan bagi pengguna — bukan total seluruh koperasi.
     * Angka ini tampil sebagai lencana pada menu, sehingga tidak boleh
     * membocorkan volume antrean peran lain.
     */
    private function hitungPending(User $user): int
    {
        $query = ApprovalTransaksi::whereIn('status', HierarkiRole::semuaStatusMenunggu());

        if ($user->isPengurus()) {
            return $query->count();
        }

        $statusBerwenang = HierarkiRole::penyetuju($user->role)
            ? HierarkiRole::statusMenunggu($user->role)
            : null;

        if ($statusBerwenang !== null) {
            return $query->where('status', $statusBerwenang)->count();
        }

        return $query->where('diajukan_oleh', $user->id)->count();
    }

    /** Detail approval */
    public function show(ApprovalTransaksi $approval)
    {
        // Cakupan peran WAJIB sama dengan daftar. Tanpa ini, pembatasan pada
        // `index` hanya lapisan tampilan: siapa pun yang menebak ID dapat
        // membuka detail approval di luar antreannya (manajer membaca antrean
        // pengurus atau keputusan manajer lain).
        $dalamCakupan = ApprovalTransaksi::whereKey($approval->getKey());
        $this->batasiKeCakupanPeran($dalamCakupan, Auth::user(), untukPengurusSemuaRiwayat: false);

        abort_unless(
            $dalamCakupan->exists(),
            403,
            'Approval ini di luar cakupan wewenang Anda.',
        );

        $approval->load(['pengaju', 'approver']);

        return view('pengelola.approval.show', compact('approval'));
    }

    /**
     * Status approval untuk klien mobile (`GET /api/approval/status`).
     *
     * Mengembalikan antrean yang relevan dengan peran pengguna saat ini,
     * memakai hierarki peran yang sama dengan halaman web (tanpa literal
     * peran). Bila `referensi_id` dikirim, hanya approval untuk transaksi
     * tersebut yang dikembalikan.
     */
    public function apiStatus(Request $request)
    {
        $user = Auth::user();

        $query = ApprovalTransaksi::with(['pengaju', 'approver'])
            ->orderBy('created_at', 'desc');

        // Cakupan peran WAJIB lebih dulu. Rute ini hanya dijaga
        // `auth:sanctum` (tanpa `izin:`), sehingga tanpa cakupan setiap
        // pengguna yang login — termasuk anggota — dapat membaca seluruh
        // antrean approval koperasi hanya dengan mengirim `?status=APPROVED`.
        $this->batasiKeCakupanPeran($query, $user, untukPengurusSemuaRiwayat: false);

        if ($request->filled('referensi_id')) {
            $query->where('referensi_id', $request->integer('referensi_id'));

            if ($request->filled('tipe_referensi')) {
                $query->where('tipe_referensi', $request->input('tipe_referensi'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $approvals = $query->limit(50)->get();

        return response()->json([
            'success' => true,
            'message' => 'Status approval berhasil diambil',
            'data' => [
                'pending_count' => $this->hitungPending($user),
                'approvals' => $approvals,
            ],
        ]);
    }

    /** Approve */
    public function approve(ApprovalTransaksi $approval, Request $request)
    {
        $request->validate(['catatan' => 'nullable|string|max:500']);

        // Cek apakah ini void request (bukan transaksi biasa yang butuh approval limit)
        $isVoidRequest = $approval->tipe_referensi === 'transaksi'
            && in_array($approval->status, HierarkiRole::semuaStatusMenunggu(), true)
            && Transaksi::where('id', $approval->referensi_id)
                ->whereIn('status_transaksi', ['Pending Void'])
                ->exists();

        if ($isVoidRequest) {
            $result = $this->reversalService->executeVoidReversal($approval, Auth::id());
            $msg = $result['status'] === 'success'
                ? 'Void berhasil disetujui dan transaksi telah dibatalkan.'
                : $result['message'];

            return redirect()->route('pengelola.approval.index')
                ->with($result['status'] === 'success' ? 'success' : 'error', $msg);
        }

        // Approval biasa (limit transaksi)
        try {
            $result = $this->limitService->approveTransaction($approval, Auth::user(), $request->catatan ?? '');
        } catch (\Throwable $e) {
            // Jurnal gagal → approveTransaction melempar agar transaksi di-rollback.
            report($e);

            return redirect()->route('pengelola.approval.index')
                ->with('error', $e->getMessage());
        }

        $msg = $result['status'] === 'success' ? 'Transaksi berhasil disetujui.' : $result['message'];

        return redirect()->route('pengelola.approval.index')
            ->with($result['status'] === 'success' ? 'success' : 'error', $msg);
    }

    /** Reject */
    public function reject(ApprovalTransaksi $approval, Request $request)
    {
        $request->validate(['catatan' => 'required|string|max:500']);
        $result = $this->limitService->rejectTransaction($approval, Auth::user(), $request->catatan);
        $msg = $result['status'] === 'success' ? 'Transaksi berhasil ditolak.' : $result['message'];

        return redirect()->route('pengelola.approval.index')
            ->with($result['status'] === 'success' ? 'success' : 'error', $msg);
    }
}
