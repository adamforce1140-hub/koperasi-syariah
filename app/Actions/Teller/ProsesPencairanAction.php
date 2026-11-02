<?php

namespace App\Actions\Teller;

use App\Models\JenisSimpanan;
use App\Models\Pembiayaan;
use App\Models\PemetaanAkadCoa;
use App\Models\Rekening;
use App\Models\Transaksi;
use App\Services\AutoJournalingService;
use App\Support\PemisahanTugas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Use-case Pencairan Pembiayaan oleh teller.
 *
 * Validasi HTTP dilakukan di controller; action ini menangani guard status,
 * pencatatan mutasi, perubahan status pembiayaan, dan posting jurnal.
 */
class ProsesPencairanAction
{
    public function __construct(
        private readonly AutoJournalingService $journalService,
    ) {}

    public function execute(Request $request, Pembiayaan $pembiayaan): RedirectResponse
    {
        /*
         * Hanya akad berstatus DISETUJUI yang boleh dicairkan.
         *
         * Sebelumnya STATUS_DICAIRKAN ikut diterima — padahal status itu justru
         * berarti "sudah dicairkan" (jalur DisbursementService). Akibatnya satu
         * akad dapat dicairkan dua kali: sekali lewat /teller/pencairan-disetujui
         * (status jadi `dicairkan`) lalu sekali lagi lewat /teller/pencairan
         * (status `dicairkan` masih dianggap siap cair). Kas keluar dua kali
         * sementara jadwal angsuran tetap satu.
         */
        if (! $pembiayaan->isStatus([Pembiayaan::STATUS_DISETUJUI])) {
            return back()->with('error', 'Pembiayaan ini tidak dalam status siap cair.');
        }

        if ((bool) $pembiayaan->is_disbursed || $pembiayaan->tgl_dicairkan !== null) {
            return back()->with('error', 'Pembiayaan ini sudah pernah dicairkan.');
        }

        $noTrx = 'PCR-'.date('YmdHis').'-'.strtoupper(substr(uniqid(), -4));

        /*
         * Seluruh proses (catat transaksi, ubah status, posting jurnal) dibungkus
         * SATU transaksi database. Jurnal yang gagal WAJIB membatalkan pencairan:
         * bila dana cair tanpa jurnal, neraca menjadi timpang dan saldo anggota
         * tidak dapat dipertanggungjawabkan (integritas data).
         */
        try {
            DB::transaction(function () use ($request, $pembiayaan, $noTrx) {
                // Kunci baris untuk mencegah pencairan ganda (double disbursement).
                $pembiayaan = Pembiayaan::whereKey($pembiayaan->id)->lockForUpdate()->firstOrFail();

                if (! $pembiayaan->isStatus([Pembiayaan::STATUS_DISETUJUI])
                    || (bool) $pembiayaan->is_disbursed
                    || $pembiayaan->tgl_dicairkan !== null) {
                    throw new \RuntimeException('Pembiayaan ini sudah pernah dicairkan.');
                }

                // GCG: pemisahan tugas — penyetuju & pengaju tidak boleh mencairkan sendiri.
                if (PemisahanTugas::dilanggar($pembiayaan->disetujui_oleh, Auth::id())) {
                    throw new \RuntimeException(PemisahanTugas::PESAN);
                }

                $pengaju = DB::table('pengajuan_pembiayaan')
                    ->where('pembiayaan_id', $pembiayaan->id)
                    ->select('ao_id', 'created_by')
                    ->first();

                if ($pengaju !== null && PemisahanTugas::dilanggarSalahSatu([$pengaju->ao_id, $pengaju->created_by], Auth::id())) {
                    throw new \RuntimeException(PemisahanTugas::PESAN);
                }

                // Catat transaksi pencairan
                $transaksi = Transaksi::create([
                    'no_transaksi' => $noTrx,
                    'anggota_id' => $pembiayaan->anggota_id,
                    'jenis_transaksi' => 'Pencairan Pembiayaan',
                    'posisi' => 'Kredit',
                    'jumlah' => $pembiayaan->jumlah_pembiayaan,
                    'saldo_setelah' => 0,
                    'catatan' => $request->catatan ?? 'Pencairan pembiayaan #'.$pembiayaan->id,
                    'waktu_transaksi' => now(),
                    'user_id' => Auth::id(),
                    'status_transaksi' => 'Sukses',
                ]);

                /*
                 * Status akhir WAJIB 'dicairkan' — sama dengan jalur
                 * DisbursementService (sumber kebenaran pencairan) dan sesuai
                 * peta transisi config/alur_pembiayaan.php
                 * ('disetujui' → 'dicairkan'). Sebelumnya aksi ini menulis
                 * 'aktif', yang melompati satu tahap state machine sehingga
                 * laporan yang memisahkan "baru cair" dan "berjalan" menjadi
                 * tidak konsisten antar jalur.
                 */
                $pembiayaan->update([
                    'status_pembiayaan' => Pembiayaan::STATUS_DICAIRKAN,
                    'tanggal_cair' => now()->toDateString(),
                    'tgl_dicairkan' => now(),
                    'is_disbursed' => true,
                ]);

                // Trigger auto journaling: DEBET Piutang Pembiayaan | KREDIT Simpanan Sukarela
                $rekeningAnggota = Rekening::where('anggota_id', $pembiayaan->anggota_id)
                    ->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))
                    ->value('id');

                $jurnal = $this->journalService->jurnalPencairan(
                    (float) $pembiayaan->jumlah_pembiayaan,
                    $pembiayaan->akad_pembiayaan ?? Pembiayaan::akadDefault(),
                    'Pencairan pembiayaan '.($pembiayaan->no_pembiayaan ?? '#'.$pembiayaan->id),
                    Auth::id(),
                    $transaksi->id,
                    $pembiayaan->id,
                    $rekeningAnggota,
                    $pembiayaan->anggota_id,
                    0.0,
                    null,
                    null,
                    $pembiayaan->produk?->kode_produk ?: PemetaanAkadCoa::SEMUA_PRODUK,
                );

                // `post()` mengembalikan null (bukan throw) bila CoA tidak ditemukan
                // atau akun nonaktif → wajib di-throw agar transaksi di-rollback.
                if ($jurnal === null) {
                    throw new \RuntimeException('Jurnal pencairan gagal diposting (CoA tidak ditemukan atau nonaktif). Pencairan dibatalkan.');
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Pencairan gagal: '.$e->getMessage());
        }

        return redirect()->route('teller.pencairan.index')
            ->with('success', "Pencairan berhasil diproses. No. Transaksi: {$noTrx}");
    }
}
