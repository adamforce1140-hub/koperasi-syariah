<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PembayaranSimpanan extends Model
{
    protected $table = 'pembayaran_simpanan';

    protected $fillable = [
        'pengajuan_id', 'nominal', 'metode_bayar',
        'bukti_bayar', 'status', 'catatan',
        'dikonfirmasi_oleh', 'waktu_konfirmasi',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'waktu_konfirmasi' => 'datetime',
    ];

    public function pengajuan(): BelongsTo
    {
        return $this->belongsTo(PengajuanRekening::class, 'pengajuan_id');
    }

    public function konfirmator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikonfirmasi_oleh');
    }
}
