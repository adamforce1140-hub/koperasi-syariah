<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DanaKebajikan extends Model
{
    use HasFactory;

    protected $table = 'dana_kebajikans';

    protected $fillable = [
        'no_referensi',
        'sumber_dana',
        'jenis',
        'jumlah',
        'keterangan',
        'created_by',
    ];

    protected $casts = [
        'jumlah' => 'decimal:2',
    ];
}
