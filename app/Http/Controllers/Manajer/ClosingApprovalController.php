<?php

namespace App\Http\Controllers\Manajer;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Jurnal;
use App\Models\KasHarian;
use App\Models\TellerOpname;
use App\Services\AutoJournalingService;
use App\Support\Coa;
use App\Support\PemisahanTugas;
use App\Support\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Verifikasi & penerimaan kas closing teller oleh Manajer.
 *
 * Saat Manajer menyetujui opname, sistem otomatis membentuk jurnal pemindahan
 * kas dari Kas Teller ke Kas Brankas (Vault) sesuai PSAK 101/109:
 *
 *   Balanced     : D 1-1003 Vault (sebesar fisik)   ; K 1-1001 Kas Teller (sebesar sistem)
 *   Cash Over    : D 1-1003 Vault (sebesar fisik)   ; K 1-1001 Kas Teller (sebesar sistem)
 *                                                   ; K 2-3099 Titipan Selisih Kas (sebesar selisih)
 *   Cash Short   : D 1-1003 Vault (sebesar fisik)   ; K 1-1001 Kas Teller (sebesar sistem)
 *                  D 1-1099 Piutang Teller (sebesar selisih)
 *
 * Catatan kode akun:
 * - 1-1002 di CoA proyek ini adalah "Bank / Rekening Utama Koperasi", sehingga kas
 *   fisik brankas memakai akun 1-1003 "Kas Brankas Utama / Vault" (lihat migrasi CoA).
 * - 2-3001 sudah dipakai "Dana Kebajikan [Ta'zir]", maka titipan selisih kas memakai 2-3099.
 */
class ClosingApprovalController extends Controller
{
    public function __construct(private AutoJournalingService $journaling) {}

    private const AKUN_KAS_BRANKAS = Coa::KAS_BRANKAS;

    private const AKUN_KAS_TELLER = Coa::KAS_TELLER;

    private const AKUN_PIUTANG_TELLER = Coa::PIUTANG_TELLER;

    private const AKUN_TITIPAN_SELISIH = Coa::TITIPAN_SELISIH_KAS;

    /**
     * Antrean pengajuan closing teller (default: yang masih pending).
     */
    public function index(Request $request): View
    {
        $status = $request->query('status', TellerOpname::STATUS_PENDING);

        if (! in_array($status, [TellerOpname::STATUS_PENDING, TellerOpname::STATUS_CLOSED, TellerOpname::STATUS_REJECTED, 'semua'], true)) {
            $status = TellerOpname::STATUS_PENDING;
        }

        $error = null;
        $opnames = collect();

        try {
            $query = TellerOpname::with(['user:id,name,role', 'approvedBy:id,name', 'jurnal:id,no_jurnal'])
                ->orderByDesc('tanggal')
                ->orderByDesc('id');

            if ($status !== 'semua') {
                $query->where('status', $status);
            }

            $opnames = $query->paginate(15)->withQueryString();
        } catch (\Throwable $e) {
            report($e);
            $error = 'Gagal memuat antrean closing: '.$e->getMessage();
        }

        // Statistik ringkas untuk header
        $statistik = [
            'pending' => 0,
            'closed' => 0,
            'rejected' => 0,
            'nilai_pending' => 0.0,
        ];

        try {
            $statistik['pending'] = TellerOpname::where('status', TellerOpname::STATUS_PENDING)->count();
            $statistik['closed'] = TellerOpname::where('status', TellerOpname::STATUS_CLOSED)->count();
            $statistik['rejected'] = TellerOpname::where('status', TellerOpname::STATUS_REJECTED)->count();
            $statistik['nilai_pending'] = (float) TellerOpname::where('status', TellerOpname::STATUS_PENDING)->sum('total_fisik');
        } catch (\Throwable $e) {
            report($e);
        }

        return view('manajer.closing.index', [
            'opnames' => $opnames,
            'status' => $status,
            'statistik' => $statistik,
            'error' => $error,
        ]);
    }

