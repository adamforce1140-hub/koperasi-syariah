<?php

namespace App\Models;

use App\Support\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShuDistribution extends Model
{
    use HasFactory;

    protected $table = 'shu_distributions';

    protected $fillable = [
        'shu_period_id',
        'anggota_id',
        'rekening_id',
        'total_simpanan_modal',
        'monthly_average_balance',
        'tenure_months',
        'total_transaksi_bagi_hasil',
        'shu_jasa_modal',
        'shu_jasa_transaksi',
        'total_shu',
        'is_hold',
        'hold_reason',
        'transaksi_id',
    ];

    protected $casts = [
        'total_simpanan_modal' => 'decimal:2',
        'monthly_average_balance' => 'decimal:2',
        'tenure_months' => 'integer',
        'total_transaksi_bagi_hasil' => 'decimal:2',
        'shu_jasa_modal' => 'decimal:2',
        'shu_jasa_transaksi' => 'decimal:2',
        'total_shu' => 'decimal:2',
        'is_hold' => 'boolean',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ShuPeriod::class, 'shu_period_id');
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    /** SHU anggota ini sedang ditahan karena tunggakan Simpanan Wajib. */
    public function scopeHold($query)
    {
        return $query->where('is_hold', true);
    }

    /** SHU anggota ini siap dicairkan (tidak ditahan). */
    public function scopeCair($query)
    {
        return $query->where('is_hold', false);
    }

    /** SHU yang benar-benar dibayarkan ke rekening anggota. */
    public function getShuDibayarkanAttribute(): float
    {
        return $this->is_hold ? 0.0 : Setting::bulatkanUang((float) $this->total_shu);
    }
}
