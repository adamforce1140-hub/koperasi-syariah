<?php

namespace App\Support;

/**
 * Registri TUNGGAL (single source of truth) seluruh nilai kolom
 * `transaksi.jenis_transaksi`.
 *
 * ── Latar belakang ────────────────────────────────────────────────────────
 * Kolom `jenis_transaksi` awalnya ENUM (lihat migrasi
 * 2024_01_01_000006_create_transaksi_table.php) lalu diubah menjadi
 * VARCHAR(50) oleh migrasi 2026_09_03_083135 ("agar fleksibel"). Perubahan
 * itu menghapus penjaga nilai di level database, sehingga setiap jalur
 * penulis (Teller, CIF, MandatorySavingsService, DisbursementService,
 * AngsuranTransactionService, seeder, dsb.) mulai menulis string literal
 * yang berbeda-beda untuk peristiwa ekonomi yang sama — mis. "Setoran
 * Tunai" / "Setoran Simpanan Sukarela" / "Simpanan Sukarela" semuanya
 * berarti setoran simpanan sukarela.
 *
 * Sementara itu pembaca (kartu Kas & Rekening, laporan Pengurus,
 * rekap/closing Teller, bagi hasil Manajer) mencari literal lain lagi,
 * sehingga sering tidak menemukan data dan menampilkan Rp 0.
 *
 * ── Peran registri ini ────────────────────────────────────────────────────
 * 1. Menetapkan NILAI KANONIK untuk setiap peristiwa (dipakai penulis baru).
 * 2. Mendaftarkan ALIAS (varian lama) sehingga pembaca dapat mengenali
 *    seluruh data historis tanpa perlu mengubah data.
 * 3. Menyediakan helper query daftar nilai untuk `whereIn()`.
 *
 * Catatan: registri ini TIDAK mengubah data historis maupun tanggal.
 */
final class JenisTransaksi
{
    /* ── NILAI KANONIK ─────────────────────────────────────────────────── */

    public const SETORAN_TUNAI = 'Setoran Tunai';

    public const SETORAN_SUKARELA = 'Setoran Simpanan Sukarela';

    public const SETORAN_POKOK = 'Setoran Simpanan Pokok';

    public const SETORAN_WAJIB = 'Setoran Simpanan Wajib';

    public const SETORAN_BERJANGKA = 'Setoran Simpanan Berjangka';

    public const PEMBAYARAN_ANGSURAN = 'Pembayaran Angsuran';

    public const PENARIKAN_TUNAI = 'Penarikan Tunai';

    public const PENARIKAN_SIMPANAN = 'Penarikan Simpanan';

    public const PENARIKAN_WAJIB = 'Penarikan Simpanan Wajib';

    public const PENARIKAN_BERJANGKA = 'Penarikan Simpanan Berjangka';

    public const PENCAIRAN_PEMBIAYAAN = 'Pencairan Pembiayaan';

    public const PENCAIRAN_DEPOSITO = 'Pencairan Deposito';

    public const TRANSFER_MASUK = 'Transfer Masuk';

    public const TRANSFER_KELUAR = 'Transfer Keluar';

    public const BAGI_HASIL = 'Bagi Hasil';

    public const BIAYA_ADMINISTRASI = 'Biaya Administrasi';

    public const DENDA = 'Denda';

    public const TAZIR = "Ta'zir";

    /**
     * Pembukaan deposito mudharabah: dana berpindah dari Simpanan Sukarela ke
     * Simpanan Berjangka. BUKAN kas fisik (laci teller tidak berubah), sehingga
     * sengaja tidak masuk CASH_IN/CASH_OUT; terdaftar agar penjaga registri
     * `KasKasirTest` tidak melaporkannya sebagai "nilai hantu".
     */
    public const PEMBUKAAN_DEPOSITO = 'Pembukaan Deposito Mudharabah';

    /* ── KELOMPOK ──────────────────────────────────────────────────────── */

