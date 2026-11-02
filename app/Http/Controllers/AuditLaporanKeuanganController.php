<?php

namespace App\Http\Controllers;

use App\Models\LaporanKeuanganLog;
use App\Support\HierarkiRole;
use App\Support\LaporanKeuanganPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan audit terintegrasi modul Laporan Keuangan.
 *
 * Menampilkan riwayat seluruh aktivitas (lihat/unduh/edit/hapus/ekspor) beserta
 * percobaan akses yang ditolak, lengkap dengan identitas pengguna, peran yang
 * digunakan, timestamp, dan perubahan data yang dihasilkan.
 *
 * Halaman ini juga menjadi tempat verifikasi integritas rantai hash dan ekspor
 * log untuk keperluan audit internal maupun eksternal.
 */
class AuditLaporanKeuanganController extends Controller
{
    /** Nama modul yang dianggap sebagai modul audit itu sendiri. */
    private const MODUL = LaporanKeuanganPermission::MODUL_AUDIT;

    // ─────────────────────────────────────────────────────────────────────
    // Halaman utama: riwayat aktivitas + filter
    // ─────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $filter = $this->ambilFilter($request);
        $query = $this->queryTerfilter($filter);

        $perHalaman = (int) $request->input('per_halaman', 50);
        $perHalaman = in_array($perHalaman, [25, 50, 100, 200], true) ? $perHalaman : 50;

        $logs = $query->paginate($perHalaman)->withQueryString();

        // Ringkasan cepat untuk header halaman
        $ringkasan = [
            'total' => LaporanKeuanganLog::count(),
            'ditolak' => LaporanKeuanganLog::where('berhasil', false)->count(),
            'hari_ini' => LaporanKeuanganLog::whereDate('created_at', now()->toDateString())->count(),
        ];

        $payloadMatriks = $this->payloadMatriks();

