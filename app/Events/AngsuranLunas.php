<?php

namespace App\Events;

use App\Models\Angsuran;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa: sebuah angsuran dinyatakan LUNAS (dibayar penuh).
 *
 * Dikirim dari `AngsuranTransactionService` setelah transaksi pembayaran
 * angsuran berhasil. Dipakai sebagai titik ekstensi untuk notifikasi/audit
 * tanpa menambah coupling ke dalam alur pembayaran itu sendiri.
 *
 * SIFAT: HANYA INFORMASI. Tidak mengubah nilai pembayaran, denda, atau jurnal.
 */
class AngsuranLunas
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Angsuran $angsuran,
        public readonly ?float $nominalBayar = null,
        public readonly ?int $userId = null,
    ) {}
}
