<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeringanTazir extends Model
{
    protected $table = 'keringanan_tazirs';

    protected $fillable = [
        'angsuran_id',
        'pembiayaan_id',
        'anggota_id',
        'diajukan_oleh',
        'diputuskan_oleh',
        'nominal_tazir_hitung',
        'nominal_tazir_final',
        'status',
        'alasan_pengajuan',
        'catatan_manajer',
        'diputuskan_at',
    ];

    protected $casts = [
        'nominal_tazir_hitung' => 'decimal:2',
        'nominal_tazir_final' => 'decimal:2',
        'diputuskan_at' => 'datetime',
    ];

    public function angsuran(): BelongsTo
    {
        return $this->belongsTo(Angsuran::class);
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function diajukanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function diputuskanOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }
}
