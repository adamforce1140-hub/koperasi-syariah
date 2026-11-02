<?php

namespace App\Http\Controllers\Pengurus;

use App\Http\Controllers\Controller;
use App\Models\ApprovalTransaksi;
use App\Models\Transaksi;
use App\Services\PpatkReportService;
use App\Services\ReversalService;
use App\Services\TransactionLimitService;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AmlComplianceController extends Controller
{
    public function __construct(
        private ReversalService $reversalService,
        private TransactionLimitService $limitService,
        private PpatkReportService $ppatkService,
    ) {}

    // Menampilkan Daftar Transaksi High Value
    public function index(Request $request)
    {
        $threshold = Setting::amlThreshold(); // default Rp 100 juta (regulasi PPATK)

        $query = Transaksi::with(['anggota', 'teller', 'rekening.anggota'])
            ->where(function ($q) use ($threshold) {
                $q->where('jumlah', '>=', $threshold)
                    ->orWhere('is_aml_high_value', true)
                    ->orWhere('is_large_transaction', true);
            });

        if ($request->filled('status_verifikasi')) {
            $query->where('aml_status_verifikasi', $request->status_verifikasi);
        }

        $transaksis = $query->latest()->paginate(15);

        // Statistik ringkasan — SATU kueri agregat bersyarat (sebelumnya 4
        // kueri COUNT terpisah, masing-masing memindai tabel `transaksi`).
        // Semantik tiap kolom dipertahankan persis sama dengan versi lama,
        // KECUALI `total_high_value`: predikatnya WAJIB sama dengan predikat
        // daftar di atas (+ `is_large_transaction`). Tanpa itu transaksi tunai
        // bernilai besar tampil di daftar tetapi tidak terhitung di kartu,
        // sehingga angka pengawasan yang dibaca Pengurus lebih kecil daripada
        // daftar yang sedang ia lihat.
        $statistik = Transaksi::selectRaw("
                SUM(CASE WHEN jumlah >= ? OR is_aml_high_value = 1 OR is_large_transaction = 1 THEN 1 ELSE 0 END) AS total_high_value,
                SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'menunggu' THEN 1 ELSE 0 END) AS total_menunggu,
                SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'terverifikasi' THEN 1 ELSE 0 END) AS total_verifikasi,
                SUM(CASE WHEN is_aml_high_value = 1 AND aml_status_verifikasi = 'ditolak' THEN 1 ELSE 0 END) AS total_ditolak
            ", [$threshold])->first();

        $totalHighValue = (int) ($statistik->total_high_value ?? 0);
        $totalMenunggu = (int) ($statistik->total_menunggu ?? 0);
        $totalVerifikasi = (int) ($statistik->total_verifikasi ?? 0);
        $totalDitolak = (int) ($statistik->total_ditolak ?? 0);

        return view('pengurus.aml.index', compact(
            'transaksis', 'threshold',
            'totalHighValue', 'totalMenunggu', 'totalVerifikasi', 'totalDitolak'
        ));
    }

    /**
     * Ekspor laporan kepatuhan PPATK (LTKT / LTKM / STR) sebagai berkas CSV.
     *
     * OJK-01 — melengkapi kewajiban pelaporan APU-PPT (UU 8/2010 & POJK
     * 27/2022). Periode mengikuti parameter `dari`/`sampai` (default: awal
     * bulan berjalan s.d. hari ini). Seluruh ambang & jenis transaksi tunai
     * dibaca dinamis dari {@see Setting}.
     */
    public function export(Request $request, string $jenis)
    {
        $jenis = strtoupper($jenis);

        if (! in_array($jenis, PpatkReportService::JENIS, true)) {
            abort(404, 'Jenis laporan PPATK tidak dikenal.');
        }

        [$dari, $sampai] = $this->periodeLaporan($request);

        $csv = $this->ppatkService->csv($jenis, $dari, $sampai);
        $nama = $this->ppatkService->namaBerkas($jenis, $dari, $sampai);

        Log::info('AmlComplianceController: ekspor laporan PPATK.', [
            'jenis' => $jenis,
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->toDateString(),
            'petugas' => Auth::id(),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
        ]);
    }

    /**
     * Tentukan rentang periode laporan dari query string.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodeLaporan(Request $request): array
    {
        $request->validate([
            'dari' => 'nullable|date',
            'sampai' => 'nullable|date',
        ]);

        $dari = $request->filled('dari') ? Carbon::parse($request->input('dari')) : Carbon::now()->startOfMonth();
        $sampai = $request->filled('sampai') ? Carbon::parse($request->input('sampai')) : Carbon::now();

        // Periode terbalik dibalik agar laporan tetap dapat dihasilkan.
        if ($sampai->lt($dari)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [$dari, $sampai];
    }

    // Verifikasi AML (simpan dokumen + ubah status menunggu/terverifikasi)
    public function verify(Request $request, $id)
    {
        $request->validate([
            'aml_sumber_dana' => 'required|string|max:255',
            'aml_status_verifikasi' => 'required|in:terverifikasi,ditolak,menunggu',
            'aml_dokumen' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'aml_catatan' => 'nullable|string',
        ]);

        $transaksi = Transaksi::with('rekening')->findOrFail($id);

        // Status TERMINAL tidak boleh diputus ulang. Penjagaan lama hanya
        // memeriksa `aml_status_verifikasi === 'ditolak'`, padahal transaksi
        // dapat sudah berakhir lewat jalur lain (void, pembatalan, impor data
        // lama) sementara status kepatuhannya masih `menunggu`. Bila lolos,
        // `tolakAml()` masuk cabang "sudah tercatat" dan menjalankan reversal
        // KEDUA: saldo rekening bertambah lagi dan jurnal pembalik terbit dua
        // kali (terbukti: Rp 1.000.000 → Rp 601.000.000 pada transaksi posisi
        // Kredit), dengan pesan "dana telah dikembalikan" yang tidak benar.
        if ($pesanTerminal = $this->pesanStatusTerminal($transaksi)) {
            return redirect()->back()->with('error', $pesanTerminal);
        }

        // Jika sebelumnya sudah ditolak, tidak bisa diubah lagi
        if ($transaksi->aml_status_verifikasi === 'ditolak') {
            return redirect()->back()->with('error', 'Transaksi yang sudah ditolak tidak dapat diubah statusnya.');
        }

        if ($request->aml_status_verifikasi === 'ditolak') {
            return $this->tolakAml($transaksi, $request);
        }

        // GCG — pemisahan tugas: pihak yang menginput transaksi high value ini
        // tidak boleh menjadi pengurus yang memverifikasi AML-nya sendiri.
        if (PemisahanTugas::dilanggar($transaksi->dibuat_oleh ?? null, Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        DB::transaction(function () use ($transaksi, $request) {
            $transaksi->aml_sumber_dana = $request->aml_sumber_dana;
            $transaksi->aml_status_verifikasi = $request->aml_status_verifikasi;
            $transaksi->aml_catatan_compliance = $request->aml_catatan;

            if ($request->hasFile('aml_dokumen')) {
                if ($transaksi->aml_dokumen_pendukung) {
                    Storage::disk('dokumen')->delete($transaksi->aml_dokumen_pendukung);
                }
                $transaksi->aml_dokumen_pendukung = $request->file('aml_dokumen')
                    ->store('aml_documents', 'dokumen');
            }

            $transaksi->save();

            // Jika terverifikasi: eksekusi persetujuan sehingga saldo rekening
            // ditambahkan & jurnal diposting (sebelumnya transaksi berstatus Pending).
            if ($request->aml_status_verifikasi === 'terverifikasi') {
                $approval = ApprovalTransaksi::where('tipe_referensi', 'transaksi')
                    ->where('referensi_id', $transaksi->id)
                    ->whereIn('status', ['PENDING_MANAJER', 'PENDING_PENGURUS'])
                    ->first();

                if ($approval) {
                    $result = $this->limitService->approveTransaction(
                        $approval,
                        Auth::user(),
                        'Verifikasi AML: '.($request->aml_catatan ?? '')
                    );

                    if ($result['status'] !== 'success') {
                        throw new \Exception($result['message']);
                    }
                }
            }
        });

        $msg = $request->aml_status_verifikasi === 'terverifikasi'
            ? 'Transaksi berhasil diverifikasi AML. Saldo rekening ditambahkan dan jurnal telah diposting.'
            : 'Status kepatuhan AML berhasil diperbarui.';

        return redirect()->back()->with('success', $msg);
    }

    // Tolak AML — kembalikan dana ke rekening
    public function tolak(Request $request, $id)
    {
        $request->validate([
            'aml_catatan' => 'required|string|max:500',
        ]);

        $transaksi = Transaksi::with('rekening')->findOrFail($id);

        return $this->tolakAml($transaksi, $request);
    }

    /**
     * Pesan penolakan bila transaksi sudah berada di status TERMINAL
     * (Gagal / Voided) — `null` berarti transaksi masih boleh diputus.
     *
     * Dipakai bersama oleh jalur verifikasi dan penolakan agar keduanya
     * memakai definisi "terminal" yang sama; sebelumnya masing-masing jalur
     * punya penjagaannya sendiri dan hanya mengenali `aml_status_verifikasi`.
     */
    private function pesanStatusTerminal(Transaksi $transaksi): ?string
    {
        $terminal = [
            Transaksi::STATUS_TRANSAKSI_GAGAL => 'Gagal',
            Transaksi::STATUS_TRANSAKSI_VOIDED => 'Voided',
            Transaksi::STATUS_TRANSAKSI_PENDING_VOID => 'Pending Void',
        ];

        return isset($terminal[$transaksi->status_transaksi])
            ? 'Transaksi sudah berstatus '.$terminal[$transaksi->status_transaksi]
                .' — status kepatuhan AML tidak dapat diubah lagi '
                .'(mencegah pembalikan dana/jurnal berganda).'
            : null;
    }

    // Logic internal tolak AML
    private function tolakAml(Transaksi $transaksi, Request $request): RedirectResponse
    {
        if ($pesanTerminal = $this->pesanStatusTerminal($transaksi)) {
            return redirect()->back()->with('error', $pesanTerminal);
        }

        if ($transaksi->aml_status_verifikasi === 'ditolak') {
            return redirect()->back()->with('error', 'Transaksi ini sudah pernah ditolak.');
        }

        // GCG — pemisahan tugas (berlaku untuk jalur verifikasi MAUPUN penolakan):
        // penginput transaksi high value tidak boleh memutuskan nasibnya sendiri.
        if (PemisahanTugas::dilanggar($transaksi->dibuat_oleh ?? null, Auth::id())) {
            return redirect()->back()->with('error', PemisahanTugas::PESAN);
        }

        // Transaksi AML high-value berstatus Pending: saldo rekening & posisi kas
        // belum pernah dicatat, sehingga penolakan tidak memerlukan pembalikan dana.
        $belumDicatat = in_array($transaksi->status_transaksi, ['Pending', 'pending_approval'])
            || $transaksi->status === 'pending_approval';

        try {
            DB::transaction(function () use ($transaksi, $request, $belumDicatat) {
                if ($belumDicatat) {
                    // Batalkan pengajuan otorisasi yang masih menggantung
                    ApprovalTransaksi::where('tipe_referensi', 'transaksi')
                        ->where('referensi_id', $transaksi->id)
                        ->whereIn('status', ['PENDING_MANAJER', 'PENDING_PENGURUS'])
                        ->update([
                            'status' => 'REJECTED',
                            'disetujui_oleh' => Auth::id(),
                            'role_approver' => Auth::user()->role,
                            'catatan_approver' => $request->aml_catatan,
                            'approved_at' => now(),
                        ]);
                } else {
                    // Transaksi sudah tercatat (mis. data lama): kembalikan saldo
                    // rekening ke kondisi sebelum transaksi + jurnal pembalik.
                    $reversal = $this->reversalService->reverseTransactionEffect(
                        $transaksi,
                        Auth::id(),
                        'TOLAK AML - '.$transaksi->jenis_transaksi.' | '.$transaksi->no_transaksi
                    );

                    if ($reversal['status'] !== 'success') {
                        throw new \Exception($reversal['message']);
                    }
                }

                $transaksi->aml_status_verifikasi = 'ditolak';
                $transaksi->aml_catatan_compliance = $request->aml_catatan;
                $transaksi->status_transaksi = $belumDicatat ? 'Gagal' : 'Voided';
                $transaksi->status = 'rejected';
                $transaksi->save();
            });
        } catch (\Throwable $e) {
            // Penolakan AML menyentuh status transaksi & reversal jurnal —
            // kegagalannya WAJIB terlihat di log, bukan hanya flash message.
            Log::error('AmlComplianceController: gagal menolak transaksi AML.', [
                'transaksi_id' => $transaksi->id ?? null,
                'pengguna' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Gagal menolak transaksi AML: '.$e->getMessage());
        }

        $msg = $belumDicatat
            ? 'Transaksi AML ditolak. Karena belum pernah dicatat, saldo rekening tidak berubah.'
            : 'Transaksi AML ditolak, dana telah dikembalikan dan saldo rekening dipulihkan.';

        return redirect()->back()->with('success', $msg);
    }
}
