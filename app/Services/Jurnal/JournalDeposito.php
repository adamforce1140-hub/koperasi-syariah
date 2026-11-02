<?php

namespace App\Services\Jurnal;

use App\Models\Jurnal;
use App\Support\Coa;
use App\Support\Setting;

/**
 * RESEP JURNAL DOMAIN SIMPANAN BERJANGKA / DEPOSITO MUDHARABAH (PSAK 101/105/108).
 *
 * Seluruh method di sini hanya MENYUSUN baris jurnal (resep), lalu
 * menyerahkannya ke `PostingJurnal` — mesin inti yang menjadi satu-satunya
 * gerbang validasi, keseimbangan, cut-off periode, dan penulisan buku besar.
 *
 * Pemisahan domain ini behavior-preserving: `AutoJournalingService` tetap
 * menyediakan method yang sama dan mendelegasikannya ke kelas ini.
 */
class JournalDeposito
{
    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Setoran Simpanan Berjangka / Deposito Mudharabah (PSAK 105).
     *
     * DEBET : Kas Teller (1-1001)
     * KREDIT: Simpanan Berjangka/Deposito (2-1003)
     */
    public function jurnalSetorDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,         'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $nominal],
        ], 'Setoran Simpanan Berjangka', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Bagi Hasil Simpanan Berjangka / Deposito Mudharabah (PSAK 105/PSAK 101 ¶71).
     *
     * DEBET : Beban Bagi Hasil Deposito (5-3001)
     * KREDIT: Kas Teller (1-1001)
     *
     * Dicatat sebagai beban koperasi yang dibayarkan kepada anggota.
     */
    public function jurnalBagiHasilDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::BEBAN_BAGI_HASIL_DEPOSITO, 'debet' => $nominal],
            ['kode' => Coa::KAS_TELLER,                 'kredit' => $nominal],
        ], 'Bagi Hasil Simpanan Berjangka', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Penarikan / Pencairan Simpanan Berjangka / Deposito Mudharabah (PSAK 105).
     *
     * DEBET : Simpanan Berjangka/Deposito (2-1003)
     * KREDIT: Kas Teller (1-1001)
     */
    public function jurnalTarikDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'debet' => $nominal],
            ['kode' => Coa::KAS_TELLER,         'kredit' => $nominal],
        ], 'Penarikan Simpanan Berjangka', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /* ================= DEPOSITO MUDHARABAH (PSAK 101/108) =================
     |
     | Berbeda dari jurnal teller di atas yang memakai Kas Teller (1-1001),
     | modul deposito syariah memindahkan dana dari REKENING SIMPANAN SUKARELA
     | anggota. Kas Teller tidak boleh menjadi sumber dana deposito.
     */

    /**
     * Pembukaan Deposito Mudharabah — pemindahan dana dari Simpanan Sukarela.
     *
     * DEBET : 2-1001 Simpanan Sukarela Anggota (saldo anggota berkurang)
     * KREDIT: 2-1003 Simpanan Berjangka / Deposito (Dana Syirkah Temporer bertambah)
     */
    public function jurnalPembukaanDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA,  'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $nominal],
        ], 'Pembukaan Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Kapitalisasi Bagi Hasil ke Pokok Deposito saat perpanjangan otomatis
     * (ARO_PLUS).
     *
     * Bagi hasil anggota yang tersimpan pada REKENING SIMPANAN SUKARELA
     * (COA 2-1001) dipindahkan kembali menjadi Dana Syirkah Temporer
     * (COA 2-1003) sebagai pokok deposito yang diperpanjang.
     *
     * DEBET : 2-1001 Simpanan Sukarela Anggota   = Nominal bagi hasil
     * KREDIT: 2-1003 Simpanan Berjangka / Deposito = Nominal bagi hasil
     *
     * Catatan PSAK 108: dana syirkah temporer bertambah karena ada setoran
     * pokok baru dari anggota, bukan karena pengakuan beban. Jurnal ini murni
     * reklasifikasi antar pos kewajiban/dana syirkah — tidak menyentuh laba rugi.
     */
    public function jurnalKapitalisasiBagiHasilDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        if (Setting::bulatkanUang($nominal) <= 0) {
            return null;
        }

        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA,  'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $nominal],
        ], 'ARO — Kapitalisasi Bagi Hasil', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Transfer Bagi Hasil Deposito ke Simpanan Sukarela saat perpanjangan
     * otomatis (ARO_POKOK) atau pencairan (NON_ARO).
     *
     * DEBET : 5-3001 Beban Bagi Hasil Deposito Mudharabah = Nominal bagi hasil
     * KREDIT: 2-1001 Simpanan Sukarela Anggota            = Nominal bagi hasil
     *
     * Dipakai untuk mengakui bagi hasil yang belum pernah dijurnal (mis.
     * pembagian bagi hasil yang statusnya masih FAILED/tertunda).
     */
    public function jurnalTransferBagiHasilKeSukarela(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        if (Setting::bulatkanUang($nominal) <= 0) {
            return null;
        }

        return $this->mesin->post($keterangan, [
            ['kode' => Coa::BEBAN_BAGI_HASIL_DEPOSITO, 'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_SUKARELA,         'kredit' => $nominal],
        ], 'Perpanjangan Deposito (ARO) — Transfer Bagi Hasil', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Pencairan Pokok Deposito Mudharabah (non-ARO) — dana kembali ke Simpanan Sukarela.
     *
     * DEBET : 2-1003 Simpanan Berjangka / Deposito
     * KREDIT: 2-1001 Simpanan Sukarela Anggota
     */
    public function jurnalPencairanDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_SUKARELA,  'kredit' => $nominal],
        ], 'Pencairan Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Pencairan DINI Deposito Mudharabah (break deposito sebelum jatuh tempo).
     *
     * DEBET : 2-1003 Simpanan Berjangka / Deposito = Total Pokok yang dicairkan
     * KREDIT: 2-3001 Dana Kebajikan                = Biaya penalti (bila ada)
     * KREDIT: 2-1001 Simpanan Sukarela Anggota     = Pokok - Biaya Penalti
     *
     * R7 — Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000: penalti pencairan dini adalah
     * ta'zir (dana sosial), BUKAN pendapatan koperasi. Karena itu default akun
     * penalti adalah Dana Kebajikan (2-3001), dan akun penalti per produk
     * (`penalty_coa_id`) tetap diterima sebagai parameter selama bukan akun
     * pendapatan — lihat `DepositoProduct::coaPenalti()`.
     */
    public function jurnalPenaltiDeposito(
        float $pokok,
        float $biayaPenalti,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        string $kodePenalti = Coa::DANA_KEBAJIKAN
    ): ?Jurnal {
        $pokok = round($pokok, 0);
        $biayaPenalti = max(0.0, round($biayaPenalti, 0));

        if ($pokok <= 0) {
            return null;
        }

        $kembaliKeAnggota = $pokok - $biayaPenalti;

        $entries = [
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'debet' => $pokok],
        ];

        if ($biayaPenalti > 0) {
            $entries[] = ['kode' => $kodePenalti, 'kredit' => $biayaPenalti];
        }

        if ($kembaliKeAnggota > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_SUKARELA, 'kredit' => $kembaliKeAnggota];
        }

        return $this->mesin->post(
            $keterangan,
            $entries,
            'Pencairan Dini Deposito Mudharabah',
            $transaksiId,
            null,
            $userId,
            $rekeningId,
            $anggotaId
        );
    }

    /**
     * Pembagian Bagi Hasil Deposito Bulanan.
     *
     * DEBET : 5-3001 Beban Bagi Hasil Deposito Mudharabah
     * KREDIT: 2-1001 Simpanan Sukarela Anggota (bagi hasil langsung menambah saldo)
     *
     * Bagi hasil BUKAN bunga: nilainya berasal dari pendapatan koperasi atas
     * penempatan dana, dibagi sesuai nisbah — bukan nominal tetap yang dijamin.
     */
    public function jurnalBagiHasilDepositoMudharabah(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::BEBAN_BAGI_HASIL_DEPOSITO, 'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_SUKARELA,         'kredit' => $nominal],
        ], 'Bagi Hasil Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId);
    }

    /**
     * Reversal PEMBUKAAN DEPOSITO MUDHARABAH (dan Kapitalisasi Bagi Hasil ARO).
     *
     * Membalik jurnal pembukaan deposito:
     *   Asal : DEBET 2-1001 Simpanan Sukarela | KREDIT 2-1003 Berjangka.
     *   Balik: DEBET 2-1003 Berjangka         | KREDIT 2-1001 Simpanan Sukarela.
     *
     * Dipakai saat void transaksi berjenis `Pembukaan Deposito` maupun
     * `Kapitalisasi Bagi Hasil` (keduanya berpola reklasifikasi sukarela →
     * berjangka). Tanpa pemetaan ini, void hanya membalik saldo tanpa jurnal
     * sehingga buku besar tidak lagi seimbang dengan saldo anggota.
     */
    public function jurnalReversalPembukaanDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_SUKARELA,  'kredit' => $nominal],
        ], 'Reversal Pembukaan Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }

    /**
     * Reversal PENCAIRAN DEPOSITO MUDHARABAH (non-ARO, non-dini).
     *
     * Membalik jurnal pencairan deposito:
     *   Asal : DEBET 2-1003 Berjangka | KREDIT 2-1001 Simpanan Sukarela.
     *   Balik: DEBET 2-1001 Sukarela  | KREDIT 2-1003 Berjangka.
     */
    public function jurnalReversalPencairanDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA,  'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $nominal],
        ], 'Reversal Pencairan Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }

    /**
     * Reversal BAGI HASIL DEPOSITO MUDHARABAH (akrual/bulanan).
     *
     * Membalik jurnal pengakuan bagi hasil:
     *   Asal : DEBET 5-3001 Beban Bagi Hasil | KREDIT 2-1001 Simpanan Sukarela.
     *   Balik: DEBET 2-1001 Simpanan Sukarela | KREDIT 5-3001 Beban Bagi Hasil.
     */
    public function jurnalReversalBagiHasilDepositoMudharabah(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::SIMPANAN_SUKARELA,         'debet' => $nominal],
            ['kode' => Coa::BEBAN_BAGI_HASIL_DEPOSITO, 'kredit' => $nominal],
        ], 'Reversal Bagi Hasil Deposito Mudharabah', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }

    /**
     * Reversal PENARIKAN / PENCAIRAN SIMPANAN BERJANGKA (jalur teller).
     *
     * Membalik jurnal penarikan berjangka:
     *   Asal : DEBET 2-1003 Berjangka | KREDIT 1-1001 Kas Teller.
     *   Balik: DEBET 1-1001 Kas Teller | KREDIT 2-1003 Berjangka.
     *
     * Dipakai sebagai cadangan (fallback) untuk jenis transaksi lain yang
     * menyentuh Simpanan Berjangka namun tidak memiliki pemetaan reversal
     * khusus, agar void tetap menghasilkan jurnal pembalik yang seimbang.
     */
    public function jurnalReversalTarikDeposito(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->mesin->post($keterangan, [
            ['kode' => Coa::KAS_TELLER,         'debet' => $nominal],
            ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $nominal],
        ], 'Reversal Penarikan Simpanan Berjangka', $transaksiId, null, $userId, $rekeningId, $anggotaId,
            null, null, null, null, null, false, true);
    }

    /**
     * Reversal PENCAIRAN DINI DEPOSITO MUDHARABAH (break sebelum jatuh tempo).
     *
     * Membalik jurnal penalti/pencairan dini:
     *   Asal : DEBET 2-1003 Berjangka (pokok)
     *          | KREDIT kodePenalti (penalti) + KREDIT 2-1001 (pokok - penalti).
     *   Balik: DEBET kodePenalti (penalti) + DEBET 2-1001 (pokok - penalti)
     *          | KREDIT 2-1003 Berjangka (pokok).
     *
     * Nominal penalti dibaca dari jurnal ASAL oleh ReversalService agar nilai
     * pemulihan saldo tepat, termasuk saat produk memakai akun penalti khusus
     * (`penalty_coa_id`) selain Dana Kebajikan.
     */
    public function jurnalReversalPencairanDiniDeposito(
        float $pokok,
        float $penalti,
        string $keterangan,
        string $kodePenalti = Coa::DANA_KEBAJIKAN,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        $pokok = Setting::bulatkanUang($pokok);
        $penalti = max(0.0, Setting::bulatkanUang($penalti));

        if ($pokok <= 0) {
            return null;
        }

        $kembaliKeAnggota = Setting::bulatkanUang($pokok - $penalti);

        $entries = [];

        if ($penalti > 0) {
            $entries[] = ['kode' => $kodePenalti, 'debet' => $penalti];
        }

        if ($kembaliKeAnggota > 0) {
            $entries[] = ['kode' => Coa::SIMPANAN_SUKARELA, 'debet' => $kembaliKeAnggota];
        }

        $entries[] = ['kode' => Coa::SIMPANAN_BERJANGKA, 'kredit' => $pokok];

        return $this->mesin->post(
            $keterangan,
            $entries,
            'Reversal Pencairan Dini Deposito Mudharabah',
            $transaksiId,
            null,
            $userId,
            $rekeningId,
            $anggotaId,
            null,
            null,
            null,
            null,
            null,
            false,
            true
        );
    }
}
