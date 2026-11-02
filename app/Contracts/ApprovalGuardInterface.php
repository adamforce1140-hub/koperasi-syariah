<?php

namespace App\Contracts;

/**
 * KONTRAK — PENJAGA PEMISAHAN TUGAS (Maker ≠ Checker).
 *
 * Menjamin satu orang tidak dapat memutuskan (menyetujui/menolak) pengajuan
 * yang ia ajukan sendiri, walaupun perannya berwenang. Seluruh modul approval
 * (limit transaksi, pengeluaran beban, keringanan ta'zir, restrukturisasi,
 * verifikasi AML) bergantung pada antarmuka ini agar definisi & pesan aturan
 * seragam.
 *
 * Implementasi bawaan: `App\Support\PemisahanTugas`.
 */
interface ApprovalGuardInterface
{
    /**
     * Pesan penolakan standar saat aturan dilanggar.
     */
    public function pesan(): string;

    /**
     * Apakah aturan maker ≠ checker dilanggar?
     *
     * @param  int|string|null  $idPemohon  ID pengaju (created_by/ao_id/teller_id/diajukan_oleh)
     * @param  int|string|null  $idPemutus  ID pihak yang hendak memutuskan
     */
    public function dilanggar(int|string|null $idPemohon, int|string|null $idPemutus): bool;

    /**
     * Varian multi-kolom: pelanggaran bila SALAH SATU kolom pengaju sama
     * dengan pihak pemutus.
     *
     * @param  array<int, int|string|null>  $idPemohonList
     */
    public function dilanggarSalahSatu(array $idPemohonList, int|string|null $idPemutus): bool;
}
