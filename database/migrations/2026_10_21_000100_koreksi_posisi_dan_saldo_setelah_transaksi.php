<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * S-2 — Koreksi konvensi `posisi` dan `saldo_setelah` pada tabel `transaksi`.
 *
 * Latar belakang (hasil audit): seeder demo menulis `posisi` dengan dua
 * konvensi yang saling bertentangan. Konvensi resmi sistem (dipakai
 * TransactionLimitService, TellerController, AngsuranTransactionService,
 * ReversalService, dan Transaksi::isMasuk()) adalah SUDUT PANDANG KAS:
 *   - `posisi = 'Debet'`  → uang MASUK  → saldo rekening bertambah
 *   - `posisi = 'Kredit'` → uang KELUAR → saldo rekening berkurang
 * Karena akun simpanan adalah kewajiban/ekuitas, arahnya berlawanan dengan
 * jurnal: baris jurnal akun simpanan yang KREDIT berarti saldo anggota NAIK.
 *
 * Migrasi ini melakukan tiga hal, tanpa mengubah saldo rekening dan tanpa
 * mengubah jurnal:
 *   1. Menyelaraskan `posisi` dengan arah mutasi yang tercatat pada jurnal
 *      (net kredit − debet pada akun simpanan). Hanya transaksi berstatus
 *      Sukses yang disentuh.
 *   2. Melepaskan transaksi angsuran TUNAI dari rekening simpanan: jurnalnya
 *      mendebit Kas Teller (bukan simpanan), sehingga `rekening_id`
 *      dikosongkan, `posisi` menjadi 'Debet', dan `saldo_setelah` menjadi
 *      null — sama seperti perilaku AngsuranTransactionService untuk metode
 *      non-autodebet.
 *   3. Menghitung ulang `saldo_setelah` seluruh transaksi per rekening secara
 *      kronologis. Saldo awal diturunkan dari `rekening.saldo` saat ini
 *      dikurangi net seluruh mutasi, sehingga transaksi terakhir berakhir
 *      tepat pada `rekening.saldo`.
 *
 * Catatan: ketidaksesuaian antara `rekening.saldo` dan riwayat mutasi
 * (temuan S-3) TIDAK diperbaiki di sini; migrasi hanya memastikan rantai
 * `saldo_setelah` konsisten dan berakhir pada saldo rekening yang berlaku.
 */
