<?php

namespace App\Models;

use App\Support\NomorDokumen;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rekening extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'rekening';

    protected $fillable = [
        'no_rekening',
        'anggota_id',
        'akun_id',
        'jenis_rekening',
        'akad',
        'saldo',
        'nominal_pembukaan',
        'batas_minimum',
        'nisbah_bank',
        'nisbah_nasabah',
        'tanggal_buka',
        'tanggal_jatuh_tempo',
        'status_rekening',
        'closed_at',
        'closed_by',
        'keterangan',
        'applied_percentage_member',
        'applied_percentage_institution',
    ];

    protected $casts = [
        'saldo' => 'decimal:2',
        'nominal_pembukaan' => 'decimal:2',
        'batas_minimum' => 'decimal:2',
        'nisbah_bank' => 'decimal:2',
        'nisbah_nasabah' => 'decimal:2',
        'tanggal_buka' => 'date',
        'tanggal_jatuh_tempo' => 'date',
        'closed_at' => 'datetime',
        'applied_percentage_member' => 'decimal:2',
        'applied_percentage_institution' => 'decimal:2',
    ];

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function akun(): BelongsTo
    {
        return $this->belongsTo(Akun::class);
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function pembiayaan(): HasMany
    {
        return $this->hasMany(Pembiayaan::class);
    }

    public function jurnalDetail(): HasMany
    {
        return $this->hasMany(JurnalDetail::class);
    }

    /** Bilyet deposito yang menjadikan rekening ini sebagai sumber dana. */
    public function depositoSumberDana(): HasMany
    {
        return $this->hasMany(Deposito::class, 'source_saving_account_id');
    }

    /** Bilyet deposito yang ditautkan ke rekening Simpanan Berjangka ini. */
    public function depositoBilyet(): HasMany
    {
        return $this->hasMany(Deposito::class, 'deposito_rekening_id');
    }

    public function scopeAktif($query)
    {
        return $query->where('status_rekening', 'Aktif');
    }

    /** Rekening berstatus `Aktif` — satu-satunya status yang boleh dimutasi. */
    public function isAktif(): bool
    {
        return strcasecmp((string) $this->status_rekening, 'Aktif') === 0;
    }

    /**
     * Nomor rekening berikutnya yang dijamin unik: `{prefix}{urut}`.
     *
     * Nilai urut diambil dari sufiks NUMERIK terbesar (bukan perbandingan
     * string), sehingga sufiks dengan panjang berbeda — mis. `006613` dan
     * `1006613` — tetap dihitung benar. Sebelumnya `substr($max, -6)`
     * memotong `1006613` menjadi `006613` lalu menghasilkan nomor yang sudah
     * terpakai (UniqueConstraintViolation).
     *
     * Bila nomor kandidat ternyata sudah ada (rekening lama/soft-deleted atau
     * nomor yang diisi manual), pencarian dilanjutkan sampai nomor bebas.
     */
    public static function nomorRekeningBaru(?string $prefix = null, int $panjang = 6): string
    {
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_rekening`);
        // kosong = tetap `RKN-{YYYY}-`.
        $prefix ??= NomorDokumen::prefix('rekening', tanggal: 'Y');

        $baris = static::withTrashed()
            ->where('no_rekening', 'like', $prefix.'%')
            ->selectRaw(
                'MAX(CAST(SUBSTRING(no_rekening, ?) AS UNSIGNED)) AS maks_urut',
                [strlen($prefix) + 1]
            )
            ->first();

        $urut = ((int) ($baris->maks_urut ?? 0)) + 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, $panjang, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::withTrashed()->where('no_rekening', $kandidat)->exists());

        return $kandidat;
    }

    public function getSaldoFormattedAttribute(): string
    {
        return 'Rp '.number_format($this->saldo, 2, ',', '.');
    }

    public function isDapatDitarik(float $jumlah): bool
    {
        $minimal = $this->saldo - $jumlah;

        return $minimal >= $this->batas_minimum;
    }
}
