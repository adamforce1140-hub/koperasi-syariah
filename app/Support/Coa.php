<?php

namespace App\Support;

use App\Models\Akun as AkunModel;
use App\Models\Coa as CoaModel;
use Illuminate\Support\Facades\Log;

/**
 * Registri TUNGGAL (single source of truth) seluruh kode akun Chart of Accounts
 * (CoA) syariah yang dipakai lintas Controller, Service, Model, dan View.
 *
 * Sebelumnya kode akun tersebar sebagai konstanta lokal di ≥16 file
 * (AutoJournalingService, DepositoService, MandatorySavingsService, Deposito,
 * DepositoProduct, ClosingApprovalController, ShuCalculatorService,
 * Pengurus\ShuController, dsb.). Duplikasi itu membuat perubahan bagan akun
 * berisiko tinggi karena harus diburu manual di seluruh basis kode.
 *
 * Sifat:
 *   - TIDAK mengubah struktur inti: hanya memusatkan pemetaan kode akun.
 *   - Konstanta lama di setiap pemakai DIPERTAHANKAN sebagai alias
 *     (mis. `AutoJournalingService::KAS_TELLER = Coa::KAS_TELLER;`) sehingga
 *     kode lama dan seluruh test suite tetap kompatibel.
 *   - Format kode mengikuti tabel `coas` (mis. `1-1001`), BUKAN format legacy
 *     tabel `akun` (mis. `121`). Lihat catatan `LEGACY_*` di bawah.
 *   - Parameter persentase (nisbah/margin) TIDAK disimpan di sini; ia dinamis
 *     dan dibaca dari produk/kebijakan (PSAK 101-111).
 *
 * Pemakaian:
 *   Coa::KAS_TELLER;
 *   Coa::nama('1-2001');
 *   Coa::piutangByAkad('Mudharabah');
 */
final class Coa
{
    /* ── ASET (1-XXXX) ─────────────────────────────────────────────────── */

    public const KAS_TELLER = '1-1001';

    public const BANK_KOPERASI = '1-1002';

    public const KAS_BRANKAS = '1-1003';

    /** Piutang Teller / Selisih Kas (akun penampung kekurangan kas teller). */
    public const PIUTANG_TELLER = '1-1099';

    public const PIUTANG_MURABAHAH = '1-2001';

    public const PIUTANG_MUDHARABAH = '1-2002';

    public const PIUTANG_MUSYARAKAH = '1-2003';

    public const PIUTANG_IJARAH = '1-2004';

    public const PINJAMAN_QARDH = '1-2005';

    /** R9 — kode unik per akad jual-beli agar neraca dapat disajikan terpisah. */
    public const PIUTANG_ISTISHNA = '1-2006';

    public const PIUTANG_SALAM = '1-2007';

    /** Penyisihan Kerugian Penurunan Nilai (CKPN) piutang pembiayaan. */
    public const CKPN_PIUTANG_PEMBIAYAAN = '1-2100';

    public const BEBAN_CKPN_PEMBIAYAAN = '5-4001';

    /**
     * Margin Murabahah Ditangguhkan / Unearned Margin — AKUN KONTRA PIUTANG
     * (PSAK 102: "Marjin murabahah tangguhan disajikan sebagai pengurang
     * (contra account) piutang murabahah").
     *
     * Saat pencairan pembiayaan Murabahah, seluruh margin kontrak sudah pasti
     * namun belum menjadi pendapatan karena jasa belum terealisasi. Margin
     * ditangguhkan berada di sisi KREDIT akun ini dan dipindahkan bertahap ke
     * Pendapatan Margin Murabahah (4-1001) sejalan realisasi angsuran.
     *
     * Karena berkepala '1' (kelompok Aset) dengan `tipe_saldo_normal` 'Kredit',
     * akun ini dikenali sebagai akun KONTRA oleh Coa::isKontra() sehingga
     * saldonya bernilai negatif dan otomatis MENGURANGI total piutang/Aset —
     * persis penyajian "Piutang murabahah neto" pada laporan posisi keuangan.
     * Sebelumnya akun ini berkode '2-4001' dan keliru disajikan sebagai
     * Kewajiban Jangka Pendek (menggelembungkan Aset & Kewajiban sekaligus).
     */
    public const MARGIN_DITANGGUHKAN = '1-2101';

    /* ── KEWAJIBAN & DANA SYIRKAH TEMPORER (2-XXXX) ────────────────────── */

    public const SIMPANAN_SUKARELA = '2-1001';

    public const SIMPANAN_WAJIB = '2-1002';

