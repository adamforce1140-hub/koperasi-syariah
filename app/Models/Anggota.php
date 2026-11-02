<?php

namespace App\Models;

use App\Services\TahunBukuService;
use App\Support\HierarkiRole;
use App\Support\PiiHasher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Anggota extends Model
{
    use HasFactory, SoftDeletes;

    /* ── Siklus hidup keanggotaan (enum `status_anggota`) ───────────────── */

    /** Baru mendaftar, setoran awal (Pokok + Wajib bulan 1) belum lunas. */
    public const STATUS_PENDING = 'PENDING';

    /** Setoran awal tervalidasi — berhak atas seluruh layanan simpanan. */
    public const STATUS_ACTIVE = 'ACTIVE';

    /** Tidak aktif sementara (mis. tidak ada transaksi / anggota pasif). */
    public const STATUS_INACTIVE = 'INACTIVE';

    /** Dibekukan karena pelanggaran / tunggakan. */
    public const STATUS_SUSPENDED = 'SUSPENDED';

    /**
     * Anggota sudah keluar secara RESMI (exit keanggotaan selesai).
     *
     * `TERMINATED` adalah status kanonik untuk anggota yang telah
     * menyelesaikan proses di modul `member_terminations` — seluruh dana
     * Simpanan Pokok (3-1001) & Simpanan Wajib (3-1002) telah dikembalikan
     * dan jurnal PSAK 108 telah diposting. Nilai ini lebih spesifik daripada
     * `INACTIVE`: ia menandakan berakhirnya keanggotaan, bukan sekadar pasif.
     *
     * Nilai `'Keluar'` dipertahankan sebagai ALIAS legacy (lihat
     * `STATUS_ALIASES`) agar data & view lama tetap terbaca.
     */
    public const STATUS_KELUAR = 'TERMINATED';

    /** Padanan eksplisit — nickname historis `Keluar` = `TERMINATED`. */
    public const STATUS_TERMINATED = 'TERMINATED';

    public const STATUS_LIST = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_KELUAR,
    ];

    /** Label Indonesia untuk tampilan badge. */
    public const STATUS_LABEL = [
        self::STATUS_PENDING => 'Menunggu Setoran Awal',
        self::STATUS_ACTIVE => 'Aktif',
        self::STATUS_INACTIVE => 'Tidak Aktif',
        self::STATUS_SUSPENDED => 'Dibekukan',
        self::STATUS_TERMINATED => 'Keluar (Terminasi)',
    ];

    /**
     * Alias legacy yang masih sah pada enum `status_anggota` (kompatibilitas
     * data lama) dan padanan nilai kanoniknya.
     */
    public const STATUS_ALIASES = [
        'Keluar' => self::STATUS_TERMINATED,
        'Aktif' => self::STATUS_ACTIVE,
        'Tidak Aktif' => self::STATUS_INACTIVE,
        'Nonaktif' => self::STATUS_INACTIVE,
        'Dibekukan' => self::STATUS_SUSPENDED,
    ];

    /* ── CDD / PEP / Pemilik Manfaat (OJK-08) ──────────────────────────────
     | Dasar: POJK No. 27/POJK.03/2022 (APU-PPT), UU No. 8/2010 Pasal 18,
     | Perpres No. 13/2018 (prinsip mengenali pemilik manfaat).
     | ------------------------------------------------------------------- */

    /** Belum/tidak tergolong Politically Exposed Person. */
    public const PEP_BUKAN = 'Bukan PEP';

    /** Tergolong PEP (pejabat publik / penyelenggara negara / pihak terkait). */
    public const PEP_YA = 'PEP';

    /** Pilihan sah `pep_status` pada form. */
    public const PEP_PILIHAN = [self::PEP_BUKAN, self::PEP_YA];

    /** CDD standar — profil risiko rendah/sedang. */
    public const CDD_SEDERHANA = 'Sederhana';

    /** Enhanced Due Diligence — wajib untuk PEP / risiko tinggi. */
    public const CDD_MENDALAM = 'Mendalam';

    public const CDD_PILIHAN = [self::CDD_SEDERHANA, self::CDD_MENDALAM];

    /** Kategori profil risiko nasabah (POJK 27/2022). */
    public const RISIKO_RENDAH = 'Rendah';

    public const RISIKO_SEDANG = 'Sedang';

    public const RISIKO_TINGGI = 'Tinggi';

    public const RISIKO_PILIHAN = [self::RISIKO_RENDAH, self::RISIKO_SEDANG, self::RISIKO_TINGGI];

    /**
     * Ubah nilai status apa pun (kanonik, beda huruf besar/kecil, atau alias
     * legacy) menjadi nilai kanonik. Mengembalikan null bila tidak dikenali.
     */
    public static function normalizeStatus(?string $status): ?string
    {
        $status = trim((string) $status);

        if ($status === '') {
            return null;
        }

        foreach (self::STATUS_LIST as $kanonik) {
            if (strcasecmp($kanonik, $status) === 0) {
                return $kanonik;
            }
        }

        foreach (self::STATUS_ALIASES as $alias => $kanonik) {
            if (strcasecmp($alias, $status) === 0) {
                return $kanonik;
            }
        }

        return null;
    }

    /**
     * Seluruh nilai yang boleh dikirim form — dipakai aturan validasi
     * `in:...` agar daftar yang divalidasi selalu sama dengan yang
     * ditawarkan dropdown.
     *
     * @return list<string>
     */
    public static function statusValidValues(): array
    {
        return array_values(array_unique(
            array_merge(self::STATUS_LIST, array_keys(self::STATUS_ALIASES))
        ));
    }

    /**
     * Status yang boleh ditetapkan MANUAL oleh operator.
     *
     * Nilai ACTIVE & PENDING hanya boleh lahir dari alur sistem:
     *   - PENDING → otomatis saat pendaftaran CIF disimpan;
     *   - ACTIVE  → otomatis setelah setoran awal bundling tervalidasi.
     *
     * `TERMINATED` (Keluar) juga TIDAK ditawarkan manual: status ini hanya
     * boleh lahir dari modul `member_terminations` setelah seluruh dana
     * Simpanan Pokok & Wajib dikembalikan dan jurnal PSAK 108 diposting.
     * Ini menutup celah "keluar tanpa pengembalian dana".
     *
     * @var list<string>
     */
    public const STATUS_PILIHAN_MANUAL = [
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
    ];

    /**
     * Peran yang berwenang mengubah status keanggotaan menjadi
     * SUSPENDED / INACTIVE / CLOSED.
     *
     * Dibaca dari sumber pusat `config/role_hierarki.php`
     * (`kelompok.pengubah_status_anggota`), bukan konstanta literal, sehingga
     * penambahan peran cukup diubah di konfigurasi.
     */
    public static function peranBolehUbahStatus(?string $peran): bool
    {
        return HierarkiRole::dalamKelompok($peran, 'pengubah_status_anggota');
    }

    /**
     * Nilai-nilai yang setara dengan satu status kanonik di kolom DB
     * (kanonik + alias legacy-nya) — untuk query WHERE IN.
     *
     * @return list<string>
     */
    public static function statusStorageValues(?string $status): array
    {
        $kanonik = self::normalizeStatus($status);

        if ($kanonik === null) {
            return [];
        }

        $nilai = [$kanonik];

        foreach (self::STATUS_ALIASES as $alias => $target) {
            if ($target === $kanonik) {
                $nilai[] = $alias;
            }
        }

        return $nilai;
    }

    protected $table = 'anggota';

    protected $fillable = [
        'no_anggota',
        'no_cif',
        'user_id',
        'nik',
        'foto_ktp',
        'nama_lengkap',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'status_perkawinan',
        'alamat',
        'rt_rw',
        'kelurahan',
        'kecamatan',
        'kota',
        'provinsi',
        'kode_pos',
        'no_telepon',
        'no_hp',
        'email',
        'pekerjaan',
        'nama_ibu_kandung',
        // ── CDD / PEP / Pemilik Manfaat (OJK-08) ──
        'cdd_jenis',
        'cdd_verified_at',
        'cdd_verified_by',
        'pep_status',
        'pep_keterangan',
        'pemilik_manfaat_nama',
        'pemilik_manfaat_hubungan',
        'pemilik_manfaat_keterangan',
        'profil_risiko',
        'status_anggota',
        'is_auto_debit',
        'tanggal_gabung',
        'tanggal_keluar',
        // ── Jejak audit exit keanggotaan (modul `member_terminations`) ──
        'tanggal_terminasi',
        'total_dana_dikembalikan',
        'terminasi_diproses_oleh',
        // ── Exit & Closure (pengunduran diri anggota) ──
        'exit_date',
        'exit_reason',
        'exit_approved_by',
        'simpanan_pokok',
        'simpanan_wajib',
        'keterangan',
    ];

    protected $casts = [
        // NIK disimpan sebagai ciphertext (AES-256-CBC + MAC, IV acak).
        // Konsekuensinya kolom ini TIDAK dapat dipakai untuk `unique()` maupun
        // `WHERE nik = ?` — keduanya dilayani oleh blind index `nik_hash`.
        // Lihat App\Support\PiiHasher & docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md.
        'nik' => 'encrypted',
        'tanggal_lahir' => 'date',
        'tanggal_gabung' => 'date',
        'tanggal_keluar' => 'date',
        'tanggal_terminasi' => 'date',
        'total_dana_dikembalikan' => 'decimal:2',
        'exit_date' => 'date',
        'exit_approved_by' => 'integer',
        'simpanan_pokok' => 'decimal:2',
        'simpanan_wajib' => 'decimal:2',
        'is_auto_debit' => 'boolean',
        // OJK-08: waktu verifikasi identitas (CDD) selesai.
        'cdd_verified_at' => 'datetime',
    ];

    /**
     * Kolom internal yang tidak pernah dikirim ke tampilan/JSON.
     *
     * `nik_hash` adalah blind index: tidak dapat dibalik, tetapi tetap tidak
     * perlu dipublikasikan ke klien.
     *
     * @var list<string>
     */
    protected $hidden = ['nik_hash'];

    /** Accessor komputasi yang disertakan pada serialisasi (tampilan badge). */
    protected $appends = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rekening(): HasMany
    {
        return $this->hasMany(Rekening::class);
    }

    public function pembiayaan(): HasMany
    {
        return $this->hasMany(Pembiayaan::class);
    }

    /** Bilyet Deposito / Simpanan Berjangka milik anggota (akad Mudharabah). */
    public function depositos(): HasMany
    {
        return $this->hasMany(Deposito::class, 'member_id');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function jurnalDetail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    public function scopeAktif($query)
    {
        return $query->whereIn('status_anggota', [
            self::STATUS_ACTIVE,
            'Aktif', // toleransi data lama yang belum ternormalisasi
        ]);
    }

    /** Filter berdasarkan satu status — menerima nilai kanonik maupun alias. */
    public function scopeStatus($query, ?string $status)
    {
        $nilai = self::statusStorageValues($status);

        return $nilai === [] ? $query : $query->whereIn('status_anggota', $nilai);
    }

    /** Anggota yang baru mendaftar & belum melunasi setoran awal. */
    public function scopePending($query)
    {
        return $query->where('status_anggota', self::STATUS_PENDING);
    }

    /** Semua anggota dalam siklus aktif (bukan yang sudah keluar/terminasi). */
    public function scopeDalamSiklus($query)
    {
        // Menyaring nilai kanonik `TERMINATED` sekaligus alias legacy `Keluar`.
        return $query->whereNotIn('status_anggota', self::statusStorageValues(self::STATUS_TERMINATED));
    }

    /* ── CDD / PEP / Pemilik Manfaat (OJK-08) ────────────────────────────── */

    /** Anggota yang tergolong Politically Exposed Person. */
    public function scopePep($query)
    {
        return $query->where('pep_status', self::PEP_YA);
    }

    /** Apakah anggota tergolong PEP? */
    public function isPep(): bool
    {
        return $this->pep_status === self::PEP_YA;
    }

    /** Apakah identitas sudah diverifikasi (CDD selesai)? */
    public function sudahCdd(): bool
    {
        return $this->cdd_verified_at !== null;
    }

    /**
     * Kelengkapan CDD minimum: penilaian PEP sudah diisi dan identitas
     * telah diverifikasi. Dipakai sebagai gerbang sebelum anggota diaktifkan.
     */
    public function cddLengkap(): bool
    {
        return $this->sudahCdd()
            && in_array($this->pep_status, self::PEP_PILIHAN, true);
    }

    /** Tagihan Simpanan Wajib bulanan anggota. */
    public function mandatorySavingsBills(): HasMany
    {
        return $this->hasMany(MandatorySavingsBill::class, 'member_id');
    }

    /**
     * Riwayat pengajuan exit keanggotaan (modul `member_terminations`).
     *
     * Satu-satunya pintu sah pengembalian dana Simpanan Pokok (3-1001) &
     * Simpanan Wajib (3-1002).
     */
    public function memberTerminations(): HasMany
    {
        return $this->hasMany(MemberTermination::class, 'anggota_id');
    }

    /** Apakah anggota berada dalam siklus aktif (belum keluar/terminasi). */
    public function isDalamSiklus(): bool
    {
        return $this->normalizeStatus($this->status_anggota) !== self::STATUS_TERMINATED;
    }

    /** Apakah anggota sudah keluar resmi (terminasi selesai). */
    public function isTerminated(): bool
    {
        return $this->normalizeStatus($this->status_anggota) === self::STATUS_TERMINATED;
    }

    public function isActive(): bool
    {
        return $this->normalizeStatus($this->status_anggota) === self::STATUS_ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->normalizeStatus($this->status_anggota) === self::STATUS_PENDING;
    }

    /** Label status untuk badge tampilan. */
    public function getStatusLabelAttribute(): string
    {
        $kanonik = $this->normalizeStatus($this->status_anggota);

        return ($kanonik !== null ? self::STATUS_LABEL[$kanonik] : null)
            ?? (string) ($this->status_anggota ?? '—');
    }

    /* =====================================================================
     | UMUR KEANGGOTAAN (Member Age) & MASA AKTIF (Tenure Months)
     |===================================================================== */

    /** Tanggal acuan awal keanggotaan — tanggal_gabung, fallback created_at. */
    public function tanggalMulaiKeanggotaan(): ?Carbon
    {
        $mulai = $this->tanggal_gabung ?? $this->created_at;

        return $mulai ? Carbon::parse($mulai)->startOfDay() : null;
    }

    /**
     * Umur keanggotaan — durasi sejak tanggal bergabung hingga hari ini,
     * diformat mudah dibaca: '1 Tahun 3 Bulan 5 Hari'.
     *
     * Bagian yang bernilai nol dihilangkan agar ringkas; bila keanggotaan
     * baru berjalan beberapa jam (0 tahun, 0 bulan, 0 hari), dikembalikan
     * '0 Hari' agar tidak pernah kosong.
     */
    public function getMemberAgeAttribute(): string
    {
        $mulai = $this->tanggalMulaiKeanggotaan();

        if (! $mulai) {
            return '0 Hari';
        }

        $sekarang = Carbon::today();

        // Anggota dengan tanggal bergabung di masa depan (impor data keliru).
        if ($mulai->gt($sekarang)) {
            return '0 Hari';
        }

        $selisih = $mulai->diff($sekarang);

        $bagian = [];
        if ($selisih->y > 0) {
            $bagian[] = $selisih->y.' Tahun';
        }
        if ($selisih->m > 0) {
            $bagian[] = $selisih->m.' Bulan';
        }
        if ($selisih->d > 0) {
            $bagian[] = $selisih->d.' Hari';
        }

        return $bagian === [] ? '0 Hari' : implode(' ', $bagian);
    }

    /** Jumlah bulan penuh keanggotaan sejak tanggal bergabung hingga hari ini. */
    public function getMemberAgeMonthsAttribute(): int
    {
        $mulai = $this->tanggalMulaiKeanggotaan();

        if (! $mulai || $mulai->gt(Carbon::today())) {
            return 0;
        }

        return (int) $mulai->diffInMonths(Carbon::today());
    }

    /**
     * Total bulan keanggotaan AKTIF — dasar kalkulasi SHU Prorated.
     *
     * Hanya bulan saat status anggota ACTIVE yang dihitung:
     *  - anggota berstatus `Keluar`   → berhenti pada tanggal_keluar
     *  - anggota berstatus SUSPENDED / INACTIVE / PENDING saat ini
     *    → masa aktifnya berhenti pada tanggal_keluar (bila ada), jika tidak
     *      ada maka seluruh periode sejak bergabung tetap dihitung karena
     *      sistem tidak memiliki jejak perubahan status historis.
     *
     * @param  int|null  $tahunBuku  Batasi perhitungan hanya pada tahun buku
     *                               tertentu (mis. 2026). Null = sejak awal.
     */
    public function getTenureMonthsAttribute(?int $tahunBuku = null): int
    {
        $mulai = $this->tanggalMulaiKeanggotaan();

        if (! $mulai) {
            return 0;
        }

        $akhir = Carbon::today();
        if ($tahunBuku !== null) {
            // Rentang tahun buku mengikuti parameter kebijakan (tahun buku
            // non-kalender), bukan hardcode 1 Jan – 31 Des.
            $tahunBukuSvc = app(TahunBukuService::class);
            $awalBuku = $tahunBukuSvc->awalPeriode($tahunBuku);
            $akhirBuku = $tahunBukuSvc->akhirPeriode($tahunBuku);

            // Anggota belum bergabung pada tahun buku tersebut.
            if ($mulai->gt($akhirBuku)) {
                return 0;
            }

            // Mulai dihitung sejak awal tahun buku (atau tanggal gabung
            // bila bergabung di tengah tahun).
            $mulai = $mulai->lt($awalBuku) ? $awalBuku : $mulai;
            $akhir = $akhirBuku->gt(Carbon::today()) ? Carbon::today() : $akhirBuku;
        }

        // Anggota keluar → masa aktif berhenti di tanggal keluar.
        if ($this->tanggal_keluar) {
            $keluar = Carbon::parse($this->tanggal_keluar)->endOfDay();
            if ($keluar->lt($akhir)) {
                $akhir = $keluar;
            }
        } elseif (! $this->isActive()) {
            // Status non-aktif tanpa tanggal keluar: masa depan tidak dihitung.
            $akhir = Carbon::today();
        }

        if ($akhir->lt($mulai)) {
            return 0;
        }

        // Tahun buku: hitung JUMLAH BULAN keanggotaan DI DALAM tahun tersebut
        // (bulan bergabung & bulan berjalan ikut dihitung) sehingga anggota
        // yang aktif penuh satu tahun buku memperoleh tepat 12 bulan —
        // faktor prorata Jasa Modal menjadi 12/12 = 1,0 dan tidak ada anggota
        // yang kehilangan sebagian porsi SHU-nya.
        if ($tahunBuku !== null) {
            return (int) round(
                $mulai->copy()->startOfMonth()->diffInMonths($akhir->copy()->startOfMonth())
            ) + 1;
        }

        return (int) $mulai->diffInMonths($akhir);
    }

    /** Label masa aktif ringkas, mis. "8 Bulan". */
    public function getTenureLabelAttribute(): string
    {
        return $this->tenure_months.' Bulan';
    }

    /**
     * Nomor anggota berikutnya, dijamin unik (format `{prefix}{NNNN}`).
     *
     * Urutan diambil dari sufiks NUMERIK terbesar pada nomor ber-prefix sama —
     * bukan `count() + 1` yang mudah bertabrakan setelah penghapusan atau saat
     * dua pendaftaran berjalan bersamaan (race). Kandidat yang masih terpakai
     * (termasuk baris soft-deleted) dilewati sampai nomor bebas.
     */
    public static function nomorAnggotaBaru(string $prefix, int $panjang = 4): string
    {
        $baris = static::withTrashed()
            ->where('no_anggota', 'like', $prefix.'%')
            ->selectRaw(
                'MAX(CAST(SUBSTRING(no_anggota, ?) AS UNSIGNED)) AS maks_urut',
                [strlen($prefix) + 1]
            )
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::withTrashed()->where('no_anggota', $kandidat)->exists());

        return $kandidat;
    }

    /** Auto-generate no_cif jika belum diisi saat create */
    protected static function booted(): void
    {
        static::creating(function (Anggota $anggota) {
            if (empty($anggota->no_cif)) {
                $urut = str_pad((static::withTrashed()->max('id') ?? 0) + 1, 4, '0', STR_PAD_LEFT);
                $anggota->no_cif = 'CIF-'.date('Ymd').'-'.$urut;
            }
        });

        /* Jaga sinkronisasi blind index `nik_hash` dengan nilai `nik`.
         | Dijalankan pada setiap penyimpanan (create & update) sehingga tidak
         | ada jalur kode yang bisa menulis NIK tanpa memperbarui indeksnya.
         | `nik_hash` sengaja TIDAK ada di $fillable agar tidak dapat diisi
         | langsung dari input pengguna (mass assignment). */
        static::saving(function (Anggota $anggota) {
            if ($anggota->isDirty('nik')) {
                $anggota->nik_hash = PiiHasher::hash($anggota->nik);
            }
        });
    }

    /**
     * Cari anggota berdasarkan NIK memakai blind index (kecocokan tepat).
     *
     * Wajib dipakai sebagai pengganti `where('nik', $nik)` karena kolom `nik`
     * kini berisi ciphertext non-deterministik.
     *
     * @param  Builder<Anggota>  $query
     */
    public function scopeDenganNik($query, ?string $nik)
    {
        $hash = PiiHasher::hash($nik);

        // NIK kosong tidak boleh mencocokkan baris apa pun (termasuk baris
        // yang `nik_hash`-nya NULL).
        return $hash === null
            ? $query->whereRaw('1 = 0')
            : $query->where('nik_hash', $hash);
    }

    public function getNamaDanNoAttribute(): string
    {
        return "{$this->no_anggota} - {$this->nama_lengkap}";
    }

    public function getTotalSaldoSimpananAttribute(): float
    {
        return $this->rekening()->sum('saldo');
    }
}
