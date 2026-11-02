<?php

namespace App\Services\Jurnal;

use App\Models\Jurnal;
use App\Support\Coa;

/**
 * RESEP JURNAL DOMAIN TA'ZIR & DANA KEBAJIKAN.
 *
 * Seluruh method di sini hanya MENYUSUN baris jurnal (resep), lalu
 * menyerahkannya ke `PostingJurnal` — mesin inti yang menjadi satu-satunya
 * gerbang validasi, keseimbangan, cut-off periode, dan penulisan buku besar.
 * Dengan begitu kontrol PSAK 108/109 tidak pernah terduplikasi.
 *
 * Pemisahan domain ini behavior-preserving: `AutoJournalingService` tetap
 * menyediakan method yang sama dan mendelegasikannya ke kelas ini.
 */
class JournalKebajikan
{
    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Pemotongan Ta'zir (denda keterlambatan).
     *
     * DEBET : Simpanan Sukarela Anggota (2-1001)
     * KREDIT: Dana Kebajikan (2-3001)
     *
     * Ta'zir adalah dana sosial — TIDAK menjadi pendapatan koperasi.
     */
    public function jurnalTazir(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA, 'debet' => $nominal],
            ['kode' => Coa::DANA_KEBAJIKAN,    'kredit' => $nominal],
        ], "Ta'zir", $transaksiId, null, $userId, $rekeningId, $anggotaId, null, PostingJurnal::POST_TYPE_TAAZIR);
    }

    /**
     * Reversal Penerimaan Dana Kebajikan (void transaksi ta'zir / denda / infak).
     *
     * Membalik jurnal asal dengan posisi DEBET 2-3001 (mengurangi dana terkumpul).
     * Sisi KREDIT menyesuaikan sumber dana asal:
     *  - Auto-debet anggota → 2-1001 Simpanan Sukarela (saldo anggota dikembalikan)
     *  - Tunai di loket     → 1-1001 Kas Teller (kas dikembalikan)
     *
     * Posisi DEBET 2-3001 di sini SAH karena merupakan pembalikan (pengurangan)
     * dana yang sebelumnya masuk — bukan penyaluran baru.
     *
     * Catatan pemisahan (PSAK 108/109): jurnal ini SENGAJA tidak diberi
     * POST_TYPE_TAAZIR karena sisi KREDIT-nya mengembalikan dana ke Simpanan
     * Sukarela / Kas Teller (bukan ke 2-3001). Validasi mapping POST_TYPE_TAAZIR
     * mewajibkan KREDIT ke 2-3001 sehingga akan menolak reversal yang sah ini.
     * Reversal tetap tunduk pada aturan pemisahan universal (lapis 1 & 3).
     */
    public function jurnalReversalTazir(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        string $kodeKredit = Coa::SIMPANAN_SUKARELA
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $kodeKredit,         'kredit' => $nominal],
            ['kode' => Coa::DANA_KEBAJIKAN, 'debet' => $nominal],
        ], 'Reversal Dana Kebajikan', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }

    /**
     * Penerimaan Dana Kebajikan (ta'zir / denda / infak / kontribusi kebajikan).
     *
     * Sisi DEBET menyesuaikan sumber dana:
     *  - Tunai di loket → 1-1001 Kas Teller (uang kas diterima)
     * Sisi KREDIT SELALU 2-3001 (Dana Kebajikan) — dana masuk/terkumpul.
     */
    public function jurnalPenerimaanDanaKebajikan(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        string $sumber = 'Infak / Kontribusi Kebajikan',
        string $kodeDebet = Coa::KAS_TELLER
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => $kodeDebet,          'debet' => $nominal],
            ['kode' => Coa::DANA_KEBAJIKAN, 'kredit' => $nominal],
        ], $sumber, $transaksiId, null, $userId, $rekeningId, $anggotaId, null, PostingJurnal::POST_TYPE_DONATION);
    }

    /**
     * Penyaluran Dana Kebajikan kepada penerima yang berhak (mustahiq).
     *
     * DEBET : 2-3001 (Dana Kebajikan) — dana keluar
     * KREDIT: 1-1001 (Kas Teller) / akun sumber dana
     *
     * Method ini satu-satunya jalur resmi yang memakai posisi DEBET 2-3001.
     */
    public function jurnalPenyaluranDanaKebajikan(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        string $kodeSumberDana = Coa::KAS_TELLER
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::DANA_KEBAJIKAN, 'debet' => $nominal],
            ['kode' => $kodeSumberDana,     'kredit' => $nominal],
        ], PostingJurnal::SUMBER_PENYALURAN_DANA_KEBAJIKAN, $transaksiId, null, $userId);
    }
}
