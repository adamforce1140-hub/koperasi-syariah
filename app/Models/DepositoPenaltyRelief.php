<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan Keringanan / Pembebasan Penalti Pencairan Dini Deposito.
 *
 * Alur berjenjang (maker ≠ checker):
 *   TELLER (PENDING_AO) -> AO (PENDING_MANAJER) -> MANAJER (APPROVED / REJECTED).
 *
 * Keringanan hanya berlaku bila disetujui Manajer, dipakai sekali pada
 * pencairan dini, dan tetap tidak boleh melampaui pokok deposito.
 */
class DepositoPenaltyRelief extends Model
{
    protected $table = 'deposito_penalty_reliefs';

    public const STATUS_PENDING_AO = 'PENDING_AO';

    public const STATUS_PENDING_MANAJER = 'PENDING_MANAJER';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const REKOMENDASI_SETUJU = 'SETUJU';

    public const REKOMENDASI_TOLAK = 'TOLAK';

    protected $fillable = [
        'deposito_id',
        'anggota_id',
        'nominal_penalti_awal',
        'nominal_penalti_disetujui',
        'alasan_pengaju',
        'pengaju_id',
        'rekomendasi_ao',
        'catatan_ao',
        'ao_id',
        'catatan_manajer',
        'manajer_id',
        'status',
        'dipakai_at',
    ];

    protected $casts = [
        'nominal_penalti_awal' => 'decimal:2',
        'nominal_penalti_disetujui' => 'decimal:2',
        'dipakai_at' => 'datetime',
    ];

    /* ── Relasi ─────────────────────────────────────────────── */

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengaju_id');
    }

    public function ao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ao_id');
    }

    public function manajer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manajer_id');
    }

    /* ── Scope ──────────────────────────────────────────────── */

    public function scopePendingAo(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING_AO);
    }

    public function scopePendingManajer(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING_MANAJER);
    }

    /**
     * Keringanan yang SUDAH disetujui namun BELUM dipakai — kandidat yang
     * boleh mengurangi penalti saat pencairan dini.
     */
    public function scopeSiapDipakai(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED)->whereNull('dipakai_at');
    }

    /* ── Helper status ──────────────────────────────────────── */

    public function isPendingAo(): bool
    {
        return $this->status === self::STATUS_PENDING_AO;
    }

    public function isPendingManajer(): bool
    {
        return $this->status === self::STATUS_PENDING_MANAJER;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function sudahDipakai(): bool
    {
        return $this->dipakai_at !== null;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_AO => 'Menunggu Verifikasi AO',
            self::STATUS_PENDING_MANAJER => 'Menunggu Approval Manajer',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            default => $this->status,
        };
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_AO,
            self::STATUS_PENDING_MANAJER => 'yellow',
            self::STATUS_APPROVED => 'green',
            self::STATUS_REJECTED => 'red',
            default => 'gray',
        };
    }

    /**
     * Cari keringanan yang siap dipakai untuk sebuah bilyet deposito.
     *
     * Hanya SATU keringanan disetujui yang belum dipakai yang boleh berlaku;
     * bila ada lebih dari satu, yang paling awal disetujui dipakai lebih dulu
     * agar keputusan lama tidak "menggantung" tanpa batas.
     */
    public static function siapDipakaiUntuk(int $depositoId): ?self
    {
        return static::siapDipakai()
            ->where('deposito_id', $depositoId)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->first();
    }
}
