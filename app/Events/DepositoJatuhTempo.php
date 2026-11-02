<?php

namespace App\Events;

use App\Models\Deposito;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa: sebuah deposito mencapai tanggal jatuh tempo.
 *
 * Dikirim dari `ProcessMaturedDeposito` (command penjadwal) untuk setiap
 * deposito yang diproses jatuh tempo.
 *
 * SIFAT: HANYA INFORMASI. Perhitungan bagi hasil & pencairan deposito tetap
 * sepenuhnya ditangani service deposito; peristiwa ini tidak menambah
 * perhitungan apa pun.
 */
class DepositoJatuhTempo
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Deposito $deposito,
        public readonly ?int $userId = null,
    ) {}
}
