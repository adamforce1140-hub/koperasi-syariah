<?php

namespace App\Support;

/**
 * NomorDokumen — sumber TUNGGAL awalan nomor dokumen koperasi.
 *
 * Latar belakang: awalan nomor dokumen (`TRX-`, `JRN-`, `RKN-`, `BBK-`,
 * `TRM-`, `DEP-`) sebelumnya ditulis langsung di masing-masing generator,
 * sehingga koperasi yang ingin memakai kode sendiri harus mengubah kode
 * program. Kini awalan dibaca dari kebijakan (`Setting`) dan dapat diubah
 * Pengurus lewat UI.
 *
 * P4 Default Aman: bila Pengurus belum mengisi prefix, `prefix()` jatuh ke
 * `BAWAAN` — nomor yang dihasilkan identik dengan perilaku lama.
 *
 * Prefix hanya berupa huruf besar & angka (maks. 8 karakter). Generator
 * lama yang masih memakai awalan tertanam (mis. `ZKT-`, `IDS-`) dibiarkan
 * apa adanya karena bersifat teknis internal dan tidak dinamiskan.
 */
class NomorDokumen
{
    /** Awalan bawaan — sama dengan nilai yang tertanam di kode sebelumnya. */
    public const BAWAAN = [
        'transaksi' => 'TRX',
        'rekening' => 'RKN',
        'jurnal' => 'JRN',
        'pengeluaran_beban' => 'BBK',
        'terminasi' => 'TRM',
        'deposito' => 'DEP',
    ];

    /**
     * Kode awalan efektif (tanpa pemisah) untuk satu jenis dokumen.
     *
     * @param  string  $jenis  Kunci pada `BAWAAN` (mis. `transaksi`).
     */
    public static function kode(string $jenis): string
    {
        $bawaan = self::BAWAAN[$jenis] ?? strtoupper($jenis);

        return Setting::prefixNomorDokumen($jenis) ?? $bawaan;
    }

    /**
     * Prefix lengkap untuk generator urutan: `KODE-{periode}-`.
     *
     * @param  string  $jenis  Kunci pada `BAWAAN`.
     * @param  string  $periode  Bagian tanggal (mis. `Ymd` atau `Y`).
     * @param  string  $tanggal  Format tanggal, dipakai bila `$periode` kosong.
     */
    public static function prefix(string $jenis, string $periode = '', string $tanggal = 'Ymd'): string
    {
        $periode = $periode !== '' ? $periode : date($tanggal);

        return self::kode($jenis).'-'.$periode.'-';
    }

    /**
     * Nomor dokumen lengkap dengan urutan aman-race.
     *
     * Delegasi ke `NomorSequence` (SELECT ... FOR UPDATE). Untuk dokumen yang
     * memakai tabel penanda urutan sendiri, `$tabelAcuan`/`$kolomAcuan` wajib
     * diisi; bila tidak, urutan diseed dari tabel `transaksi`.
     */
    public static function buat(
        string $jenis,
        int $panjang = 6,
        string $tabelAcuan = 'transaksi',
        string $kolomAcuan = 'no_transaksi',
        string $periode = '',
        string $tanggal = 'Ymd'
    ): string {
        return NomorSequence::berikutnya(
            self::prefix($jenis, $periode, $tanggal),
            $panjang,
            $tabelAcuan,
            $kolomAcuan
        );
    }
}
