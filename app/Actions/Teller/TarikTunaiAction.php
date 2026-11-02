<?php

namespace App\Actions\Teller;

use App\Models\AuditLog;
use App\Models\KasHarian;
use App\Models\Rekening;
use App\Services\AutoJournalingService;
use App\Services\TransactionLimitService;
use App\Support\GuardSimpananModal;
use App\Support\NomorDokumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Penarikan Tunai Simpanan di loket teller.
 *
 * Tiga gerbang wajib: allowlist rekening (Simpanan Pokok/Wajib = ekuitas,
 * PSAK 101 ¶31), kecukupan kas fisik teller, dan batas saldo minimum
 * rekening. Mutasi saldo + jurnal dijalankan dalam satu DB transaction.
 * Validasi HTTP di controller.
 */
class TarikTunaiAction
{
    public function __construct(
        private readonly TransactionLimitService $limitService,
        private readonly AutoJournalingService $journalService,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $rekening = Rekening::with('anggota')->findOrFail($request->rekening_id);

        // GATE 1 — ALLOWLIST KETAT PENARIKAN TUNAI (Bagian 4 poin 1).
        // Penarikan tunai harian HANYA diizinkan untuk rekening Simpanan
        // Sukarela (CoA 2-1001). Simpanan Pokok (3-1001) & Simpanan Wajib
        // (3-1002) adalah MODAL/EKUITAS anggota (PSAK 101 ¶ 31) dan DITOLAK
        // dengan pesan standar; pengembalian dananya hanya melalui modul
        // `member_terminations`. Pelanggaran dicatat ke audit_log terpusat.
        try {
            GuardSimpananModal::pastikanRekeningDapatDitarikTunai($rekening, 'teller.tarik.store');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['jumlah' => $e->getMessage()])->withInput();
        }

        // GATE 2 — STATUS REKENING: hanya rekening `Aktif` yang boleh dimutasi.
        // Penyaringan status sebelumnya hanya ada di `tarikForm()` (lapisan
        // tampilan), sehingga request langsung ke endpoint ini dapat menarik
        // rekening `Dibekukan`/`Ditutup`. Status lain tetap ditolak di sini.
        if (! $rekening->isAktif()) {
            return back()->withErrors([
                'jumlah' => 'Penarikan ditolak. Rekening '.($rekening->no_rekening ?? '').
                    ' berstatus '.($rekening->status_rekening ?? '-').'; hanya rekening Aktif yang dapat ditarik.',
            ])->withInput();
        }

