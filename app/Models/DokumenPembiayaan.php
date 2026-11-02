<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DokumenPembiayaan extends Model
{
    protected $table = 'dokumen_pembiayaan';

    protected $fillable = [
        'pembiayaan_id',
        'jenis_dokumen',
        'nama_file',
        'path_file',
        'ukuran_file',
        'diupload_oleh',
    ];

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diupload_oleh');
    }
}
