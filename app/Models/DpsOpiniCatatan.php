<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DpsOpiniCatatan extends Model
{
    use HasFactory;

    protected $table = 'dps_opini_catatan';

    protected $fillable = [
        'user_id',
        'judul',
        'kategori',
        'periode',
        'status_kepatuhan',
        'isi',
        'lampiran',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
