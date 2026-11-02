<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0 — Rapikan `saldo_setelah` transaksi setelah data demo di-seed ulang.
 *
 * Latar belakang (hasil audit lintas peran): kolom `transaksi.saldo_setelah`
 * dipakai langsung oleh portal anggota (`nasabah/mutasi.blade.php`), riwayat
 * teller, slip transaksi, dan dasbor. Nilainya harus merupakan saldo
 * berjalan rekening pada saat transaksi terjadi.
 *
 * Ditemukan dua cacat:
 *   1. `ModulSimpananSeeder` membuat transaksi `TRX-SEED-*` tanpa mengisi
 *      `saldo_setelah` → nilainya NULL sehingga portal anggota menampilkan
 *      "Rp 0" pada kolom saldo.
 *   2. `ModulSimpananSeeder::rapikanTanggalDemo()` memindahkan
 *      `waktu_transaksi` transaksi demo ke tanggal historis tanpa menghitung
 *      ulang `saldo_setelah`, sehingga rantai saldo berjalan tidak lagi
 *      kronologis (mis. `100-2026-000001`: #18 tercatat Rp 11.500.000
 *      padahal seharusnya Rp 10.000.000).
 *
 * Migrasi ini HANYA menghitung ulang `saldo_setelah`. Ia tidak mengubah
 * `rekening.saldo`, nominal transaksi, `posisi`, maupun jurnal — sehingga
 * tidak ada dampak akuntansi. Algoritmanya sama dengan
 * `2026_10_21_000100_koreksi_posisi_dan_saldo_setelah_transaksi`:
 * saldo awal diturunkan dari `rekening.saldo` sekarang dikurangi net seluruh
 * mutasi, sehingga transaksi terakhir berakhir tepat pada saldo rekening.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->hitungUlangSaldoSetelah();
        });
    }

    public function down(): void
    {
        // Nilai lama adalah data yang justru salah (tidak nyambung antar
        // transaksi, sebagian NULL). Memulihkannya akan mengembalikan cacat
        // data, jadi tidak ada rollback untuk kolom ini.
    }

    /**
     * Hitung ulang `saldo_setelah` per rekening secara kronologis.
     * Saldo awal = saldo rekening sekarang − net seluruh mutasi.
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
                ->get(['id', 'posisi', 'jumlah', 'saldo_setelah']);

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

                $lama = $trx->saldo_setelah === null ? null : (float) $trx->saldo_setelah;

                // Hanya tulis bila memang berbeda — baris yang sudah benar
                // tidak disentuh agar migrasi tidak menimbulkan diff palsu.
                if ($lama === null || abs($lama - $saldo) > 0.009) {
                    DB::table('transaksi')->where('id', $trx->id)->update(['saldo_setelah' => $saldo]);
                }
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
