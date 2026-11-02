<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShuPeriod extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'shu_periods';

    protected $fillable = [
        'tahun_buku',
        'total_shu_bersih',
        'total_shu_hold',
        'jumlah_anggota_hold',
        'persen_cadangan',
        'persen_jasa_modal',
        'persen_jasa_transaksi',
        'persen_pengurus',
        'persen_sosial',
        'nominal_cadangan',
        'nominal_jasa_modal',
        'nominal_jasa_transaksi',
        'nominal_pengurus',
        'nominal_sosial',
        'status',
        'is_distributed',
        'jurnal_id',
        'user_id',
        'diproses_at',
    ];

    protected $casts = [
        'total_shu_bersih' => 'decimal:2',
        'total_shu_hold' => 'decimal:2',
        'jumlah_anggota_hold' => 'integer',
        'persen_cadangan' => 'decimal:2',
        'persen_jasa_modal' => 'decimal:2',
        'persen_jasa_transaksi' => 'decimal:2',
        'persen_pengurus' => 'decimal:2',
        'persen_sosial' => 'decimal:2',
        'nominal_cadangan' => 'decimal:2',
        'nominal_jasa_modal' => 'decimal:2',
        'nominal_jasa_transaksi' => 'decimal:2',
        'nominal_pengurus' => 'decimal:2',
        'nominal_sosial' => 'decimal:2',
        'is_distributed' => 'boolean',
        'diproses_at' => 'datetime',
    ];

    public function distributions(): HasMany
    {
        return $this->hasMany(ShuDistribution::class);
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'Draft';
    }

    public function isSelesai(): bool
    {
        return $this->status === 'Selesai';
    }
}
