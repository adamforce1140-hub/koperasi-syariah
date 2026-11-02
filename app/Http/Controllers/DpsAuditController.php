<?php

namespace App\Http\Controllers;

use App\Models\Pembiayaan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DpsAuditController extends Controller
{
    /**
     * Daftar pengajuan berstatus 'pending_dps' — antrian pengesahan akad DPS.
     */
    public function index(Request $request)
    {
        $query = Pembiayaan::with(['anggota', 'disahkanOleh'])
            ->statusIn([Pembiayaan::STATUS_PENDING_DPS])
            ->orderBy('updated_at', 'asc');

        if ($request->filled('search')) {
            $cari = $request->search;
            $query->where(function ($q) use ($cari) {
                $q->where('no_pembiayaan', 'like', "%{$cari}%")
                    ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$cari}%")
                        ->orWhere('no_anggota', 'like', "%{$cari}%"));
            });
        }

        $pembiayaan = $query->paginate(15)->withQueryString();

        return view('dps.pengesahan-akad', compact('pembiayaan'));
    }

    /**
     * Kembalikan detail pengajuan sebagai JSON (untuk AJAX modal).
     */
    public function getDetail(int $id)
    {
        $pb = Pembiayaan::with(['anggota', 'reviewedBy', 'approvedFinalBy'])
            ->statusIn([Pembiayaan::STATUS_PENDING_DPS])
            ->findOrFail($id);

        return response()->json([
            'id' => $pb->id,
            'no_pembiayaan' => $pb->no_pembiayaan,
            'akad_pembiayaan' => $pb->akad_pembiayaan,
            'tujuan_pembiayaan' => $pb->tujuan_pembiayaan,
            'jumlah_pembiayaan' => $pb->jumlah_pembiayaan,
            'tenor' => $pb->tenor,
            'frekuensi_angsuran' => $pb->frekuensi_angsuran,
            'jumlah_angsuran' => $pb->jumlah_angsuran,
            'margin_nisbah' => $pb->margin_nisbah,
            'agunan' => $pb->agunan,
            'keterangan' => $pb->keterangan,
            'catatan_manajer' => $pb->catatan_manajer,
            'catatan_pengurus' => $pb->catatan_pengurus,
            'anggota' => [
                'nama_lengkap' => $pb->anggota?->nama_lengkap,
                'no_anggota' => $pb->anggota?->no_anggota,
                'no_hp' => $pb->anggota?->no_hp,
                'alamat' => $pb->anggota?->alamat,
            ],
            'direview_oleh_nama' => $pb->reviewedBy?->name,
            'disetujui_oleh_nama' => $pb->approvedFinalBy?->name,
        ]);
    }

    /**
     * DPS menyetujui & mengesahkan akad, sekaligus menetapkan tgl_pencairan.
     */
    public function approve(Request $request, int $id)
    {
        $request->validate([
            'tgl_pencairan' => [
                'required',
                'date',
            ],
            'catatan_dps' => 'nullable|string|max:1000',
        ], [
            'tgl_pencairan.required' => 'Tanggal rencana pencairan wajib diisi.',
            'tgl_pencairan.date' => 'Format tanggal tidak valid.',
        ]);

        DB::transaction(function () use ($request, $id) {
            $pb = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_DPS])
                ->lockForUpdate()
                ->findOrFail($id);

            $pb->update([
                'status_pembiayaan' => Pembiayaan::STATUS_DISETUJUI,
                'disahkan_oleh' => Auth::id(),
                'tanggal_disahkan' => now(),
                'catatan_dps' => $request->catatan_dps,
                'tgl_pencairan' => $request->tgl_pencairan,
            ]);
        });

        return redirect()->route('dps.pengesahan-akad')
            ->with('success', 'Akad berhasil disahkan. Pembiayaan siap dicairkan pada tanggal yang ditetapkan.');
    }

    /**
     * DPS menolak pengajuan.
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'catatan_dps' => 'required|string|max:1000',
        ], [
            'catatan_dps.required' => 'Alasan penolakan wajib diisi.',
        ]);

        DB::transaction(function () use ($request, $id) {
            $pb = Pembiayaan::statusIn([Pembiayaan::STATUS_PENDING_DPS])
                ->lockForUpdate()
                ->findOrFail($id);

            $pb->update([
                'status_pembiayaan' => Pembiayaan::STATUS_DITOLAK,
                'disahkan_oleh' => Auth::id(),
                'tanggal_disahkan' => now(),
                'catatan_dps' => $request->catatan_dps,
            ]);
        });

        return redirect()->route('dps.pengesahan-akad')
            ->with('error', 'Pengajuan pembiayaan telah ditolak oleh DPS.');
    }
}