return new class extends Migration
{
    /** Akun CoA yang merepresentasikan simpanan anggota. */
    private const KODE_AKUN_SIMPANAN = [
        '2-1001', // Simpanan Sukarela
        '2-1002', // Simpanan Wajib
        '2-1003', // Simpanan Berjangka
        '3-1001', // Simpanan Pokok
        '3-1002', // Simpanan Wajib (ekuitas)
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $this->selaraskanPosisiDenganJurnal();
            $this->lepaskanAngsuranTunaiDariRekening();
            $this->hitungUlangSaldoSetelah();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // 1. Kembalikan posisi transaksi setoran ke nilai lama ('Kredit').
            $kembaliKeKredit = [];
            foreach (['TRX-SP-', 'TRX-SW-', 'TRX-SS-'] as $prefix) {
                for ($i = 1; $i <= 5; $i++) {
                    $kembaliKeKredit[] = $prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                }
            }
            $kembaliKeKredit[] = 'TRX-SUKARELA-TOPDOWN-5';

            DB::table('transaksi')
                ->whereIn('no_transaksi', $kembaliKeKredit)
                ->update(['posisi' => 'Kredit']);

            // 2. Kembalikan posisi penarikan, ta'zir, dan auto-debit ke 'Debet'.
            $kembaliKeDebet = ['TRX-TK-0001', 'TRX-TK-0003', 'TRX-TAZIR-0001'];
            for ($i = 2; $i <= 10; $i++) {
                $kembaliKeDebet[] = 'TRX-AUTODEBIT-SW-5-2026-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            }
            for ($i = 1; $i <= 5; $i++) {
                $kembaliKeDebet[] = 'TRX-20261015-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            }

            DB::table('transaksi')
                ->whereIn('no_transaksi', $kembaliKeDebet)
                ->update(['posisi' => 'Debet']);

            // 3. Kembalikan angsuran tunai ke rekening pembiayaan semula.
            $angsuran = DB::table('transaksi as t')
                ->join('pembiayaan as p', 'p.id', '=', 't.pembiayaan_id')
                ->where('t.no_transaksi', 'like', 'TRX-ANG-%')
                ->whereNull('t.rekening_id')
                ->get(['t.id', 'p.rekening_id']);

            foreach ($angsuran as $row) {
                DB::table('transaksi')->where('id', $row->id)->update([
                    'rekening_id' => $row->rekening_id,
                    'posisi' => 'Kredit',
                    'saldo_setelah' => 0,
                ]);
            }

            // 4. `saldo_setelah` tidak dikembalikan: nilai lamanya adalah data
            //    yang justru salah (tidak nyambung antar transaksi), sehingga
            //    memulihkannya akan mengembalikan cacat data.
        });
    }

    /**
     * Selaraskan `posisi` dengan arah mutasi simpanan pada jurnal.
     * Net kredit − debet > 0 berarti saldo simpanan bertambah (Debet/Kas masuk).
     */
    private function selaraskanPosisiDenganJurnal(): void
    {
        $coaIds = DB::table('coas')
            ->whereIn('kode_akun', self::KODE_AKUN_SIMPANAN)
            ->pluck('id')
            ->all();

        if ($coaIds === []) {
            return;
        }

        $baris = DB::table('transaksi as t')
            ->join('jurnal as j', 'j.transaksi_id', '=', 't.id')
            ->join('jurnal_detail as d', 'd.jurnal_id', '=', 'j.id')
            ->whereIn('d.coa_id', $coaIds)
            ->whereNull('t.deleted_at')
            ->where('t.status_transaksi', 'Sukses')
            ->groupBy('t.id', 't.posisi')
            ->select('t.id', 't.posisi', DB::raw('SUM(d.kredit) - SUM(d.debet) as net'))
            ->get();

        foreach ($baris as $row) {
            $net = (float) $row->net;

            // Tanpa pergerakan neto pada akun simpanan → bukan mutasi simpanan.
            if (abs($net) < 0.005) {
                continue;
            }

            $seharusnya = $net > 0 ? 'Debet' : 'Kredit';

            if ($row->posisi !== $seharusnya) {
                DB::table('transaksi')->where('id', $row->id)->update(['posisi' => $seharusnya]);
            }
        }
    }

    /**
     * Angsuran tunai tidak memutasi rekening simpanan: jurnalnya mendebit Kas
     * Teller. Lepaskan `rekening_id` agar tidak ikut rantai saldo simpanan.
     */
    private function lepaskanAngsuranTunaiDariRekening(): void
    {
        $coaIds = DB::table('coas')
            ->whereIn('kode_akun', self::KODE_AKUN_SIMPANAN)
            ->pluck('id')
            ->all();

        $angsuran = DB::table('transaksi as t')
            ->whereIn('t.jenis_transaksi', ['Bayar Angsuran', 'Pembayaran Angsuran'])
            ->whereNotNull('t.rekening_id')
            ->whereNull('t.deleted_at')
            ->where('t.status_transaksi', 'Sukses')
            ->whereNotExists(function ($q) use ($coaIds) {
                $q->select(DB::raw(1))
                    ->from('jurnal as j')
                    ->join('jurnal_detail as d', 'd.jurnal_id', '=', 'j.id')
                    ->whereColumn('j.transaksi_id', 't.id')
                    ->whereIn('d.coa_id', $coaIds);
            })
            ->get(['t.id']);

        foreach ($angsuran as $row) {
            DB::table('transaksi')->where('id', $row->id)->update([
                'rekening_id' => null,
                'posisi' => 'Debet',
                'saldo_setelah' => null,
            ]);
        }
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
