<?php

namespace App\Http\Controllers\AO;

use App\Http\Controllers\Controller;
use App\Models\TazirReliefRequest;
use App\Support\PemisahanTugas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AO — Verifikasi & Rekomendasi Lapangan atas pengajuan Keringanan Ta'zir.
 *
 * Alur: TELLER (PENDING_AO) -> AO (verifikasi) -> status PENDING_MANAJER.
 *
 * PEMISAHAN TUGAS (maker ≠ checker) pada transisi status (batch 14):
 * pihak yang MENGAJUKAN keringanan tidak boleh menjadi AO yang
 * memverifikasinya sendiri. Aturan ini sudah berlaku pada jalur setara
 * (`DepositoPenaltyReliefController::rekomendasi()` →
 * `PemisahanTugas::dilanggar($relief->pengaju_id, ...)`), sehingga jalur
 * ta'zir wajib mengikuti aturan yang sama.
 *
 * Catatan: pembatasan peran ke AO sudah ditegakkan lapisan izin
 * (`izin:ao.pembiayaan` → `['ao']` pada config/izin.php, CekIzin → 403),
 * sehingga controller tidak perlu gerbang hierarki tambahan.
 */
class KeringananTazirController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->status ?? TazirReliefRequest::STATUS_PENDING_AO;
        $search = $request->search;

        $query = TazirReliefRequest::with([
            'angsuran',
            'pembiayaan.anggota',
            'anggota',
            'teller',
            'ao',
        ])->latest();

        if ($status && in_array($status, [
            TazirReliefRequest::STATUS_PENDING_AO,
            TazirReliefRequest::STATUS_PENDING_MANAJER,
            TazirReliefRequest::STATUS_APPROVED,
            TazirReliefRequest::STATUS_REJECTED,
        ])) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('anggota', fn ($x) => $x->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%")
                )
                    ->orWhereHas('pembiayaan', fn ($x) => $x->where('no_pembiayaan', 'like', "%{$search}%")
                    );
            });
        }

        $pengajuan = $query->paginate(20)->withQueryString();

        $totalPendingAo = TazirReliefRequest::pendingAo()->count();
        $totalPendingManajer = TazirReliefRequest::pendingManajer()->count();
        $totalApproved = TazirReliefRequest::where('status', TazirReliefRequest::STATUS_APPROVED)->count();
        $totalRejected = TazirReliefRequest::where('status', TazirReliefRequest::STATUS_REJECTED)->count();

        return view('ao.keringanan_tazir.index', compact(
            'pengajuan', 'totalPendingAo', 'totalPendingManajer',
            'totalApproved', 'totalRejected', 'status', 'search'
        ));
    }

    public function show(TazirReliefRequest $relief)
    {
        $relief->load(['angsuran', 'pembiayaan.anggota', 'anggota', 'teller', 'ao', 'manajer']);

        return view('ao.keringanan_tazir.show', compact('relief'));
    }

    public function rekomendasi(Request $request, TazirReliefRequest $relief)
    {
        if (! $relief->isPendingAo()) {
            return redirect()->back()->with('error', 'Pengajuan ini sudah diverifikasi sebelumnya.');
        }

        $request->validate([
            'rekomendasi_ao' => 'required|in:SETUJU,TOLAK',
            'catatan_ao' => 'required|string|max:1000',
        ], [
            'rekomendasi_ao.required' => 'Rekomendasi AO wajib dipilih.',
            'rekomendasi_ao.in' => 'Rekomendasi AO tidak valid.',
            'catatan_ao.required' => 'Catatan Analisis AO wajib diisi.',
        ]);

        /*
         | GCG — pemisahan tugas (maker ≠ checker): pihak yang MENGAJUKAN
         | keringanan tidak boleh menjadi AO yang memverifikasinya sendiri.
         | Aturan ini sudah berlaku pada jalur setara
         | (`DepositoPenaltyReliefController::rekomendasi()`), sehingga jalur
         | ta'zir wajib mengikuti aturan yang sama.
         */
        if (PemisahanTugas::dilanggar($relief->teller_id, Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($relief, $request) {
            $relief->update([
                'rekomendasi_ao' => $request->rekomendasi_ao,
                'catatan_ao' => $request->catatan_ao,
                'ao_id' => Auth::id(),
                'status' => TazirReliefRequest::STATUS_PENDING_MANAJER,
            ]);
        });

        $pesan = $request->rekomendasi_ao === TazirReliefRequest::REKOMENDASI_SETUJU
            ? 'Rekomendasi BEBAS (Rp 0) terkirim ke Manajer untuk keputusan akhir.'
            : 'Rekomendasi TOLAK (anggota tetap bayar denda) terkirim ke Manajer untuk keputusan akhir.';

        return redirect()->route('ao.keringanan-tazir.index')->with('success', $pesan);
    }
}