    public const SIMPANAN_BERJANGKA = '2-1003';

    /** Utang SHU Ditahan (hold SHU anggota menunggak). */
    public const SHU_DITAHAN = '2-1004';

    /** Utang Dana Pengurus & Karyawan (pembagian SHU pos pengurus/karyawan). */
    public const UTANG_DANA_PENGURUS = '2-2001';

    public const DANA_KEBAJIKAN = '2-3001';

    /**
     * Dana Zakat — dana titipan (amanah) dari muzakki yang WAJIB disalurkan
     * kepada 8 asnaf (PSAK 109). Disajikan TERPISAH dari Dana Kebajikan
     * (2-3001) dan dari Dana Infak & Sedekah (2-3004) karena mustahik serta
     * batas penyalurannya berbeda. Bukan pendapatan dan bukan bagian SHU.
     */
    public const DANA_ZAKAT = '2-3002';

    /** Dana Sosial / Pos Sosial (pembagian SHU pos dana sosial). */
    public const DANA_SOSIAL = '2-3003';

    /**
     * Dana Infak & Sedekah — dana sosial sukarela (PSAK 109), dipisahkan dari
     * zakat dan dari dana kebajikan/ta'zir.
     */
    public const DANA_INFAK_SEDEKAH = '2-3004';

    /** Titipan Selisih Kas / Kewajiban Lain-Lain (akun penampung kelebihan kas teller). */
    public const TITIPAN_SELISIH_KAS = '2-3099';

    /* ── EKUITAS (3-XXXX) ─────────────────────────────────────────────── */

    public const SIMPANAN_POKOK = '3-1001';

    public const SIMPANAN_WAJIB_MODAL = '3-1002';

    public const CADANGAN_KOPERASI = '3-2001';

    public const IKHTISAR_SHU = '3-9000';

    /* ── PENDAPATAN (4-XXXX) ──────────────────────────────────────────── */

    public const PENDAPATAN_MARGIN = '4-1001';

    public const PENDAPATAN_BAGI_HASIL = '4-1002';

    public const PENDAPATAN_BAGI_HASIL_MUSYARAKAH = '4-1003';

    public const PENDAPATAN_IJARAH = '4-1004';

    public const PENDAPATAN_ADMIN = '4-2001';

    /* ── BEBAN (5-XXXX) ───────────────────────────────────────────────── */

    public const BEBAN_OPERASIONAL = '5-1001';

    public const BEBAN_ATK = '5-1002';

    public const BEBAN_UTILITAS = '5-1003';

    public const BEBAN_BAGI_HASIL_DEPOSITO = '5-3001';

    /* ── Akun Kas/Bank yang sah sebagai sumber pembayaran ───────────────── */

    /**
     * Kode akun likuid yang boleh dipakai sebagai sumber dana pembayaran
     * (pengeluaran beban, penyaluran dana, dsb.).
     *
     * Catatan: Kas di Brankas (1-1003) TIDAK termasuk di sini karena bukan
     * sumber pembayaran langsung — dana brankas harus dipindahkan ke laci
     * teller terlebih dahulu. Gunakan AKUN_KAS_ARUS_KAS untuk keperluan
     * pelaporan arus kas.
     *
     * @var array<int, string>
     */
    public const AKUN_KAS_LIKUID = [
        self::KAS_TELLER,
        self::BANK_KOPERASI,
    ];

    /**
     * Definisi "kas dan setara kas" untuk Laporan Arus Kas (PSAK 101 par. 12).
     *
     * Berbeda dari AKUN_KAS_LIKUID yang membatasi sumber pembayaran: seluruh
     * kas fisik milik koperasi — termasuk Kas di Brankas (1-1003) — adalah
     * setara kas, sehingga saldo awal/akhir serta mutasi arus kas wajib
     * mencakup brankas. Tanpa ini, transfer teller↔brankas tampak sebagai
     * arus kas keluar/masuk palsu dan kas brankas "hilang" dari saldo.
     *
     * @var array<int, string>
     */
    public const AKUN_KAS_ARUS_KAS = [
        self::KAS_TELLER,
        self::BANK_KOPERASI,
        self::KAS_BRANKAS,
    ];

    /**
     * Akun pos arus kas (sisi debit/kredit arus kas) yang dapat dikenali
     * langsung sebagai kas, melengkapi AKUN_KAS_ARUS_KAS untuk kebutuhan
     * atribusi aliran kas per akun lawan.
     *
     * @var array<int, string>
     */
    public const AKUN_KAS_TERLACAK = [
        self::KAS_TELLER,
        self::BANK_KOPERASI,
        self::KAS_BRANKAS,
        self::PIUTANG_TELLER,
    ];

