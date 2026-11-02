<?php

namespace App\Services\Jurnal;

use App\Models\Jurnal;
use App\Models\PemetaanAkadCoa;
use App\Services\CoaResolver;
use App\Support\Coa;
use App\Support\Setting;
use Illuminate\Support\Facades\Log;

/**
 * RESEP JURNAL DOMAIN PEMBIAYAAN (PSAK 102/107/108/109).
 *
 * Seluruh method di sini hanya MENYUSUN baris jurnal (resep), lalu
 * menyerahkannya ke `PostingJurnal` — mesin inti yang menjadi satu-satunya
 * gerbang validasi, keseimbangan, cut-off periode, dan penulisan buku besar.
 *
 * Pemisahan domain ini behavior-preserving: `AutoJournalingService` tetap
 * menyediakan method yang sama dan mendelegasikannya ke kelas ini.
 */
class JournalPembiayaan
{
    public function __construct(private readonly PostingJurnal $mesin) {}

    /**
     * Pencairan Pembiayaan (dropping) — jurnal PSAK 108 per AKAD & per METODE.
     *
     * DEBET  : Piutang/Investasi sesuai akad        sebesar [Pokok + Margin]
     * KREDIT : Akun sumber dana sesuai metode       sebesar [Pokok cair]
     * KREDIT : Margin Ditangguhkan (Murabahah saja) sebesar [Margin]
     *
     * Akun PIUTANG/INVESTASI & MARGIN DITANGGUHKAN diambil DINAMIS dari
     * `kebijakan_koperasis` (lewat CoaResolver) dengan fallback ke registri
     * App\Support\Coa — tidak ada nomor akun yang ditanam di sini.
     *
     * Akun SUMBER DANA juga dinamis mengikuti `disbursement_method`:
     *   CASH             -> Kas Teller (account_kas_teller_id)
     *   TRANSFER_BANK    -> CoA bank operasional terpilih (account_bank_koperasi_id)
     *   TRANSFER_SAVINGS -> Simpanan Sukarela (account_simpanan_sukarela_id)
     *
     * KOMPATIBILITAS: `$margin = 0` dan `$akunSumberDana = null` menghasilkan
     * jurnal 2 baris klasik (DEBET piutang / KREDIT Simpanan Sukarela) sehingga
     * pemanggil lama tetap sah. Margin Ditangguhkan hanya muncul bila margin
     * diserahkan eksplisit, dan akad Qardh tidak pernah memiliki margin.
     *
     * @param  float  $nominal  Nominal POKOK yang dicairkan
     * @param  string  $akad  Akad pembiayaan (Murabahah/Mudharabah/...)
     * @param  string  $keterangan  Keterangan jurnal
     * @param  int|null  $userId  User pemosting
     * @param  int|null  $transaksiId  ID transaksi kas/bank/simpanan terkait
     * @param  int|null  $pembiayaanId  ID pembiayaan (referensi PSAK 108)
     * @param  int|null  $rekeningId  ID rekening terkait
     * @param  int|null  $anggotaId  ID anggota
     * @param  float  $margin  Total margin ditangguhkan (Murabahah)
     * @param  string|null  $akunSumberDana  Kode CoA sumber dana; null = Simpanan Sukarela
     * @param  string|null  $metode  Nilai DisbursementMethod (penanda audit)
     * @param  string  $kodeProduk  Kode produk pembiayaan untuk pemetaan
     *                              akad→CoA per produk (Tahap 11); default
     *                              '*' = pemetaan berlaku semua produk.
     */
    public function jurnalPencairan(
        float $nominal,
        string $akad = 'Murabahah',
        string $keterangan = '',
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        float $margin = 0.0,
        ?string $akunSumberDana = null,
        ?string $metode = null,
        string $kodeProduk = PemetaanAkadCoa::SEMUA_PRODUK
    ): ?Jurnal {
        if ($nominal <= 0) {
            return null;
        }

        $resolver = app(CoaResolver::class);
        $akunPiutang = $resolver->kodePiutangByAkad($akad, $kodeProduk);

        // Margin hanya sah pada akad yang memang memiliki margin. Qardh
        // (pinjaman kebajikan) dilarang keras memiliki margin — PSAK 108/109.
        $margin = $this->mesin->marginQardhDilarang($akad, $margin) ? 0.0 : max(0.0, $margin);

        $akunMargin = $margin > 0
            ? $resolver->kodeMarginDitangguhkanByAkad($akad, $kodeProduk)
            : null;

        /* Bila akun Margin Ditangguhkan belum dipetakan pengurus dan belum ada
         | di registri standar, margin TIDAK BOLEH hilang diam-diam dari neraca.
         | Jurnal dibatalkan & dicatat agar tidak terbentuk piutang yang tidak
         | diimbangi sumber dana (jurnal tidak balance). */
        if ($margin > 0 && $akunMargin === null) {
            Log::error('JournalPembiayaan: akun Margin Ditangguhkan belum dipetakan — jurnal pencairan dibatalkan.', [
                'akad' => $akad,
                'margin' => $margin,
                'pembiayaan_id' => $pembiayaanId,
                'aturan' => 'PSAK 108: margin Murabahah wajib ditangguhkan di akun kewajiban; petakan account_margin_deferred_id di kebijakan koperasi.',
            ]);

            return null;
        }

        // Sumber dana dinamis: metode menentukan akun kredit pokok. Bila
        // pemanggil tidak mengirim akun MAUPUN metode (perilaku lama), jatuh ke
        // Simpanan Sukarela agar struktur jurnal historis tidak berubah.
        $akunSumber = $akunSumberDana
            ?: ($metode !== null
                ? $resolver->kodeSumberDanaByMetode($metode)
                : $resolver->kodeSimpananSukarela());

        $entries = [
            ['kode' => $akunPiutang, 'debet' => $nominal + $margin],
            ['kode' => $akunSumber,  'kredit' => $nominal],
        ];

        if ($akunMargin !== null) {
            $entries[] = ['kode' => $akunMargin, 'kredit' => $margin];
        }

        $labelMetode = $metode ? " [{$metode}]" : '';

        return $this->mesin->post(
            $keterangan ?: "Pencairan pembiayaan akad {$akad}{$labelMetode}",
            $entries,
            'Pencairan Pembiayaan',
            $transaksiId,
            $pembiayaanId,
            $userId,
            $rekeningId,
            $anggotaId
        );
    }

