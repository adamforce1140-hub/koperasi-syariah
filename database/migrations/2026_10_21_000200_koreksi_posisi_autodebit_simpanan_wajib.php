<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S-2 (residu) — Koreksi `posisi` transaksi pemindahan dana antar simpanan.
 *
 * Auto-debit simpanan wajib yang sumbernya rekening Simpanan Sukarela
 * dijurnal sebagai:
 *     2-1001 Simpanan Sukarela  DEBET   (saldo sukarela berkurang)
 *     3-1002 Simpanan Wajib     KREDIT  (saldo wajib bertambah)
 * Net antar akun simpanan = 0, sehingga migrasi sebelumnya
 * (2026_10_21_000100) melewatinya. Padahal dari sudut pandang rekening
 * yang tercatat di `transaksi.rekening_id` (rekening Sukarela), dana
 * KELUAR → `posisi` seharusnya 'Kredit', bukan 'Debet'.
 *
 * Migrasi ini hanya membalik `posisi` 14 transaksi tersebut dan menghitung
 * ulang `saldo_setelah` rekening terkait. Saldo rekening dan jurnal tidak
 * diubah.
 */
return new class extends Migration
{
    private const JENIS = ['Setoran Simpanan Wajib', 'Auto-Debit Simpanan Wajib'];

    public function up(): void
    {
        DB::transaction(function () {
            $ids = $this->transaksiSukarela('Debet');

            if ($ids === []) {
                return;
            }

            DB::table('transaksi')->whereIn('id', $ids)->update(['posisi' => 'Kredit']);

            $this->hitungUlangSaldoSetelah();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = $this->transaksiSukarela('Kredit');

            if ($ids === []) {
                return;
            }

            DB::table('transaksi')->whereIn('id', $ids)->update(['posisi' => 'Debet']);

            $this->hitungUlangSaldoSetelah();
        });
    }

    /**
     * Transaksi pemindahan dana ke simpanan wajib yang dibebankan pada
     * rekening Simpanan Sukarela, dengan posisi tertentu.
     */
    private function transaksiSukarela(string $posisi): array
    {
        return DB::table('transaksi as t')
            ->join('rekening as r', 'r.id', '=', 't.rekening_id')
            ->whereIn('t.jenis_transaksi', self::JENIS)
            ->where('r.jenis_rekening', 'Simpanan Sukarela')
            ->where('t.posisi', $posisi)
            ->where('t.status_transaksi', 'Sukses')
            ->whereNull('t.deleted_at')
            ->pluck('t.id')
            ->all();
    }

    /**
     * Hitung ulang `saldo_setelah` per rekening secara kronologis.
     * Saldo awal = saldo rekening sekarang − net seluruh mutasi, sehingga
     * transaksi terakhir berakhir tepat di `rekening.saldo`.
     */
    private function hitungUlangSaldoSetelah(): void
    {
        $rekeningIds = DB::table('transaksi')
            ->whereNotNull('rekening_id')
            ->whereNull('deleted_at')
            ->where('status_transaksi', 'Sukses')
            ->distinct()
            ->pluck('rekening_id');

        foreach ($rekeningIds as $rekeningId) {
            $saldoSekarang = (float) DB::table('rekening')->where('id', $rekeningId)->value('saldo');

            $transaksi = DB::table('transaksi')
                ->where('rekening_id', $rekeningId)
                ->whereNull('deleted_at')
                ->where('status_transaksi', 'Sukses')
                ->orderBy('waktu_transaksi')
                ->orderBy('id')
                ->get(['id', 'posisi', 'jumlah']);

            if ($transaksi->isEmpty()) {
                continue;
            }

            $net = 0.0;
            foreach ($transaksi as $trx) {
                $net += $this->efekSaldo($trx);
            }

            $saldo = round($saldoSekarang - $net, 2);

            foreach ($transaksi as $trx) {
                $saldo = round($saldo + $this->efekSaldo($trx), 2);

                DB::table('transaksi')->where('id', $trx->id)->update(['saldo_setelah' => $saldo]);
            }
        }
    }

    /** Efek satu transaksi terhadap saldo rekening: Debet menambah, Kredit mengurangi. */
    private function efekSaldo(object $trx): float
    {
        $jumlah = (float) $trx->jumlah;

        return $trx->posisi === 'Debet' ? $jumlah : -$jumlah;
    }
};
