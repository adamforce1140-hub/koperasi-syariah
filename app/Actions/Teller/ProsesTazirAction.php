<?php

namespace App\Actions\Teller;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\DanaKebajikanService;
use App\Services\TazirService;
use App\Support\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Use-case Pemotongan Ta'zir langsung oleh teller (alur tanpa angsuran).
 *
 * PSAK 108/109 — seluruh nominal wajib masuk Dana Kebajikan (2-3001),
 * bukan pendapatan. Fitur denda harus aktif pada parameter kebijakan.
 */
class ProsesTazirAction
{
    public function __construct(
        private readonly TazirService $tazirService,
        private readonly DanaKebajikanService $danaKebajikanService,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $userId = $request->user_id ?? $request->anggota_id;
        // Nominal ta'zir: prioritas input teller, fallback ke kebijakan koperasi
        // (tanpa hardcode — dapat diubah pengurus dari UI Pengaturan).
        $nominalTazir = (float) ($request->nominal ?? Setting::nominalTazirDefault());

        /* PSAK 108/109 — Pemisahan Dana Kebajikan (Ta'zir):
         | Denda keterlambatan HANYA boleh dibebankan bila pengurus mengaktifkan
         | fitur pada parameter `financing_late_fee_per_day`. Bila fitur NONAKTIF
         | (nilai 0), permintaan ditolak dan dicatat ke log audit — mencegah
         | pemungutan denda tanpa dasar kebijakan tertulis.
         | Seluruh nominal wajib masuk Dana Kebajikan (2-3001), bukan pendapatan. */
        if (! $this->tazirService->fiturAktif()) {
            Log::error(
                'TellerController::prosesTazir DITOLAK — fitur denda (financing_late_fee_per_day) NONAKTIF.',
                [
                    'anggota_id' => $userId,
                    'nominal_diminta' => $nominalTazir,
                    'user_id' => Auth::id(),
                ]
            );

            return redirect()->back()->withErrors([
                'nominal' => "Fitur denda keterlambatan (Ta'zir) sedang nonaktif. "
                    .'Aktifkan parameter "Denda Keterlambatan per Hari" pada Pengaturan Kebijakan Koperasi.',
            ]);
        }

        if ($nominalTazir <= 0) {
            return redirect()->back()->withErrors([
                'nominal' => "Nominal Ta'zir harus lebih besar dari nol.",
            ]);
        }

        $anggota = null;
        if ($userId) {
            $anggota = Anggota::find($userId) ?? Anggota::where('user_id', $userId)->first();
        }

        try {
            // Seluruh alur (potong saldo → transaksi → Dana Kebajikan + jurnal)
            // dijalankan atomik. Bila jurnal Dana Kebajikan gagal, saldo anggota
            // dan transaksi ikut di-rollback agar ta'zir tidak pernah "hilang"
            // dari catatan double-entry (PSAK 109).
            DB::transaction(function () use ($anggota, $nominalTazir) {
                $rekening = null;
                if ($anggota) {
                    $rekening = Rekening::where('anggota_id', $anggota->id)
                        ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                        ->lockForUpdate()
                        ->first();
                }

                if (! $rekening || (float) $rekening->saldo < $nominalTazir) {
                    $saldoAkhirAnggota = $rekening ? (float) $rekening->saldo : 0;
                } else {
                    $rekening->saldo = (float) $rekening->saldo - $nominalTazir;
                    $rekening->save();
                    $saldoAkhirAnggota = (float) $rekening->saldo;
                }

                $transaksi = Transaksi::create([
                    'no_transaksi' => 'TRX-TAZIR-'.now()->format('YmdHis'),
                    'rekening_id' => $rekening?->id,
                    'anggota_id' => $anggota?->id,
                    'user_id' => Auth::id(),
                    'jenis_transaksi' => "Pemotongan Ta'zir Keterlambatan",
                    'posisi' => 'Debet',
                    'jumlah' => $nominalTazir,
                    'saldo_setelah' => $saldoAkhirAnggota,
                    'sumber_transaksi' => 'Teller',
                    'status_transaksi' => 'Sukses',
                    'status' => 'success',
                    'catatan' => "Pemotongan Ta'zir Keterlambatan Angsuran Anggota",
                    'waktu_transaksi' => now(),
                ]);

                // Bila sinkronisasi jurnal gagal, exception melempar keluar
                // sehingga DB::transaction me-rollback saldo & transaksi di atas.
                $this->danaKebajikanService->sinkronkanTransaksiTunaiTeller($transaksi);
            });
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->withErrors([
                'nominal' => 'Pemotongan Ta\'zir GAGAL dan seluruh perubahan dibatalkan: '.$e->getMessage(),
            ]);
        }

        return redirect()->back()->with('success', "Transaksi Ta'zir berhasil diproses dan dialokasikan ke Dana Kebajikan.");
    }
}
