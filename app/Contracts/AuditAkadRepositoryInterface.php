<?php

namespace App\Contracts;

use App\Models\Pembiayaan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * KONTRAK — DATA AKSES AUDIT PEMBIAYAAN & AKAD (DPS).
 *
 * Memisahkan kueri modul Audit Akad DPS (tabel terfilter, badge counter per
 * akad, total "Semua Akad", dan ringkasan per akad & status) dari controller.
 * Seluruh method memakai basis populasi yang sama, yaitu
 * `Pembiayaan::pascaPersetujuan()`, sehingga badge dan tabel tidak mungkin
 * memakai daftar status yang berbeda.
 *
 * Implementasi bawaan: `App\Repositories\AuditAkadRepository`.
 */
interface AuditAkadRepositoryInterface
{
    /**
     * Tabel utama: pembiayaan pasca-persetujuan dengan filter opsional.
     *
     * @return LengthAwarePaginator<int, Pembiayaan>
     */
    public function daftar(
        ?string $akad = null,
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null,
        int $perHalaman = 25
    ): LengthAwarePaginator;

    /**
     * Jumlah pembiayaan per jenis akad untuk badge tab.
     *
     * Filter akad sengaja TIDAK diterapkan agar setiap tab menghitung
     * grupnya sendiri.
     *
     * @return Collection<string, int|string>
     */
    public function hitungPerAkad(
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null
    ): Collection;

    /**
     * Total badge "Semua Akad" — basis sama dengan `hitungPerAkad()`.
     */
    public function total(
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null
    ): int;

    /**
     * Ringkasan jumlah kontrak dan total plafon per akad & status.
     *
     * @return Collection<int, Collection<string, mixed>|object>
     */
    public function ringkasanPerAkadDanStatus(): Collection;
}
