<?php

namespace App\Contracts;

use App\Models\Jurnal;

/**
 * KONTRAK — PEMOSTING JURNAL OTOMATIS.
 *
 * Titik tunggal untuk membukukan jurnal berpasangan (debet & kredit) dari
 * seluruh modul. Pemanggil tidak perlu tahu mekanisme internal (validasi
 * balance, integritas periode/cut-off, idempotensi, jejak audit); cukup
 * bergantung pada kontrak ini sehingga mesin posting dapat ditukar/diuji
 * (mis. implementasi no-op atau spy pada test) tanpa menyentuh modul.
 *
 * Implementasi bawaan: `App\Services\AutoJournalingService`.
 *
 * CATATAN: method di sini mencerminkan entry point publik yang paling banyak
 * dipakai modul lain. Method service asli yang bersifat internal tetap dipakai
 * langsung lewat kelas konkret.
 */
interface JournalPosterInterface
{
    /**
     * Helper inti: buat Jurnal berpasangan ke CoA + validasi balance.
     *
     * @param  string  $keterangan  Deskripsi jurnal
     * @param  array<int, array<string, mixed>>  $entries  Baris [{kode, debet?, kredit?, keterangan?, rekening_id?, anggota_id?}]
     * @param  string  $sumber  Asal jurnal (mis. 'Angsuran', 'Pencairan Pembiayaan')
     * @param  int|null  $transaksiId  ID transaksi kas/bank/simpanan terkait
     * @param  int|null  $pembiayaanId  ID pembiayaan terkait
     * @param  int|null  $userId  User pemosting (null = Auth::id())
     * @param  int|null  $rekeningId  ID rekening terkait
     * @param  int|null  $anggotaId  ID anggota terkait
     * @param  mixed  $tanggalJurnal  Tanggal jurnal (Y-m-d) atau null = hari ini
     * @param  string|null  $postType  Jenis posting (POST_TYPE_*)
     * @param  string|null  $noJurnal  Nomor jurnal eksplisit
     * @param  string|null  $noRef  Nomor referensi dokumen
     * @param  string|null  $jenisJurnal  Klasifikasi jurnal
     * @param  bool  $pembalikan  Mode contra-entry: baris sengaja dibalik, aturan POSISI dilewati
     * @param  bool  $reversal  Mode jurnal PEMBALIK transaksi (void/AML): gate status transaksi
     *                          dilewati dan jurnal ditandai `is_reversal`
     */
    public function post(
        string $keterangan,
        array $entries,
        string $sumber = 'Jurnal Umum',
        ?int $transaksiId = null,
        ?int $pembiayaanId = null,
        ?int $userId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $postType = null,
        ?string $noJurnal = null,
        ?string $noRef = null,
        ?string $jenisJurnal = null,
        bool $pembalikan = false,
        bool $reversal = false
    ): ?Jurnal;

    /**
     * Setoran tunai (sukarela) — DEBET Kas Teller / KREDIT Simpanan Sukarela.
     */
    public function jurnalSetorTunai(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        bool $reversal = false
    ): ?Jurnal;

    /**
     * Penarikan tunai (sukarela) — DEBET Simpanan Sukarela / KREDIT Kas Teller.
     */
    public function jurnalTarikTunai(
        float $nominal,
        string $keterangan,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        bool $reversal = false
    ): ?Jurnal;

    /**
     * Pembayaran angsuran (pokok + margin/ujroh sesuai akad).
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
    ): ?Jurnal;

    /**
     * Pencairan pembiayaan (dropping) — PSAK 108 per akad & metode.
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
        string $kodeProduk = '*'
    ): ?Jurnal;
}
