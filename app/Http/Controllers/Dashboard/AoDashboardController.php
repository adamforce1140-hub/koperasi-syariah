<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AoDashboardController extends Controller
{
    public function index()
    {
        return view('dashboard.ao', $this->data());
    }

    /**
     * Potongan HTML untuk mesin denyut (real-time tanpa reload penuh).
     * Kunci `html` dipetakan ke `data-denyut-sasaran` pada view.
     */
    public function ringkas(): JsonResponse
    {
        $data = $this->data();

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.ao-stats', $data)->render(),
                'menunggu' => view('dashboard.partials.ao-menunggu', $data)->render(),
                'riwayat' => view('dashboard.partials.ao-riwayat', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /** Satu sumber data untuk index() dan ringkas(). */
    private function data(): array
    {
        $user = Auth::user();

        // Query pengajuan sesuai milik AO / diproses AO
        $queryPengajuan = fn () => PengajuanPembiayaan::where(function ($q) use ($user) {
            $q->where('ao_id', $user->id)
                ->orWhere('created_by', $user->id)
                ->orWhere('diproses_oleh', $user->id);
        });

        // Pengajuan pembiayaan menunggu review AO (hanya status awal yang belum disurvei)
        $pengajuanMenunggu = $queryPengajuan()
            ->statusIn([PengajuanPembiayaan::STATUS_MENUNGGU])
            ->whereNull('analisis_5c')
            ->with(['anggota', 'produk'])
            ->latest()
            ->limit(5)
            ->get();

        $totalMenunggu = $queryPengajuan()->statusIn([PengajuanPembiayaan::STATUS_MENUNGGU])->whereNull('analisis_5c')->count();
        $totalDisetujui = $queryPengajuan()->statusIn([PengajuanPembiayaan::STATUS_DISETUJUI])->count();
        $totalDitolak = $queryPengajuan()->statusIn([PengajuanPembiayaan::STATUS_DITOLAK])->count();

        // Pembiayaan berjalan milik binaan AO (aktif + dicairkan + restrukturisasi
        // + pending restrukturisasi) — ditautkan lewat pengajuan yang ia buat/proses.
        $pembiayaanBinaan = $queryPengajuan()
            ->whereNotNull('pembiayaan_id')
            ->pluck('pembiayaan_id')
            ->unique()
            ->values();

        $totalPembiayaanAktif = Pembiayaan::whereIn('id', $pembiayaanBinaan)
            ->statusIn([
                Pembiayaan::STATUS_DICAIRKAN,
                Pembiayaan::STATUS_AKTIF,
                Pembiayaan::STATUS_RESTRUKTURISASI,
                Pembiayaan::STATUS_PENDING_RESTRUKTURISASI,
            ])
            ->count();

        // 8 pengajuan terakhir semua status (riwayat kerja AO)
        $pengajuanTerakhir = $queryPengajuan()
            ->with(['anggota', 'produk'])
            ->latest()
            ->limit(8)
            ->get();

        return [
            'pengajuanMenunggu' => $pengajuanMenunggu,
            'totalMenunggu' => $totalMenunggu,
            'totalDisetujui' => $totalDisetujui,
            'totalDitolak' => $totalDitolak,
            'totalPembiayaanAktif' => $totalPembiayaanAktif,
            'pengajuanTerakhir' => $pengajuanTerakhir,
        ];
    }
}