    /* ── Akun format legacy (tabel `akun`, bukan `coas`) ─────────────────── */

    /**
     * Pemetaan kode CoA modern -> kode akun legacy pada tabel `akun`
     * (diisi `AkunSeeder`). Dipakai HANYA untuk bridging modul lama yang masih
     * menyimpan `akun_id` berformat numerik pendek (mis. `121`).
     *
     * JANGAN dipakai untuk jurnal baru — gunakan akun `coas`.
     *
     * @var array<string, string>
     */
    public const LEGACY_BY_COA = [
        self::KAS_TELLER => '111',
        self::BANK_KOPERASI => '112',
        self::PIUTANG_MURABAHAH => '121',
        self::PIUTANG_MUDHARABAH => '122',
        self::PINJAMAN_QARDH => '123',
        self::PIUTANG_MUSYARAKAH => '124',
        self::PIUTANG_IJARAH => '125',
    ];

    /**
     * Pemetaan kode CoA jenis simpanan -> akun legacy `rekening.akun_id`.
     *
     * `2-1003` (Simpanan Berjangka) sengaja mengikuti `2-1001` karena tabel
     * `akun` legacy tidak punya akun berjangka tersendiri.
     *
     * @var array<string, string>
     */
    public const LEGACY_SIMPANAN_BY_COA = [
        self::SIMPANAN_POKOK => '1131',
        self::SIMPANAN_WAJIB => '1132',
        self::SIMPANAN_SUKARELA => '1133',
        self::SIMPANAN_BERJANGKA => '1133',
    ];

    /** Akun legacy cadangan bila kode CoA jenis simpanan belum punya padanan. */
    public const LEGACY_SIMPANAN_DEFAULT = '1133';

    /**
     * Pemetaan akad pembiayaan -> kode akun piutang (normal saldo: Debet).
     *
     * Akad berbasis JUAL-BELI (Murabahah, Istishna, Salam) dan IMBT memakai
     * akun piutang murabahah, selaras `PendapatanService::AKAD_MARGIN`.
     *
     * @var array<string, string>
     */
    private const PIUTANG_BY_AKAD = [
        'mudharabah' => self::PIUTANG_MUDHARABAH,
        'mudarabah' => self::PIUTANG_MUDHARABAH,
        'musyarakah' => self::PIUTANG_MUSYARAKAH,
        'musyarakat' => self::PIUTANG_MUSYARAKAH,
        'musharakah' => self::PIUTANG_MUSYARAKAH,
        'ijarah' => self::PIUTANG_IJARAH,
        'ijaroh' => self::PIUTANG_IJARAH,
        'qardh' => self::PINJAMAN_QARDH,
        'qard' => self::PINJAMAN_QARDH,
        'qardhul hasan' => self::PINJAMAN_QARDH,
        'qard al hasan' => self::PINJAMAN_QARDH,
        'murabahah' => self::PIUTANG_MURABAHAH,
        'murabaha' => self::PIUTANG_MURABAHAH,
        'istishna' => self::PIUTANG_ISTISHNA,
        'salam' => self::PIUTANG_SALAM,
        'ijarah muntahia bittamlik' => self::PIUTANG_MURABAHAH,
    ];

    /**
     * Pemetaan akad pembiayaan -> kode akun pendapatan (normal saldo: Kredit).
     * Akad Qardh tidak menghasilkan pendapatan (null).
     *
     * @var array<string, string|null>
     */
    private const PENDAPATAN_BY_AKAD = [
        'mudharabah' => self::PENDAPATAN_BAGI_HASIL,
        'mudarabah' => self::PENDAPATAN_BAGI_HASIL,
        'musyarakah' => self::PENDAPATAN_BAGI_HASIL_MUSYARAKAH,
        'musyarakat' => self::PENDAPATAN_BAGI_HASIL_MUSYARAKAH,
        'musharakah' => self::PENDAPATAN_BAGI_HASIL_MUSYARAKAH,
        'ijarah' => self::PENDAPATAN_IJARAH,
        'ijaroh' => self::PENDAPATAN_IJARAH,
        'qardh' => null,
        'qard' => null,
        'qardhul hasan' => null,
        'qard al hasan' => null,
        'murabahah' => self::PENDAPATAN_MARGIN,
        'murabaha' => self::PENDAPATAN_MARGIN,
        'istishna' => self::PENDAPATAN_MARGIN,
        'salam' => self::PENDAPATAN_MARGIN,
        'ijarah muntahia bittamlik' => self::PENDAPATAN_MARGIN,
    ];