    /**
     * Pembayaran Angsuran
     * DEBET: Kas Teller (1-1001) | KREDIT: Piutang Pembiayaan + Pendapatan sesuai akad
     *   Murabahah  → 4-1001 | Mudharabah → 4-1002 | Musyarakah → 4-1003
     *   Ijarah     → 4-1004 (ujroh via param $ujroh) | Qardh → tidak ada margin
     *
     * CKPN-11 / PSAK 102/402 — untuk akad BERMARGIN (punya akun 1-2101), porsi
     * margin TIDAK diakui langsung sebagai pendapatan, melainkan DIPINDAHKAN dari
     * akun kontra margin ditangguhkan (DEBET 1-2101 | KREDIT 4-100x) dan piutang
     * dikredit sebesar POKOK + MARGIN (piutang bruto). Ini menyamakan hasil
     * dengan {@see AngsuranTransactionService::postJurnal()} sehingga seluruh
     * jalur (teller, simulasi, autodebet) mencatat amortisasi secara konsisten.
     */
    public function jurnalAngsuran(
        float $pokok,
        float $margin,
        float $ujroh = 0,
        string $keterangan = '',
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        string $akad = 'Murabahah',
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        $total = $pokok + $margin + $ujroh;
        if ($total <= 0) {
            return null;
        }

        $akunPendapatan = $this->pendapatanByAkad($akad);
        $akunMargin = $this->getMarginDitangguhkanByAkad($akad);

        // PSAK 108/109: Qardhul Hasan DILARANG memiliki margin/ujroh apa pun.
        // Hanya Pokok Piutang Qardh (1-2005) yang boleh dicatat.
        if ($this->mesin->marginQardhDilarang($akad, $margin, $ujroh)) {
            return null;
        }

        $entries = [
            ['kode' => Coa::KAS_TELLER,            'debet' => $total],
            ['kode' => $this->piutangByAkad($akad), 'kredit' => $akunMargin !== null ? $pokok + $margin : $pokok],
        ];

        if ($margin > 0 && $akunPendapatan !== null) {
            if ($akunMargin !== null) {
                // Amortisasi: pindahkan margin dari akun kontra ke pendapatan.
                $entries[] = ['kode' => $akunMargin,     'debet' => $margin];
                $entries[] = ['kode' => $akunPendapatan, 'kredit' => $margin];
            } else {
                $entries[] = ['kode' => $akunPendapatan, 'kredit' => $margin];
            }
        }

        // PSAK 107 ¶22-24: ujroh (Ijarah) disajikan pada akun pendapatan sewa
        // tersendiri 4-1004 — bukan 4-2001 (Pendapatan Administrasi).
        if ($ujroh > 0) {
            $entries[] = ['kode' => Coa::PENDAPATAN_IJARAH, 'kredit' => $ujroh];
        }

        // Akad tanpa akun pendapatan (Qardh) → kunci mapping POST_TYPE_QARDH
        // agar lapisan validasi mapping menolak KREDIT ke kelas 4- apa pun.
        $postType = $akunPendapatan === null ? PostingJurnal::POST_TYPE_QARDH : null;

        return $this->mesin->post($keterangan ?: 'Pembayaran angsuran', $entries, 'Angsuran', $transaksiId, $pembiayaanId, $userId, $rekeningId, $anggotaId, null, $postType);
    }

