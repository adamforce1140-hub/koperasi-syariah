<?php

namespace App\Http\Controllers;

use App\Models\Angsuran;
use App\Models\KeringanTazir;
use App\Models\TazirReliefRequest;
use App\Services\TazirService;
use App\Support\PemisahanTugas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KeringanTazirController extends Controller
{
    public function __construct(private TazirService $tazirService) {}

    public function index(Request $request)
    {
        $status = $request->status;
        $search = $request->search;

        $query = KeringanTazir::with([
            'angsuran',
            'pembiayaan.anggota',
            'anggota',
            'diajukanOleh',
            'diputuskanOleh',
        ])->latest('created_at');

        if ($status && in_array($status, ['PENDING', 'APPROVED', 'REJECTED'])) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->whereHas('anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$search}%")
                ->orWhere('no_anggota', 'like', "%{$search}%")
            )->orWhereHas('pembiayaan', fn ($q) => $q->where('no_pembiayaan', 'like', "%{$search}%")
            );
        }

        $keringanans = $query->paginate(20)->withQueryString();

        $totalPending = KeringanTazir::where('status', 'PENDING')->count();
        $totalApproved = KeringanTazir::where('status', 'APPROVED')->count();
        $totalRejected = KeringanTazir::where('status', 'REJECTED')->count();

        return view('manajer.keringanan-tazir', compact(
            'keringanans', 'totalPending', 'totalApproved', 'totalRejected',
            'status', 'search'
        ));
    }

    public function approve(Request $request, KeringanTazir $keringanTazir)
    {
        $request->validate([
            'catatan_manajer' => 'nullable|string|max:500',
        ]);

        if (! $keringanTazir->isPending()) {
            return redirect()->back()->with('error', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        // S-06 — GCG pemisahan tugas: pengaju tidak boleh memutus pengajuannya
        // sendiri (maker ≠ checker), walaupun perannya berwenang.
        if (PemisahanTugas::dilanggar($keringanTazir->diajukan_oleh, Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($keringanTazir, $request) {
            $keringanTazir->update([
                'status' => 'APPROVED',
                'nominal_tazir_final' => 0, // dibebaskan penuh
                'diputuskan_oleh' => Auth::id(),
                'catatan_manajer' => $request->catatan_manajer,
                'diputuskan_at' => now(),
            ]);

            // Update angsuran: set denda = 0 jika belum dibayar
            $angsuran = $keringanTazir->angsuran;
            if ($angsuran && $angsuran->status_angsuran !== 'Sudah Dibayar') {
                $angsuran->update(['denda' => 0]);
            }
        });

        return redirect()->back()->with('success',
            'Keringanan ta\'zir DISETUJUI. Denda anggota dibebaskan (Rp 0).');
    }

    public function reject(Request $request, KeringanTazir $keringanTazir)
    {
        $request->validate([
            'catatan_manajer' => 'required|string|max:500',
        ]);

        if (! $keringanTazir->isPending()) {
            return redirect()->back()->with('error', 'Pengajuan ini sudah diproses sebelumnya.');
        }

        // S-06 — GCG pemisahan tugas (lihat approve()).
        if (PemisahanTugas::dilanggar($keringanTazir->diajukan_oleh, Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($keringanTazir, $request) {
            $keringanTazir->update([
                'status' => 'REJECTED',
                'nominal_tazir_final' => $keringanTazir->nominal_tazir_hitung, // kembali ke normal
                'diputuskan_oleh' => Auth::id(),
                'catatan_manajer' => $request->catatan_manajer,
                'diputuskan_at' => now(),
            ]);

            // Kembalikan denda ke nominal kalkulasi
            $angsuran = $keringanTazir->angsuran;
            if ($angsuran && $angsuran->status_angsuran !== 'Sudah Dibayar') {
                $angsuran->update(['denda' => $keringanTazir->nominal_tazir_hitung]);
            }
        });

        return redirect()->back()->with('warning',
            'Keringanan ta\'zir DITOLAK. Denda kembali ke nominal kalkulasi: Rp '.
            number_format($keringanTazir->nominal_tazir_hitung, 0, ',', '.'));
    }

    // ── TELLER: riwayat pengajuan keringanan (alur baru: tazir_relief_requests) ──
    //
    // CATATAN: Pengajuan keringanan TIDAK lagi dibuat dari halaman ini.
    // Pengajuan dilakukan dari form "Bayar Angsuran" (checkbox Ajukan Keringanan),
    // sehingga Pokok & Margin tetap dibayar dan komponen Ta'zir ditangguhkan.
    // Halaman ini hanya menampilkan status pengajuan teller (AO -> Manajer).
    public function riwayatTeller(Request $request)
    {
        $status = $request->status;

        $query = TazirReliefRequest::with(['angsuran', 'pembiayaan.anggota', 'anggota', 'ao', 'manajer'])
            ->where('teller_id', Auth::id())
            ->latest();

        if ($status && in_array($status, [
            TazirReliefRequest::STATUS_PENDING_AO,
            TazirReliefRequest::STATUS_PENDING_MANAJER,
            TazirReliefRequest::STATUS_APPROVED,
            TazirReliefRequest::STATUS_REJECTED,
        ])) {
            $query->where('status', $status);
        }

        $keringanans = $query->paginate(20)->withQueryString();
        $totalPending = TazirReliefRequest::where('teller_id', Auth::id())
            ->whereIn('status', [
                TazirReliefRequest::STATUS_PENDING_AO,
                TazirReliefRequest::STATUS_PENDING_MANAJER,
            ])->count();
        $totalApproved = TazirReliefRequest::where('teller_id', Auth::id())
            ->where('status', TazirReliefRequest::STATUS_APPROVED)->count();
        $totalRejected = TazirReliefRequest::where('teller_id', Auth::id())
            ->where('status', TazirReliefRequest::STATUS_REJECTED)->count();

        return view('teller.keringanan-tazir', compact(
            'keringanans', 'totalPending', 'totalApproved', 'totalRejected', 'status'
        ));
    }
}
