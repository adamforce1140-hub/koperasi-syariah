<?php

namespace App\Actions\Teller;

use App\Models\KasHarian;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Support\NomorDokumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Setoran Hasil Kas Harian: Laci Teller → Brankas Utama (Vault).
 *
 * Guard anti-deficit: tidak boleh menyetor melebihi saldo kas laci yang
 * benar-benar ada. Auto-journal: D 1-1003 Kas Brankas / K 1-1001 Kas Teller.
 * Validasi HTTP dilakukan di controller.
 */
class SetorKasKeBrankasAction
{
    public function __construct(
        private readonly AutoJournalingService $journalService,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $today = now()->toDateString();
        $kasHarian = KasHarian::where('user_id', Auth::id())->where('tanggal', $today)->first();

        if (! $kasHarian) {
            return back()->with('error', 'Kas belum dibuka. Silakan buka kas terlebih dahulu.');
        }

        if ($kasHarian->sudahClosing()) {
            return back()->with('error', 'Kas hari ini sudah di-closing. Setoran ke brankas tidak dapat dilakukan lagi.');
        }

        $nominal = (float) $request->nominal_setor;

        // ── Guard anti-deficit laci: setoran tidak boleh melebihi kas fisik.
        $saldoLaci = KasHarian::hitungSaldoKasTeller(Auth::id(), $today);
        if ($saldoLaci['saldo'] < $nominal) {
            return back()->withInput()->with('error',
                'Setoran ke brankas ditolak. Saldo kas laci saat ini Rp '.
                number_format($saldoLaci['saldo'], 0, ',', '.').
                ' kurang dari nominal yang akan disetor Rp '.number_format($nominal, 0, ',', '.').'.');
        }

        $keterangan = $request->keterangan ?: 'Setoran hasil kas harian ke Brankas Utama';

        try {
            DB::transaction(function () use ($nominal, $keterangan) {
                $transaksi = Transaksi::create([
                    'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'waktu_transaksi' => now(),
                    'jenis_transaksi' => 'Setoran Kas ke Brankas',
                    'posisi' => 'Kredit',
                    'jumlah' => $nominal,
                    'saldo_setelah' => KasHarian::hitungSaldoKasTeller(Auth::id())['saldo'] - $nominal,
                    'user_id' => Auth::id(),
                    'dibuat_oleh' => Auth::id(),
                    'status_transaksi' => 'Sukses',
                    'catatan' => $keterangan,
                ]);

                // Auto-journal PSAK 101/108: D 1-1003 Kas Brankas / K 1-1001 Kas Teller.
                $jurnal = $this->journalService->jurnalSetorKasKeBrankas($nominal, $keterangan, Auth::id(), $transaksi->id);

                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal setoran ke brankas gagal diposting (CoA tidak ditemukan atau nonaktif).');
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error',
                'Setoran ke brankas dibatalkan: '.$e->getMessage().' Saldo kas laci tidak diubah.');
        }

        return back()->with('success', 'Kas sebesar Rp '.number_format($nominal, 0, ',', '.').' berhasil disetor ke Brankas Utama.');
    }
}
