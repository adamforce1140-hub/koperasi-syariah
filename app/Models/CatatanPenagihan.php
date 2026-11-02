<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat catatan penagihan yang ditulis AO pada satu angsuran.
 * Satu angsuran bisa punya banyak catatan (histori penagihan).
 */
class CatatanPenagihan extends Model
{
    protected $table = 'catatan_penagihan';

    protected $fillable = [
        'angsuran_id',
        'user_id',
        'catatan',
    ];

    public function angsuran(): BelongsTo
    {
        return $this->belongsTo(Angsuran::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