    /**
     * Public proxy — dipakai AngsuranTransactionService untuk bangun entries jurnal.
     */
    public function getPendapatanByAkad(string $akad): ?string
    {
        return $this->pendapatanByAkad($akad);
    }

    /** @see pendapatanByAkad() — alasan pemakaian CoaResolver. */
    public function getPiutangByAkad(string $akad): string
    {
        return $this->piutangByAkad($akad);
    }

    /**
     * Public proxy — kode akun kontra Margin Ditangguhkan (1-2101) untuk akad tertentu.
     *
     * Dipakai AngsuranTransactionService untuk MENGAMORTISASI margin ditangguhkan:
     * saat angsuran diterima, margin yang menjadi hak diakui sebagai pendapatan
     * (4-100x) dengan memindahkannya dari akun kontra 1-2101 — bukan dikredit
     * langsung ke pendapatan. Tanpa pemindahan ini saldo 1-2101 menumpuk
     * permanen dan pendapatan margin terbaca dua kali (PSAK 102/402).
     *
     * Mengembalikan null untuk akad tanpa margin ditangguhkan (Mudharabah,
     * Musyarakah, Qardh) sehingga pemanggil melewati langkah amortisasi.
     */
    public function getMarginDitangguhkanByAkad(string $akad): ?string
    {
        return app(CoaResolver::class)->kodeMarginDitangguhkanByAkad($akad);
    }

    /**
     * Pemetaan akad pembiayaan -> kode akun pendapatan CoA.
     * Dipakai oleh jurnalAngsuran agar kredit pendapatan mengalir ke akun yang tepat.
     * Qardh (pinjaman kebajikan) tidak menghasilkan pendapatan → return null.
     *
     * Sumber pemetaan: {@see CoaResolver} (pemetaan akad berbasis data →
     * kebijakan koperasi → registri standar App\Support\Coa). Wajib lewat
     * resolver agar akun yang dipakai MENGKREDIT sama dengan akun yang dipakai
     * MENDESEBIT saat pencairan — bila pengurus memindahkan akun lewat
     * kebijakan, kedua sisi ikut berpindah dan saldo piutang tetap dapat nol.
     */
    private function pendapatanByAkad(string $akad): ?string
    {
        return app(CoaResolver::class)->kodePendapatanByAkad($akad);
    }

