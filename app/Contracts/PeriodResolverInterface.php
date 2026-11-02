<?php

namespace App\Contracts;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * KONTRAK — RESOLVER PERIODE / TAHUN BUKU DINAMIS.
 *
 * Menghilangkan asumsi kaku "tahun buku = 1 Januari s.d. 31 Desember".
 * Koperasi dengan tahun buku non-kalender (mis. Juli–Juni) atau periode pendek
 * (tahun pertama yang hanya beberapa bulan) dapat dilayani tanpa mengubah kode,
 * karena seluruh batas periode dihitung dari parameter kebijakan.
 *
 * Implementasi bawaan: `App\Services\TahunBukuService`.
 */
interface PeriodResolverInterface
{
    /**
     * Bulan pertama tahun buku (1-12).
     */
    public function bulanMulai(): int;

    /**
     * Jumlah bulan dalam satu tahun buku (1-12).
     */
    public function jumlahBulan(): int;

    /**
     * Apakah tahun buku mengikuti tahun kalender (Jan–Des)?
     */
    public function kalender(): bool;

    /**
     * Tanggal awal periode (inklusif) untuk tahun buku tertentu.
     */
    public function awalPeriode(int $tahun): Carbon;

    /**
     * Tanggal akhir periode (inklusif) untuk tahun buku tertentu.
     */
    public function akhirPeriode(int $tahun): Carbon;

    /**
     * Tahun buku yang memuat tanggal tertentu.
     */
    public function tahunBukuDari(CarbonInterface|string $tanggal): int;

    /**
     * Jumlah bulan yang terlewati di antara dua tanggal (0 bila sama).
     */
    public function bulanAntara(CarbonInterface|string $dari, CarbonInterface|string $sampai): int;

    /**
     * Label periode yang dapat dibaca manusia untuk tahun buku tertentu.
     */
    public function label(int $tahun): string;
}
