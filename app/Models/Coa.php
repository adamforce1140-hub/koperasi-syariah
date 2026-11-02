<?php

namespace App\Models;

use App\Support\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coa extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'coas';

    protected $fillable = [
        'kode_akun',
        'nama_akun',
        'kategori',
        'klasifikasi_neraca',
        'has_percentage',
        'percentage_type',
        'default_percentage_member',
        'default_percentage_institution',
        'tipe_saldo_normal',
        'saldo_awal',
        'is_active',
    ];

    protected $casts = [
        'saldo_awal' => 'decimal:2',
        'is_active' => 'boolean',
        'has_percentage' => 'boolean',
        'percentage_type' => 'string',
        'default_percentage_member' => 'decimal:2',
        'default_percentage_institution' => 'decimal:2',
    ];

    /* ============ PARAMETER PERSENTASE DINAMIS (PSAK 101/108) ============
     | Satu sumber kebenaran untuk nisbah bagi hasil, rate margin pembiayaan,
     | dan penanda akun yang imbalannya lewat SHU Tahunan. Nilainya dapat
     | diubah kapan saja lewat menu Master COA tanpa mengubah kode.
     */

    /** Nisbah bagi hasil (Simpanan Sukarela, Simpanan Berjangka). */
    public const PERCENTAGE_NISBAH = 'nisbah';

    /** Rate margin keuntungan pembiayaan (Murabahah). */
    public const PERCENTAGE_MARGIN = 'margin';

    /** Imbalan diselesaikan lewat SHU Tahunan (Simpanan Pokok & Wajib). */
    public const PERCENTAGE_SHU = 'shu';

    /** Pilihan `percentage_type` untuk dropdown form Master COA. */
    public const PERCENTAGE_TYPE = [
        self::PERCENTAGE_NISBAH,
        self::PERCENTAGE_MARGIN,
        self::PERCENTAGE_SHU,
    ];

    /** Label manusiawi tiap tipe persentase (untuk tampilan tabel/form). */
    public const PERCENTAGE_LABEL = [
        self::PERCENTAGE_NISBAH => 'Nisbah Bagi Hasil',
        self::PERCENTAGE_MARGIN => 'Rate Margin Pembiayaan',
        self::PERCENTAGE_SHU => 'SHU Tahunan (Ekuitas)',
    ];

    /** Apakah akun ini benar-benar menerapkan persentase pada transaksi. */
    public function menerapkanPersentase(): bool
    {
        return (bool) $this->has_percentage;
    }

    /** Label tipe persentase; null bila akun tidak memakai persentase. */
    public function getPercentageLabelAttribute(): ?string
    {
        if (! $this->percentage_type) {
            return null;
        }

        return self::PERCENTAGE_LABEL[$this->percentage_type] ?? ucfirst($this->percentage_type);
    }

    /** Nisbah Anggota / Rate Margin yang berlaku (float, 0 bila kosong). */
    public function persentaseAnggota(): float
    {
        return (float) ($this->default_percentage_member ?? 0);
    }

    /** Nisbah Koperasi yang berlaku (float, 0 bila kosong). */
    public function persentaseInstitusi(): float
    {
        return (float) ($this->default_percentage_institution ?? 0);
    }

    /**
     * Nilai persentase berpasangan untuk dijadikan snapshot akad.
     *
     * @return array{applied_percentage_member: float|null, applied_percentage_institution: float|null}
     */
    public function snapshotPersentase(): array
    {
        if (! $this->menerapkanPersentase()) {
            return [
                'applied_percentage_member' => null,
                'applied_percentage_institution' => null,
            ];
        }

        return [
            'applied_percentage_member' => $this->persentaseAnggota(),
            'applied_percentage_institution' => $this->persentaseInstitusi(),
        ];
    }

    /** Ambil nilai persentase anggota dari master berdasarkan kode akun. */
    public static function persentaseAnggotaByKode(string $kode, float $fallback = 0.0): float
    {
        $coa = static::findByKode($kode);

        return $coa && $coa->menerapkanPersentase()
            ? $coa->persentaseAnggota()
            : $fallback;
    }

    /** Scope: akun yang menerapkan persentase (nisbah/margin/SHU). */
    public function scopeBerpersentase($query)
    {
        return $query->where('has_percentage', true);
    }

    /** Scope: filter akun menurut tipe persentasenya. */
    public function scopePercentageType($query, string $tipe)
    {
        return $query->where('percentage_type', $tipe);
    }

    /* ================= KLASIFIKASI STANDAR (PSAK 101) =================
     | `kategori` = 5 pos besar; `klasifikasi_neraca` = kelompok penyajian.
     | Urutan array menentukan urutan penyajian pada Laporan Neraca.
     */

    /** Klasifikasi yang sah per kategori (validasi & dropdown form). */
    public const KLASIFIKASI = [
        'Aset' => [
            'Aset Lancar',
            'Aset Tidak Lancar',
        ],
        'Kewajiban' => [
            'Kewajiban Jangka Pendek',
            'Kewajiban Jangka Panjang',
            'Dana Syirkah Temporer',
        ],
        'Ekuitas' => [
            'Modal Anggota',
            'Dana Cadangan',
            'Saldo Laba / SHU',
        ],
        'Pendapatan' => [
            'Pendapatan Usaha',
            'Pendapatan Lain-lain',
        ],
        'Beban' => [
            'Beban Usaha',
            'Beban Lain-lain',
        ],
    ];

    /** Daftar klasifikasi yang mungkin tersedia untuk akun baru (form CoA). */
    public static function klasifikasiUntuk(string $kategori): array
    {
        return self::KLASIFIKASI[$kategori] ?? [];
    }

    /** Label klasifikasi lengkap "Aset Lancar" dsb.; null bila belum dipetakan. */
    public function getKlasifikasiLabelAttribute(): ?string
    {
        return $this->klasifikasi_neraca ?: null;
    }

    public function jurnalDetail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    /**
     * Jenis jurnal penutup — menolkan akun Pendapatan & Beban lalu memindahkan
     * hasilnya ke 3-9000 Ikhtisar SHU.
     *
     * Jurnal ini WAJIB dikecualikan saat menyusun laporan Laba Rugi dan Posisi
     * Keuangan untuk periode yang sudah ditutup buku; bila tidak, pendapatan &
     * beban periode tersebut terbaca nol dan laporan tampak "impas semu".
     */
    public const JENIS_JURNAL_PENUTUP = 'Jurnal Penutup';

    /**
     * Total mutasi debet — hanya dari jurnal berstatus Posted.
     *
     * @param  string|null  $sampai  Batas tanggal (Y-m-d). Jika diisi, hanya
     *                               jurnal dengan tanggal_jurnal <= $sampai
     *                               yang dihitung. Memakai tanggal_jurnal
     *                               (tanggal kejadian), bukan created_at, agar
     *                               jurnal bertanggal mundur tetap masuk
     *                               periode yang benar.
     * @param  list<string>  $kecualiJenis  Jenis jurnal yang tidak diikutkan.
     *                                      Default kosong = perilaku lama (semua
     *                                      jenis) sehingga Buku Besar & verifikasi
     *                                      tutup buku tetap melihat jurnal penutup.
     */
    public function totalDebet(?string $sampai = null, array $kecualiJenis = []): float
    {
        return (float) $this->jurnalDetail()
            ->whereHas('jurnal', function ($q) use ($sampai, $kecualiJenis) {
                $q->where('status', 'Posted');
                if ($kecualiJenis !== []) {
                    $q->whereNotIn('jenis_jurnal', $kecualiJenis);
                }
                if ($sampai !== null) {
                    // Kolom `tanggal_jurnal` bertipe DATE, jadi perbandingan
                    // langsung setara dengan whereDate() namun SARGABLE:
                    // membungkus kolom dengan DATE() membuat indeks komposit
                    // (status, tanggal_jurnal) tidak dapat dipakai untuk range.
                    $q->where('tanggal_jurnal', '<=', $sampai);
                }
            })
            ->sum('debet');
    }

    /** Total mutasi kredit — hanya dari jurnal berstatus Posted. */
    public function totalKredit(?string $sampai = null, array $kecualiJenis = []): float
    {
        return (float) $this->jurnalDetail()
            ->whereHas('jurnal', function ($q) use ($sampai, $kecualiJenis) {
                $q->where('status', 'Posted');
                if ($kecualiJenis !== []) {
                    $q->whereNotIn('jenis_jurnal', $kecualiJenis);
                }
                if ($sampai !== null) {
                    // Lihat catatan pada totalDebet(): rentang sargable.
                    $q->where('tanggal_jurnal', '<=', $sampai);
                }
            })
            ->sum('kredit');
    }

    /* ================= RUMUS SALDO BERDASARKAN POSISI (KEPALA AKUN) =================
     |
     | Saldo akun ditentukan oleh POSISI akun dalam persamaan dasar akuntansi,
     | yang dikenali dari digit pertama (Kepala) kode akun:
     |
     |   Kepala 1 (Aset)       → posisi DEBET   → saldo = Total Debet  − Total Kredit
     |   Kepala 2 (Kewajiban)  → posisi KREDIT  → saldo = Total Kredit − Total Debet
     |   Kepala 3 (Ekuitas)    → posisi KREDIT  → saldo = Total Kredit − Total Debet
     |   Kepala 4 (Pendapatan) → posisi KREDIT  → saldo = Total Kredit − Total Debet
     |   Kepala 5 (Beban)      → posisi DEBET   → saldo = Total Debet  − Total Kredit
     |
     | PENEGASAN KHUSUS EKUITAS (KEPALA 3):
     | Seluruh akun Ekuitas/Modal — termasuk 3-9000 Ikhtisar SHU — WAJIB memakai
     | rumus (Total Kredit − Total Debet). Memakai rumus terbalik (Total Debet −
     | Total Kredit) membuat saldo akun Ekuitas terbaca sebagai nilai negatif pada
     | kondisi koperasi untung, karena penambahan ekuitas dicatat di sisi Kredit.
     | Karena itu posisi akun diturunkan dari Kepala kode akun, bukan hanya dari
     | kolom `tipe_saldo_normal`, supaya akun Ekuitas tetap benar meskipun kolom
     | itu keliru terisi 'Debet' karena salah input di form CoA.
     */

    /**
     * Posisi saldo normal sebuah akun: 'Debet' atau 'Kredit'.
     *
     * ⚠ PENTING — jangan tertukar dengan kolom `tipe_saldo_normal`:
     *   - kolom `tipe_saldo_normal` = LABEL yang tersimpan di basis data (bisa
     *     berbeda dari posisi turunan, khususnya pada akun kontra);
     *   - method ini = POSISI EFEKTIF yang diturunkan dari Kepala kode akun,
     *     dan INILAH satu-satunya rujukan untuk menghitung saldo & arah mutasi.
     *
     * Seluruh perhitungan saldo (saldoBerjalan(), saldoAkhirBaris(), laporan
     * keuangan, Buku Besar, verifikasi jurnal) WAJIB memakai method ini,
     * bukan kolom mentah `tipe_saldo_normal`.
     */
    public function posisiSaldo(): string
    {
        return static::posisiSaldoDariKode($this->kode_akun, $this->tipe_saldo_normal);
    }

    /**
     * Apakah akun ini akun KONTRA (contra account) — akun yang disajikan
     * sebagai pengurang kelompoknya karena saldo normal sebenarnya berlawanan
     * dengan Kepala kode akunnya?
     *
     * Kasus nyata: 1-2100 Penyisihan Kerugian Piutang Pembiayaan (CKPN) —
     * berkepala 1 (kelompok Aset) tetapi kolom `tipe_saldo_normal` = 'Kredit'
     * karena ia MENGURANGI nilai piutang pembiayaan (PSAK 71 / PSAK 101).
     *
     * Akun kontra tetap dihitung dengan posisi turunan Kepala (yaitu 'Debet'),
     * sehingga saldoBerjalan()-nya bernilai NEGATIF. Nilai negatif itu memang
     * yang benar: saat seluruh akun Aset dijumlahkan (jumlahPresisi()),
     * akun kontra otomatis MENGURANGI total Aset — persis seperti penyajian
     * "Piutang Pembiayaan neto" pada laporan Posisi Keuangan.
     *
     * ⚠ Karena itu jangan pernah "memperbaiki" kolom `tipe_saldo_normal`
     * 1-2100 menjadi 'Debet' maupun mengubah turunan posisi Kepala 1 menjadi
     * 'Kredit' — keduanya akan MENAMBAH total Aset dan merusak keseimbangan
     * Neraca.
     */
    public function isKontra(): bool
    {
        if ($this->tipe_saldo_normal === null || $this->tipe_saldo_normal === '') {
            return false;
        }

        return $this->posisiSaldo() !== $this->tipe_saldo_normal;
    }

    /**
     * Deteksi posisi saldo normal HANYA dari awalan (kepala) kode akun —
     * tanpa input manual dari pengguna:
     *
     *   1-xxxx (Aset)       → Debet
     *   2-xxxx (Kewajiban)  → Kredit
     *   3-xxxx (Ekuitas)    → Kredit
     *   4-xxxx (Pendapatan) → Kredit
     *   5-xxxx (Beban)      → Debet
     *
     * Kepala tak dikenal (akun baru di luar bagan standar) menghormati kolom
     * `tipe_saldo_normal`; bila kosong dianggap Kredit (aman untuk Kewajiban/
     * Ekuitas/Pendapatan, yang paling umum memakai akun non-standar).
     *
     * AKUN KONTRA (mis. 1-2100 CKPN) tetap mengikuti Kepala kodenya di sini.
     * Akibatnya saldoBerjalan() akun kontra bernilai negatif dan otomatis
     * mengurangi total kelompoknya — lihat isKontra() untuk penjelasan lengkap.
     * Cabang '1' TIDAK BOLEH diubah menjadi 'Kredit' hanya karena akun kontra.
     *
     * Dipisah sebagai static agar rumus ini bisa diuji langsung untuk SETIAP
     * jenis akun tanpa harus menyentuh basis data.
     */
    public static function posisiSaldoDariKode(?string $kodeAkun, ?string $tipeSaldoNormal = null): string
    {
        return match (substr((string) $kodeAkun, 0, 1)) {
            '1', '5' => 'Debet',   // Aset, Beban, dan akun kontra (Akumulasi Penyusutan 1-xxxx)
            '2', '3', '4' => 'Kredit', // Kewajiban, Ekuitas/Modal, Pendapatan
            default => $tipeSaldoNormal === 'Debet' ? 'Debet' : 'Kredit',
        };
    }

    /**
     * Saldo akhir (berjalan) sebuah baris mutasi bila rumusnya diterapkan pada
     * saldo awal tertentu — mengikuti POSISI akun, bukan tebakan:
     *
     *   Posisi Debet  (1-xxxx Aset, 5-xxxx Beban)      → Saldo Awal + Debet − Kredit
     *   Posisi Kredit (2/3/4-xxxx Kewajiban/Ekuitas/
     *                  Pendapatan)                     → Saldo Awal + Kredit − Debet
     *
     * Dipakai view Buku Besar untuk kolom "Saldo Kumulatif" per baris.
     */
    public function saldoAkhirBaris(float $saldoAwal, float $debet, float $kredit): float
    {
        return $this->posisiSaldo() === 'Debet'
            ? $saldoAwal + $debet - $kredit
            : $saldoAwal + $kredit - $debet;
    }

    /** Apakah akun ini termasuk kategori Ekuitas/Modal (Kepala 3)? */
    public function isEkuitas(): bool
    {
        return substr((string) $this->kode_akun, 0, 1) === '3';
    }

    /**
     * Saldo akun sesuai POSISI akun (lihat tabel di atas), per tanggal tertentu.
     *
     * @param  string|null  $sampai  Batas tanggal (Y-m-d). null = seluruh periode.
     *
     * Nilai bisa negatif bila mutasi berlawanan arah melebihi saldo normalnya —
     * mis. akun 3-9000 setelah jurnal distribusi SHU yang mendebet tanpa jurnal
     * penutup laba yang mengkredit. Gunakan saldoBerjalanTampil() bila yang
     * dibutuhkan adalah angka yang aman ditampilkan ke pengguna.
     */
    public function saldoBerjalan(?string $sampai = null): float
    {
        $debet = $this->totalDebet($sampai);
        $kredit = $this->totalKredit($sampai);

        return $this->posisiSaldo() === 'Debet'
            ? (float) $this->saldo_awal + $debet - $kredit
            : (float) $this->saldo_awal + $kredit - $debet;
    }

    /**
     * Saldo akun untuk PENYAJIAN LAPORAN KEUANGAN — jurnal penutup dikecualikan.
     *
     * Jurnal penutup menolkan Pendapatan & Beban dan memindahkan hasilnya ke
     * 3-9000 Ikhtisar SHU. Bila efeknya ikut dihitung, laporan Laba Rugi
     * periode yang sudah ditutup buku akan menampilkan pendapatan & beban nol
     * ("impas semu") — padahal realisasinya ada.
     *
     * Kedua sisi harus dikecualikan bersama-sama agar identitas neraca tetap
     * utuh: Pendapatan/Beban kembali ke nilai riil, dan 3-9000 tidak lagi
     * memuat laba yang sama (mencegah hitung ganda pada Total Pasiva).
     *
     * ⚠ `saldoBerjalan()` SENGAJA tidak diubah: Buku Besar, verifikasi tutup
     * buku, dan Coa::labaBersihBerjalan() memang harus melihat efek jurnal
     * penutup apa adanya.
     */
    public function saldoBerjalanLaporan(?string $sampai = null): float
    {
        $kecuali = [self::JENIS_JURNAL_PENUTUP];

        $debet = $this->totalDebet($sampai, $kecuali);
        $kredit = $this->totalKredit($sampai, $kecuali);

        return $this->posisiSaldo() === 'Debet'
            ? (float) $this->saldo_awal + $debet - $kredit
            : (float) $this->saldo_awal + $kredit - $debet;
    }

    /**
     * Sama seperti saldoBerjalan() tapi selalu kembalikan nilai mentah (bisa negatif).
     * Digunakan di view untuk menampilkan tanda kurung pada nilai minus.
     */
    public function saldoBerjalanRaw(): float
    {
        return $this->saldoBerjalan();
    }

    /** Mutasi akun ini searah posisinya (menambah saldo) — mis. Kredit untuk Ekuitas. */
    public function mutasiPenambah(?string $sampai = null): float
    {
        return $this->posisiSaldo() === 'Debet'
            ? $this->totalDebet($sampai)
            : $this->totalKredit($sampai);
    }

    /** Mutasi akun ini berlawanan posisinya (mengurangi saldo) — mis. Debet untuk Ekuitas. */
    public function mutasiPengurang(?string $sampai = null): float
    {
        return $this->posisiSaldo() === 'Debet'
            ? $this->totalKredit($sampai)
            : $this->totalDebet($sampai);
    }

    /**
     * Selisih mutasi berlawanan arah terhadap saldo yang tersedia.
     *
     * Nol berarti sehat: mutasi pengurang masih tertutup oleh saldo awal +
     * mutasi penambah. Nilai positif berarti akun tertekan ke arah berlawanan
     * posisinya — inilah besar "kelebihan Debet" pada akun Ekuitas.
     */
    public function kelebihanMutasiBerlawanan(?string $sampai = null): float
    {
        return Setting::bulatkanUang(
            $this->mutasiPengurang($sampai)
                - ((float) $this->saldo_awal + $this->mutasiPenambah($sampai))
        );
    }

    /**
     * Nilai saldo yang aman ditampilkan ke pengguna.
     *
     * Akun berposisi Kredit (Kewajiban/Ekuitas/Pendapatan) yang tertekan ke
     * arah Debet tidak pernah ditampilkan sebagai angka minus; kelebihannya
     * dilaporkan terpisah lewat `akun_bermasalah` supaya penyebabnya tetap
     * terlihat oleh auditor, bukan disembunyikan.
     *
     * @return array{nilai: float, mentah: float, ditampilkan_negatif: bool, akun_bermasalah: bool}
     */
    public function saldoBerjalanTampil(?string $sampai = null): array
    {
        $mentah = $this->saldoBerjalan($sampai);
        $bermasalah = $mentah < 0 && $this->posisiSaldo() === 'Kredit';

        return [
            'nilai' => $bermasalah ? 0.0 : $mentah,
            'mentah' => $mentah,
            'ditampilkan_negatif' => ! $bermasalah && $mentah < 0,
            'akun_bermasalah' => $bermasalah,
        ];
    }

    /**
     * Apakah saldo berjalan akun ini negatif (di luar posisi normalnya)?
     * Contoh: 3-9000 setelah jurnal distribusi SHU tanpa jurnal penutup laba.
     */
    public function isSaldoNegatif(): bool
    {
        return $this->saldoBerjalan() < 0;
    }

    /* ================= LABA / RUGI BERJALAN ================= */

    /**
     * Laba (SHU) bersih berjalan: Pendapatan − Beban.
     *
     * Positif = laba/untung, negatif = rugi, nol = impas (break-even).
     * Dipakai untuk memverifikasi STATUS keuangan sebelum menampilkan saldo
     * akun Ekuitas — supaya "akun Ekuitas minus saat koperasi untung" bisa
     * dikenali sebagai anomali jurnal, bukan dikira posisi yang wajar.
     *
     * Memakai `saldoBerjalanLaporan()` — jurnal penutup dikecualikan — agar
     * angka ini konsisten dengan Laporan Laba Rugi dan kolom Laba Berjalan
     * pada Neraca. Bila memakai `saldoBerjalan()`, periode yang sudah ditutup
     * buku akan terbaca impas semu (Pendapatan & Beban sudah dinolkan jurnal
     * penutup) dan status keuangan koperasi salah dilaporkan.
     *
     * @param  string|null  $sampai  Batas tanggal (Y-m-d). null = seluruh periode.
     */
    public static function labaBersihBerjalan(?string $sampai = null): float
    {
        $pendapatan = static::where('kategori', 'Pendapatan')->where('is_active', true)->get()
            ->sum(fn (self $c) => $c->saldoBerjalanLaporan($sampai));

        $beban = static::where('kategori', 'Beban')->where('is_active', true)->get()
            ->sum(fn (self $c) => $c->saldoBerjalanLaporan($sampai));

        return Setting::bulatkanUang((float) $pendapatan - (float) $beban);
    }

    /**
     * Status keuangan berjalan: 'untung', 'rugi', atau 'impas'.
     *
     * Toleransi selisih dibaca dari parameter kebijakan koperasi
     * (Setting::toleransiSelisihJurnal(), default 1 sen) bila tidak diberikan.
     */
    public static function statusKeuangan(?string $sampai = null, ?float $toleransi = null): string
    {
        $toleransi ??= Setting::toleransiSelisihJurnal();

        $laba = static::labaBersihBerjalan($sampai);

        if (abs($laba) <= $toleransi) {
            return 'impas';
        }

        return $laba > 0 ? 'untung' : 'rugi';
    }

    /**
     * Scope: akun Pendapatan & Beban (kandidat reset tutup buku).
     */
    public function scopeResetable($query)
    {
        return $query->whereIn('kategori', ['Pendapatan', 'Beban']);
    }

    /** Scope: filter akun berdasarkan klasifikasi penyajiannya. */
    public function scopeKlasifikasi($query, string $klasifikasi)
    {
        return $query->where('klasifikasi_neraca', $klasifikasi);
    }

    /** Scope: akun yang belum dipetakan ke klasifikasi standar (temuan validasi). */
    public function scopeBelumDiklasifikasi($query)
    {
        return $query->where(fn ($q) => $q->whereNull('klasifikasi_neraca')->orWhere('klasifikasi_neraca', ''));
    }

    /** Lookup cepat akun aktif berdasarkan kode. */
    public static function findByKode(string $kode): ?self
    {
        return static::where('kode_akun', $kode)->where('is_active', true)->first();
    }

    public function getKategoriBadgeAttribute(): string
    {
        return match ($this->kategori) {
            'Aset' => '#dbeafe|#1e40af',
            'Kewajiban' => '#fef3c7|#92400e',
            'Ekuitas' => '#e0e7ff|#3730a3',
            'Pendapatan' => '#d1fae5|#065f46',
            'Beban' => '#fee2e2|#991b1b',
            default => '#f1f5f9|#334155',
        };
    }
}
