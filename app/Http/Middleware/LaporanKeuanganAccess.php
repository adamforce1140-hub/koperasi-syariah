<?php

namespace App\Http\Middleware;

use App\Models\LaporanKeuanganLog;
use App\Support\LaporanKeuanganPermission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gerbang RBAC + pencatatan audit untuk modul Laporan Keuangan.
 *
 * Middleware ini adalah otoritas tunggal yang memutuskan boleh/tidaknya akses,
 * sekaligus menulis jejak auditnya. Karena itu ia WAJIB dipasang SEBELUM
 * middleware `role:` pada definisi rute — kalau `role:` berjalan lebih dulu,
 * ia akan memblokir dengan 403 sebelum kesempatan mencatat percobaan akses
 * tidak sah, sehingga upaya tersebut lolos dari audit.
 *
 * Pemakaian pada rute:
 *
 *     Route::get('/laporan/neraca', [Controller::class, 'neraca'])
 *         ->middleware(['laporan.akses:neraca', 'role:manajer,pengurus,dps']);
 *
 * Parameter pertama = nama modul. Aksi yang diminta ditentukan otomatis:
 * permintaan ekspor (`?export=...`) dinilai sebagai hak `ekspor`, bukan `lihat`
 * — sehingga peran yang hanya boleh melihat tidak bisa mengekspor data.
 */
class LaporanKeuanganAccess
{
    public function handle(Request $request, Closure $next, string $modul, string $aksiDefault = LaporanKeuanganPermission::LIHAT): Response
    {
        $user = auth()->user();
        $peran = $user?->role;

        // Belum terautentikasi — serahkan ke middleware 'auth' berikutnya.
        if (! $user) {
            return $next($request);
        }

        $aksi = $this->tentukanAksi($request, $aksiDefault);

        // ── Tolak & catat ──
        if (! LaporanKeuanganPermission::boleh($peran, $modul, $aksi)) {
            $this->catat($request, $modul, $aksi, false, 403, [
                'alasan' => 'Peran tidak memiliki hak akses.',
            ]);

            abort(403, 'Akses ditolak. Peran Anda tidak memiliki wewenang untuk '
                .$aksi.' pada laporan '.LaporanKeuanganPermission::labelModul($modul).'.');
        }

        // ── Izinkan, lalu catat hasilnya ──
        $response = $next($request);

        $this->catat($request, $modul, $aksi, $response->getStatusCode() < 400, $response->getStatusCode());

        return $response;
    }

    /**
     * Tentukan aksi yang sebenarnya diminta.
     *
     * Ekspor dan unduh tidak punya rute sendiri (mis. Neraca diekspor lewat
     * `?export=excel` pada rute yang sama), jadi aksi harus dibaca dari
     * permintaan. Tanpa ini, hak `ekspor` tidak akan pernah benar-benar
     * diperiksa dan setiap peran yang boleh `lihat` otomatis boleh ekspor.
     */
    private function tentukanAksi(Request $request, string $aksiDefault): string
    {
        if ($request->filled('export') || $request->filled('unduh')) {
            return LaporanKeuanganPermission::EKSPOR;
        }

        $namaRute = (string) $request->route()?->getName();

        if (str_contains($namaRute, 'export') || str_contains($namaRute, 'ekspor')) {
            return LaporanKeuanganPermission::EKSPOR;
        }

        if (str_contains($namaRute, 'download') || str_contains($namaRute, 'unduh')) {
            return LaporanKeuanganPermission::UNDUH;
        }

        // Operasi tulis dinilai dari metode HTTP-nya. Ini yang menutup celah
        // "route tulis baru lupa dipetakan": begitu modul laporan keuangan punya
        // endpoint simpan/ubah/hapus, ia otomatis menuntut hak edit/hapus
        // sesuai matriks — tidak perlu ada route baru yang didaftarkan manual.
        if ($request->isMethod('DELETE')) {
            return LaporanKeuanganPermission::HAPUS;
        }

        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            return LaporanKeuanganPermission::EDIT;
        }

        return $aksiDefault;
    }

    /**
     * Tulis satu baris jejak audit.
     *
     * Kegagalan menulis log sengaja tidak menghentikan permintaan: laporan
     * keuangan harus tetap bisa dibuka walau tabel log bermasalah. Kesalahan
     * tetap dicatat ke log aplikasi agar tidak hilang diam-diam, dan kelengkapan
     * penulisan log diverifikasi lewat pengujian.
     *
     * @param  array<string, mixed>  $perubahanData
     */
    private function catat(
        Request $request,
        string $modul,
        string $aksi,
        bool $berhasil,
        ?int $statusHttp,
        array $perubahanData = []
    ): void {
        try {
            $user = auth()->user();

            // Parameter permintaan disimpan sebagai konteks: inilah "data yang
            // dihasilkan" pada operasi baca — mis. periode & akun yang dipilih.
            $parameter = collect($request->query())
                ->except(['_token'])
                ->all();

            LaporanKeuanganLog::catat([
                'user_id' => $user?->id,
                'user_nama' => $user?->name,
                'role' => $user?->role,
                'modul' => $modul,
                'aktivitas' => $aksi,
                'deskripsi' => $this->susunDeskripsi($modul, $aksi, $berhasil, $request),
                'route' => $request->route()?->getName(),
                'metode' => $request->method(),
                'url' => mb_substr($request->fullUrl(), 0, 500),
                'parameter' => $parameter ?: null,
                'perubahan_data' => $perubahanData ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'status_http' => $statusHttp,
                'berhasil' => $berhasil,
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal menulis log audit Laporan Keuangan.', [
                'modul' => $modul,
                'aktivitas' => $aksi,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Kalimat manusiawi untuk kolom deskripsi di laporan audit. */
    private function susunDeskripsi(string $modul, string $aksi, bool $berhasil, Request $request): string
    {
        $labelModul = LaporanKeuanganPermission::labelModul($modul);
        $labelAksi = LaporanKeuanganLog::AKTIVITAS[$aksi] ?? ucfirst($aksi);

        $kalimat = $berhasil
            ? "{$labelAksi} laporan {$labelModul}"
            : "Percobaan {$labelAksi} laporan {$labelModul} DITOLAK";

        $rincian = [];
        if ($request->filled('tanggal')) {
            $rincian[] = 'tanggal: '.$request->input('tanggal');
        }
        if ($request->filled('mode')) {
            $rincian[] = 'mode: '.$request->input('mode');
        }
        if ($request->filled('kode_akun')) {
            $rincian[] = 'akun: '.$request->input('kode_akun');
        }
        if ($request->filled('bulan')) {
            $rincian[] = 'bulan: '.$request->input('bulan');
        }
        if ($request->filled('tahun')) {
            $rincian[] = 'tahun: '.$request->input('tahun');
        }
        if ($request->filled('export')) {
            $rincian[] = 'format: '.$request->input('export');
        }

        return $rincian ? $kalimat.' ('.implode(', ', $rincian).')' : $kalimat;
    }
}
