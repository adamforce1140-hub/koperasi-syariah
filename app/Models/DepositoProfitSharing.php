<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat pembagian bagi hasil deposito per periode bulan.
 *
 * `gross_koperasi_revenue` = pendapatan koperasi dari penempatan dana pada
 * periode tersebut (dasar perhitungan). `member_profit_amount` = porsi anggota
 * hasil perkalian nisbah. Tidak ada komponen SHU di sini.
 */
class DepositoProfitSharing extends Model
{
    use HasFactory;

    public const STATUS_PAID = 'PAID';

    public const STATUS_FAILED = 'FAILED';

    /** Bagi hasil hangus karena deposito dicairkan sebelum jatuh tempo. */
    public const STATUS_FORFEITED = 'FORFEITED';

    /** Status yang porsinya sudah/akan diakui sebagai hak anggota (bukan hangus). */
    public const STATUS_DIAKUI = [
        self::STATUS_PAID,
        self::STATUS_FAILED,
    ];

    protected $table = 'deposito_profit_sharings';

    protected $fillable = [
        'deposito_id',
        'period_month',
        'period_year',
        'gross_koperasi_revenue',
        'member_profit_amount',
        'payout_date',
        'status',
        'journal_id',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'period_year' => 'integer',
        'gross_koperasi_revenue' => 'decimal:2',
        'member_profit_amount' => 'decimal:2',
        'payout_date' => 'datetime',
    ];

    /* ===================== RELASI ===================== */

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class, 'journal_id');
    }

    /* ===================== SCOPE ===================== */

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * Bagi hasil yang porsinya diakui sebagai hak anggota — kandidat
     * penyelesaian saat jatuh tempo (transfer maupun kapitalisasi).
     */
    public function scopeDiakui($query)
    {
        return $query->whereIn('status', self::STATUS_DIAKUI);
    }

    public function scopePeriode($query, int $tahun, int $bulan)
    {
        return $query->where('period_year', $tahun)->where('period_month', $bulan);
    }

    /* ===================== HELPER ===================== */

    public function getNamaPeriodeAttribute(): string
    {
        static $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return ($bulan[$this->period_month] ?? $this->period_month).' '.$this->period_year;
    }

    public function getMemberProfitFormattedAttribute(): string
    {
        return 'Rp '.number_format((float) $this->member_profit_amount, 0, ',', '.');
    }
}