    /** Setoran tunai ke rekening simpanan yang bersifat setoran (pokok/wajib/sukarela). */
    public const SETORAN = [
        self::SETORAN_TUNAI,
        self::SETORAN_SUKARELA,
        self::SETORAN_POKOK,
        self::SETORAN_WAJIB,
        'Simpanan Sukarela',
        'Simpanan Pokok',
        'Simpanan Wajib',
        'Setoran Awal Anggota',
    ];

    /** Auto-debit simpanan wajib (pemindahan, bukan kas fisik). */
    public const AUTO_DEBIT_WAJIB = [
        'Auto-Debit Simpanan Wajib',
    ];

    /** Pembayaran angsuran pembiayaan (semua varian nama). */
    public const ANGSURAN = [
        self::PEMBAYARAN_ANGSURAN,
        'Bayar Angsuran',
        'Angsuran',
    ];

    /* ── ARAH ARUS KAS ─────────────────────────────────────────────────── */

    /**
     * Seluruh nilai `jenis_transaksi` yang menambah kas/aset (cash in).
     * Mencakup nilai kanonik DAN alias historis.
     */
    public const CASH_IN = [
        self::SETORAN_TUNAI,
        self::SETORAN_SUKARELA,
        self::SETORAN_POKOK,
        self::SETORAN_WAJIB,
        self::SETORAN_BERJANGKA,
        self::PEMBAYARAN_ANGSURAN,
        'Bayar Angsuran',
        'Angsuran',
        self::TRANSFER_MASUK,
        self::BAGI_HASIL,
        self::BIAYA_ADMINISTRASI,
        self::DENDA,
        self::TAZIR,
        // Alias historis setoran
        'Simpanan Sukarela',
        'Simpanan Pokok',
        'Simpanan Wajib',
        'Setoran Awal Anggota',
    ];

    /**
     * Seluruh nilai `jenis_transaksi` yang mengurangi kas/aset (cash out).
     * Mencakup nilai kanonik DAN alias historis.
     */
    public const CASH_OUT = [
        self::PENARIKAN_TUNAI,
        self::PENARIKAN_SIMPANAN,
        self::PENARIKAN_WAJIB,
        self::PENARIKAN_BERJANGKA,
        self::PENCAIRAN_PEMBIAYAAN,
        self::PENCAIRAN_DEPOSITO,
        self::TRANSFER_KELUAR,
        'Setoran Kas ke Brankas',
    ];

    /** Status transaksi yang TIDAK diperhitungkan pada perhitungan kas. */
    public const STATUS_NON_KAS = ['Gagal', 'Dibatalkan', 'Voided', 'Pending Void', 'Pending'];

    /**
     * Pemindahan dana antar-produk simpanan: kas fisik TIDAK berubah, sehingga
     * bukan cash in maupun cash out. Nilai di sini tetap termasuk daftar
     * "dikenal" agar tidak dianggap nilai hantu.
     */
    public const PEMINDAHAN_PRODUK = [
        self::PEMBUKAAN_DEPOSITO,
    ];

    /**
     * Nilai kolom `transaksi.jenis_pembayaran` yang bersifat PEMINDAHAN dana
     * (bukan penerimaan/pengeluaran kas fisik). Baris dengan nilai ini harus
     * dikecualikan dari perhitungan kas laci teller: uangnya tidak pernah
     * masuk/keluar laci, hanya berpindah antar-akun.
     */
    public const PEMBAYARAN_NON_KAS = ['autodebet'];

    /* ── HELPER ────────────────────────────────────────────────────────── */

    /** Daftar nilai untuk whereIn() cash in (tanpa duplikat). */
    public static function cashIn(): array
    {
        return array_values(array_unique(self::CASH_IN));
    }

    /** Daftar nilai untuk whereIn() cash out (tanpa duplikat). */
    public static function cashOut(): array
    {
        return array_values(array_unique(self::CASH_OUT));
    }

    /** Daftar nilai setoran simpanan (pokok/wajib/sukarela + setoran tunai). */
    public static function setoran(): array
    {
        return array_values(array_unique(self::SETORAN));
    }

    /** Daftar nilai pembayaran angsuran (semua alias). */
    public static function angsuran(): array
    {
        return array_values(array_unique(self::ANGSURAN));
    }

