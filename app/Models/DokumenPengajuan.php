<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokumenPengajuan extends Model
{
    protected $table = 'dokumen_pengajuan_pembiayaan';

    protected $fillable = [
        'pengajuan_id',
        'jenis_dokumen',
        'nama_file',
        'path_file',
        'ukuran_file',
        'diupload_oleh',
    ];

    public function pengajuanPembiayaan(): BelongsTo
    {
        return $this->belongsTo(PengajuanPembiayaan::class, 'pengajuan_id');
    }
}
