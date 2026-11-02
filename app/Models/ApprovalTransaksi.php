<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalTransaksi extends Model
{
    protected $table = 'approval_transaksi';

    protected $fillable = [
        'kode_approval', 'tipe_referensi', 'referensi_id', 'jenis_transaksi',
        'nominal', 'jenis_pembayaran', 'status', 'diajukan_oleh', 'role_pengaju',
        'disetujui_oleh', 'role_approver', 'catatan_pengaju', 'catatan_approver', 'approved_at',
    ];

    protected $casts = ['nominal' => 'decimal:2', 'approved_at' => 'datetime'];

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class, 'referensi_id');
    }

    public static function generateKode(): string
    {
        $maxId = (int) static::whereDate('created_at', today())->max('id');
        $urut = $maxId > 0 ? $maxId + 1 : 1;

        return 'APR-'.date('Ymd').'-'.str_pad($urut, 4, '0', STR_PAD_LEFT);
    }
}
