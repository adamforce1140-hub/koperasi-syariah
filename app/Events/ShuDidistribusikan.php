<?php

namespace App\Events;

use App\Models\ShuPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa: SHU (Sisa Hasil Usaha) sebuah periode selesai didistribusikan.
 *
 * Dikirim dari modul SHU setelah distribusi tersimpan. Titik ekstensi untuk
 * audit trail & notifikasi anggota, tanpa menambah coupling pada alur
 * perhitungan SHU.
 *
 * SIFAT: HANYA INFORMASI. Tidak mengubah angka alokasi maupun jurnal.
 */
class ShuDidistribusikan
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ?ShuPeriod $periode = null,
        public readonly float $totalShu = 0.0,
        public readonly ?int $userId = null,
    ) {}
}
