<?php

namespace App\Http\Controllers;

use App\Services\LaporanKeuanganSyariahService;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Http\Request;

/**
 * Laporan Keuangan Syariah — penyajian lengkap PSAK 101.
 *
 * Satu halaman menampilkan kelima komponen laporan keuangan syariah beserta
 * Catatan atas Laporan Keuangan, seluruhnya dihitung dari satu service
 * (`LaporanKeuanganSyariahService`) agar antar-komponen dapat direkonsiliasi.
 *
 * Sebelumnya tidak ada halaman ini: Neraca berdiri sendiri di
 * `DanaCadanganController`, Laporan Laba Rugi hanya tampil sebagai satu baris
 * di dalam Neraca, dan tiga komponen lain tidak pernah ada. Akibatnya pemakainya
 * tidak pernah bisa memastikan angka Neraca, L/R, dan Arus Kas berbicara tentang
 * realitas yang sama.
 */
class LaporanKeuanganSyariahController extends Controller
{
    public function __construct(private LaporanKeuanganSyariahService $laporan) {}

    /**
     * Tampilkan paket laporan keuangan syariah untuk satu periode.
     */
    public function index(Request $request)
    {
        $periode = $this->laporan->periode(
            (string) $request->input('tanggal', ''),
            (string) $request->input('mode', '')
        );

        $peran = auth()->user()->role ?? null;

        $data = [
            'periode' => $periode,
            'komponen' => $this->laporan->semuaKomponen($periode),
            'mode' => $periode['mode'],
            'tanggalInput' => $periode['tanggalInput'],
            // Hak akses dibaca dari sumber kebenaran yang sama dengan middleware,
            // supaya tombol yang tampil tidak pernah lebih luas dari yang diizinkan.
            'peran' => $peran,
            'bolehLihat' => LaporanKeuanganPermission::boleh($peran, LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101, LaporanKeuanganPermission::LIHAT),
            'bolehUnduh' => LaporanKeuanganPermission::boleh($peran, LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101, LaporanKeuanganPermission::UNDUH),
            'bolehEkspor' => LaporanKeuanganPermission::boleh($peran, LaporanKeuanganPermission::MODUL_LAPORAN_PSAK101, LaporanKeuanganPermission::EKSPOR),
        ];

        // Ekspor Excel: satu berkas per periode, memuat seluruh komponen.
        // Pertahanan berlapis: middleware laporan.akses memang sudah memetakan
        // ?export= sebagai aksi EKSPOR, tetapi guard eksplisit di sini menjaga
        // bila suatu saat rute diubah/dipindah tanpa middleware tersebut.
        if ($request->input('export') === 'excel') {
            abort_unless($data['bolehEkspor'], 403, 'Anda tidak memiliki izin mengekspor laporan ini.');

            return response()->view('laporan.psak101-excel', $data, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="LaporanKeuanganSyariah-'.$periode['sampaiStr'].'.xls"',
            ]);
        }

        return view('laporan.psak101', $data);
    }
}