    /**
     * Peta seluruh ALIAS → nilai KANONIK.
     *
     * Dibuat otomatis dari grup-grup alias di atas sehingga penambahan alias
     * baru cukup di satu tempat. Setiap alias dipetakan ke nilai kanonik
     * (elemen pertama/kepala grupnya).
     *
     * @return array<string, string>
     */
    public static function aliasMap(): array
    {
        $peta = [];

        foreach (self::grupAlias() as $kanonik => $alias) {
            $peta[$kanonik] = $kanonik;
            foreach ($alias as $nilai) {
                // Alias tidak boleh menimpa pemetaan kanonik yang sudah ada.
                if (! isset($peta[$nilai])) {
                    $peta[$nilai] = $kanonik;
                }
            }
        }

        return $peta;
    }

    /**
     * Normalisasi satu nilai `jenis_transaksi` ke nilai KANONIK-nya.
     * Nilai yang tidak dikenal dikembalikan apa adanya (aman: tidak menghapus
     * data liar, tetapi juga tidak mengarang kategori baru).
     */
    public static function kanonik(string $jenis): string
    {
        return self::aliasMap()[$jenis] ?? $jenis;
    }

    /**
     * Apakah nilai `jenis` tergolong pembayaran angsuran (kanonik/alias)?
     * Menggantikan heuristik `in_array` dan `str_contains($jenis, 'Angsuran')`.
     */
    public static function adalahAngsuran(string $jenis): bool
    {
        return in_array($jenis, self::ANGSURAN, true);
    }

    /**
     * Definisi grup alias: label kanonik => daftar seluruh nilai (kanonik +
     * alias) untuk peristiwa itu. Sumber tunggal pemetaan & normalisasi.
     *
     * @return array<string, array<int, string>>
     */
    private static function grupAlias(): array
    {
        return [
            self::SETORAN_TUNAI => [self::SETORAN_TUNAI],
            self::SETORAN_SUKARELA => self::sukarela(),
            self::SETORAN_POKOK => self::pokok(),
            self::SETORAN_WAJIB => self::wajib(),
            self::SETORAN_BERJANGKA => [self::SETORAN_BERJANGKA],
            self::PEMBAYARAN_ANGSURAN => self::angsuran(),
            self::PENARIKAN_TUNAI => [self::PENARIKAN_TUNAI],
            self::PENARIKAN_SIMPANAN => [self::PENARIKAN_SIMPANAN],
            self::PENARIKAN_WAJIB => [self::PENARIKAN_WAJIB],
            self::PENARIKAN_BERJANGKA => [self::PENARIKAN_BERJANGKA],
            self::PENCAIRAN_PEMBIAYAAN => [self::PENCAIRAN_PEMBIAYAAN],
            self::PENCAIRAN_DEPOSITO => [self::PENCAIRAN_DEPOSITO],
        ];
    }

    /** Semua nilai setoran simpanan sukarela (kanonik + alias lama). */
    public static function sukarela(): array
    {
        return [self::SETORAN_SUKARELA, 'Simpanan Sukarela'];
    }

    /** Semua nilai setoran simpanan pokok (kanonik + alias lama). */
    public static function pokok(): array
    {
        return [self::SETORAN_POKOK, 'Simpanan Pokok', 'Setoran Awal Anggota'];
    }

    /** Semua nilai setoran simpanan wajib (kanonik + alias lama + auto-debit). */
    public static function wajib(): array
    {
        return [self::SETORAN_WAJIB, 'Simpanan Wajib', 'Auto-Debit Simpanan Wajib'];
    }

    /**
     * Jumlahkan nilai dari array rincian (mis. `rincian_in` KasHarian) untuk
     * sekumpulan kunci. Dipakai pembaca agar tidak lagi membaca satu kunci
     * literal yang mungkin tidak terisi.
     */
    public static function jumlah(array $rincian, array $keys): float
    {
        $total = 0.0;
        foreach ($keys as $key) {
            $total += (float) ($rincian[$key] ?? 0);
        }

        return $total;
    }
}
