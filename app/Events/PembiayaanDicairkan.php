<?php

namespace App\Events;

use App\Models\Pembiayaan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa: sebuah pembiayaan selesai dicairkan (dropping).
 *
 * Dikirim dari `DisbursementService` SETELAH transaksi database pencairan
 * berhasil di-commit, sehingga listener selalu melihat data final.
 *
 * SIFAT: HANYA INFORMASI (audit/notifikasi). Tidak ada efek uang/jurnal baru
 * yang boleh timbul dari peristiwa ini.
 */
class PembiayaanDicairkan
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Pembiayaan  $pembiayaan  Baris pembiayaan yang dicairkan.
     * @param  string  $metode  Skema pencairan (cash | transfer_bank | transfer_savings).
     * @param  float  $nominal  Nominal pokok yang dicairkan.
     * @param  int|null  $userId  Petugas yang memproses.
     */
    public function __construct(
        public readonly Pembiayaan $pembiayaan,
        public readonly string $metode,
        public readonly float $nominal,
        public readonly ?int $userId = null,
    ) {}
}