    /**
     * Kode akun piutang untuk akad tertentu (default: Murabahah).
     */
    public static function piutangByAkad(?string $akad): string
    {
        return self::PIUTANG_BY_AKAD[self::kunciAkad($akad)] ?? self::PIUTANG_MURABAHAH;
    }

    /**
     * Kode akun pendapatan untuk akad tertentu; null bila akad tanpa margin
     * (Qardh) atau akad belum dikenal (fallback: Pendapatan Margin).
     */
    public static function pendapatanByAkad(?string $akad): ?string
    {
        $kunci = self::kunciAkad($akad);

        if (array_key_exists($kunci, self::PENDAPATAN_BY_AKAD)) {
            return self::PENDAPATAN_BY_AKAD[$kunci];
        }

        return self::PENDAPATAN_MARGIN;
    }

    /**
     * Pemetaan akad pembiayaan -> kode akun Margin Ditangguhkan (PSAK 108).
     *
     * Hanya akad jual-beli (Murabahah dan turunannya: Istishna, Salam, IMBT)
     * yang memiliki margin pasti dan karena itu ditangguhkan pada saat
     * pencairan. Akad bagi hasil (Mudharabah/Musyarakah), sewa (Ijarah), dan
     * Qardh tidak memiliki margin ditangguhkan → null.
     *
     * @var array<string, string|null>
     */
    private const MARGIN_DITANGGUHKAN_BY_AKAD = [
        'murabahah' => self::MARGIN_DITANGGUHKAN,
        'murabaha' => self::MARGIN_DITANGGUHKAN,
        'istishna' => self::MARGIN_DITANGGUHKAN,
        'salam' => self::MARGIN_DITANGGUHKAN,
        'ijarah muntahia bittamlik' => self::MARGIN_DITANGGUHKAN,
        'mudharabah' => null,
        'mudarabah' => null,
        'musyarakah' => null,
        'musyarakat' => null,
        'musharakah' => null,
        'ijarah' => null,
        'ijaroh' => null,
        'qardh' => null,
        'qard' => null,
        'qardhul hasan' => null,
        'qard al hasan' => null,
    ];

    /**
     * Kode akun Margin Ditangguhkan untuk akad tertentu; null bila akad tidak
     * memiliki margin ditangguhkan (bagi hasil, sewa, atau Qardh).
     *
     * Akad yang belum dikenal diperlakukan sebagai Murabahah (fallback aman,
     * konsisten dengan piutangByAkad()).
     */
    public static function marginDitangguhkanByAkad(?string $akad): ?string
    {
        $kunci = self::kunciAkad($akad);

        if (array_key_exists($kunci, self::MARGIN_DITANGGUHKAN_BY_AKAD)) {
            return self::MARGIN_DITANGGUHKAN_BY_AKAD[$kunci];
        }

        return self::MARGIN_DITANGGUHKAN;
    }

    /**
     * Kebalikan dari legacy(): kode CoA modern untuk sebuah kode akun LEGACY.
     *
     * Dipakai saat menerima masukan dari klien/skema lama yang masih
     * mengirim `kode_akun` tabel `akun` (mis. "121"), agar baris jurnal tetap
     * tersimpan pada kolom kanonik `coa_id` (mis. "1-2001").
     *
     * Bila beberapa kode CoA memakai akun legacy yang sama (mis. Simpanan
     * Sukarela & Berjangka sama-sama `1133`), yang dikembalikan adalah kode
     * CoA pertama menurut urutan registri — pemanggil yang butuh kepastian
     * sebaiknya mengirim `coa_id` secara eksplisit.
     */
    public static function coaDariLegacy(string $kodeLegacy): ?string
    {
        $kode = trim($kodeLegacy);

        if ($kode === '') {
            return null;
        }

        foreach ([self::LEGACY_BY_COA, self::LEGACY_SIMPANAN_BY_COA] as $peta) {
            $hasil = array_search($kode, $peta, true);

            if ($hasil !== false) {
                return $hasil;
            }
        }

        return null;
    }

    /**
     * Kode akun legacy (tabel `akun`) untuk sebuah kode CoA modern.
     *
     * Mencakup dua kelompok pemetaan: piutang/kas (`LEGACY_BY_COA`) dan jenis
     * simpanan (`LEGACY_SIMPANAN_BY_COA`). Mengembalikan null bila kode CoA
     * tidak punya padanan pada tabel `akun` legacy.
     */
    public static function legacy(string $kodeCoa): ?string
    {
        return self::LEGACY_BY_COA[$kodeCoa]
            ?? self::LEGACY_SIMPANAN_BY_COA[$kodeCoa]
            ?? null;
    }

