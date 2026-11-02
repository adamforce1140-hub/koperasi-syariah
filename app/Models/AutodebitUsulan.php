<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usulan Auto-Debit — SATU PINTU konfirmasi pemotongan dana.
 *
 * Model ini mengubah pola auto-debit dari "potong otomatis oleh sistem"
 * menjadi "usulkan dulu, potong setelah dikonfirmasi pengguna". Scheduler /
 * pemicu otomatis HANYA boleh membuat usulan (status MENUNGGU_KONFIRMASI);
 * pemotongan saldo baru dijalankan setelah baris berstatus DIKONFIRMASI.
 *
 * Siklus hidup (kolom `status`):
 *   MENUNGGU_KONFIRMASI → usulan dibuat, dana BELUM dipotong.
 *   DIKONFIRMASI        → pengguna menyetujui; pemotongan dijalankan.
 *   DITOLAK             → pengguna menolak; tidak ada pemotongan.
 *   KEDALUWARSA         → usulan melewati batas waktu (opsional/gagal eksekusi).
 *   GAGAL               → eksekusi gagal (mis. saldo berubah saat konfirmasi).
 *
 * Kanal (`kanal`) menentukan aksi pemotongan yang dieksekusi saat konfirmasi:
 *   SIMPANAN_WAJIB → MandatorySavingsService::autoDebit()
 *   ANGSURAN       → AngsuranTransactionService::proses()
 *   TAZIR          → TazirService::prosesAutodebetSusulan()
 */
class AutodebitUsulan extends Model
{
    use HasFactory;

    /* ── Kanal (sumber pemotongan) ─────────────────────────────────────── */
    public const KANAL_SIMPANAN_WAJIB = 'SIMPANAN_WAJIB';

    public const KANAL_ANGSURAN = 'ANGSURAN';

    public const KANAL_TAZIR = 'TAZIR';

    public const KANAL_LIST = [
        self::KANAL_SIMPANAN_WAJIB,
        self::KANAL_ANGSURAN,
        self::KANAL_TAZIR,
    ];

    public const KANAL_LABEL = [
        self::KANAL_SIMPANAN_WAJIB => 'Simpanan Wajib',
        self::KANAL_ANGSURAN => 'Angsuran Pembiayaan',
        self::KANAL_TAZIR => "Ta'zir Susulan",
    ];

    /* ── Status siklus hidup ───────────────────────────────────────────── */
    public const STATUS_MENUNGGU_KONFIRMASI = 'MENUNGGU_KONFIRMASI';

    public const STATUS_DIKONFIRMASI = 'DIKONFIRMASI';

    public const STATUS_DITOLAK = 'DITOLAK';

    public const STATUS_KEDALUWARSA = 'KEDALUWARSA';

    public const STATUS_GAGAL = 'GAGAL';

    public const STATUS_LIST = [
        self::STATUS_MENUNGGU_KONFIRMASI,
        self::STATUS_DIKONFIRMASI,
        self::STATUS_DITOLAK,
        self::STATUS_KEDALUWARSA,
        self::STATUS_GAGAL,
    ];

    public const STATUS_LABEL = [
        self::STATUS_MENUNGGU_KONFIRMASI => 'Menunggu Konfirmasi',
        self::STATUS_DIKONFIRMASI => 'Dikonfirmasi',
        self::STATUS_DITOLAK => 'Ditolak',
        self::STATUS_KEDALUWARSA => 'Kedaluwarsa',
        self::STATUS_GAGAL => 'Gagal',
    ];

    /** Status yang menandakan usulan masih menunggu tindakan pengguna. */
    public const STATUS_AKTIF = [self::STATUS_MENUNGGU_KONFIRMASI];

    protected $table = 'autodebit_usulans';

    protected $fillable = [
        'kanal',
        'referensi_id',
        'referensi_tipe',
        'anggota_id',
        'rekening_id',
        'nominal',
        'periode',
        'alasan',
        'catatan',
        'status',
        'confirmed_by',
        'confirmed_at',
        'confirmation_note',
        'transaksi_id',
        'executed_at',
        'signature',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    /* ── Relasi ────────────────────────────────────────────────────────── */

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'anggota_id');
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class, 'rekening_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'transaksi_id');
    }

    /* ── Scope ─────────────────────────────────────────────────────────── */

    public function scopeMenungguKonfirmasi(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_MENUNGGU_KONFIRMASI);
    }

    public function scopeKanal(Builder $query, string $kanal): Builder
    {
        return $query->where('kanal', $kanal);
    }

    public function scopePeriode(Builder $query, string $periode): Builder
    {
        return $query->where('periode', $periode);
    }

    /**
     * Cari usulan yang masih menunggu konfirmasi untuk satu kanal + referensi.
     * Dipakai untuk deduplikasi (mencegah usulan ganda per tagihan/angsuran).
     */
    public function scopeUntukReferensi(Builder $query, string $kanal, int $referensiId): Builder
    {
        return $query->where('kanal', $kanal)->where('referensi_id', $referensiId);
    }

    /* ── Helper ────────────────────────────────────────────────────────── */

    public function isMenungguKonfirmasi(): bool
    {
        return $this->status === self::STATUS_MENUNGGU_KONFIRMASI;
    }

    public function isDikonfirmasi(): bool
    {
        return $this->status === self::STATUS_DIKONFIRMASI;
    }

    public function getKanalLabelAttribute(): string
    {
        return self::KANAL_LABEL[$this->kanal] ?? $this->kanal;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABEL[$this->status] ?? $this->status;
    }

    public function getNominalFormattedAttribute(): string
    {
        return 'Rp '.number_format((float) $this->nominal, 0, ',', '.');
    }

    public function getDibuatFormattedAttribute(): string
    {
        return $this->created_at
            ? Carbon::parse($this->created_at)->format('d/m/Y H:i')
            : '—';
    }

    /**
     * Signature unik untuk deduplikasi usulan: satu usulan aktif per
     * kanal + referensi + periode. Membuat ulang usulan dengan signature
     * sama akan di-`updateOrCreate` alih-alih menggandakan baris.
     */
    public static function signature(string $kanal, int $referensiId, ?string $periode = null): string
    {
        return implode(':', array_filter([
            $kanal,
            (string) $referensiId,
            $periode ?? '',
        ], static fn ($v) => $v !== ''));
    }
}
