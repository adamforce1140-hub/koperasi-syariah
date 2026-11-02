<?php

namespace App\Models;

use App\Support\PiiHasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class PengajuanRekening extends Model
{
    use SoftDeletes;

    protected $table = 'pengajuan_rekening';

    /* ---------------------------------------------------------------------
     | Status Pengajuan Rekening — nilai kanonik (mengikuti ENUM kolom).
     | Alur: Menunggu Verifikasi -> Menunggu Pembayaran -> Menunggu Konfirmasi -> Aktif
     | Semua penulisan status WAJIB memakai konstanta di bawah ini.
     | ------------------------------------------------------------------- */
    public const STATUS_MENUNGGU_VERIFIKASI = 'Menunggu Verifikasi';

    public const STATUS_DISETUJUI = 'Disetujui';

    public const STATUS_DITOLAK = 'Ditolak';

    public const STATUS_MENUNGGU_PEMBAYARAN = 'Menunggu Pembayaran';

    public const STATUS_MENUNGGU_KONFIRMASI = 'Menunggu Konfirmasi';

    public const STATUS_AKTIF = 'Aktif';

    /**
     * Alias / varian lama -> nilai kanonik (kunci lowercase).
     */
    private const STATUS_ALIASES = [
        'menunggu verifikasi' => self::STATUS_MENUNGGU_VERIFIKASI,
        'menunggu pembayaran' => self::STATUS_MENUNGGU_PEMBAYARAN,
        'menunggu konfirmasi' => self::STATUS_MENUNGGU_KONFIRMASI,
        'disetujui' => self::STATUS_DISETUJUI,
        'ditolak' => self::STATUS_DITOLAK,
        'aktif' => self::STATUS_AKTIF,
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

        return self::STATUS_ALIASES[$key] ?? $status;
    }

    /**
     * Daftar seluruh nilai kanonik yang dikenal.
     *
     * @return array<int, string>
     */
    public static function canonicalStatuses(): array
    {
        return [
            self::STATUS_MENUNGGU_VERIFIKASI,
            self::STATUS_DISETUJUI,
            self::STATUS_DITOLAK,
            self::STATUS_MENUNGGU_PEMBAYARAN,
            self::STATUS_MENUNGGU_KONFIRMASI,
            self::STATUS_AKTIF,
        ];
    }

    protected $fillable = [
        'kode_pengajuan', 'user_id', 'nama_lengkap', 'nik',
        'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin', 'alamat',
        'kota', 'provinsi', 'no_hp', 'email', 'pekerjaan',
        // CDD / PEP / Pemilik Manfaat (OJK-08)
        'pep_status', 'pep_keterangan', 'pemilik_manfaat_nama', 'pemilik_manfaat_hubungan',
        'foto_ktp', 'foto_selfie_ktp', 'jenis_simpanan',
        'nominal_simpanan_awal', 'status', 'alasan_tolak',
        'diverifikasi_oleh', 'waktu_verifikasi',
        'anggota_id', 'no_rekening_terbit',
    ];

    protected $casts = [
        // NIK disimpan sebagai ciphertext; pencarian & keunikan dilayani
        // blind index `nik_hash` (lihat App\Support\PiiHasher).
        'nik' => 'encrypted',
        'tanggal_lahir' => 'date',
        'waktu_verifikasi' => 'datetime',
        'nominal_simpanan_awal' => 'decimal:2',
    ];

    /**
     * Kolom internal yang tidak pernah dikirim ke tampilan/JSON.
     *
     * @var list<string>
     */
    protected $hidden = ['nik_hash'];

    /**
     * Jaga sinkronisasi blind index `nik_hash` dengan nilai `nik`.
     *
     * `nik_hash` sengaja tidak ada di $fillable agar tidak dapat diisi
     * langsung dari input pengguna (mass assignment).
     */
    protected static function booted(): void
    {
        static::saving(function (PengajuanRekening $pengajuan) {
            if ($pengajuan->isDirty('nik')) {
                $pengajuan->nik_hash = PiiHasher::hash($pengajuan->nik);
            }
        });
    }

    /**
     * Cari pengajuan berdasarkan NIK memakai blind index (kecocokan tepat).
     *
     * @param  Builder<PengajuanRekening>  $query
     */
    public function scopeDenganNik($query, ?string $nik)
    {
        $hash = PiiHasher::hash($nik);

        return $hash === null
            ? $query->whereRaw('1 = 0')
            : $query->where('nik_hash', $hash);
    }

    // Generate kode pengajuan otomatis: PGJ-YYYYMM-XXXX
    public static function generateKode(): string
    {
        $prefix = 'PGJ-'.now()->format('Ym').'-';

        // Urutan dari sufiks NUMERIK terbesar, bukan dari baris terakhir
        // (orderByDesc('id')) yang rapuh: baris bisa dihapus atau nomornya
        // diisi manual sehingga `substr(..., -4)` mengulang nomor yang sama.
        return static::nomorKodeBaru($prefix);
    }

    /**
     * Kode pengajuan berikutnya, dijamin unik (format `{prefix}{NNNN}`).
     *
     * Memakai MAX sufiks numerik + pencarian kandidat bebas, sehingga tidak
     * pernah mengulang nomor walau ada baris terhapus (race/penghapusan).
     */
    public static function nomorKodeBaru(string $prefix, int $panjang = 4): string
    {
        $baris = static::withTrashed()
            ->where('kode_pengajuan', 'like', $prefix.'%')
            ->selectRaw(
                'MAX(CAST(SUBSTRING(kode_pengajuan, ?) AS UNSIGNED)) AS maks_urut',
                [strlen($prefix) + 1]
            )
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::withTrashed()->where('kode_pengajuan', $kandidat)->exists());

        return $kandidat;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function pembayaran(): HasOne
    {
        return $this->hasOne(PembayaranSimpanan::class, 'pengajuan_id');
    }

    // Badge warna status untuk view
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_MENUNGGU_VERIFIKASI => 'badge-amber',
            self::STATUS_DISETUJUI => 'badge-blue',
            self::STATUS_DITOLAK => 'badge-red',
            self::STATUS_MENUNGGU_PEMBAYARAN => 'badge-amber',
            self::STATUS_MENUNGGU_KONFIRMASI => 'badge-blue',
            self::STATUS_AKTIF => 'badge-green',
            default => 'badge-gray',
        };
    }

    /**
     * Accessor: semua pembacaan status selalu dalam bentuk kanonik.
     */
    public function getStatusAttribute($value): ?string
    {
        return self::normalizeStatus($value);
    }

    /**
     * Mutator: semua penulisan status selalu disimpan dalam bentuk kanonik.
     */
    public function setStatusAttribute($value): void
    {
        $this->attributes['status'] = self::normalizeStatus(
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

        return $query->whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $normalized));
    }

    /**
     * Cek status saat ini terhadap satu atau beberapa status (case-insensitive).
     *
     * @param  string|array<int, string>  $statuses
     */
    public function isStatus(string|array $statuses): bool
    {
        $current = self::normalizeStatus($this->status);

        foreach ((array) $statuses as $status) {
            if ($current === self::normalizeStatus($status)) {
                return true;
            }
        }

        return false;
    }
}