    /**
     * ID akun pada tabel `akun` legacy untuk sebuah kode CoA modern.
     *
     * Dipakai modul yang masih menyimpan FK `akun_id` (mis. `rekening`,
     * `pembiayaan`) agar kode CoA tidak perlu ditulis ulang sebagai kode legacy
     * di Controller/Service. Null bila padanan tidak ditemukan.
     */
    public static function akunLegacyId(string $kodeCoa): ?int
    {
        $kodeLegacy = self::legacy($kodeCoa);

        if ($kodeLegacy === null) {
            return null;
        }

        try {
            $id = AkunModel::query()->where('kode_akun', $kodeLegacy)->value('id');

            return $id === null ? null : (int) $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Nama akun menurut basis data CoA (null bila akun belum terdaftar).
     * Berguna untuk audit kelengkapan bagan akun.
     */
    public static function nama(string $kode): ?string
    {
        try {
            return CoaModel::query()->where('kode_akun', $kode)->value('nama_akun');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * F-06 — Ambil model CoA TERDAFTAR untuk sebuah kode registri, tanpa
     * pernah membuat akun baru secara otomatis.
     *
     * Sebelumnya modul SHU memakai `Coa::firstOrCreate([...])` sehingga kode
     * dapat diam-diam menambah akun ke buku besar — praktik berisiko dari sisi
     * tata kelola (pembukaan akun adalah kewenangan pengurus, bukan efek
     * samping transaksi). Helper ini mengubah kontraknya menjadi:
     *
     *   1. Utamakan akun ber-ID dari Kebijakan Koperasi (`Setting::akunId()`
     *      melalui $settingId, mis. `account_shu_ditahan_id`) bila diberikan.
     *   2. Jika tidak, cari akun AKTIF berdasarkan kode registri.
     *   3. Bila akun tidak ditemukan → fail-fast (RuntimeException) dengan
     *      pesan tindakan, sehingga operator membukanya lebih dulu lewat menu
     *      Master COA / seeder.
     *
     * @param  string  $kode  Kode akun registri, mis. `Coa::SHU_DITAHAN`.
     * @param  string  $konteks  Label modul untuk pesan galat.
     * @param  string|null  $settingId  Nama setting `account_*_id` (opsional).
     *
     * @throws \RuntimeException bila akun terdaftar tidak ada.
     */
    public static function terdaftar(string $kode, string $konteks = 'jurnal', ?string $settingId = null): CoaModel
    {
        $coa = null;

        if ($settingId !== null) {
            $coaId = Setting::akunId($settingId);

            if ($coaId !== null) {
                $coa = CoaModel::query()->where('id', $coaId)->where('is_active', true)->first();

                if ($coa === null) {
                    Log::warning("Coa: setting [{$settingId}] menunjuk CoA id={$coaId} yang tidak ada/nonaktif — jatuh ke kode registri {$kode}.");
                }
            }
        }

        if ($coa === null) {
            $coa = CoaModel::query()->where('kode_akun', $kode)->where('is_active', true)->first();
        }

        if ($coa === null) {
            throw new \RuntimeException(
                "CoA {$kode} belum terdaftar/aktif dan diperlukan untuk {$konteks}. "
                .'Buka akun tersebut lebih dulu lewat menu Master COA '
                .'(atau jalankan php artisan db:seed --class=CoaSeeder) — '
                .'akun tidak lagi dibuat otomatis saat transaksi berjalan.'
            );
        }

        return $coa;
    }

    /**
     * Seluruh kode akun yang diregistrasi kelas ini (untuk pengujian/audit).
     *
     * @return array<int, string>
     */
    public static function semuaKode(): array
    {
        $refleksi = new \ReflectionClass(self::class);
        $kode = [];

        foreach ($refleksi->getConstants() as $nama => $nilai) {
            if (is_string($nilai) && preg_match('/^\d-\d{4}$/', $nilai) === 1) {
                $kode[$nilai] = $nilai;
            }
        }

        return array_values($kode);
    }

    /**
     * Toleransi pembulatan selisih (floating-point) standar sistem, dalam rupiah.
     * Dipakai untuk cek keseimbangan jurnal & cek status keuangan.
     */
    public static function toleransiSelisih(): float
    {
        return Setting::toleransiSelisihJurnal();
    }

    /** Normalisasi kunci akad (huruf kecil, tanpa spasi ganda). */
    private static function kunciAkad(?string $akad): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $akad) ?? ''));
    }
}
