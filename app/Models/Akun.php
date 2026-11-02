<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Akun extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'akun';

    protected $fillable = [
        'kode_akun',
        'nama_akun',
        'klasifikasi',
        'jenis_akun',
        'parent_id',
        'saldo_normal',
        'is_active',
        'keterangan',
    ];

    protected $casts = [
        'saldo_normal' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Akun::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Akun::class, 'parent_id');
    }

    public function rekening(): HasMany
    {
        return $this->hasMany(Rekening::class);
    }

    public function pembiayaan(): HasMany
    {
        return $this->hasMany(Pembiayaan::class);
    }

    public function jurnalDetail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDetail($query)
    {
        return $query->where('jenis_akun', 'Detail');
    }

    public function scopeHeader($query)
    {
        return $query->where('jenis_akun', 'Header');
    }
}