    /**
     * Pemetaan akad pembiayaan -> kode akun piutang CoA (normal saldo: Debet).
     * Dipakai bersama oleh jurnalPencairan & jurnalAngsuran agar piutang
     * tercatat dan berkurang di akun yang sesuai akad.
     *
     * Sumber pemetaan: {@see CoaResolver} — lihat {@see pendapatanByAkad()}.
     */
    private function piutangByAkad(string $akad): string
    {
        return app(CoaResolver::class)->kodePiutangByAkad($akad);
    }

    /**
     * Reversal Angsuran — membalikkan jurnal pembayaran angsuran yang dibatalkan.
     *
     * Kebalikan PERSIS dari jurnal pembayaran angsuran (posisi Debet/Kredit
     * dibalik), termasuk amortisasi margin ditangguhkan:
     *
     *   DEBET  : Kas Teller (1-1001)                  sebesar [Nominal]
     *   KREDIT : Piutang sesuai akad                  sebesar [Pokok]
     *   DEBET  : 4-100x Pendapatan Margin             sebesar [Margin]
     *   KREDIT : 1-2101 Margin Ditangguhkan (Murabahah) sebesar [Margin]
     *
     * Tanpa memutar-balik amortisasi, pembatalan angsuran Murabahah akan
     * mengembalikan piutang tetapi meninggalkan pendapatan margin dan
     * mengurangi akun kontra 1-2101 secara permanen — neraca & laba-rugi salah.
     *
     * @param  float  $nominal  Pokok terbayar yang dikembalikan ke piutang
     * @param  float  $margin  Porsi margin yang dibatalkan pengakuannya
     */
    public function jurnalReversalAngsuran(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        string $akad = 'Murabahah',
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        float $margin = 0.0
    ): ?Jurnal {
        if ($nominal <= 0 && $margin <= 0) {
            return null;
        }

        $akunPiutang = $this->piutangByAkad($akad);
        $akunPendapatan = $this->pendapatanByAkad($akad);
        $akunMargin = $this->getMarginDitangguhkanByAkad($akad);

        $entries = [
            ['kode' => Coa::KAS_TELLER,  'debet' => $nominal],
            ['kode' => $akunPiutang,     'kredit' => $nominal],
        ];

        if ($margin > 0 && $akunPendapatan !== null && $akunMargin !== null) {
            $entries[] = ['kode' => $akunPendapatan, 'debet' => $margin];
            $entries[] = ['kode' => $akunMargin,     'kredit' => $margin];
        }

        return $this->mesin->post(
            $keterangan ?: 'Reversal Angsuran',
            $entries,
            'Reversal Angsuran',
            $transaksiId,
            $pembiayaanId,
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

    /**
     * Reversal PENCAIRAN PEMBIAYAAN.
     *
     * Membalik jurnal pencairan PSAK 108:
     *   DEBET  : akun sumber dana (Kas Teller / Bank) = pokok cair
     *   KREDIT : akun piutang sesuai akad             = pokok + margin
     *   DEBET  : Margin Ditangguhkan (bila akad jual-beli) = margin
     */
    public function jurnalReversalPencairanPembiayaan(
        float $nominal,
        float $margin,
        string $keterangan,
        ?string $akad = null,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        string $kodeSumberDana = Coa::KAS_TELLER
    ): ?Jurnal {
        $resolver = app(CoaResolver::class);
        $kodePiutang = $resolver->kodePiutangByAkad($akad);
        $kodeMargin = $resolver->kodeMarginDitangguhkanByAkad($akad);

        $entries = [
            ['kode' => $kodeSumberDana, 'debet' => $nominal],
            ['kode' => $kodePiutang,    'kredit' => Setting::bulatkanUang($nominal + $margin)],
        ];

        if ($margin > 0 && $kodeMargin !== null) {
            $entries[] = ['kode' => $kodeMargin, 'debet' => $margin];
        }

        return $this->mesin->post($keterangan, $entries, 'Reversal Pencairan Pembiayaan', $transaksiId, $pembiayaanId, $userId,
            null, null, null, null, null, null, null, false, true);
    }
}
