<?php

namespace App\Services;

use App\Contracts\JournalPosterInterface;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\PemetaanAkadCoa;
use App\Services\Jurnal\JournalBebanShu;
use App\Services\Jurnal\JournalDeposito;
use App\Services\Jurnal\JournalKas;
use App\Services\Jurnal\JournalKebajikan;
use App\Services\Jurnal\JournalPembiayaan;
use App\Services\Jurnal\JournalSimpananModal;
use App\Services\Jurnal\PostingJurnal;
use App\Support\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Auto-Posting Jurnal Umum Berpasangan (Debet & Kredit)
 * Berdasarkan Chart of Accounts standar akuntansi syariah (PSAK 101-111).
 */
class AutoJournalingService implements JournalPosterInterface
{
    /*
     | Kode akun CoA standar — SELURUH nilai di bawah adalah ALIAS ke registri
     | tunggal `App\Support\Coa`. Konstanta dipertahankan apa adanya agar kode
     | lama & test suite tetap kompatibel, tetapi sumber kebenarannya kini satu.
     | Jangan menambah kode akun baru di sini — daftarkan di App\Support\Coa.
     */
    const KAS_TELLER = \App\Support\Coa::KAS_TELLER;        // Kas Teller

    const KAS_BRANKAS = \App\Support\Coa::KAS_BRANKAS;       // Kas Brankas Utama / Vault

    const BANK_KOPERASI = \App\Support\Coa::BANK_KOPERASI;     // Bank / Rekening Utama Koperasi

    const PIUTANG_MURABAHAH = \App\Support\Coa::PIUTANG_MURABAHAH;  // Piutang Pembiayaan Murabahah

    const PIUTANG_MUDHARABAH = \App\Support\Coa::PIUTANG_MUDHARABAH; // Pembiayaan Mudharabah

    const PIUTANG_MUSYARAKAH = \App\Support\Coa::PIUTANG_MUSYARAKAH; // Pembiayaan Musyarakah

    const PIUTANG_IJARAH = \App\Support\Coa::PIUTANG_IJARAH;     // Piutang Ijarah

    const PINJAMAN_QARDH = \App\Support\Coa::PINJAMAN_QARDH;     // Pinjaman Qardh / Kebajikan

    const SIMPANAN_SUKARELA = \App\Support\Coa::SIMPANAN_SUKARELA;  // Simpanan Sukarela / Wadiah Anggota

    /**
     * @deprecated Akun kewajiban legacy (2-1002). PSAK 101 mensyaratkan
     * Simpanan Wajib disajikan sebagai MODAL ANGGOTA — gunakan
     * `SIMPANAN_WAJIB_MODAL` (3-1002) atau titik resolusi tunggal
     * `MandatorySavingsService::akunSimpananWajib()`. Konstanta ini hanya
     * dipertahankan untuk kompatibilitas data historis.
     */
    const SIMPANAN_WAJIB = \App\Support\Coa::SIMPANAN_WAJIB;     // (legacy) Simpanan Wajib Anggota

    const SIMPANAN_BERJANGKA = \App\Support\Coa::SIMPANAN_BERJANGKA; // Simpanan Berjangka / Deposito Mudharabah

    const DANA_KEBAJIKAN = \App\Support\Coa::DANA_KEBAJIKAN;     // Dana Kebajikan / Social Fund

    const DANA_SOSIAL = \App\Support\Coa::DANA_SOSIAL;        // Dana Sosial (2-3003)

    const DANA_ZAKAT = \App\Support\Coa::DANA_ZAKAT;         // Dana Zakat (2-3002) — kewajiban zakat koperasi

    const PENDAPATAN_MARGIN_AKUN = \App\Support\Coa::PENDAPATAN_MARGIN;  // Pendapatan Margin Murabahah (4-1001)

    /** Sumber jurnal yang diizinkan memakai posisi DEBET pada akun 2-3001 (penyaluran dana sosial keluar). */
    const SUMBER_PENYALURAN_DANA_KEBAJIKAN = PostingJurnal::SUMBER_PENYALURAN_DANA_KEBAJIKAN;

    /* =====================================================================
     | PSAK 108 / PSAK 109 — JENIS POSTING (PEMISAHAN ABSOLUT)
     |
     | Margin pembiayaan adalah PENDAPATAN OPERASIONAL koperasi (komponen
     | pembentuk SHU). Ta'zir/denda, infak, dan shadaqah adalah dana sosial
     | NON-OPERASIONAL. Keduanya TIDAK BOLEH bertukar akun maupun saling
     | dialihkan. Konstanta di bawah menegaskan pemetaan yang sah:

     |   POST_TYPE_MARGIN   -> 4-1001 Pendapatan Margin (dan 4-1002/4-1003/4-1004)
     |   POST_TYPE_TAAZIR   -> 2-3001 Dana Kebajikan
     |   POST_TYPE_DONATION -> 2-3001 Dana Kebajikan
     |   POST_TYPE_QARDH    -> 1-2005 Pokok Piutang Qardh (tanpa margin apa pun)

     | Setiap pelanggaran ditolak dan dicatat ke log audit sistem.
     ===================================================================== */

    public const POST_TYPE_MARGIN = PostingJurnal::POST_TYPE_MARGIN;

    public const POST_TYPE_TAAZIR = PostingJurnal::POST_TYPE_TAAZIR;

    public const POST_TYPE_DONATION = PostingJurnal::POST_TYPE_DONATION;

    public const POST_TYPE_QARDH = PostingJurnal::POST_TYPE_QARDH;

    /**
     * Pemetaan jenis posting -> daftar akun CoA yang SAH menerima KREDIT.
     * Di luar daftar ini, jurnal ditolak (pencegahan misrouting permanen).
     *
     * Nilai mengikuti `PostingJurnal::AKUN_KREDIT_SAH_BY_POST_TYPE`.
     *
     * @var array<string, array<int, string>>
     */
    public const AKUN_KREDIT_SAH_BY_POST_TYPE = PostingJurnal::AKUN_KREDIT_SAH_BY_POST_TYPE;

