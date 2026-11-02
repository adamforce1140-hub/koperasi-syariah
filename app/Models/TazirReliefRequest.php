<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pengajuan Keringanan / Pembebasan Ta'zir (Denda Syariah).
 *
 * Alur: TELLER (PENDING_AO) -> AO (PENDING_MANAJER) -> MANAJER (APPROVED / REJECTED).
 */
class TazirReliefRequest extends Model
{
    protected $table = 'tazir_relief_requests';

    public const STATUS_PENDING_AO = 'PENDING_AO';

    public const STATUS_PENDING_MANAJER = 'PENDING_MANAJER';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const REKOMENDASI_SETUJU = 'SETUJU';

    public const REKOMENDASI_TOLAK = 'TOLAK';

    protected $fillable = [
        'jadwal_angsuran_id',
        'pembiayaan_id',
        'anggota_id',
        'nominal_tazir_awal',
        'nominal_tazir_disetujui',
        'alasan_teller',
        'teller_id',
        'rekomendasi_ao',
        'catatan_ao',
        'ao_id',
        'catatan_manajer',
        'manajer_id',
        'status',
    ];

    protected $casts = [
        'nominal_tazir_awal' => 'decimal:2',
        'nominal_tazir_disetujui' => 'decimal:2',
    ];

    /* ── Relasi ─────────────────────────────────────────────── */

    public function angsuran(): BelongsTo
    {
        return $this->belongsTo(Angsuran::class, 'jadwal_angsuran_id');
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
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
}