        // Proteksi kas fisik: tolak penarikan bila Saldo Kas Teller
        // (Kas Awal + Total Cash In - Total Cash Out) tidak mencukupi.
        // Bila posisi kas gagal dihitung, transaksi dibatalkan dengan pesan jelas
        // agar tidak ada penarikan yang lolos tanpa validasi saldo.
        try {
            $kasTeller = KasHarian::hitungSaldoKasTeller(Auth::id());
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'jumlah' => 'Gagal memverifikasi saldo kas teller: '.$e->getMessage(),
            ])->withInput();
        }

        if ($kasTeller['saldo'] < (float) $request->jumlah) {
            return back()->withErrors([
                'jumlah' => 'Gagal: Saldo Kas Teller tidak mencukupi untuk melakukan penarikan ini.',
            ])->withInput();
        }

        // Safeguard: penarikan ditolak bila saldo tidak mencukupi ATAU akan menyisakan
        // saldo di bawah saldo minimum koperasi (anti saldo minus). Saat ditolak,
        // saldo rekening TIDAK berkurang dan tidak ada mutasi/jurnal yang dibuat.
        if (! $rekening->isDapatDitarik((float) $request->jumlah)) {
            return back()->withErrors(['jumlah' => 'Penarikan ditolak. Saldo tidak mencukupi atau akan berada di bawah saldo minimum Rp '.
                number_format((float) $rekening->batas_minimum, 0, ',', '.').'. Saldo tersedia: Rp '.
                number_format($rekening->saldo, 0, ',', '.')])->withInput();
        }

        $result = $this->limitService->processTransactionWithLimitCheck(
            Auth::user(),
            'tarik_tunai',
            (float) $request->jumlah,
            'tunai',
            [
                'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                'rekening_id' => $rekening->id,
                'anggota_id' => $rekening->anggota_id,
                'jenis_transaksi' => 'Penarikan Tunai',
                'posisi' => 'Kredit',
                'jumlah' => (float) $request->jumlah,
                // Jika pending approval: simpan saldo saat ini (belum terpotong)
                // Jika langsung sukses: saldo_setelah akan diupdate setelah decrement
                'saldo_setelah' => $rekening->saldo,
                'catatan' => $request->catatan ?? 'Tarik tunai',
                'waktu_transaksi' => now(),
            ]
        );

        if ($result['status'] === 'success') {
            $transaksi = $result['data']['transaksi'];

            // TAHAP 5 — INTEGRITAS SALDO & JURNAL: pemotongan saldo dan posting
            // jurnal dilakukan dalam SATU transaksi basis data dengan baris
            // rekening dikunci (`lockForUpdate`).
            //   • Anti lost-update — dua penarikan bersamaan tidak saling menimpa.
            //   • Guard saldo diulang DI DALAM kunci. Pemeriksaan kuota di
            //     `processTransactionWithLimitCheck()` berjalan sebelum baris
            //     terkunci, sehingga tanpa pengulangan ini dua penarikan yang
            //     lolos bersamaan dapat menembus batas saldo minimum.
            //   • Anti saldo-yatim — bila jurnal gagal, saldo ikut dikembalikan.
            try {
                DB::transaction(function () use ($rekening, $request, $transaksi) {
                    $rekeningTerkunci = Rekening::whereKey($rekening->id)->lockForUpdate()->firstOrFail();

                    // Status dapat berubah setelah pemeriksaan di luar kunci
                    // (mis. rekening ditutup bersamaan) → tolak & rollback.
                    if (! $rekeningTerkunci->isAktif()) {
                        throw new \RuntimeException('Rekening tidak aktif ('.($rekeningTerkunci->status_rekening ?? '-').').');
                    }

                    $saldoSetelah = (float) $rekeningTerkunci->saldo - (float) $request->jumlah;
                    $saldoMinimum = (float) ($rekeningTerkunci->batas_minimum ?? 0);

                    if ($saldoSetelah < 0) {
                        throw new \RuntimeException('Saldo tidak mencukupi (saldo akan menjadi negatif).');
                    }
                    if ($saldoSetelah < $saldoMinimum) {
                        throw new \RuntimeException('Penarikan melewati batas saldo minimum rekening.');
                    }

                    $rekeningTerkunci->decrement('saldo', (float) $request->jumlah);
                    $rekeningTerkunci->refresh();
                    $transaksi->update(['saldo_setelah' => $rekeningTerkunci->saldo]);

                    $jurnal = $this->journalService->jurnalTarikTunai(
                        (float) $request->jumlah,
                        'Tarik tunai '.($rekeningTerkunci->no_rekening ?? '').' - '
                            .($rekeningTerkunci->anggota->nama_lengkap ?? 'Anggota'),
                        Auth::id(),
                        $transaksi->id ?? null,
                        $rekeningTerkunci->id,
                        $rekeningTerkunci->anggota_id
                    );

                    // Selaras `setorStore`: null berarti jurnal gagal → rollback
                    // agar saldo anggota tidak berkurang tanpa jejak jurnal.
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal penarikan tunai gagal diposting (CoA tidak ditemukan atau nonaktif).');
                    }

                    return $jurnal;
                });
            } catch (\Throwable $e) {
                report($e);

                return back()->withErrors([
                    'jumlah' => 'Penarikan dibatalkan: '.$e->getMessage(),
                ])->withInput();
            }

            AuditLog::record(
                'Penarikan Tunai',
                (float) $request->jumlah,
                $rekening,
                'Penarikan tunai simpanan atas nama '.($rekening->anggota->nama_lengkap ?? 'Anggota')
            );

            return redirect()->route('teller.nota', $transaksi->id)->with('success', 'Penarikan tunai berhasil!');
        }

        if ($result['status'] === 'PENDING_APPROVAL') {
            return redirect()->route('teller.riwayat')->with('warning', $result['message']);
        }

        return back()->withErrors(['jumlah' => $result['message'] ?? 'Transaksi gagal.'])->withInput();
    }
}
