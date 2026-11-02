<?php

namespace App\Services\Jurnal;

use App\Models\Jurnal;
use App\Support\Coa;

/**
 * Journaler domain Kas/Teller & Brankas.
 *
 * Menyusun resep baris jurnal untuk perpindahan kas fisik antara Brankas
 * Utama (Vault) dan Laci Teller, lalu menyerahkan eksekusi + validasi ke
 * {@see PostingJurnal} (satu-satunya gerbang kontrol PSAK).
 *
 * Catatan akuntansi: perpindahan kas antar-rekening aset BUKAN pendapatan,
 * hanya reklasifikasi antar akun Kas sehingga total aset tetap seimbang.
 */
class JournalKas
{
    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Transfer Kas Brankas (Vault) → Laci Teller (Injeksi Kas).
     *
     * DEBET : 1-1001 Kas Teller  = nominal injeksi
     * KREDIT: 1-1003 Kas Brankas Utama / Vault = nominal injeksi
     *
     * Dipakai saat teller membutuhkan tambahan uang tunai di awal/pertengahan
     * shift. Nominal divalidasi terhadap saldo Vault dan batas kas laci oleh
     * pemanggil (TellerController) memakai parameter dinamis.
     */
    public function jurnalInjeksiKasBrankas(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null): ?Jurnal
    {
        return $this->mesin->post(
            $keterangan ?: 'Injeksi kas dari Brankas Utama ke Laci Teller',
            [
                ['kode' => Coa::KAS_TELLER,    'debet' => $nominal],
                ['kode' => Coa::KAS_BRANKAS,   'kredit' => $nominal],
            ],
            'Injeksi Kas Brankas',
            $transaksiId,
            null,
            $userId
        );
    }

    /**
     * Transfer Kas Laci Teller → Brankas (Vault) (Setoran Hasil Kas Harian).
     *
     * DEBET : 1-1003 Kas Brankas Utama / Vault = nominal setoran
     * KREDIT: 1-1001 Kas Teller                 = nominal setoran
     *
     * Dipakai untuk memindahkan kelebihan kas di laci teller ke brankas utama,
     * mis. saat saldo laci melampaui `setting('teller_max_cash_limit')`.
     */
    public function jurnalSetorKasKeBrankas(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null): ?Jurnal
    {
        return $this->mesin->post(
            $keterangan ?: 'Setoran hasil kas harian dari Laci Teller ke Brankas Utama',
            [
                ['kode' => Coa::KAS_BRANKAS,   'debet' => $nominal],
                ['kode' => Coa::KAS_TELLER,    'kredit' => $nominal],
            ],
            'Setoran Kas ke Brankas',
            $transaksiId,
            null,
            $userId
        );
    }

    /**
     * Pembukaan Kas Teller (Modal Awal Shift) — R6.
     *
     * DEBET : 1-1001 Kas Teller    = modal awal
     * KREDIT: 1-1003 Kas Brankas   = modal awal
     *
     * Modal awal kas bukan pendapatan melainkan PEMINDAHAN kas fisik dari
     * brankas ke laci teller, sehingga wajib dijurnal agar total aset Kas
     * tetap seimbang. Sebelumnya `KasHarian::create()` mencatat modal awal
     * tanpa jurnal (temuan audit R6).
     */
    public function jurnalPembukaanKasTeller(float $nominal, string $keterangan, ?int $userId = null): ?Jurnal
    {
        return $this->mesin->post(
            $keterangan ?: 'Pembukaan kas teller (modal awal shift)',
            [
                ['kode' => Coa::KAS_TELLER,  'debet' => $nominal],
                ['kode' => Coa::KAS_BRANKAS, 'kredit' => $nominal],
            ],
            'Pembukaan Kas Teller',
            null,
            null,
            $userId
        );
    }

    /**
     * Reversal INJEKSI KAS Brankas → Laci Teller — R6.
     *
     * Membalik jurnal injeksi kas: DEBET Kas Brankas / KREDIT Kas Teller.
     */
    public function jurnalReversalInjeksiKas(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null): ?Jurnal
    {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_BRANKAS, 'debet' => $nominal],
            ['kode' => Coa::KAS_TELLER,  'kredit' => $nominal],
        ], 'Reversal Injeksi Kas Brankas', $transaksiId, null, $userId, null, null,
            null, null, null, null, null, false, true);
    }
}
