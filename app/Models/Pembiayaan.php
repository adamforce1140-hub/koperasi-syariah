<?php

namespace App\Models;

use App\Enums\DisbursementMethod;
use App\Services\CkpnService;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Pembiayaan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'pembiayaan';

    /**
     * Metadata tampilan status (label manusiawi + warna) — SATU sumber untuk
     * legend/badge dashboard. Kunci = nilai kanonik lowercase hasil
     * {@see normalizeStatus()}, sehingga legend dan donut chart tidak pernah
     * memakai daftar status yang berbeda (temuan audit F2/C2).
     *
     * @return array<string, array{label: string, color: string}>
     */
    public static function statusMeta(): array
    {
        return [
            self::STATUS_PENDING_AO => ['label' => 'Menunggu AO',              'color' => '#f59e0b'],
            self::STATUS_PENDING_MANAJER => ['label' => 'Menunggu Manajer',         'color' => '#fbbf24'],
            self::STATUS_PENDING_PENGURUS => ['label' => 'Menunggu Pengurus',        'color' => '#8b5cf6'],
            self::STATUS_PENDING_DPS => ['label' => 'Menunggu DPS',             'color' => '#6366f1'],
            self::STATUS_DIREVIEW => ['label' => 'Direview',                 'color' => '#0ea5e9'],
            self::STATUS_DISETUJUI => ['label' => 'Disetujui',                'color' => '#10b981'],
            self::STATUS_DISAHKAN => ['label' => 'Disahkan',                 'color' => '#14b8a6'],
            self::STATUS_PENDING_PENCAIRAN => ['label' => 'Menunggu Pencairan',       'color' => '#06b6d4'],
            self::STATUS_DICAIRKAN => ['label' => 'Dicairkan',                'color' => '#0284c7'],
            self::STATUS_AKTIF => ['label' => 'Aktif',                    'color' => '#059669'],
            self::STATUS_LUNAS => ['label' => 'Lunas',                    'color' => '#3b82f6'],
            self::STATUS_DITOLAK => ['label' => 'Ditolak',                  'color' => '#ef4444'],
            self::STATUS_MACET => ['label' => 'Macet',                    'color' => '#dc2626'],
            self::STATUS_RESTRUKTURISASI => ['label' => 'Restrukturisasi',          'color' => '#d97706'],
            self::STATUS_PENDING_RESTRUKTURISASI => ['label' => 'Menunggu Restrukturisasi', 'color' => '#f97316'],
            self::STATUS_DIBATALKAN => ['label' => 'Dibatalkan',               'color' => '#94a3b8'],
            self::STATUS_HAPUS_BUKU => ['label' => 'Hapus Buku',               'color' => '#64748b'],
        ];
    }

    /* ---------------------------------------------------------------------
     | Status Pembiayaan — nilai kanonik (selalu lowercase snake_case)
     | Semua penulisan status WAJIB memakai konstanta di bawah ini.
     | ------------------------------------------------------------------- */
    public const STATUS_PENDING_AO = 'pending_ao';

    public const STATUS_PENDING_MANAJER = 'pending_manajer';

    public const STATUS_PENDING_PENGURUS = 'pending_pengurus';

    public const STATUS_PENDING_DPS = 'pending_dps';

    public const STATUS_DIREVIEW = 'direview';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DISAHKAN = 'disahkan';

    public const STATUS_PENDING_PENCAIRAN = 'pending_pencairan';

    public const STATUS_DICAIRKAN = 'dicairkan';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_LUNAS = 'lunas';

    public const STATUS_DITOLAK = 'ditolak';

    public const STATUS_MACET = 'macet';

    public const STATUS_RESTRUKTURISASI = 'restrukturisasi';

    public const STATUS_PENDING_RESTRUKTURISASI = 'pending_restrukturisasi';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    /**
     * CKPN-5 — piutang MACET yang telah DIHAPUS BUKUKAN (write-off).
     *
     * Berbeda dari {@see STATUS_LUNAS} (dibayar penuh) dan {@see STATUS_MACET}
     * (masih tercatat di neraca): status ini menandai aset keuangan yang sudah
     * DIHENTIKAN PENGAKUANNYA (derecognized) sesuai PSAK 71, dengan sisa nilai
     * tercatat dihapus terhadap cadangan kerugian (akun 1-2100).
     *
     * Pembiayaan berstatus ini TIDAK BOLEH lagi:
     *   - dihitung dalam basis CKPN ({@see CkpnService::rincian()}),
     *   - diproses autodebet, dan
     *   - muncul sebagai piutang berjalan pada laporan posisi keuangan.
     *
     * Penagihan tetap dapat dilanjutkan secara administratif; pemulihan yang
     * diterima diakui sebagai pendapatan lain-lain (tidak mengembalikan aset).
     */
    public const STATUS_HAPUS_BUKU = 'hapus_buku';

    /**
     * Sinonim "cair" untuk STATUS_DICAIRKAN.
     * Dipakai modul DPS/Akad yang menyebut tahap pencairan sebagai "cair".
     * Nilainya identik dengan STATUS_DICAIRKAN — bukan status baru.
     */
    public const STATUS_CAIR = self::STATUS_DICAIRKAN;

    /**
     * Alias / varian lama -> nilai kanonik.
     * Kunci selalu lowercase; pencocokan dilakukan case-insensitive.
     */
    private const STATUS_ALIASES = [
        'draft' => self::STATUS_PENDING_AO,
        'pengajuan' => self::STATUS_PENDING_AO,
        'pending' => self::STATUS_PENDING_AO,
        'menunggu' => self::STATUS_PENDING_AO,
        'dikaji' => self::STATUS_DIREVIEW,
        'review' => self::STATUS_DIREVIEW,
        'disetujui_manajer' => self::STATUS_DISETUJUI,
        'disetujui_pengurus' => self::STATUS_DISETUJUI,
        'npf' => self::STATUS_MACET,
        'terlambat' => self::STATUS_MACET,
        'bermasalah' => self::STATUS_MACET,
        'hapusbuku' => self::STATUS_HAPUS_BUKU,
        'hapus buku' => self::STATUS_HAPUS_BUKU,
        'writeoff' => self::STATUS_HAPUS_BUKU,
        'write-off' => self::STATUS_HAPUS_BUKU,
        'write_off' => self::STATUS_HAPUS_BUKU,
        'menunggu pencairan' => self::STATUS_PENDING_PENCAIRAN,
        'menunggu_approval_restrukturisasi' => self::STATUS_PENDING_RESTRUKTURISASI,
        // Istilah "cair" dipakai modul DPS / akad sebagai sinonim "dicairkan".
        'cair' => self::STATUS_DICAIRKAN,
    ];

    /**
     * Normalisasi nilai status ke bentuk kanonik (case-insensitive).
     */
    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $key = strtolower(trim($status));

        if ($key === '') {
            return $status;
        }

        return self::STATUS_ALIASES[$key] ?? $key;
    }

    /**
     * Daftar seluruh nilai kanonik yang dikenal.
     *
     * @return array<int, string>
     */
    public static function canonicalStatuses(): array
    {
        return [
            self::STATUS_PENDING_AO,
            self::STATUS_PENDING_MANAJER,
            self::STATUS_PENDING_PENGURUS,
            self::STATUS_PENDING_DPS,
            self::STATUS_DIREVIEW,
            self::STATUS_DISETUJUI,
            self::STATUS_DISAHKAN,
            self::STATUS_PENDING_PENCAIRAN,
            self::STATUS_DICAIRKAN,
            self::STATUS_AKTIF,
            self::STATUS_LUNAS,
            self::STATUS_DITOLAK,
            self::STATUS_MACET,
            self::STATUS_RESTRUKTURISASI,
            self::STATUS_PENDING_RESTRUKTURISASI,
            self::STATUS_DIBATALKAN,
            self::STATUS_HAPUS_BUKU,
        ];
    }

    /* ---------------------------------------------------------------------
     | Akad Syariah — daftar kanonik (satu sumber kebenaran).
     |
     | Nilai WAJIB sama persis dengan ENUM kolom
     | `pembiayaan.akad_pembiayaan` agar validasi, dropdown, dan penyimpanan
     | tidak pernah memakai daftar yang berbeda. Sebelumnya daftar akad
     | terduplikasi dengan isi berbeda (5 / 6 / 8 akad) di beberapa controller.
     | ------------------------------------------------------------------- */
    public const AKAD_MURABAHAH = 'Murabahah';

    public const AKAD_MUDHARABAH = 'Mudharabah';

    public const AKAD_MUSYARAKAH = 'Musyarakah';

    public const AKAD_IJARAH = 'Ijarah';

    public const AKAD_IMBT = 'Ijarah Muntahia Bittamlik';

    public const AKAD_QARDH = 'Qardh';

    public const AKAD_ISTISHNA = 'Istishna';

    public const AKAD_SALAM = 'Salam';

    /**
     * Akad syariah KANONIK bawaan sistem (registri standar).
     *
     * Daftar ini sengaja tetap berupa konstanta karena menjadi JARING PENGAMAN:
     * bila tabel pemetaan akad belum dimigrasikan / kosong, sistem tetap
     * mengenali delapan akad standar ini. Akad TAMBAHAN tidak ditulis di sini,
     * melainkan ditambahkan pengurus lewat pemetaan akad (basis data).
     *
     * @return array<int, string>
     */
    public static function daftarAkadKanonik(): array
    {
        return [
            self::AKAD_MURABAHAH,
            self::AKAD_MUDHARABAH,
            self::AKAD_MUSYARAKAH,
            self::AKAD_IJARAH,
            self::AKAD_IMBT,
            self::AKAD_QARDH,
            self::AKAD_ISTISHNA,
            self::AKAD_SALAM,
        ];
    }

    /**
     * Seluruh akad syariah yang dikenali sistem.
     *
     * = akad kanonik + akad tambahan hasil pemetaan pengurus (tabel
     * `pemetaan_akad_coa`). Dengan begitu akad baru dapat ditambahkan lewat
     * UI tanpa mengubah kode sumber; lihat App\Models\PemetaanAkadCoa.
     *
     * @return array<int, string>
     */
    public static function daftarAkad(): array
    {
        return PemetaanAkadCoa::daftarAkad();
    }

    /**
     * Akad default untuk data lama atau kolom akad yang kosong.
     *
     * Sumber tunggal: `config/pembiayaan.php` (`akad_default`). Nilai tetap
     * divalidasi terhadap daftar akad aktif agar konfigurasi yang salah tidak
     * menghasilkan akad tak dikenal; bila tidak valid, jatuh ke akad kanonik
     * pertama (Murabahah). Pengganti seluruh fallback akad literal (DYN-09).
     */
    public static function akadDefault(): string
    {
        $akad = trim((string) config('pembiayaan.akad_default', self::AKAD_MURABAHAH));

        return in_array($akad, self::daftarAkad(), true) ? $akad : self::AKAD_MURABAHAH;
    }

    /**
     * Aturan validasi Laravel untuk kolom akad pembiayaan.
     *
     * @return array<int, string>
     */
    public static function aturanValidasiAkad(bool $wajib = true): array
    {
        return [
            $wajib ? 'required' : 'nullable',
            'string',
            Rule::in(self::daftarAkad()),
        ];
    }

    /**
     * Akad syariah dalam bentuk peta nilai => label untuk dropdown.
     *
     * @return array<string, string>
     */
    public static function pilihanAkad(): array
    {
        $pilihan = [];

        foreach (self::daftarAkad() as $akad) {
            $pilihan[$akad] = $akad;
        }

        return $pilihan;
    }

    protected $fillable = [
        'no_pembiayaan',
        'anggota_id',
        'akun_id',
        'akad_pembiayaan',
        'tujuan_pembiayaan',
        'jumlah_pembiayaan',
        'margin_nisbah',
        'total_ujroh',
        'total_pembiayaan',
        'tenor',
        'frekuensi_angsuran',
        'jumlah_angsuran',
        'nisbah_pengelola',
        'nisbah_pemilik',
        'tanggal_akad',
        'tanggal_cair',
        'tanggal_jatuh_tempo',
        'rekening_id',
        'status_pembiayaan',
        'status_kolektibilitas',
        'tanggal_hapus_buku',
        'sisa_pembiayaan',
        'agunan',
        'user_approved',
        'keterangan',
        'direview_oleh',
        'tanggal_direview',
        'catatan_manajer',
        'disetujui_oleh',
        'tanggal_disetujui',
        'catatan_pengurus',
        // DPS
        'disahkan_oleh',
        'tanggal_disahkan',
        'catatan_dps',
        'tgl_pencairan',
        'tgl_dicairkan',
        // Pencairan (dropping) — PSAK 108, dinamis tanpa hardcode CoA.
        'disbursement_method',
        'bank_account_id',
        'savings_account_id',
        'margin_deferred_amount',
        // Penanda eksposur & agunan back-to-back. WAJIB ada di $fillable —
        // sebelumnya kolom ini terbuang diam-diam oleh mass-assignment sehingga
        // penjagaan "cegah double pencairan" (is_disbursed) tidak pernah aktif.
        'is_disbursed',
        'is_low_risk',
        'agunan_tipe',
        'deposito_id',
        // Snapshot persentase COA/produk saat akad dibuat (anti korupsi data historis).
        'applied_percentage_member',
        'applied_percentage_institution',
    ];

    protected $casts = [
        'jumlah_pembiayaan' => 'decimal:2',
        'margin_nisbah' => 'decimal:2',
        'total_ujroh' => 'decimal:2',
        'total_pembiayaan' => 'decimal:2',
        'jumlah_angsuran' => 'decimal:2',
        'nisbah_pengelola' => 'decimal:2',
        'nisbah_pemilik' => 'decimal:2',
        'tanggal_akad' => 'date',
        'tanggal_cair' => 'date',
        'tanggal_jatuh_tempo' => 'date',
        'tgl_pencairan' => 'date',
        'tgl_dicairkan' => 'datetime',
        'tanggal_hapus_buku' => 'date',
        // Cast enum memaksa nilai kolom selalu sah (CASH | TRANSFER_BANK |
        // TRANSFER_SAVINGS). Nilai liar dari luar sistem ditolak saat dibaca
        // sehingga logika pencairan tidak pernah menerima metode tak dikenal.
        'disbursement_method' => DisbursementMethod::class,
        'margin_deferred_amount' => 'decimal:2',
        'is_disbursed' => 'boolean',
        'is_low_risk' => 'boolean',
        'deposito_id' => 'integer',
        'bank_account_id' => 'integer',
        'savings_account_id' => 'integer',
        'sisa_pembiayaan' => 'decimal:2',
        'applied_percentage_member' => 'decimal:2',
        'applied_percentage_institution' => 'decimal:2',
    ];

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    /**
     * Produk diambil via pengajuan (pengajuan_pembiayaan.produk_id),
     * karena tabel pembiayaan tidak memiliki kolom produk_id.
     */
    public function produk(): HasOneThrough
    {
        return $this->hasOneThrough(
            MasterProdukPembiayaan::class,
            PengajuanPembiayaan::class,
            'pembiayaan_id',   // FK di pengajuan_pembiayaan
            'id',              // PK di master_produk_pembiayaan
            'id',              // PK di pembiayaan
            'produk_id'        // FK di pengajuan_pembiayaan
        );
    }

    public function akun(): BelongsTo
    {
        return $this->belongsTo(Akun::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_approved');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'direview_oleh');
    }

    public function approvedFinalBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function disahkanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disahkan_oleh');
    }

    public function dokumen(): HasMany
    {
        return $this->hasMany(DokumenPembiayaan::class);
    }

    public function angsuran(): HasMany
    {
        return $this->hasMany(Angsuran::class)->orderBy('angsuran_ke');
    }

    public function jadwalAngsuran(): HasMany
    {
        return $this->hasMany(Angsuran::class, 'pembiayaan_id')->orderBy('angsuran_ke');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function jurnal(): HasMany
    {
        return $this->hasMany(Jurnal::class);
    }

    public function restrukturisasiRequests(): HasMany
    {
        return $this->hasMany(RestrukturisasiRequest::class)->latest();
    }

    /**
     * Accessor: semua pembacaan status selalu dalam bentuk kanonik (lowercase).
     */
    public function getStatusPembiayaanAttribute($value): ?string
    {
        return self::normalizeStatus($value);
    }

    /**
     * Mutator: semua penulisan status selalu disimpan dalam bentuk kanonik.
     */
    public function setStatusPembiayaanAttribute($value): void
    {
        $this->attributes['status_pembiayaan'] = self::normalizeStatus(
            $value === null ? null : (string) $value
        );
    }

    /**
     * Scope query status yang case-insensitive.
     *
     * @param  Builder  $query
     * @param  array<int, string>  $statuses
     */
    public function scopeStatusIn($query, array $statuses)
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(fn ($s) => self::normalizeStatus($s), $statuses),
            fn ($s) => $s !== null && $s !== ''
        )));

        if (empty($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(DB::raw('LOWER(status_pembiayaan)'), $normalized);
    }

    /**
     * Scope pembiayaan yang sedang berjalan (aktif / dicairkan).
     */
    public function scopeAktif($query)
    {
        return $query->statusIn(self::statusBerjalan());
    }

    /**
     * Status pembiayaan yang kontraknya MASIH BERJALAN (belum lunas).
     *
     * Satu-satunya sumber kebenaran untuk pertanyaan
     * "berapa pembiayaan aktif yang harus ditampilkan?".
     *
     * Definisi operasional: sudah cair/aktif sehingga menanggung kewajiban
     * angsuran bagi anggota, dan belum berakhir (lunas / macet / ditolak).
     * Termasuk 'disetujui' (lolos persetujuan, menunggu pencairan) dan
     * 'restrukturisasi' (akad lama berjalan dengan jadwal baru).
     *
     * Catatan: 'pending_dps' TIDAK termasuk — kontrak yang masih menunggu
     * pengesahan akad belum menanggung kewajiban angsuran, sehingga dihitung
     * pada kartu "Pengajuan Pending", bukan "Pembiayaan Aktif".
     *
     * Dipakai bersama oleh Dashboard Pengurus, Dashboard DPS, Teller, dan
     * modul Angsuran agar angka "pembiayaan aktif" tidak berbeda antar modul.
     *
     * @return array<int, string>
     */
    public static function statusBerjalan(): array
    {
        return [
            self::STATUS_DISETUJUI,
            self::STATUS_DISAHKAN,
            self::STATUS_PENDING_PENCAIRAN,
            self::STATUS_DICAIRKAN,          // = 'cair'
            self::STATUS_AKTIF,
            self::STATUS_RESTRUKTURISASI,
            self::STATUS_PENDING_RESTRUKTURISASI,
        ];
    }

    /**
     * Scope: pembiayaan yang kontraknya masih berjalan (belum lunas).
     */
    public function scopeBerjalan($query)
    {
        return $query->statusIn(self::statusBerjalan());
    }

    /**
     * Status pembiayaan yang DANANYA SUDAH DISALURKAN (pasca-pencairan).
     *
     * Berbeda dari {@see statusBerjalan()} yang juga memuat status pra-pencairan
     * (disetujui / disahkan / pending_pencairan), daftar ini HANYA memuat status
     * setelah akad dicairkan. Dipakai modul yang mengukur EKSPOSUR PIUTANG nyata
     * — mis. CKPN (PSAK 71): expected credit loss hanya dibentuk atas aset
     * keuangan yang telah diakui, sehingga pembiayaan yang belum cair tidak
     * boleh dikenai penyisihan.
     *
     * CKPN-4 — STATUS_MACET termasuk di sini: piutang macet SUDAH dicairkan dan
     * tetap TERCATAT penuh di neraca sampai dihapus buku (derecognized) sesuai
     * PSAK 71. Justru piutang macet inilah eksposur dengan ECL tertinggi
     * (kol5 = 100%), sehingga mengeluarkannya dari daftar ini akan membuat CKPN
     * tidak pernah terbentuk atas risiko terbesar. Satu-satunya status yang
     * benar-benar keluar dari eksposur piutang adalah STATUS_HAPUS_BUKU.
     *
     * @return array<int, string>
     */
    public static function statusTercair(): array
    {
        return [
            self::STATUS_DICAIRKAN,          // = 'cair'
            self::STATUS_AKTIF,
            self::STATUS_MACET,              // CKPN-4: tetap tercatat sampai hapus buku
            self::STATUS_RESTRUKTURISASI,
            self::STATUS_PENDING_RESTRUKTURISASI,
        ];
    }

    /**
     * Scope: pembiayaan yang dananya sudah disalurkan (pasca-pencairan).
     */
    public function scopeTercair($query)
    {
        return $query->statusIn(self::statusTercair());
    }

    /**
     * Status pembiayaan yang sudah melewati tahap persetujuan / pencairan.
     *
     * Dipakai modul DPS (Audit Pembiayaan & Akad) sebagai daftar status
     * yang WAJIB terambil. Satu-satunya sumber kebenaran agar badge dan
     * tabel tidak pernah memakai daftar status yang berbeda.
     *
     * Termasuk 'disetujui' (lolos persetujuan, menunggu pencairan),
     * 'cair'/'dicairkan', 'aktif', serta status lanjutan setelahnya.
     *
     * @return array<int, string>
     */
    public static function statusPascaPersetujuan(): array
    {
        return [
            self::STATUS_DISETUJUI,
            self::STATUS_DISAHKAN,
            self::STATUS_PENDING_PENCAIRAN,
            self::STATUS_DICAIRKAN,          // = 'cair'
            self::STATUS_AKTIF,
            self::STATUS_RESTRUKTURISASI,
            self::STATUS_PENDING_RESTRUKTURISASI,
            self::STATUS_LUNAS,
            self::STATUS_MACET,
            self::STATUS_HAPUS_BUKU,
        ];
    }

    /**
     * Scope: pembiayaan yang sudah melewati persetujuan / pencairan.
     */
    public function scopePascaPersetujuan($query)
    {
        return $query->statusIn(self::statusPascaPersetujuan());
    }

    /**
     * Status yang boleh dipakai sebagai filter pada halaman
     * "Approval Pembiayaan" Manajer.
     *
     * Daftar ini sengaja hidup di model, bukan sebagai array literal di
     * controller (temuan audit DYN-19): menambah/mengganti status cukup di
     * satu tempat bersama konstanta status lainnya.
     *
     * @return array<int, string>
     */
    public static function statusFilterApproval(): array
    {
        return [
            self::STATUS_PENDING_MANAJER,
            self::STATUS_PENDING_PENGURUS,
            self::STATUS_PENDING_DPS,
            self::STATUS_DISETUJUI,
            self::STATUS_DICAIRKAN,
            self::STATUS_DITOLAK,
        ];
    }

    /**
     * Cek status saat ini terhadap satu atau beberapa status (case-insensitive).
     *
     * @param  string|array<int, string>  $statuses
     */
    public function isStatus(string|array $statuses): bool
    {
        $current = self::normalizeStatus($this->status_pembiayaan);

        foreach ((array) $statuses as $status) {
            if ($current === self::normalizeStatus($status)) {
                return true;
            }
        }

        return false;
    }

    public function getPersentaseTerbayarAttribute(): float
    {
        if ($this->total_pembiayaan <= 0) {
            return 0;
        }
        $terbayar = $this->total_pembiayaan - $this->sisa_pembiayaan;

        return Setting::bulatkanUang(($terbayar / $this->total_pembiayaan) * 100);
    }

    public function isLunas(): bool
    {
        return $this->sisa_pembiayaan <= 0
            || $this->isStatus(self::STATUS_LUNAS);
    }

    /**
     * CKPN-5 — piutang ini sudah dihapusbukukan (derecognized, PSAK 71)?
     *
     * Aset yang sudah dihapus buku tidak lagi diakui di neraca: tidak lagi
     * membentuk penyisihan CKPN, tidak lagi diproses autodebet, dan tidak lagi
     * dihitung sebagai piutang berjalan.
     */
    public function isHapusBuku(): bool
    {
        return $this->isStatus(self::STATUS_HAPUS_BUKU)
            || $this->tanggal_hapus_buku !== null;
    }

    public function getAngsuranSudahBayarAttribute(): int
    {
        return $this->angsuran()->whereIn('status_angsuran', ['Sudah Dibayar'])->count();
    }
}