        return view('laporan.audit', [
            'logs' => $logs,
            'filter' => $filter,
            'perHalaman' => $perHalaman,
            'ringkasan' => $ringkasan,
            'matriks' => LaporanKeuanganPermission::matriksLengkap(),
            'labelPeran' => LaporanKeuanganPermission::LABEL_PERAN,
            'labelModul' => LaporanKeuanganPermission::LABEL_MODUL,
            'labelAktivitas' => LaporanKeuanganLog::AKTIVITAS,
            'warnaAktivitas' => LaporanKeuanganLog::WARNA_AKTIVITAS,
            'signatureMatriks' => $payloadMatriks['tanda'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Matriks hak akses (JSON) — sumber data refresh dinamis
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Matriks hak akses dalam bentuk JSON untuk penyegaran tabel tanpa reload.
     *
     * Datanya dibaca dari LaporanKeuanganPermission — sumber kebenaran yang sama
     * dengan yang dipakai middleware LaporanKeuanganAccess pada setiap permintaan.
     * Respons menyertakan `tanda` (signature) agar sisi klien hanya menyentuh DOM
     * ketika konfigurasi permission benar-benar berubah.
     */
    public function matriks(): JsonResponse
    {
        return response()
            ->json($this->payloadMatriks())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Susunan data matriks hak akses untuk konsumsi Blade maupun JSON.
     *
     * Datanya dibaca langsung dari LaporanKeuanganPermission — sumber kebenaran
     * yang sama dengan yang dipakai middleware LaporanKeuanganAccess pada setiap
     * permintaan. `tanda` adalah sidik jari konfigurasi, sehingga sisi klien hanya
     * perlu menyentuh DOM ketika permission benar-benar berubah.
     *
     * @return array<string, mixed>
     */
    private function payloadMatriks(): array
    {
        $matriks = LaporanKeuanganPermission::matriksLengkap();
        $modul = LaporanKeuanganPermission::MODUL;

        // Urutan hak mengikuti AKTIVITAS agar label & urutan badge konsisten.
        $isi = [];
        foreach (array_keys(LaporanKeuanganPermission::LABEL_PERAN) as $peran) {
            foreach ($modul as $modulKode) {
                $isi[$peran][$modulKode] = array_values(array_intersect(
                    array_keys(LaporanKeuanganLog::AKTIVITAS),
                    $matriks[$peran][$modulKode] ?? []
                ));
            }
        }

        return [
            'peran' => LaporanKeuanganPermission::LABEL_PERAN,
            'modul' => $modul,
            'labelModul' => array_intersect_key(
                LaporanKeuanganPermission::LABEL_MODUL,
                array_flip($modul)
            ),
            'labelAktivitas' => LaporanKeuanganLog::AKTIVITAS,
            'matriks' => $isi,
            'tanda' => hash('sha256', json_encode([
                LaporanKeuanganPermission::LABEL_PERAN, $modul, $isi,
            ])),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verifikasi integritas rantai hash
    // ─────────────────────────────────────────────────────────────────────

    public function verifikasiIntegritas()
    {
        $hasil = LaporanKeuanganLog::verifyChain();

        // Jejak verifikasi itu sendiri juga dicatat — memeriksa integritas log
        // adalah aktivitas audit yang perlu diketahui oleh auditor lain.
        LaporanKeuanganLog::catat([
            'user_id' => auth()->id(),
            'user_nama' => auth()->user()?->name,
            'role' => auth()->user()?->role,
            'modul' => self::MODUL,
            'aktivitas' => LaporanKeuanganLog::AKTIVITAS_VERIFIKASI,
            'deskripsi' => 'Verifikasi integritas rantai hash: '
                             .($hasil['utuh'] ? 'UTUH' : 'RUSAK pada baris #'.$hasil['rusak_di'])
                             .' ('.$hasil['diperiksa'].' baris diperiksa)',
            'route' => request()->route()?->getName(),
            'metode' => request()->method(),
            'url' => mb_substr(request()->fullUrl(), 0, 500),
            'perubahan_data' => $hasil,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'status_http' => 200,
            'berhasil' => $hasil['utuh'],
        ]);

        return response()->json($hasil);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ekspor log (CSV) — untuk audit internal & eksternal
    // ─────────────────────────────────────────────────────────────────────

    public function ekspor(Request $request): StreamedResponse|RedirectResponse
    {
        $filter = $this->ambilFilter($request);

        // Batas volume ekspor dibaca dari SSOT peran (config/role_hierarki.php),
        // bukan angka tetap. Sebelumnya baris dipotong di angka 20.000 untuk
        // SEMUA peran: DPS — satu-satunya pemegang izin modul ini — berhak
        // 50.000 baris, sehingga sisa 30.000 baris hilang tanpa pemberitahuan
        // dan berkas audit tampak lengkap padahal tidak. `ambilDenganBatas()`
        // mengambil batas + 1 baris agar pemanggil tahu permintaan MELAMPAUI
        // batas dan dapat menolaknya secara terang-terangan.
        $hasil = HierarkiRole::ambilDenganBatas(
            $this->queryTerfilter($filter),
            $request->user()?->role
        );

        if ($hasil['melampaui']) {
            return redirect()->back()->with(
                'error',
                'Volume data melebihi batas akses peran Anda (maksimal '
                .number_format((int) $hasil['batas'], 0, ',', '.')
                .' baris). Persempit rentang tanggal atau filter lainnya.'
            );
        }

        $logs = $hasil['data'];

        $namaBerkas = 'Audit-Laporan-Keuangan-'.now()->format('Ymd-His').'.csv';

        // Ekspor itu sendiri adalah aktivitas yang wajib tercatat.
        LaporanKeuanganLog::catat([
            'user_id' => auth()->id(),
            'user_nama' => auth()->user()?->name,
            'role' => auth()->user()?->role,
            'modul' => self::MODUL,
            'aktivitas' => LaporanKeuanganPermission::EKSPOR,
            'deskripsi' => 'Ekspor log audit Laporan Keuangan ('.$logs->count().' baris)',
            'route' => $request->route()?->getName(),
            'metode' => $request->method(),
            'url' => mb_substr($request->fullUrl(), 0, 500),
            'parameter' => array_filter($filter, fn ($v) => $v !== null && $v !== ''),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'status_http' => 200,
            'berhasil' => true,
        ]);

        $labelAktivitas = LaporanKeuanganLog::AKTIVITAS;
        $labelPeran = LaporanKeuanganPermission::LABEL_PERAN;
        $labelModul = LaporanKeuanganPermission::LABEL_MODUL;

        return response()->stream(function () use ($logs, $labelAktivitas, $labelPeran, $labelModul) {
            $keluaran = fopen('php://output', 'w');

            // BOM UTF-8 supaya Excel membaca aksara dengan benar
            fwrite($keluaran, "\xEF\xBB\xBF");

            fputcsv($keluaran, [
                'ID', 'Waktu', 'Pengguna', 'Peran', 'Modul', 'Aktivitas',
                'Deskripsi', 'Metode', 'Rute', 'Status HTTP', 'Berhasil',
                'Perubahan Data', 'IP', 'Hash',
            ], ';');

            foreach ($logs as $log) {
                fputcsv($keluaran, [
                    $log->id,
                    $log->created_at?->format('Y-m-d H:i:s'),
                    $log->user_nama ?? '(anonim)',
                    $labelPeran[$log->role] ?? $log->role,
                    $labelModul[$log->modul] ?? $log->modul,
                    $labelAktivitas[$log->aktivitas] ?? $log->aktivitas,
                    $log->deskripsi,
                    $log->metode,
                    $log->route,
                    $log->status_http,
                    $log->berhasil ? 'Ya' : 'Tidak',
                    $log->perubahan_data ? json_encode($log->perubahan_data, JSON_UNESCAPED_UNICODE) : '',
                    $log->ip_address,
                    $log->hash,
                ], ';');
            }

            fclose($keluaran);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$namaBerkas.'"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pembantu
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Menyusun dan menyaring daftar log.
     *
     * @param  array<string, mixed>  $filter
     */
    private function queryTerfilter(array $filter)
    {
        return LaporanKeuanganLog::query()
            ->when($filter['peran'], fn ($q, $v) => $q->where('role', $v))
            ->when($filter['modul'], fn ($q, $v) => $q->where('modul', $v))
            ->when($filter['aktivitas'], fn ($q, $v) => $q->where('aktivitas', $v))
            ->when($filter['dari'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filter['sampai'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filter['status'] === 'berhasil', fn ($q) => $q->where('berhasil', true))
            ->when($filter['status'] === 'ditolak', fn ($q) => $q->where('berhasil', false))
            ->when($filter['cari'], function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('user_nama', 'like', "%{$v}%")
                        ->orWhere('deskripsi', 'like', "%{$v}%")
                        ->orWhere('url', 'like', "%{$v}%")
                        ->orWhere('ip_address', 'like', "%{$v}%");
                });
            })
            ->orderByDesc('id');
    }

    /**
     * Mengambil parameter filter dari permintaan.
     *
     * @return array<string, mixed>
     */
    private function ambilFilter(Request $request): array
    {
        return [
            'peran' => $request->input('peran'),
            'modul' => $request->input('modul'),
            'aktivitas' => $request->input('aktivitas'),
            'status' => $request->input('status'),
            'dari' => $request->input('dari'),
            'sampai' => $request->input('sampai'),
            'cari' => $request->input('cari'),
        ];
    }
}
