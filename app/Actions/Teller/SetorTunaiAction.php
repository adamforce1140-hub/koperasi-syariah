<?php

namespace App\Actions\Teller;

use App\Models\Anggota;
use App\Models\AuditLog;
use App\Models\AutodebitUsulan;
use App\Models\JenisSimpanan;
use App\Models\Rekening;
use App\Models\TazirPenangguhan;
use App\Services\AutodebitService;
use App\Services\AutoJournalingService;
use App\Services\SetoranAwalService;
use App\Services\TransactionLimitService;
use App\Support\NomorDokumen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Setoran Tunai Simpanan di loket teller.
 *
 * Mencakup: kuota transaksi, mutasi saldo terkunci, auto-journal sesuai jenis
 * simpanan, aktivasi anggota PENDING, dan pengusulan ta'zir susulan ke Pusat
 * Konfirmasi Auto-Debit (TIDAK memotong saldo). Validasi HTTP di controller.
 */
class SetorTunaiAction
{
    public function __construct(
        private readonly TransactionLimitService $limitService,
        private readonly AutoJournalingService $journalService,
        private readonly SetoranAwalService $setoranAwalService,
        private readonly AutodebitService $autodebitService,
    ) {}

    public function execute(Request $request): RedirectResponse
    {
        $rekening = Rekening::with('anggota')->findOrFail($request->rekening_id);

        // Jenis transaksi mengikuti jenis rekening agar setoran Simpanan Pokok/Wajib
        // ikut terhitung sebagai kas masuk pada Laporan Kas Harian Teller.
        // Klasifikasi tipe (pokok/wajib/sukarela/berjangka) dibaca dari PARAMETER
        // DINAMIS `jenis_simpanans`, sehingga pengurus dapat menambah jenis baru
        // tanpa harus mengubah kode ini. Akun KREDIT jurnal juga mengikuti parameter.
        $jenisSimpanan = $rekening->jenis_rekening;
        $tipeSimpanan = JenisSimpanan::tipe($jenisSimpanan);

        $jenisTransaksi = in_array($tipeSimpanan, [JenisSimpanan::TIPE_POKOK, JenisSimpanan::TIPE_WAJIB], true)
            ? $jenisSimpanan
            : 'Setoran Tunai';

        $result = $this->limitService->processTransactionWithLimitCheck(
            Auth::user(),
            'setor_tunai',
            (float) $request->jumlah,
            'tunai',
            [
                'no_transaksi' => NomorDokumen::kode('transaksi').'-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4)),
                'rekening_id' => $rekening->id,
                'anggota_id' => $rekening->anggota_id,
                'jenis_transaksi' => $jenisTransaksi,
                'posisi' => 'Debet',
                'jumlah' => (float) $request->jumlah,
                'saldo_setelah' => $rekening->saldo + (float) $request->jumlah,
                'catatan' => $request->catatan ?? 'Setor tunai',
                'waktu_transaksi' => now(),
            ]
        );

        if ($result['status'] === 'success') {
            $transaksi = $result['data']['transaksi'];

            // TAHAP 5 — INTEGRITAS SALDO & JURNAL: mutasi saldo dan posting jurnal
            // dijalankan dalam SATU transaksi basis data dengan baris rekening
            // dikunci (`lockForUpdate`). Dua alasan:
            //   1. Anti lost-update — dua setoran bersamaan tidak saling menimpa
            //      sehingga saldo tetap dapat direkonsiliasi dengan mutasi jurnal.
            //   2. Anti saldo-yatim — bila jurnal gagal, perubahan saldo ikut
            //      dibatalkan; saldo tidak pernah berubah tanpa jejak jurnal.
            try {
                DB::transaction(function () use ($rekening, $request, $jenisSimpanan, $tipeSimpanan, $transaksi) {
                    $rekeningTerkunci = Rekening::whereKey($rekening->id)->lockForUpdate()->firstOrFail();
                    $rekeningTerkunci->increment('saldo', (float) $request->jumlah);

                    $keterangan = 'Setor '.$jenisSimpanan.' '.($rekeningTerkunci->no_rekening ?? '')
                                .' - '.($rekeningTerkunci->anggota->nama_lengkap ?? 'Anggota');

                    $jurnal = $this->journalService->jurnalSetorSimpanan(
                        (float) $request->jumlah,
                        $keterangan,
                        $jenisSimpanan,
                        Auth::id(),
                        $transaksi->id ?? null,
                        $rekeningTerkunci->id,
                        $rekeningTerkunci->anggota_id,
                        $tipeSimpanan
                    );

                    // `post()` mengembalikan null (tanpa throw) bila CoA tidak
                    // ditemukan/nonaktif. Tanpa pemeriksaan ini saldo sudah
                    // bertambah padahal tidak ada jurnal → saldo yatim. Lempar
                    // agar DB::transaction rollback seluruh perubahan.
                    if ($jurnal === null) {
                        throw new \RuntimeException('Jurnal setoran simpanan gagal diposting (CoA tidak ditemukan atau nonaktif).');
                    }

                    return $jurnal;
                });
            } catch (\Throwable $e) {
                report($e);

                return back()->withErrors([
                    'jumlah' => 'Setoran dibatalkan: jurnal akuntansi gagal dibuat sehingga saldo tidak diubah. '
                                .$e->getMessage(),
                ])->withInput();
            }

            AuditLog::record(
                'Setoran Tunai',
                (float) $request->jumlah,
                $rekening,
                'Setoran tunai simpanan atas nama '.($rekening->anggota->nama_lengkap ?? 'Anggota')
            );

            // ── USULAN AUTODEBET TA'ZIR SUSULAN (BUKAN PEMOTONGAN) ──────────────
            // KEBIJAKAN: "tidak ada auto-debit tanpa konfirmasi pengguna". Saat
            // ini ada setoran tunai Simpanan Sukarela baru, tunggakan ta'zir
            // ber-flag PENDING_AUTODEBET_SUSULAN TIDAK lagi dipotong otomatis.
            // Sistem hanya MENGUSULKAN pemotongan ke Pusat Konfirmasi; pengguna
            // berwenang menekan konfirmasi sebelum saldo benar-benar dipotong.
            // Cash Basis Syariah tetap dijaga: usulan mencatat saldo terkini.
            /* Aktivasi anggota otomatis: bila anggota berstatus PENDING dan
             | setoran awal (Simpanan Pokok + Simpanan Wajib bulan ke-1) kini
             | terverifikasi di sistem akuntansi, ubah status menjadi ACTIVE.
             | Kegagalan aktivasi TIDAK membatalkan setoran yang sudah sah. */
            $pesanAktivasi = '';
            try {
                $anggotaSetor = $rekening->anggota()->first();

                if ($anggotaSetor && $anggotaSetor->status_anggota === Anggota::STATUS_PENDING) {
                    $hasil = $this->setoranAwalService->aktivasiJikaLengkap($anggotaSetor);

                    $pesanAktivasi = $hasil['diaktivasi']
                        ? ' Setoran awal lengkap — status anggota '.$anggotaSetor->no_anggota.' kini ACTIVE.'
                        : ' Aktivasi ditangguhkan: '.implode(' ', $hasil['alasan']);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // Usulkan ta'zir susulan bila memiliki tunggakan ber-flag pending.
            // TIDAK ada pemotongan di sini — hanya pencatatan usulan.
            $pesanAutodebet = '';
            try {
                $rekening->refresh();
                $anggotaUsulan = $rekening->anggota()->first();
                $tunggakanList = TazirPenangguhan::pendingAutodebetSusulan()
                    ->where('anggota_id', $rekening->anggota_id)
                    ->get();

                foreach ($tunggakanList as $tunggakan) {
                    $this->autodebitService->usulkan(
                        AutodebitUsulan::KANAL_TAZIR,
                        (int) $tunggakan->id,
                        $anggotaUsulan,
                        (float) $tunggakan->nominal_tazir,
                        [
                            'rekening_id' => $rekening->id,
                            'alasan' => AutodebitService::wajibKonfirmasi() ? 'MENUNGGU_KONFIRMASI' : 'AUTODEBET',
                            'catatan' => "Usulan ta'zir susulan dipicu setoran tunai teller.",
                            'referensi_tipe' => TazirPenangguhan::class,
                        ]
                    );
                }

                if ($tunggakanList->isNotEmpty()) {
                    // Teller TIDAK berwenang mengonfirmasi (izin:autodebit.konfirmasi
                    // hanya manajer/super_admin/admin), jadi pesan ini tidak boleh
                    // menyebut "Anda" — Teller hanya memicu usulannya.
                    $pesanAutodebet = ' '.$tunggakanList->count().
                        ' usulan pemotongan ta\'zir dibuat — menunggu konfirmasi di Pusat Konfirmasi Auto-Debit.';
                }
            } catch (\Throwable $e) {
                report($e);
            }

            return redirect()->route('teller.nota', $transaksi->id)
                ->with('success', 'Setoran tunai berhasil!'.$pesanAktivasi.$pesanAutodebet);
        }

        if ($result['status'] === 'PENDING_APPROVAL') {
            return redirect()->route('teller.riwayat')->with('warning', $result['message']);
        }

        return back()->withErrors(['jumlah' => $result['message'] ?? 'Transaksi gagal.'])->withInput();
    }
}
