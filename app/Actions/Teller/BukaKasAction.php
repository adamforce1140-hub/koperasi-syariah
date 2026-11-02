<?php

namespace App\Actions\Teller;

use App\Models\KasHarian;
use App\Services\AturanLintasHariService;
use App\Services\AutoJournalingService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Buka Kas (opening sesi teller) + jurnal pemindahan modal awal.
 *
 * Modal awal BUKAN pendapatan: kas fisik berpindah dari Brankas Utama
 * (1-1003) ke Laci Teller (1-1001) sehingga wajib dijurnal agar total aset
 * Kas tetap seimbang. Validasi HTTP dilakukan di controller.
 */
class BukaKasAction
{
    public function __construct(
        private readonly AutoJournalingService $journalService,
        private readonly AturanLintasHariService $lintasHari,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $modalAwal = (float) $request->modal_awal;

        if ($modalAwal <= 0) {
            return back()->withInput()
                ->withErrors(['modal_awal' => 'Modal awal harus lebih besar dari Rp 0.']);
        }

        $today = $this->lintasHari->tanggalBisnis();

        // Cegah double opening (tanggal bisnis yang sama).
        $existing = KasHarian::where('user_id', Auth::id())->whereDate('tanggal', $today)->first();
        if ($existing) {
            return redirect()->route('teller.kas.opening')
                ->with('info', 'Kas sudah dibuka hari ini.');
        }

        // ── Aturan lintas hari ────────────────────────────────────────────
        // Sesi kas pada tanggal SEBELUM tanggal bisnis berjalan wajib sudah
        // ditutup. Tanpa penjagaan ini, saldo laci kemarin tercampur dengan
        // modal opening hari ini dan rekonsiliasi kas menjadi tidak akurat.
        $sesiTertunda = $this->lintasHari->sesiBelumTutupSebelum(Auth::id(), $today);

        if ($sesiTertunda !== null) {
            $tanggalTertunda = $sesiTertunda->tanggal?->toDateString() ?? (string) $sesiTertunda->tanggal;
            $labelStatus = KasHarian::labelStatusOpname($sesiTertunda->status_opname);

            return redirect()
                ->route('teller.kas.closing', ['tanggal' => $tanggalTertunda])
                ->with('error', "Kas tanggal {$tanggalTertunda} belum ditutup (status: {$labelStatus}). "
                    .'Selesaikan Closing sesi tersebut sebelum membuka kas hari ini.');
        }

        try {
            // Modal awal kas BUKAN pendapatan, melainkan pemindahan kas fisik
            // dari Brankas Utama ke laci teller → wajib dijurnal (R6) supaya
            // total aset Kas tetap seimbang. `post()` mengembalikan null bila
            // CoA hilang/nonaktif → lemparkan agar seluruh perubahan rollback.
            DB::transaction(function () use ($modalAwal, $request, $today) {
                KasHarian::create([
                    'user_id' => Auth::id(),
                    'tanggal' => $today,
                    'modal_awal' => $modalAwal,
                    'catatan_opening' => $request->catatan,
                    'waktu_opening' => now()->format('H:i:s'),
                    'tambah_modal' => [],
                ]);

                $keterangan = $request->catatan
                    ?: 'Pembukaan kas teller (modal awal shift) — Rp '.number_format($modalAwal, 0, ',', '.');
                $jurnal = $this->journalService->jurnalPembukaanKasTeller($modalAwal, $keterangan, Auth::id());
                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal pembukaan kas gagal diposting (CoA Kas Teller/Brankas tidak ditemukan atau nonaktif).');
                }
            });
        } catch (QueryException $e) {
            // Unique constraint (user_id, tanggal): double submit / request bersamaan.
            // Perlakukan sebagai "sudah dibuka", bukan error 500.
            return redirect()->route('teller.kas.opening')
                ->with('info', 'Kas sudah dibuka hari ini.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error',
                'Pembukaan kas dibatalkan: '.$e->getMessage().' Modal awal tidak dicatat.');
        }

        return redirect()->route('teller.kas.opening')
            ->with('success', 'Kas berhasil dibuka. Modal awal: Rp '.number_format($modalAwal, 0, ',', '.'));
    }
}
