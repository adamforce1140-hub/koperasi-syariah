<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdukSyaratDokumen extends Model
{
    protected $table = 'produk_syarat_dokumen';

    protected $fillable = ['produk_id', 'nama_dokumen', 'is_required', 'keterangan'];

    protected $casts = ['is_required' => 'boolean'];

    public function produk(): BelongsTo
    {
        return $this->belongsTo(MasterProdukPembiayaan::class, 'produk_id');
    }
}