    /**
     * Terima kas & setujui closing.
     *
     * Dalam satu DB::transaction:
     *  1. Kunci baris (lockForUpdate) → cegah approve ganda oleh dua manajer.
     *  2. Set opname: closed + approved_by + approved_at.
     *  3. Set sesi kas_harian: status_opname = CLOSED.
     *  4. Bentuk jurnal pemindahan kas ke Vault.
     */
    public function approve(Request $request, TellerOpname $opname): RedirectResponse
    {
        $validated = $request->validate([
            'catatan_approval' => 'nullable|string|max:1000',
        ]);

        if (! $opname->isPending()) {
            return back()->with('error', 'Pengajuan ini sudah diproses sebelumnya (status: '
                .TellerOpname::labelStatus($opname->status).').');
        }

        // GCG — pemisahan tugas: teller tidak boleh menyetujui closing kasnya sendiri.
        if (PemisahanTugas::dilanggar($opname->user_id, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        try {
            $hasil = DB::transaction(function () use ($opname, $validated) {
                // Kunci baris opname agar tidak diproses dua kali bersamaan.
                $opname = TellerOpname::whereKey($opname->id)->lockForUpdate()->first();

                if (! $opname || ! $opname->isPending()) {
                    return null;
                }

                // Pastikan relasi teller & sesi kas tersedia untuk jurnal.
                $opname->loadMissing(['user:id,name', 'kasHarian']);

                $saldoSistem = (float) $opname->saldo_sistem;
                $totalFisik = (float) $opname->total_fisik;
                $selisih = (float) $opname->selisih;

                // ─── 1) Jurnal pemindahan kas (PSAK 101/109) ───
                $jurnal = $this->postingJurnalPemindahanKas($opname, $saldoSistem, $totalFisik, $selisih);

                if (! $jurnal) {
                    throw new \RuntimeException('Jurnal pemindahan kas gagal dibentuk. Periksa ketersediaan akun CoA (1-1001, 1-1003, 1-1099, 2-3099).');
                }

                // ─── 2) Setujui opname ───
                $opname->update([
                    'status' => TellerOpname::STATUS_CLOSED,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'catatan_approval' => $validated['catatan_approval'] ?? null,
                    'jurnal_id' => $jurnal->id,
                ]);

                // ─── 3) Tutup sesi Buka Kas ───
                $opname->kasHarian?->update([
                    'status_opname' => KasHarian::STATUS_CLOSED,
                    'waktu_closing' => $opname->kasHarian->waktu_closing ?? now()->format('H:i:s'),
                    'fisik_kas' => $totalFisik,
                    'saldo_sistem' => $saldoSistem,
                    'selisih' => $selisih,
                    'status_closing' => TellerOpname::labelStatusSelisih($opname->status_selisih),
                ]);

                // ─── 4) Riwayat persetujuan (jejak audit) ───
                AuditLog::record(
                    'Approve Closing Kas',
                    $totalFisik,
                    null,
                    sprintf(
                        'Manajer %s menyetujui closing kas teller %s (opname #%d) | Sistem: Rp %s | Fisik: Rp %s | Selisih: Rp %s (%s) | Jurnal: %s',
                        Auth::user()?->name ?? ('User #'.Auth::id()),
                        $opname->user?->name ?? ('User #'.$opname->user_id),
                        $opname->id,
                        number_format($saldoSistem, 0, ',', '.'),
                        number_format($totalFisik, 0, ',', '.'),
                        number_format(abs($selisih), 0, ',', '.'),
                        TellerOpname::labelStatusSelisih($opname->status_selisih),
                        $jurnal->no_jurnal
                    )
                );

                return $jurnal;
            });
        } catch (\Throwable $e) {
            report($e);
            Log::error('ClosingApprovalController: approve gagal.', [
                'opname_id' => $opname->id,
                'manajer' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Approval gagal: '.$e->getMessage());
        }

        if (! $hasil) {
            return back()->with('error', 'Pengajuan ini sudah diproses manajer lain. Muat ulang halaman untuk melihat status terbaru.');
        }

        return redirect()->route('manajer.closing.index')
            ->with('success', 'Kas diterima & closing disetujui. Jurnal pemindahan kas '
                .$hasil->no_jurnal.' telah diposting.');
    }

    /**
     * Tolak pengajuan closing — kas dikembalikan ke status OPEN agar teller
     * dapat menghitung ulang fisiknya.
     */
    public function reject(Request $request, TellerOpname $opname): RedirectResponse
    {
        $validated = $request->validate([
            'catatan_approval' => 'required|string|max:1000',
        ], [
            'catatan_approval.required' => 'Alasan penolakan wajib diisi agar teller tahu apa yang harus diperbaiki.',
        ]);

        if (! $opname->isPending()) {
            return back()->with('error', 'Pengajuan ini sudah diproses sebelumnya (status: '
                .TellerOpname::labelStatus($opname->status).').');
        }

        // GCG — pemisahan tugas: teller tidak boleh menolak closing kasnya sendiri.
        if (PemisahanTugas::dilanggar($opname->user_id, Auth::id())) {
            return back()->with('error', PemisahanTugas::PESAN);
        }

        try {
            DB::transaction(function () use ($opname, $validated) {
                $opname = TellerOpname::whereKey($opname->id)->lockForUpdate()->first();

                if (! $opname || ! $opname->isPending()) {
                    throw new \RuntimeException('Pengajuan sudah diproses manajer lain.');
                }

                $opname->loadMissing(['user:id,name', 'kasHarian']);

                $opname->update([
                    'status' => TellerOpname::STATUS_REJECTED,
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                    'catatan_approval' => $validated['catatan_approval'],
                ]);

                // Buka kembali sesi kas supaya teller bisa opname ulang.
                $opname->kasHarian?->update([
                    'status_opname' => KasHarian::STATUS_OPEN,
                    'waktu_closing' => null,
                    'status_closing' => null,
                    'catatan_closing' => null,
                ]);

                // Riwayat penolakan (jejak audit)
                AuditLog::record(
                    'Reject Closing Kas',
                    (float) $opname->total_fisik,
                    null,
                    sprintf(
                        'Manajer %s menolak closing kas teller %s (opname #%d) | Alasan: %s',
                        Auth::user()?->name ?? ('User #'.Auth::id()),
                        $opname->user?->name ?? ('User #'.$opname->user_id),
                        $opname->id,
                        $validated['catatan_approval']
                    )
                );
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Gagal menolak pengajuan: '.$e->getMessage());
        }

        return redirect()->route('manajer.closing.index')
            ->with('success', 'Pengajuan closing ditolak. Sesi kas teller dibuka kembali untuk dihitung ulang.');
    }

    /**
     * Membentuk jurnal pemindahan kas Teller → Brankas (Vault).
     *
     * Rumus selalu balance:
     *   Debet Vault = Total Fisik
     *   Kredit Kas Teller = Saldo Sistem
     *   Selisihnya diserap akun Titipan Selisih Kas (over) atau Piutang Teller (short).
     */
    private function postingJurnalPemindahanKas(TellerOpname $opname, float $saldoSistem, float $totalFisik, float $selisih): ?Jurnal
    {
        $entries = [
            ['kode' => self::AKUN_KAS_BRANKAS, 'debet' => $totalFisik, 'kredit' => 0],
            ['kode' => self::AKUN_KAS_TELLER,  'debet' => 0, 'kredit' => $saldoSistem],
        ];

        if (Setting::bulatkanUang($selisih) > 0) {
            // Kas LEBIH: fisik > sistem → selisih jadi kewajiban titipan.
            $entries[] = ['kode' => self::AKUN_TITIPAN_SELISIH, 'debet' => 0, 'kredit' => $selisih];
        } elseif (Setting::bulatkanUang($selisih) < 0) {
            // Kas KURANG: fisik < sistem → selisih jadi piutang ke teller.
            // Nilai debet harus positif (abs) agar jurnal seimbang.
            $entries[] = ['kode' => self::AKUN_PIUTANG_TELLER, 'debet' => abs($selisih), 'kredit' => 0];
        }

        $label = TellerOpname::labelStatusSelisih($opname->status_selisih);

        $keterangan = sprintf(
            'Pemindahan kas closing teller #%d — %s | Sistem: Rp %s | Fisik: Rp %s | Selisih: Rp %s (%s)',
            $opname->id,
            $opname->user?->name ?? ('User #'.$opname->user_id),
            number_format($saldoSistem, 0, ',', '.'),
            number_format($totalFisik, 0, ',', '.'),
            number_format(abs($selisih), 0, ',', '.'),
            $label
        );

        return $this->journaling->post(
            keterangan: $keterangan,
            entries: $entries,
            sumber: 'Opname Kas Teller',
            userId: Auth::id(),
        );
    }
}
