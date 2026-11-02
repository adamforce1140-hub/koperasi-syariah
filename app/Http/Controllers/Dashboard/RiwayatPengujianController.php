<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TestRun;
use App\Services\TestRunnerService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Riwayat Pengujian — dasbor Pengurus.
 *
 * Menyediakan verifikasi kualitas sistem TANPA keluar dari aplikasi:
 * Pengurus memilih tanggal pengujian & modul, menekan "Run Test", lalu
 * {@see TestRunnerService} menjalankan PHPUnit sebagai subproses dan hasilnya
 * ditampilkan sebagai tabel di halaman ini sekaligus tersimpan sebagai riwayat.
 *
 * Akses dibatasi peran `pengurus` melalui middleware pada definisi rute.
 */
class RiwayatPengujianController extends Controller
{
    public function __construct(private readonly TestRunnerService $runner) {}

    /**
     * Halaman utama: formulir (tanggal + modul + target) dan tabel riwayat.
     */
    public function index(Request $request)
    {
        $riwayat = TestRun::query()
            ->with('pelaksana:id,name')
            ->latest('id')
            ->paginate(20);

        // Statistik ringkas untuk kartu di atas tabel.
        $ringkasan = [
            'total' => TestRun::count(),
            'lulus' => TestRun::where('status', TestRun::STATUS_PASSED)->count(),
            'gagal' => TestRun::whereIn('status', [TestRun::STATUS_FAILED, TestRun::STATUS_ERROR])->count(),
            'terakhir' => TestRun::latest('id')->first(),
        ];

        return view('pengurus.riwayat-pengujian', [
            'riwayat' => $riwayat,
            'ringkasan' => $ringkasan,
            'modul' => TestRunnerService::MODUL,
        ]);
    }

    /**
     * Jalankan pengujian dan simpan hasilnya ke riwayat.
     *
     * Masukan divalidasi ketat; seluruh keamanan perintah dijaga di
     * {@see TestRunnerService} (argumen sebagai array, tanpa shell).
     */
    public function jalankan(Request $request)
    {
        $data = $request->validate([
            'modul' => 'required|string|in:'.implode(',', array_keys(TestRunnerService::MODUL)),
            'tanggal_uji' => 'required|date',
            'target_uji' => 'nullable|string|max:255',
        ], [
            'modul.required' => 'Pilih modul yang akan diuji.',
            'modul.in' => 'Modul yang dipilih tidak dikenali.',
            'tanggal_uji.required' => 'Tanggal pengujian wajib diisi.',
            'tanggal_uji.date' => 'Format tanggal pengujian tidak valid.',
        ]);

        try {
            $run = $this->runner->jalankan(
                $data['modul'],
                $request->user(),
                $data['tanggal_uji'] ?? null,
                $data['target_uji'] ?? null,
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('pengurus.riwayat-pengujian.index')
                ->with('error', 'Pengujian gagal dijalankan: '.$e->getMessage());
        }

        $pesan = $run->berhasil()
            ? "Pengujian modul \"{$run->modul}\" selesai: {$run->lulus} lulus, {$run->gagal} gagal, {$run->dilewati} dilewati."
            : "Pengujian modul \"{$run->modul}\" selesai dengan status {$run->labelStatus()} — periksa detail di bawah.";

        return redirect()
            ->route('pengurus.riwayat-pengujian.index')
            ->with($run->berhasil() ? 'success' : 'error', $pesan);
    }

    /**
     * Hapus satu entri riwayat (tidak menghapus berkas apa pun).
     */
    public function hapus(int $id)
    {
        $run = TestRun::findOrFail($id);
        $run->delete();

        return redirect()
            ->route('pengurus.riwayat-pengujian.index')
            ->with('success', 'Entri riwayat pengujian berhasil dihapus.');
    }
}