    /**
     * Prefix kelas akun PENDAPATAN OPERASIONAL koperasi (4-XXXX).
     * Akun kelas ini pembentuk SHU dan DILARANG dialihkan ke Dana Kebajikan.
     */
    public const PREFIX_PENDAPATAN_OPERASIONAL = PostingJurnal::PREFIX_PENDAPATAN_OPERASIONAL;

    /**
     * Sumber jurnal yang SAH boleh memuat margin operasional (kredit kelas 4-)
     * sekaligus penampung ta'zir (kredit 2-3001) dalam SATU entri jurnal.
     *
     * Contoh sah: jurnal angsuran anggota (pokok + margin + denda keterlambatan).
     * Pemetaan ini mencegah false-positive pada aturan pemisahan permanen —
     * yang DILARANG adalah MENGALOKASIKAN margin ke Dana Kebajikan (mis. Debet
     * Pendapatan Margin / Kredit Dana Kebajikan), bukan jurnal gabungan sah.
     *
     * @var array<int, string>
     */
    public const SUMBER_GABUNGAN_SAH = PostingJurnal::SUMBER_GABUNGAN_SAH;

    /**
     * Akun penampung DANA SOSIAL / KEBAJIKAN (non-operasional PSAK 109).
     * Margin operasional DILARANG mengalir ke akun-akun ini.
     *
     * Keluarga akun dana titipan (2-300x) bersifat NON-OPERASIONAL: seluruhnya
     * menampung dana yang bukan hak koperasi, sehingga margin pendapatan
     * (kelas 4-XXXX) tidak boleh dialokasikan ke sini dalam bentuk apa pun.
     *
     * @var array<int, string>
     */
    public const AKUN_DANA_SOSIAL = PostingJurnal::AKUN_DANA_SOSIAL;

    /**
     * Mesin inti posting jurnal.
     *
     * Seluruh mesin validasi & penulisan jurnal (balance, CoA, cut-off
     * periode, kontrol PSAK 108/109) berada di `PostingJurnal`. Facade ini
     * mendelegasikan agar perilaku uang/jurnal tetap identik sekaligus
     * menjaga kontrak lama (`JournalPosterInterface`, konstanta, & method
     * publik) bagi seluruh pemanggil.
     */
    protected function mesin(): PostingJurnal
    {
        return app(PostingJurnal::class);
    }

    /** Journaler domain ta'zir & dana kebajikan. */
    protected function kebajikan(): JournalKebajikan
    {
        return app(JournalKebajikan::class);
    }

    /** Journaler domain beban operasional & alokasi SHU. */
    protected function bebanShu(): JournalBebanShu
    {
        return app(JournalBebanShu::class);
    }

    /** Journaler domain simpanan anggota (sukarela, pokok, wajib, exit). */
    protected function simpanan(): JournalSimpananModal
    {
        return app(JournalSimpananModal::class);
    }

    /** Journaler domain simpanan berjangka / deposito mudharabah. */
    protected function deposito(): JournalDeposito
    {
        return app(JournalDeposito::class);
    }

    /** Journaler domain pembiayaan (pencairan, angsuran, reversal). */
    protected function pembiayaan(): JournalPembiayaan
    {
        return app(JournalPembiayaan::class);
    }

    /** Journaler domain kas/teller & brankas. */
    protected function kas(): JournalKas
    {
        return app(JournalKas::class);
    }

    /**
     * Sumber jurnal bundling setoran awal anggota baru.
     *
     * Dipakai sebagai kunci IDEMPOTENSI: satu anggota hanya boleh memiliki
     * satu jurnal setoran awal. Percobaan posting ulang (mis. dari double-submit
     * form Teller) akan dikembalikan ke jurnal yang sudah ada, bukan membuat
     * jurnal baru — mencegah double posting & saldo Kas Teller menggelembung.
     */
    const SUMBER_BUNDLING_SETORAN_AWAL = JournalSimpananModal::SUMBER_BUNDLING_SETORAN_AWAL;

    /**
     * Sumber jurnal PENGEMBALIAN DANA EXIT KEANGGOTAAN (PSAK 108).
     *
     * Dipakai sebagai kunci IDEMPOTENSI: satu dokumen terminasi hanya boleh
     * menghasilkan satu jurnal pengembalian. Percobaan posting ulang akan
     * dikembalikan ke jurnal yang sudah ada (lihat `jurnalPengembalianExitTersimpan()`),
     * bukan membuat jurnal baru — mencegah double posting & Kas Teller/Bank menggelembung.
     */
    const SUMBER_PENGEMBALIAN_EXIT = JournalSimpananModal::SUMBER_PENGEMBALIAN_EXIT;

    const SIMPANAN_POKOK = \App\Support\Coa::SIMPANAN_POKOK;        // Simpanan Pokok Anggota

    /**
     * Simpanan Wajib Anggota (sisi MODAL/EKUITAS, PSAK 101).
     *
     * CoA menyediakan DUA akun simpanan wajib:
     *  - 2-1002 = kewajiban operasional (dipakai jurnal simpanan wajib lama), dan
     *  - 3-1002 = klasifikasi MODAL ANGGOTA sesuai PSAK 101.
     * Bundling setoran awal & auto-debit simpanan wajib mengikuti rujukan COA
     * master produk (`DepositoProduct::COA_BY_TIPE`) yang memakai 3-1002.
     */
    const SIMPANAN_WAJIB_MODAL = \App\Support\Coa::SIMPANAN_WAJIB_MODAL;  // Simpanan Wajib Anggota (Ekuitas / Modal Anggota)

    const CADANGAN_KOPERASI = \App\Support\Coa::CADANGAN_KOPERASI;     // Cadangan Koperasi

    const IKHTISAR_SHU = \App\Support\Coa::IKHTISAR_SHU;          // Ikhtisar SHU / Laba Ditahan

    const PENDAPATAN_MARGIN = \App\Support\Coa::PENDAPATAN_MARGIN;     // Pendapatan Margin Murabahah

    const PENDAPATAN_BAGI_HASIL = \App\Support\Coa::PENDAPATAN_BAGI_HASIL; // Pendapatan Bagi Hasil Mudharabah

