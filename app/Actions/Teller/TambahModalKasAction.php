<?php

namespace App\Actions\Teller;

use App\Models\KasHarian;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Support\NomorDokumen;
use App\Support\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Injeksi Kas: Brankas Utama (Vault) → Laci Teller.
 *
 * Guard anti-deficit: transaksi ditolak bila saldo Vault (CoA 1-1003) tidak
 * mencukupi. Batas maksimum kas laci juga dijaga memakai parameter dinamis
 * `Setting::tellerMaxCashLimit()` supaya uang tunai tidak menumpuk di loket.
 * Validasi HTTP dilakukan di controller.
 */
class TambahModalKasAction
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
            return back()->with('error', 'Kas hari ini sudah di-closing. Modal tidak dapat ditambahkan lagi.');
        }

        $nominal = (float) $request->nominal_tambah;

        // ── Guard anti-deficit Vault: tidak boleh mengeluarkan uang yang tidak ada.
        $saldoVault = KasHarian::saldoBrankas();
        if ($saldoVault < $nominal) {
            return back()->withInput()->with('error',
                'Injeksi kas ditolak. Saldo Brankas Utama (Rp '.number_format($saldoVault, 0, ',', '.').
                ') tidak mencukupi untuk mengeluarkan Rp '.number_format($nominal, 0, ',', '.').'.');
        }

        // ── Batas kas laci: cegah penumpukan tunai melebihi kebijakan.
        $batasLaci = Setting::tellerMaxCashLimit();
        $saldoLaci = KasHarian::hitungSaldoKasTeller(Auth::id(), $today);
        if ($batasLaci > 0 && ($saldoLaci['saldo'] + $nominal) > $batasLaci) {
            return back()->withInput()->with('error',
                'Injeksi kas ditolak. Setelah injeksi, saldo laci (Rp '.
                number_format($saldoLaci['saldo'] + $nominal, 0, ',', '.').
                ') akan melampaui batas maksimum kas laci Rp '.number_format($batasLaci, 0, ',', '.').'.');
        }

        $keterangan = $request->keterangan ?: 'Injeksi kas dari Brankas Utama';

        // Seluruh proses dibungkus SATU transaksi basis data: perubahan laci,
        // mutasi Transaksi, dan jurnal harus berhasil bersama-sama. Bila jurnal
        // gagal (CoA hilang/nonaktif → `post()` mengembalikan null, bukan throw),
        // transaksi WAJIB di-rollback agar saldo tidak berubah tanpa jejak jurnal.
        try {
            DB::transaction(function () use ($kasHarian, $nominal, $keterangan) {
                $kasHarian->tambahModalKas($nominal, $keterangan);

                // Catat sebagai transaksi kas masuk agar ikut terhitung pada
                // Laporan Kas Harian & rekonsiliasi Closing.
                $transaksi = Transaksi::create([
                    'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                    'waktu_transaksi' => now(),
                    'jenis_transaksi' => 'Injeksi Kas Brankas',
                    'posisi' => 'Debet',
                    'jumlah' => $nominal,
                    'saldo_setelah' => KasHarian::hitungSaldoKasTeller(Auth::id())['saldo'] + $nominal,
                    'user_id' => Auth::id(),
                    'dibuat_oleh' => Auth::id(),
                    'status_transaksi' => 'Sukses',
                    'catatan' => $keterangan,
                ]);

                // Auto-journal PSAK 101/108: D 1-1001 Kas Teller / K 1-1003 Kas Brankas.
                $jurnal = $this->journalService->jurnalInjeksiKasBrankas($nominal, $keterangan, Auth::id(), $transaksi->id);

                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal injeksi kas gagal diposting (CoA tidak ditemukan atau nonaktif).');
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error',
                'Injeksi kas dibatalkan: '.$e->getMessage().' Saldo laci tidak diubah.');
        }

        return back()->with('success', 'Modal kas berhasil ditambahkan sebesar Rp '.number_format($nominal, 0, ',', '.'));
    }
}
