<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ApprovalTransaksi;
use App\Models\PengeluaranBeban;
use App\Models\Transaksi;
use App\Support\HierarkiRole;
use App\Support\RentangPeriode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TellerDashboardController extends Controller
{
    public function index()
    {
        return view('dashboard.teller', $this->data());
    }

    /**
     * Ringkasan parsial untuk sinkronisasi real-time.
     *
     * Dipanggil oleh mesin `Denyut` (lihat resources/views/partials/denyut.blade.php)
     * HANYA ketika salah satu domain yang dipantau berubah, jadi biaya kuerinya
     * tidak dibayar pada setiap detak. Hanya dua potongan yang dikirim — kartu
     * statistik dan tabel transaksi terakhir — supaya peramban tidak perlu
     * merender ulang seluruh halaman dan tidak ada kedipan.
     */
    public function ringkas(Request $request): JsonResponse
    {
        $data = $this->data();

        return response()->json([
            'html' => [
                'stats' => view('dashboard.partials.teller-stats', $data)->render(),
                'transaksi' => view('dashboard.partials.teller-transaksi', $data)->render(),
            ],
            'waktu' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, must-revalidate');
    }

    /**
     * Satu sumber data untuk render penuh maupun render parsial — mencegah
     * angka pada potongan ringkas menyimpang dari angka halaman penuh.
     *
     * @return array<string,mixed>
     */
    private function data(): array
    {
        $user = Auth::user();

        // Ringkasan hari ini — disamakan dengan halaman /teller/riwayat agar angka
        // dashboard konsisten dengan Daftar Transaksi (mencakup semua teller).
        // Hanya status Sukses yang dihitung untuk nilai uang, karena transaksi
        // Gagal/Ditolak tidak mengubah posisi kas.
        //
        // Rentang eksplisit (sargable) — whereDate() membungkus kolom dengan
        // DATE() sehingga indeks waktu_transaksi tidak terpakai. Pada dasbor yang
        // kuerinya dipanggil ulang setiap ada perubahan data, biaya full table
        // scan itu terasa jauh lebih besar daripada di laporan bulanan.
        // SENGAJA hanya 'Setoran Tunai' — definisi "Setoran" yang sama dengan
        // halaman Closing ("Total Setoran (Cash In)"), Riwayat, dan Rekap:
        // setoran tunai murni. Setoran Simpanan Pokok/Wajib/Sukarela adalah
        // pos TERPISAH (dilaporkan sebagai "Simpanan Pokok"/"Simpanan Wajib"),
        // BUKAN bagian dari "Setoran" — lihat JenisTransaksi::pokok()/wajib().
        $totalSetorHariIni = RentangPeriode::padaHari(
            Transaksi::query()->where('jenis_transaksi', 'Setoran Tunai')->where('status_transaksi', 'Sukses'),
            'waktu_transaksi'
        )->sum('jumlah');

        $totalTarikHariIni = RentangPeriode::padaHari(
            Transaksi::query()->where('jenis_transaksi', 'Penarikan Tunai')->where('status_transaksi', 'Sukses'),
            'waktu_transaksi'
        )->sum('jumlah');

        // Jumlah seluruh transaksi hari ini (semua status) — sama dengan kartu
        // "Total Transaksi" di halaman riwayat.
        $jumlahTransaksiHariIni = RentangPeriode::padaHari(
            Transaksi::query(),
            'waktu_transaksi'
        )->count();

        // Approval pending dari transaksi teller ini.
        // Daftar status menunggu dibaca dari hierarki peran sehingga setiap
        // tingkat otorisasi baru ikut terhitung tanpa perubahan di sini.
        $pendingApproval = ApprovalTransaksi::where('diajukan_oleh', $user->id)
            ->whereIn('status', HierarkiRole::semuaStatusMenunggu())
            ->count();

        // 10 transaksi terakhir — sama seperti Daftar Transaksi di /teller/riwayat
        // (mencakup semua teller). Tiebreaker id dipakai agar transaksi dengan
        // waktu identik selalu tampil dalam urutan yang sama di kedua halaman.
        $transaksiTerakhir = Transaksi::with(['rekening.anggota', 'anggota', 'pembiayaan', 'angsuran.pembiayaan'])
            ->orderByDesc('waktu_transaksi')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // Pengeluaran beban yang diinput teller ini (5 terakhir)
        $bebanTerakhir = PengeluaranBeban::where('created_by', $user->id)
            ->with(['coaBeban', 'coaKas'])
            ->latest()
            ->limit(5)
            ->get();

        return compact(
            'totalSetorHariIni', 'totalTarikHariIni',
            'jumlahTransaksiHariIni', 'pendingApproval', 'transaksiTerakhir', 'bebanTerakhir'
        );
    }
}