    const PENDAPATAN_BAGI_HASIL_MUSYARAKAH = \App\Support\Coa::PENDAPATAN_BAGI_HASIL_MUSYARAKAH; // Pendapatan Bagi Hasil Musyarakah

    const PENDAPATAN_IJARAH = \App\Support\Coa::PENDAPATAN_IJARAH;     // Pendapatan Sewa Ijarah

    const PENDAPATAN_ADMIN = \App\Support\Coa::PENDAPATAN_ADMIN;      // Pendapatan Biaya Administrasi

    const BEBAN_OPERASIONAL = \App\Support\Coa::BEBAN_OPERASIONAL;     // Beban Operasional Koperasi (general / fallback)

    const BEBAN_ATK = \App\Support\Coa::BEBAN_ATK;             // Beban ATK & Perlengkapan

    const BEBAN_UTILITAS = \App\Support\Coa::BEBAN_UTILITAS;        // Beban Utilitas / Listrik

    const BEBAN_BAGI_HASIL_DEPOSITO = \App\Support\Coa::BEBAN_BAGI_HASIL_DEPOSITO; // Beban Bagi Hasil Deposito Mudharabah

    /**
     * Pemetaan JENIS BEBAN OPERASIONAL -> kode akun CoA spesifik.
     *
     * Dipakai form input & pembuatan jurnal agar setiap jenis pengeluaran
     * tercatat pada akun yang tepat, bukan menumpuk di satu akun general.
     * Jenis 'gaji' memakai 5-1001 yang sekaligus berperan sebagai akun
     * general/cadangan bila kode akun spesifik lain belum tersedia.
     */
    const JENIS_BEBAN = JournalBebanShu::JENIS_BEBAN;

    /**
     * 1. Setoran Sukarela
     * DEBET: Kas Teller (1-1001) | KREDIT: Simpanan Sukarela Anggota (2-1001)
     */
    public function jurnalSetorTunai(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null, bool $reversal = false): ?Jurnal
    {
        return $this->simpanan()->jurnalSetorTunai($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $reversal);
    }

