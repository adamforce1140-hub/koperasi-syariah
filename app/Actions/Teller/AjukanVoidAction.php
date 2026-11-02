<?php

namespace App\Actions\Teller;

use App\Models\ApprovalTransaksi;
use App\Models\Transaksi;
use App\Support\HierarkiRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Use-case Permintaan Void transaksi oleh teller.
 *
 * Transaksi tidak langsung dibatalkan: status diubah menjadi "Pending Void"
 * dan persetujuan Manajer diajukan lewat tabel `approval_transaksi`.
 * Tahap menunggu diturunkan dari hierarki peran agar tidak ada literal
 * status yang harus dicari saat tingkat otorisasi berubah.
 */
class AjukanVoidAction
{
    public function execute(Request $request, Transaksi $transaksi): JsonResponse
    {
        // Pastikan transaksi belum di-void
        if (in_array($transaksi->status_transaksi, [Transaksi::STATUS_TRANSAKSI_PENDING_VOID, Transaksi::STATUS_TRANSAKSI_VOIDED], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaksi sudah dalam proses void atau sudah di-void.',
            ], 422);
        }

        // Hanya bisa void transaksi hari ini
        if ($transaksi->waktu_transaksi->toDateString() !== today()->toDateString()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Void hanya dapat dilakukan untuk transaksi hari ini.',
            ], 422);
        }

        $transaksi->update(['status_transaksi' => Transaksi::STATUS_TRANSAKSI_PENDING_VOID]);

        // Buat approval request untuk manajer — sesuai skema tabel approval_transaksi.
        ApprovalTransaksi::create([
            'kode_approval' => ApprovalTransaksi::generateKode(),
            'tipe_referensi' => 'transaksi',
            'referensi_id' => $transaksi->id,
            'jenis_transaksi' => $transaksi->jenis_transaksi,
            'nominal' => $transaksi->jumlah,
            'jenis_pembayaran' => 'tunai',
            'status' => HierarkiRole::statusMenunggu('manajer'),
            'diajukan_oleh' => Auth::id(),
            'role_pengaju' => 'teller',
            'catatan_pengaju' => $request->alasan ?? 'Permintaan void dari teller',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Permintaan void berhasil dikirim ke manajer untuk otorisasi.',
        ]);
    }
}
