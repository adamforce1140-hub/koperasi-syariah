<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JurnalDetail extends Model
{
    use HasFactory;

    protected $table = 'jurnal_detail';

    public $timestamps = true;

    protected $fillable = [
        'jurnal_id',
        'akun_id',
        'coa_id',
        'baris',
        'debet',
        'kredit',
        'keterangan',
        'anggota_id',
        'rekening_id',
    ];

    protected $casts = [
        'debet' => 'decimal:2',
        'kredit' => 'decimal:2',
        'baris' => 'integer',
    ];

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function akun(): BelongsTo
    {
        return $this->belongsTo(Akun::class);
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_id');
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function rekening(): BelongsTo
    {
        return $this->belongsTo(Rekening::class);
    }

    public function isDebet(): bool
    {
        return $this->debet > 0;
    }

    public function isKredit(): bool
    {
        return $this->kredit > 0;
    }

    public function getJumlahAttribute(): float
    {
        return $this->debet > 0 ? $this->debet : $this->kredit;
    }

    public function getJumlahFormattedAttribute(): string
    {
        return 'Rp '.number_format($this->jumlah, 2, ',', '.');
    }
}
