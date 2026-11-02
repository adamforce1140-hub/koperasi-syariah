<?php

namespace App\Http\Controllers;

use App\Models\AutodebitUsulan;
use App\Services\AutodebitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PUSAT KONFIRMASI AUTO-DEBIT.
 *
 * Halaman kerja tunggal tempat pengguna berwenang (manajer/super_admin/admin —
 * peran yang lolos `izin:autodebit.konfirmasi`) meninjau usulan pemotongan yang
 * dibuat oleh seluruh kanal (Simpanan Wajib, Angsuran, Ta'zir) dan memutuskan:
 * KONFIRMASI (potong) atau TOLAK.
 *
 * Ini adalah satu-satunya pintu tempat dana benar-benar dipotong. Selama usulan
 * berstatus MENUNGGU_KONFIRMASI, saldo anggota TIDAK tersentuh — memenuhi
 * kebijakan "tidak ada auto-debit tanpa konfirmasi pengguna".
 */
class AutodebitController extends Controller
{
    public function __construct(private AutodebitService $autodebitService) {}

    /** Daftar usulan auto-debit + ringkasan status. */
    public function index(Request $request)
    {
        $status = $request->status ?: AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI;

        $query = AutodebitUsulan::with(['anggota', 'rekening', 'confirmedBy'])->latest();

        if (in_array($status, [
            AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI,
            AutodebitUsulan::STATUS_DIKONFIRMASI,
            AutodebitUsulan::STATUS_DITOLAK,
            AutodebitUsulan::STATUS_GAGAL,
            AutodebitUsulan::STATUS_KEDALUWARSA,
        ], true)) {
            $query->where('status', $status);
        }

        $kanal = $request->kanal;

        if ($kanal && in_array($kanal, AutodebitUsulan::KANAL_LIST, true)) {
            $query->where('kanal', $kanal);
        }

        $usulan = $query->paginate(20)->withQueryString();

        $totalMenunggu = AutodebitUsulan::menungguKonfirmasi()->count();
        $totalHariIni = AutodebitUsulan::whereDate('created_at', today())->count();

        return view('autodebit.index', compact(
            'usulan', 'status', 'kanal', 'totalMenunggu', 'totalHariIni'
        ));
    }

    /** Konfirmasi usulan → eksekusi pemotongan lewat jalur resmi kanal. */
    public function konfirmasi(Request $request, AutodebitUsulan $usulan)
    {
        $request->validate([
            'catatan' => 'nullable|string|max:500',
        ]);

        $hasil = $this->autodebitService->konfirmasi($usulan, Auth::id(), $request->catatan);

        return redirect()->back()->with(
            ($hasil['ok'] ?? false) ? 'success' : 'error',
            $hasil['pesan'] ?? 'Proses konfirmasi selesai.'
        );
    }

    /** Tolak usulan → tidak ada pemotongan. */
    public function tolak(Request $request, AutodebitUsulan $usulan)
    {
        $request->validate([
            'catatan' => 'nullable|string|max:500',
        ]);

        $hasil = $this->autodebitService->tolak($usulan, Auth::id(), $request->catatan);

        return redirect()->back()->with(
            ($hasil['ok'] ?? false) ? 'success' : 'error',
            $hasil['pesan'] ?? 'Proses penolakan selesai.'
        );
    }
}
