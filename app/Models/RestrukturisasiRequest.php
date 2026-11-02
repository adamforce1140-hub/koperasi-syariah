<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pengajuan Restrukturisasi Pembiayaan (3R: Rescheduling / Reconditioning / Restructuring).
 *
 * Alur status: PENDING_APPROVAL (AO) -> APPROVED / REJECTED (Manajer).
 */
class RestrukturisasiRequest extends Model
{
    use SoftDeletes;

    protected $table = 'restrukturisasi_requests';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const SKEMA_RESCHEDULING = 'Rescheduling';

    public const SKEMA_RECONDITIONING = 'Reconditioning';

    public const SKEMA_RESTRUCTURING = 'Restructuring';

    protected $fillable = [
        'pembiayaan_id',
        'ao_id',
        'skema_restrukturisasi',
        'tenor_baru',
        'grace_period',
        'tanggal_jatuh_tempo_baru',
        'alasan_restrukturisasi',
        'kemampuan_bayar_baru',
        'catatan_ao',
        // Snapshot piutang saat pengajuan (safeguard Fatwa DSN-MUI No. 49)
        'sisa_pokok_snapshot',
        'sisa_margin_snapshot',
        'sisa_piutang_snapshot',
        'angsuran_lama_snapshot',
        'status',
        'manajer_id',
        'catatan_manajer',
        'keputusan_at',
        'addendum_no',
    ];

    protected $casts = [
        'kemampuan_bayar_baru' => 'decimal:2',
        'sisa_pokok_snapshot' => 'decimal:2',
        'sisa_margin_snapshot' => 'decimal:2',
        'sisa_piutang_snapshot' => 'decimal:2',
        'angsuran_lama_snapshot' => 'decimal:2',
        'tanggal_jatuh_tempo_baru' => 'date',
        'keputusan_at' => 'datetime',
    ];

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function ao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ao_id');
    }

    public function manajer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manajer_id');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }
}
