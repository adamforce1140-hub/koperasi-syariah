<?php

namespace App\Actions\Teller;

use App\Models\AuditLog;
use App\Models\JenisSimpanan;
use App\Models\KasHarian;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Services\JurnalReconciliationService;
use App\Support\NomorDokumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Pencairan Deposito (simpanan berjangka) di teller.
 *
 * Prasyarat tipe rekening diperiksa lewat `abort_unless` (404) agar rekening
 * non-deposito tidak dapat dicairkan lewat rute ini.
 */
class CairkanDepositoAction
{
    public function __construct(
        private readonly AutoJournalingService $journalService,
        private readonly JurnalReconciliationService $reconciliation,
    ) {}

    public function execute(Request $request, Rekening $rekening): RedirectResponse
    {
        abort_unless(in_array($rekening->jenis_rekening, JenisSimpanan::namaRekening(JenisSimpanan::TIPE_BERJANGKA), true), 404);

        if (! in_array($rekening->status_rekening, ['Aktif', 'Jatuh Tempo'])) {
            return back()->with('error', 'Rekening ini tidak dapat dicairkan (status: '.$rekening->status_rekening.').');
        }

        $nominal = (float) $rekening->saldo;
        $noTrx = NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4));

        // Anti-deficit: pencairan tunai hanya boleh bila kas fisik laci mencukupi.
        $kasTeller = KasHarian::hitungSaldoKasTeller(Auth::id());
        if ($kasTeller['saldo'] < $nominal) {
            return back()->with('error',
                'Pencairan ditolak. Saldo Kas Teller saat ini Rp '.number_format($kasTeller['saldo'], 0, ',', '.').
                ' tidak mencukupi untuk mencairkan Rp '.number_format($nominal, 0, ',', '.').'.');
        }

        // Seluruh proses (mutasi, penutupan rekening, jurnal) dibungkus SATU
        // transaksi basis data dengan baris rekening dikunci. Bila jurnal gagal
        // (`post()` mengembalikan null, bukan throw), semuanya di-rollback agar
        // saldo deposito tidak hilang tanpa jejak jurnal.
        try {
            DB::transaction(function () use ($rekening, $nominal, $noTrx, $request, &$transaksi) {
                $rekeningTerkunci = Rekening::whereKey($rekening->id)->lockForUpdate()->firstOrFail();

                $transaksi = Transaksi::create([
                    'no_transaksi' => $noTrx,
                    'rekening_id' => $rekeningTerkunci->id,
                    'anggota_id' => $rekeningTerkunci->anggota_id,
                    'jenis_transaksi' => 'Pencairan Deposito',
                    'posisi' => 'Kredit',
                    'jumlah' => $nominal,
                    'saldo_setelah' => 0,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'waktu_transaksi' => now(),
                    'user_id' => Auth::id(),
                    'catatan' => $request->catatan ?? 'Pencairan deposito mudharabah jatuh tempo',
                ]);

                // R12 — URUTAN BAKU AKUNTANSI: jurnal diposting & diverifikasi
                // LEBIH DULU, baru saldo dinolkan dan status rekening ditutup.
                // Bila auto-journaling gagal, transaksi di-rollback tanpa
                // meninggalkan rekening yang tertutup tanpa jejak jurnal.
                // Jurnal: DEBET Simpanan Berjangka (2-1003) | KREDIT Kas Teller (1-1001)
                $jurnal = $this->journalService->jurnalTarikDeposito(
                    $nominal,
                    'Pencairan deposito '.$rekeningTerkunci->no_rekening.' — '.$rekeningTerkunci->anggota?->nama_lengkap,
                    Auth::id(),
                    $transaksi->id,
                    $rekeningTerkunci->id,
                    $rekeningTerkunci->anggota_id
                );

                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal pencairan deposito gagal diposting (CoA tidak ditemukan atau nonaktif).');
                }

                $rekeningTerkunci->update(['saldo' => 0, 'status_rekening' => 'Ditutup']);

                AuditLog::record('Pencairan Deposito', $nominal, $rekeningTerkunci,
                    'Deposito dicairkan: '.$rekeningTerkunci->no_rekening);

                // Gerbang P1-1: subledger simpanan wajib sama dengan akun kontrol.
                $this->reconciliation->pastikanSubledgerSehat();
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error',
                'Pencairan deposito dibatalkan: '.$e->getMessage().' Saldo deposito tidak diubah.');
        }

        return redirect()->route('teller.nota', $transaksi->id)
            ->with('success', 'Deposito '.$rekening->no_rekening.' berhasil dicairkan. Jurnal PSAK 105 telah diposting.');
    }
}
