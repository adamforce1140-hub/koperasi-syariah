<?php

namespace App\Actions\Teller;

use App\Models\Angsuran;
use App\Models\AuditLog;
use App\Models\TazirPenangguhan;
use App\Models\TazirReliefRequest;
use App\Services\AngsuranTransactionService;
use App\Services\TazirService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Pembayaran Angsuran oleh teller.
 *
 * Guard status angsuran diperiksa SEBELUM validasi HTTP (perilaku lama
 * dipertahankan) agar pesan "sudah dibayar" tetap muncul lebih dulu.
 */
class BayarAngsuranAction
{
    public function __construct(
        private readonly AngsuranTransactionService $angsuranService,
        private readonly TazirService $tazirService,
    ) {}

    public function execute(Request $request, Angsuran $angsuran): RedirectResponse
    {
        if ($angsuran->isSudahDibayar()) {
            return back()->withErrors(['angsuran' => 'Angsuran ini sudah dibayar.']);
        }

        // Angsuran yang masih berstatus 'Dibayar Sebagian' belum lunas; sisa
        // kekurangan pokoknya wajib diselesaikan lebih dahulu sebelum angsuran
        // berikutnya diproses.
        if ($angsuran->status_angsuran === 'Dibayar Sebagian') {
            return back()->withErrors([
                'angsuran' => 'Angsuran ini masih berstatus Dibayar Sebagian (sisa pokok Rp '.
                    number_format((float) $angsuran->sisa_pokok, 0, ',', '.').
                    '). Lunasi kekurangannya terlebih dahulu.',
            ]);
        }

        $request->validate([
            'jumlah_bayar' => 'required|numeric|gt:0',
            'nominal_tazir' => 'nullable|numeric|min:0',
            'metode_bayar' => 'nullable|in:TUNAI,AUTODEBET',
            'ajukan_keringanan' => 'nullable|boolean',
            'alasan_keringanan' => 'nullable|string|max:500',
        ], [
            'jumlah_bayar.required' => 'Jumlah bayar tidak terkirim. Muat ulang halaman lalu pilih anggota kembali.',
            'jumlah_bayar.gt' => 'Jumlah bayar harus lebih besar dari nol.',
        ]);

        $ajukanKeringanan = $request->boolean('ajukan_keringanan');

        // Jika mengajukan keringanan, alasan wajib diisi
        if ($ajukanKeringanan && empty($request->alasan_keringanan)) {
            return back()->withErrors(['alasan_keringanan' => 'Alasan keringanan ta\'zir wajib diisi.'])->withInput();
        }

        // Cegah duplikasi pengajuan yang masih berjalan untuk angsuran yang sama
        if ($ajukanKeringanan) {
            $sudahAda = TazirReliefRequest::where('jadwal_angsuran_id', $angsuran->id)
                ->whereIn('status', [
                    TazirReliefRequest::STATUS_PENDING_AO,
                    TazirReliefRequest::STATUS_PENDING_MANAJER,
                ])
                ->exists();

            if ($sudahAda) {
                return back()->withErrors([
                    'angsuran' => 'Pengajuan keringanan untuk angsuran ini sudah ada dan sedang diproses (AO/Manajer).',
                ])->withInput();
            }
        }

        // Ta'zir awal = hasil kalkulasi sistem (TazirService)
        $nominalTazirAwal = (float) $this->tazirService->calculate($angsuran);

        // Tunggakan ta'zir dari keringanan yang DITOLAK Manajer (carry forward).
        // Nilai diambil dari DB (server-side), bukan dari input klien.
        $tunggakanRecords = TazirPenangguhan::belumDibayar()
            ->where('pembiayaan_id', $angsuran->pembiayaan_id)
            ->get();
        $totalTunggakan = (float) $tunggakanRecords->sum('nominal_tazir');

        // Jika mengajukan keringanan → ta'zir DITANGGUHKAN (Rp 0) menunggu keputusan AO & Manajer
        $nominalTazir = $ajukanKeringanan
            ? 0.0
            : (float) ($request->nominal_tazir ?? $nominalTazirAwal);

        // Normalisasi metode bayar (form bisa kirim 'kas'/'tabungan' lama atau 'TUNAI'/'AUTODEBET' baru)
        $metodeBayarRaw = strtoupper($request->metode_bayar ?? 'TUNAI');
        $metode = match ($metodeBayarRaw) {
            'TABUNGAN', 'AUTODEBET' => 'AUTODEBET',
            default => 'TUNAI',
        };

        $result = null;
        $reliefRequest = null;

        try {
            DB::transaction(function () use (
                $angsuran, $request, $metode, $nominalTazir,
                $ajukanKeringanan, $nominalTazirAwal, $totalTunggakan,
                &$result, &$reliefRequest
            ) {
                $result = $this->angsuranService->proses($angsuran, [
                    'jumlah_bayar' => (float) ($request->jumlah_bayar ?? $angsuran->jumlah_angsuran),
                    'nominal_tazir' => $nominalTazir,
                    'tunggakan_tazir' => $totalTunggakan,
                    'metode_bayar' => $metode,
                    'dibebaskan_tazir' => false,
                    'tazir_ditangguhkan' => $ajukanKeringanan,
                    'alasan_keringanan' => $request->alasan_keringanan ?? '',
                    'user_id' => Auth::id(),
                ]);

                // Simpan pengajuan keringanan → status PENDING_AO (verifikasi AO)
                if ($ajukanKeringanan) {
                    $reliefRequest = TazirReliefRequest::create([
                        'jadwal_angsuran_id' => $angsuran->id,
                        'pembiayaan_id' => $angsuran->pembiayaan_id,
                        'anggota_id' => $angsuran->pembiayaan->anggota_id,
                        'nominal_tazir_awal' => $nominalTazirAwal,
                        'nominal_tazir_disetujui' => 0,
                        'alasan_teller' => $request->alasan_keringanan,
                        'teller_id' => Auth::id(),
                        'status' => TazirReliefRequest::STATUS_PENDING_AO,
                    ]);
                }
            });
        } catch (\Exception $e) {
            return back()->withErrors(['angsuran' => $e->getMessage()])->withInput();
        }

        $pembiayaan = $angsuran->pembiayaan;
        AuditLog::record(
            'Pembayaran Angsuran',
            $result['total_dibayar'],
            $pembiayaan->rekening ?? ($pembiayaan->anggota->rekening->first() ?? null),
            "Pembayaran angsuran ke-{$angsuran->angsuran_ke} oleh ".
            ($pembiayaan->anggota->nama_lengkap ?? 'Anggota').
            " ({$pembiayaan->no_pembiayaan})".
            ($nominalTazir > 0 ? " + Ta'zir Rp ".number_format($nominalTazir, 0, ',', '.') : '').
            ($totalTunggakan > 0 ? " + Tunggakan Ta'zir Rp ".number_format($totalTunggakan, 0, ',', '.') : '').
            " via {$metode}".
            ($ajukanKeringanan ? " | Pengajuan keringanan Ta'zir Rp ".number_format($nominalTazirAwal, 0, ',', '.') : '')
        );

        $successMsg = 'Pembayaran angsuran berhasil diproses!';
        if ($nominalTazir > 0) {
            $successMsg .= " Ta'zir Rp ".number_format($nominalTazir, 0, ',', '.').' dialokasikan ke Dana Kebajikan (2-3001).';
        }
        if ($totalTunggakan > 0) {
            $successMsg .= " Tunggakan Ta'zir Rp ".number_format($totalTunggakan, 0, ',', '.').
                           ' (keringanan ditolak) telah DILUNASI dan dibukukan ke Dana Kebajikan (2-3001).';
        }
        if ($ajukanKeringanan) {
            $successMsg .= " Pengajuan keringanan Ta'zir Rp ".number_format($nominalTazirAwal, 0, ',', '.').
                           " dikirim ke AO untuk verifikasi. Ta'zir ditangguhkan sampai ada keputusan.";
        }

        return redirect()->route('teller.nota', $result['transaksi_id'])->with('success', $successMsg);
    }
}
