<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat persetujuan pengunduran diri anggota (exit & closure).
 *
 * Menyimpan jejak audit setiap keputusan Manajer atas pengajuan yang nilainya
 * melampaui `high_value_withdrawal_limit`: siapa yang memutuskan, kapan, aksi
 * apa (APPROVED/REJECTED), dan catatan tambahan.
 */
class ExitApprovalLog extends Model
{
    protected $table = 'exit_approval_logs';

    public const AKSI_APPROVED = 'APPROVED';

    public const AKSI_REJECTED = 'REJECTED';

    protected $fillable = [
        'member_termination_id',
        'user_id',
        'aksi',
        'catatan',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function terminasi(): BelongsTo
    {
        return $this->belongsTo(MemberTermination::class, 'member_termination_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