    /**
     * 2. Penarikan Sukarela
     * DEBET: Simpanan Sukarela Anggota (2-1001) | KREDIT: Kas Teller (1-1001)
     */
    public function jurnalTarikTunai(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null, bool $reversal = false): ?Jurnal
    {
        return $this->simpanan()->jurnalTarikTunai($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $reversal);
    }

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
        return $this->kas()->jurnalInjeksiKasBrankas($nominal, $keterangan, $userId, $transaksiId);
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
        return $this->kas()->jurnalSetorKasKeBrankas($nominal, $keterangan, $userId, $transaksiId);
    }

    /**
     * 2b. Pembukaan Kas Teller (Modal Awal Shift) — R6.
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
        return $this->kas()->jurnalPembukaanKasTeller($nominal, $keterangan, $userId);
    }

    /**
     * 3. Pemotongan Ta'zir (Denda Keterlambatan)
     * DEBET: Simpanan Sukarela Anggota (2-1001) | KREDIT: Dana Kebajikan (2-3001)
     * Ta'zir adalah dana sosial — TIDAK menjadi pendapatan koperasi.
     */
    public function jurnalTazir(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->kebajikan()->jurnalTazir($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * 4. Pencairan Pembiayaan (dropping) — jurnal PSAK 108 per AKAD & per METODE.
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
        return $this->pembiayaan()->jurnalPencairan(
            $nominal, $akad, $keterangan, $userId, $transaksiId, $pembiayaanId,
            $rekeningId, $anggotaId, $margin, $akunSumberDana, $metode, $kodeProduk,
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
    public function jurnalAngsuran(float $pokok, float $margin, float $ujroh = 0, string $keterangan = '', ?int $userId = null, ?int $transaksiId = null, ?int $pembiayaanId = null, string $akad = 'Murabahah', ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->pembiayaan()->jurnalAngsuran(
            $pokok, $margin, $ujroh, $keterangan, $userId, $transaksiId, $pembiayaanId, $akad, $rekeningId, $anggotaId,
        );
    }

    /**
     * Setoran Simpanan Berjangka / Deposito Mudharabah (PSAK 105)
     * DEBET: Kas Teller (1-1001) | KREDIT: Simpanan Berjangka/Deposito (2-1003)
     */
    public function jurnalSetorDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalSetorDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Bagi Hasil Simpanan Berjangka / Deposito Mudharabah (PSAK 105/PSAK 101 ¶71)
     * DEBET: Beban Bagi Hasil Deposito (5-3001) | KREDIT: Kas Teller (1-1001)
     * Dicatat sebagai beban koperasi yang dibayarkan kepada anggota.
     */
    public function jurnalBagiHasilDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalBagiHasilDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Penarikan / Pencairan Simpanan Berjangka / Deposito Mudharabah (PSAK 105)
     * DEBET: Simpanan Berjangka/Deposito (2-1003) | KREDIT: Kas Teller (1-1001)
     */
    public function jurnalTarikDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalTarikDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /* ================= DEPOSITO MUDHARABAH (PSAK 101/108) =================
     |
     | Berbeda dari jurnal teller di atas yang memakai Kas Teller (1-1001),
     | modul deposito syariah memindahkan dana dari REKENING SIMPANAN SUKARELA
     | anggota. Kas Teller tidak boleh menjadi sumber dana deposito.
     */

    /**
     * Pembukaan Deposito Mudharabah — pemindahan dana dari Simpanan Sukarela.
     * DEBET : 2-1001 Simpanan Sukarela Anggota (saldo anggota berkurang)
     * KREDIT: 2-1003 Simpanan Berjangka / Deposito (Dana Syirkah Temporer bertambah)
     */
    public function jurnalPembukaanDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalPembukaanDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
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
    public function jurnalKapitalisasiBagiHasilDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalKapitalisasiBagiHasilDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
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
    public function jurnalTransferBagiHasilKeSukarela(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalTransferBagiHasilKeSukarela($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Pencairan Pokok Deposito Mudharabah (non-ARO) — dana kembali ke Simpanan Sukarela.
     * DEBET : 2-1003 Simpanan Berjangka / Deposito
     * KREDIT: 2-1001 Simpanan Sukarela Anggota
     */
    public function jurnalPencairanDeposito(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalPencairanDeposito($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
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
        string $kodePenalti = self::DANA_KEBAJIKAN
    ): ?Jurnal {
        return $this->deposito()->jurnalPenaltiDeposito(
            $pokok, $biayaPenalti, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $kodePenalti,
        );
    }

    /**
     * Pembagian Bagi Hasil Deposito Bulanan.
     * DEBET : 5-3001 Beban Bagi Hasil Deposito Mudharabah
     * KREDIT: 2-1001 Simpanan Sukarela Anggota (bagi hasil langsung menambah saldo)
     *
     * Bagi hasil BUKAN bunga: nilainya berasal dari pendapatan koperasi atas
     * penempatan dana, dibagi sesuai nisbah — bukan nominal tetap yang dijamin.
     */
    public function jurnalBagiHasilDepositoMudharabah(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->deposito()->jurnalBagiHasilDepositoMudharabah($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Setoran Simpanan Pokok
     * DEBET: Kas Teller (1-1001) | KREDIT: Simpanan Pokok Anggota (3-1001)
     */
    public function jurnalSetorSimpananPokok(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->simpanan()->jurnalSetorSimpananPokok($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Setoran Simpanan Wajib.
     *
     * PSAK 101 — Simpanan Wajib disajikan sebagai MODAL ANGGOTA (ekuitas),
     * sehingga akun kredit kanonik = 3-1002, bukan akun kewajiban legacy 2-1002.
     *
     * DEBET: Kas Teller (1-1001) | KREDIT: Simpanan Wajib Anggota (3-1002)
     */
    public function jurnalSetorSimpananWajib(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null): ?Jurnal
    {
        return $this->simpanan()->jurnalSetorSimpananWajib($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId);
    }

    /**
     * Setoran simpanan dengan akun KREDIT mengikuti PARAMETER DINAMIS.
     *
     * Pengganti `match` hardcoded di pemanggil: akun kredit dibaca dari master
     * `jenis_simpanans` (kolom `kode_akun`) melalui resolver JenisSimpanan yang
     * ber-cache. Pengurus cukup mengubah parameter di UI — tanpa mengubah kode —
     * dan seluruh alur setoran (CIF, Teller, auto-debit, dsb.) langsung mengikuti.
     *
     * PENJAGA KOMPATIBILITAS:
     *  - `$sumber` SENGAJA tetap memakai label lama ('Setoran Simpanan Pokok' /
     *    'Setoran Simpanan Wajib' / 'Setoran Tunai') agar filter & laporan
     *    yang sudah ada tidak berubah perilaku.
     *  - Jenis simpanan bertipe `berjangka` (deposito) dilewatkan ke `jurnalSetorTunai()`
     *    agar penempatan deposito tidak keliru diposting sebagai titipan biasa.
     *
     * @param  string|null  $namaJenis  Jenis simpanan pemilik rekening; null = setoran tunai umum.
     * @param  string|null  $tipe  Hasil `JenisSimpanan::tipe($namaJenis)` bila sudah diketahui.
     */
    public function jurnalSetorSimpanan(
        float $nominal,
        string $keterangan,
        ?string $namaJenis = null,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        ?string $tipe = null,
        $tanggalJurnal = null
    ): ?Jurnal {
        return $this->simpanan()->jurnalSetorSimpanan(
            $nominal, $keterangan, $namaJenis, $userId, $transaksiId, $rekeningId, $anggotaId, $tipe, $tanggalJurnal
        );
    }

    /**
     * Pelunasan Tagihan Simpanan Wajib melalui TELLER (tunai/transfer).
     *
     * PSAK 101 (penyajian modal anggota) — sisi KREDIT memakai akun MODAL
     * Simpanan Wajib (3-1002), bukan akun kewajiban legacy 2-1002, agar
     * Simpanan Wajib tersaji sebagai Modal Anggota pada Neraca:
     *   DEBET  : 1-1001 Kas Teller / Bank           = nominal tagihan
     *   KREDIT : 3-1002 Simpanan Wajib Anggota      = nominal tagihan
     *
     * @param  ?string  $akunWajibKode  Akun kredit hasil resolusi kebijakan
     *                                  (`MandatorySavingsService::akunSimpananWajib()`). NULL = pakai akun
     *                                  standar (perilaku lama) sehingga pemanggil lama tetap kompatibel.
     * @param  ?string  $akunKasKode  Akun debet (kas/bank) opsional.
     */
    public function jurnalSimpananWajibTeller(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $anggotaId = null, $tanggalJurnal = null, ?string $akunWajibKode = null, ?string $akunKasKode = null, ?int $rekeningWajibId = null): ?Jurnal
    {
        return $this->simpanan()->jurnalSimpananWajibTeller(
            $nominal, $keterangan, $userId, $transaksiId, $anggotaId, $tanggalJurnal, $akunWajibKode, $akunKasKode, $rekeningWajibId
        );
    }

    /**
     * Bundling Setoran Awal Anggota Baru (Simpanan Pokok + Simpanan Wajib Bulan 1).
     *
     * Nominal diambil dinamis dari parameter koperasi
     * (`kebijakan_koperasis.mandatory_principal_savings` &
     * `mandatory_monthly_savings`) — lihat KebijakanKoperasi::simpananPokokWajib().
     *
     * PSAK 101 (penyajian modal anggota):
     *  DEBET  : 1-1001 Kas / Bank                      = pokok + wajib
     *  KREDIT : 3-1001 Simpanan Pokok Anggota          = pokok
     *  KREDIT : 3-1002 Simpanan Wajib Anggota (Modal)  = wajib
     */
    public function jurnalBundlingSetoranAwal(float $simpananPokok, float $simpananWajib, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningPokokId = null, ?int $anggotaId = null, ?int $rekeningWajibId = null): ?Jurnal
    {
        return $this->simpanan()->jurnalBundlingSetoranAwal(
            $simpananPokok, $simpananWajib, $keterangan, $userId, $transaksiId, $rekeningPokokId, $anggotaId, $rekeningWajibId
        );
    }

    /**
     * Jurnal pengembalian dana exit yang SUDAH tersimpan untuk seorang anggota.
     *
     * Kunci idempotensi modul `member_terminations`: dipakai untuk mencegah
     * dobel posting pengembalian dana `3-1001`/`3-1002` (PSAK 108 — integritas
     * buku besar). Bila jurnal sudah ada, pemanggil WAJIB memakai jurnal lama
     * (bukan membuat yang baru).
     */
    public function jurnalPengembalianExitTersimpan(int $anggotaId): ?Jurnal
    {
        return $this->simpanan()->jurnalPengembalianExitTersimpan($anggotaId);
    }

    /**
     * PENGEMBALIAN DANA EXIT KEANGGOTAAN — Simpanan Pokok + Simpanan Wajib.
     *
     * Satu-satunya pintu sah pengembalian dana akun ekuitas anggota `3-1001`
     * dan `3-1002` (PSAK 101 ¶ 31 — Simpanan Pokok & Wajib adalah Modal
     * Anggota; PSAK 108 — akad titipan/modal). Dipanggil eksklusif oleh
     * `ExitKeanggotaanService::eksekusi()` setelah anggota dinyatakan bersih
     * dari seluruh kewajiban.
     *
     * PSAK 108:
     *  DEBET  : 3-1001 Simpanan Pokok Anggota  = nominal_simpanan_pokok
     *  DEBET  : 3-1002 Simpanan Wajib Anggota  = nominal_simpanan_wajib
     *  KREDIT : 1-1001 Kas Teller              = total (metode CASH)
     *  KREDIT : 1-1002 Kas di Bank             = total (metode TRANSFER_BANK)
     *
     * Baris debet bernilai nol otomatis diabaikan agar jurnal tetap balance
     * (mis. anggota tanpa Simpanan Pokok terisi). Bila kedua nominal nol,
     * jurnal tidak dibuat (return null) — tidak ada dana yang dikembalikan.
     *
     * @param  float  $nominalPokok  Saldo Simpanan Pokok (3-1001) yang dikembalikan.
     * @param  float  $nominalWajib  Akumulasi Simpanan Wajib (3-1002) yang dikembalikan.
     * @param  string  $metode  MemberTermination::METODE_CASH | METODE_TRANSFER_BANK.
     * @param  ?string  $akunKasKode  Override akun kredit (opsional, hasil resolusi kebijakan).
     * @param  float  $nominalSukarela  Saldo Sukarela/Wadiah (2-1001).
     * @param  float  $nominalDeposito  Pokok Simpanan Berjangka (2-1003).
     * @param  float  $nominalAkrualBagiHasil  Akrual bagi hasil deposito (5-3001).
     * @param  float  $nominalZakat  Potongan zakat → KREDIT Dana Zakat (2-3002).
     * @param  float  $nominalBiayaAdmin  Biaya penutupan CIF → KREDIT Pendapatan Admin (4-2001).
     */
    public function jurnalPengembalianDanaExit(
        float $nominalPokok,
        float $nominalWajib,
        string $keterangan,
        string $metode,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null,
        $tanggalJurnal = null,
        ?string $akunKasKode = null,
        float $nominalSukarela = 0.0,
        float $nominalDeposito = 0.0,
        float $nominalAkrualBagiHasil = 0.0,
        float $nominalZakat = 0.0,
        float $nominalBiayaAdmin = 0.0,
    ): ?Jurnal {
        return $this->simpanan()->jurnalPengembalianDanaExit(
            $nominalPokok,
            $nominalWajib,
            $keterangan,
            $metode,
            $userId,
            $transaksiId,
            $rekeningId,
            $anggotaId,
            $tanggalJurnal,
            $akunKasKode,
            $nominalSukarela,
            $nominalDeposito,
            $nominalAkrualBagiHasil,
            $nominalZakat,
            $nominalBiayaAdmin,
        );
    }

    /**
     * Auto-Debit Simpanan Wajib Bulanan dari saldo Simpanan Sukarela anggota.
     *
     * Dipakai scheduler `mandatory-savings:process-daily` ketika saldo Sukarela
     * mencukupi tagihan bulan berjalan.
     *
     * PSAK 101 (reklasifikasi kewajiban sukarela → modal anggota):
     *  DEBET  : 2-1001 Simpanan Sukarela Anggota       = nominal tagihan
     *  KREDIT : 3-1002 Simpanan Wajib Anggota (Modal)  = nominal tagihan
     *
     * @param  ?string  $akunWajibKode  Akun kredit hasil resolusi kebijakan
     *                                  (`MandatorySavingsService::akunSimpananWajib()`). NULL = akun standar.
     * @param  ?string  $akunSukarelaKode  Akun debet (sumber dana) opsional.
     */
    public function jurnalSimpananWajibAutoDebit(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null, $tanggalJurnal = null, ?string $akunWajibKode = null, ?string $akunSukarelaKode = null, ?int $rekeningWajibId = null): ?Jurnal
    {
        return $this->simpanan()->jurnalSimpananWajibAutoDebit(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $tanggalJurnal, $akunWajibKode, $akunSukarelaKode, $rekeningWajibId
        );
    }

    /**
     * Penarikan Simpanan Wajib (mis. penutupan rekening / keluar keanggotaan).
     *
     * PSAK 101 — Simpanan Wajib disajikan sebagai Modal Anggota, sehingga sisi
     * DEBET memakai akun kanonik hasil resolusi kebijakan (standar 3-1002).
     *
     * @param  ?string  $akunWajibKode  Akun debet hasil resolusi kebijakan.
     * @param  ?string  $akunKasKode  Akun kredit (kas/bank) opsional.
     */
    public function jurnalTarikSimpananWajib(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null, ?string $akunWajibKode = null, ?string $akunKasKode = null): ?Jurnal
    {
        return $this->simpanan()->jurnalTarikSimpananWajib(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $akunWajibKode, $akunKasKode
        );
    }

    /**
     * Public proxy — dipakai AngsuranTransactionService untuk bangun entries jurnal.
     */
    public function getPendapatanByAkad(string $akad): ?string
    {
        return $this->pembiayaan()->getPendapatanByAkad($akad);
    }

    /** @see pendapatanByAkad() — alasan pemakaian CoaResolver. */
    public function getPiutangByAkad(string $akad): string
    {
        return $this->pembiayaan()->getPiutangByAkad($akad);
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
        return $this->pembiayaan()->getMarginDitangguhkanByAkad($akad);
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
    public function jurnalReversalAngsuran(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $pembiayaanId = null, string $akad = 'Murabahah', ?int $rekeningId = null, ?int $anggotaId = null, float $margin = 0.0): ?Jurnal
    {
        return $this->pembiayaan()->jurnalReversalAngsuran(
            $nominal, $keterangan, $userId, $transaksiId, $pembiayaanId, $akad, $rekeningId, $anggotaId, $margin,
        );
    }

    /**
     * Biaya Administrasi / Ujroh
     * DEBET: Kas Teller (1-1001) | KREDIT: Pendapatan Biaya Administrasi (4-2001)
     */
    public function jurnalBiayaAdmin(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null): ?Jurnal
    {
        return $this->bebanShu()->jurnalBiayaAdmin($nominal, $keterangan, $userId, $transaksiId);
    }

    /**
     * 8. Pengeluaran Beban Operasional (double-entry berpasangan)
     * DEBET: Akun Beban terpilih (mis. 5-1001) -> saldo beban bertambah
     * KREDIT: Sumber Kas/Bank terpilih (1-1001 / 1-1002) -> saldo kas berkurang
     */
    public function jurnalPengeluaranBeban(
        float $nominal,
        string $kodeAkunBeban,
        string $kodeAkunKas,
        string $keterangan,
        ?int $userId = null,
        ?string $noRef = null
    ): ?Jurnal {
        return $this->bebanShu()->jurnalPengeluaranBeban(
            $nominal, $kodeAkunBeban, $kodeAkunKas, $keterangan, $userId, $noRef
        );
    }

    /**
     * Kode akun beban yang benar-benar dipakai untuk posting jurnal.
     *
     * Error handling master CoA: akun beban spesifik (5-1002/5-1003) bisa saja
     * belum ter-seed atau dinonaktifkan. Bila demikian, pengeluaran TIDAK boleh
     * gagal — akun dialihkan ke Beban Operasional Koperasi (5-1001) sebagai
     * akun general, dan pengalihan dicatat ke log untuk jejak audit.
     */
    public function kodeAkunBebanTerpakai(string $kodeAkunBeban, ?string $konteks = null): string
    {
        return $this->bebanShu()->kodeAkunBebanTerpakai($kodeAkunBeban, $konteks);
    }

    /**
     * Resolusi JENIS BEBAN OPERASIONAL (gaji/atk/utilitas) -> kode akun CoA.
     *
     * Selalu melalui `kodeAkunBebanTerpakai()` sehingga jaminan fallback ke
     * akun general berlaku untuk seluruh jalur input.
     */
    public function kodeAkunBebanByJenis(?string $jenisBeban, ?string $konteks = null): string
    {
        return $this->bebanShu()->kodeAkunBebanByJenis($jenisBeban, $konteks);
    }

    /**
     * Alokasi SHU ke Dana Cadangan Koperasi
     * DEBET  : 3-9000 (Ikhtisar SHU / Laba Ditahan)
     * KREDIT : 3-2001 (Dana Cadangan Koperasi)
     * Hanya boleh dipanggil oleh Pengurus.
     */
    public function jurnalAlokasiShu(
        float $nominal,
        string $keterangan,
        ?int $userId = null
    ): ?Jurnal {
        return $this->bebanShu()->jurnalAlokasiShu($nominal, $keterangan, $userId);
    }

    /**
     * Validasi posisi jurnal untuk akun Dana Kebajikan (2-3001).
     *
     * ATURAN (PSAK 109 + Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000):
     *  - KREDIT = dana masuk/terkumpul (ta'zir, denda, infak, kontribusi kebajikan).
     *  - DEBET  = HANYA penyaluran dana sosial keluar kepada penerima yang berhak.
     *
     * @param  string  $kode  Kode CoA baris jurnal
     * @param  float  $debet  Nominal debet baris
     * @param  float  $kredit  Nominal kredit baris
     * @param  string  $sumber  Sumber jurnal (penanda jenis transaksi)
     * @return bool true bila posisi sah
     */
    public function posisiDanaKebajikanValid(string $kode, float $debet, float $kredit, string $sumber): bool
    {
        return $this->mesin()->posisiDanaKebajikanValid($kode, $debet, $kredit, $sumber);
    }

    /* =====================================================================
     | PSAK 108 / 109 — PEMISAHAN ABSOLUT MARGIN vs DANA KEBAJIKAN
     |
     | Tiga lapis pengaman:
     |  1. posisiMarginValid()          → margin tidak boleh dialokasikan ke
     |                                    Dana Kebajikan / Dana Sosial.
     |  2. mappingPostTypeValid()       → POST_TYPE_MARGIN wajib ke 4-100x;
     |                                    POST_TYPE_TAAZIR/DONATION wajib ke 2-3001.
     |  3. posisiCampuranMarginValid()  → jurnal TIDAK boleh mencampuradukkan
     |                                    pengalokasian margin (4-100x) dengan
     |                                    Dana Kebajikan (2-3001) dalam bentuk
     |                                    Debet-Pendapatan / Kredit-Kebajikan.
     | Semua pelanggaran ditolak & dicatat ke log audit (Log::error).
     ===================================================================== */

    /**
     * Apakah sebuah kode akun termasuk akun Pendapatan Operasional (kelas 4-).
     */
    public function akunPendapatanOperasional(string $kode): bool
    {
        return $this->mesin()->akunPendapatanOperasional($kode);
    }

    /**
     * Apakah sebuah kode akun termasuk penampung Dana Sosial / Kebajikan.
     */
    public function akunDanaSosial(string $kode): bool
    {
        return $this->mesin()->akunDanaSosial($kode);
    }

    /**
     * LAPIS 1 — Pembatasan permanen: margin pembiayaan (Murabahah/Mudharabah/
     * Musyarakah/Ijarah) DILARANG dialokasikan ke rekening Dana Kebajikan /
     * Dana Sosial.
     *
     * Pola pelanggaran yang ditolak: baris DEBET pada akun Pendapatan (4-100x)
     * dipasangkan dengan baris KREDIT pada akun Dana Kebajikan/Dana Sosial —
     * yaitu "memindahkan" margin keluar dari Pendapatan Operasional.
     *
     * @param  array  $barisBaris  Baris jurnal hasil normalisasi
     *                             (masing-masing: kode, debet, kredit)
     * @param  string  $sumber  Sumber jurnal (penanda jenis transaksi)
     * @return bool true bila tidak ada pengalihan margin ke Dana Kebajikan
     */
    public function posisiMarginValid(array $barisBaris, string $sumber): bool
    {
        return $this->mesin()->posisiMarginValid($barisBaris, $sumber);
    }

    /**
     * LAPIS 2 — Validasi pemetaan JENIS POSTING ke akun CoA (tidak dapat diubah).
     *
     *  - POST_TYPE_MARGIN   → KREDIT HANYA boleh ke akun Pendapatan Margin/
     *                         Bagi Hasil (4-100x). Mapping ke akun lain DITOLAK.
     *  - POST_TYPE_TAAZIR   → KREDIT HANYA boleh ke 2-3001 Dana Kebajikan.
     *  - POST_TYPE_DONATION → KREDIT HANYA boleh ke 2-3001 Dana Kebajikan.
     *  - POST_TYPE_QARDH    → tanpa margin: TIDAK boleh ada KREDIT ke kelas 4-.
     *
     * @param  string  $postType  Jenis posting (salah satu POST_TYPE_*)
     * @return bool true bila seluruh baris KREDIT sesuai pemetaan
     */
    public function mappingPostTypeValid(string $postType, array $barisBaris): bool
    {
        return $this->mesin()->mappingPostTypeValid($postType, $barisBaris);
    }

    /**
     * LAPIS 3 — Larangan permanen mencampuradukkan pengalokasian margin
     * (CoA 4-1001 dan keluarga 4-100x) dengan CoA 2-3001 (Dana Kebajikan)
     * sebagai PEMINDAHAN dana, dalam satu entri jurnal yang sama.
     *
     * DILARANG  : Debet 4-1001 Pendapatan Margin  | Kredit 2-3001 Dana Kebajikan
     * DIIZINKAN : jurnal gabungan sah pada sumber 'Angsuran', mis.
     *             Debet 1-1001 Kas | Kredit 1-2001 Piutang | Kredit 4-1001 Margin
     *             | Kredit 2-3001 Ta'zir — sesuai keputusan desain & PSAK 109.
     *
     * @return bool true bila jurnal tidak melakukan pemindahan margin ke kebajikan
     */
    public function posisiCampuranMarginValid(array $barisBaris, string $sumber): bool
    {
        return $this->mesin()->posisiCampuranMarginValid($barisBaris, $sumber);
    }

    /**
     * Deteksi otomatis JENIS POSTING (POST_TYPE_*) dari sumber jurnal, HANYA
     * untuk jenis yang tidak ambigu (dana sosial). Margin operasional DIBERIKAN
     * eksplisit oleh pemanggil (mis. via parameter $postType), karena string
     * sumber bebas dan rawan salah klasifikasi (mis. "Bagi Hasil Simpanan
     * Berjangka" adalah BEBAN, bukan margin pendapatan).
     *
     * Bila tidak terklasifikasi → null: jurnal tetap tunduk pada aturan
     * pemisahan margin lapis 1 & 3 yang bersifat universal.
     */
    public function deteksiPostType(string $sumber): ?string
    {
        return $this->mesin()->deteksiPostType($sumber);
    }

    /**
     * QARDHUL HASAN TIDAK BOLEH MEMILIKI MARGIN (PSAK 108 / Fatwa DSN-MUI No. 19/DSN-MUI/IV/2001).
     *
     * Qardh adalah pinjaman kebajikan (tabarru') — koperasi hanya boleh
     * menerima kembali POKOK pinjaman; menetapkan margin/bagi hasil apa pun
     * menjadikannya riba dan membatalkan sifat kebajikannya. Parameter
     * `$margin` / `$ujroh` untuk akad Qardh SELALU ditolak (dan dicatat ke log).
     *
     * @param  string  $akad  Akad pembiayaan (Murabahah/Mudharabah/.../Qardh)
     * @param  float  $margin  Porsi margin/bagi hasil yang DIMINTA dicatat
     * @param  float  $ujroh  Porsi ujrah yang DIMINTA dicatat
     * @return bool true bila input melanggar (harus ditolak)
     */
    public function marginQardhDilarang(string $akad, float $margin, float $ujroh = 0.0): bool
    {
        return $this->mesin()->marginQardhDilarang($akad, $margin, $ujroh);
    }

    /**
     * Audit log sistem atas setiap UPAYA PELANGGARAN aturan pemisahan
     * margin vs Dana Kebajikan (PSAK 108/109). Dipakai untuk keperluan audit
     * internal maupun eksternal — setiap penolakan meninggalkan jejak.
     */
    public function catatPelanggaranPemisahan(string $aturan, string $sumber, array $konteks = []): void
    {
        $this->mesin()->catatPelanggaranPemisahan($aturan, $sumber, $konteks);
    }

    /**
     * REVERSAL Penerimaan Dana Kebajikan (void transaksi ta'zir / denda / infak).
     * Membalik jurnal asal dengan posisi DEBET 2-3001 (mengurangi dana terkumpul).
     *
     * Sisi KREDIT menyesuaikan sumber dana asal:
     *  - Auto-debet anggota → 2-1001 Simpanan Sukarela (saldo anggota dikembalikan)
     *  - Tunai di loket     → 1-1001 Kas Teller (kas dikembalikan)
     *
     * Posisi DEBET 2-3001 di sini SAH karena merupakan pembalikan (pengurangan)
     * dana yang sebelumnya masuk — bukan penyaluran baru. Nominalnya mengurangi
     * akumulasi "TOTAL TERKUMPUL" sehingga saldo tetap akurat.
     *
     * Catatan pemisahan (PSAK 108/109): jurnal ini SENGAJA tidak diberi
     * POST_TYPE_TAAZIR karena sisi KREDIT-nya mengembalikan dana ke Simpanan
     * Sukarela / Kas Teller (bukan ke 2-3001). Validasi mapping POST_TYPE_TAAZIR
     * mewajibkan KREDIT ke 2-3001 sehingga akan menolak reversal yang sah ini.
     * Reversal tetap tunduk pada aturan pemisahan universal (lapis 1 & 3).
     */
    public function jurnalReversalTazir(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null, ?int $rekeningId = null, ?int $anggotaId = null, string $kodeKredit = self::SIMPANAN_SUKARELA): ?Jurnal
    {
        return $this->kebajikan()->jurnalReversalTazir($nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $kodeKredit);
    }

    /**
     * Reversal SETORAN SIMPANAN (Pokok / Wajib / Sukarela) — R6.
     *
     * Membalik jurnal setoran simpanan: DEBET akun simpanan / KREDIT Kas Teller.
     * Dipakai saat void/penolakan transaksi berjenis `Simpanan Pokok`,
     * `Simpanan Wajib`, atau `Simpanan Sukarela` (input manual TransaksiController)
     * yang sebelumnya TIDAK punya pemetaan jurnal pembalik.
     */
    public function jurnalReversalSetoranSimpanan(
        float $nominal,
        string $keterangan,
        string $kodeSimpanan = self::SIMPANAN_SUKARELA,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->simpanan()->jurnalReversalSetoranSimpanan(
            $nominal, $keterangan, $kodeSimpanan, $userId, $transaksiId, $rekeningId, $anggotaId
        );
    }

    /**
     * Reversal PENCAIRAN PEMBIAYAAN (dropping) — R6.
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
        string $kodeSumberDana = self::KAS_TELLER
    ): ?Jurnal {
        return $this->pembiayaan()->jurnalReversalPencairanPembiayaan(
            $nominal, $margin, $keterangan, $akad, $userId, $transaksiId, $pembiayaanId, $kodeSumberDana,
        );
    }

    /**
     * Reversal INJEKSI KAS Brankas → Laci Teller — R6.
     *
     * Membalik jurnal injeksi kas: DEBET Kas Brankas / KREDIT Kas Teller.
     */
    public function jurnalReversalInjeksiKas(float $nominal, string $keterangan, ?int $userId = null, ?int $transaksiId = null): ?Jurnal
    {
        return $this->kas()->jurnalReversalInjeksiKas($nominal, $keterangan, $userId, $transaksiId);
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
        return $this->deposito()->jurnalReversalPembukaanDeposito(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId,
        );
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
        return $this->deposito()->jurnalReversalPencairanDeposito(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId,
        );
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
        return $this->deposito()->jurnalReversalBagiHasilDepositoMudharabah(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId,
        );
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
        return $this->deposito()->jurnalReversalTarikDeposito(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId,
        );
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
        string $kodePenalti = self::DANA_KEBAJIKAN,
        ?int $userId = null,
        ?int $transaksiId = null,
        ?int $rekeningId = null,
        ?int $anggotaId = null
    ): ?Jurnal {
        return $this->deposito()->jurnalReversalPencairanDiniDeposito(
            $pokok, $penalti, $keterangan, $kodePenalti, $userId, $transaksiId, $rekeningId, $anggotaId,
        );
    }

    /**
     * Penerimaan Dana Kebajikan (ta'zir / denda / infak / kontribusi kebajikan).
     *
     * Sisi DEBET menyesuaikan sumber dana:
     *  - Tunai di loket      → 1-1001 Kas Teller (uang kas diterima)
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
        string $kodeDebet = self::KAS_TELLER
    ): ?Jurnal {
        return $this->kebajikan()->jurnalPenerimaanDanaKebajikan(
            $nominal, $keterangan, $userId, $transaksiId, $rekeningId, $anggotaId, $sumber, $kodeDebet
        );
    }

    /**
     * PENYALURAN Dana Kebajikan kepada penerima yang berhak (mustahiq).
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
        string $kodeSumberDana = self::KAS_TELLER
    ): ?Jurnal {
        return $this->kebajikan()->jurnalPenyaluranDanaKebajikan(
            $nominal, $keterangan, $userId, $transaksiId, $kodeSumberDana
        );
    }

    /**
     * Tentukan `rekening_id` untuk satu baris jurnal.
     *
     * Atribusi hanya sah bila akun baris memang akun simpanan yang sesuai
     * dengan `jenis_rekening` milik rekening tersebut. Sebelumnya satu
     * `$rekeningId` global ditempel ke SEMUA baris jurnal, sehingga baris
     * kas dan baris simpanan jenis lain (mis. Pokok/Wajib pada jurnal
     * bundling) ikut tertandai rekening yang salah. Subledger per rekening
     * menjadi menyesatkan. Baris tanpa rekening yang jelas dibiarkan NULL
     * (tidak diatribusikan) daripada diatribusikan keliru.
     */
    private function rekeningIdUntukCoa(Coa $coa, ?int $rekeningId): ?int
    {
        return $this->mesin()->rekeningIdUntukCoa($coa, $rekeningId);
    }

    /**
     * Mesin inti: buat Jurnal berpasangan ke CoA + validasi balance.
     *
     * Didelegasikan ke `PostingJurnal` — satu-satunya gerbang validasi,
     * keseimbangan, cut-off periode, dan penulisan buku besar.
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
    ): ?Jurnal {
        return $this->mesin()->post(
            $keterangan, $entries, $sumber, $transaksiId, $pembiayaanId, $userId,
            $rekeningId, $anggotaId, $tanggalJurnal, $postType, $noJurnal,
            $noRef, $jenisJurnal, $pembalikan, $reversal
        );
    }
}
