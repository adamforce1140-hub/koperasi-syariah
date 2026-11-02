<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tagihan Simpanan Wajib bulanan anggota.
 *
 * Penerbitan & pelunasan otomatis dikelola command
 * `mandatory-savings:process-daily` (lihat routes/console.php):
 *  1. `firstOrCreate` per (member_id, bill_period) → anti-duplikat.
 *  2. Auto-debit dari saldo Simpanan Sukarela (CoA 2-1001) bila mencukupi,
 *     lalu jurnal PSAK: DEBET 2-1001 | KREDIT 3-1002.
 *  3. Bila saldo tidak cukup → status OVERDUE + notifikasi anggota & admin.
 *
 * Tagihan berstatus OVERDUE pada penutupan tahun buku menyebabkan pencairan
 * SHU anggota ditahan (mode HOLD) hingga tunggakan dilunasi.
 */
class MandatorySavingsBill extends Model
{
    use HasFactory;

    public const STATUS_UNPAID = 'UNPAID';

    public const STATUS_PAID = 'PAID';

    public const STATUS_OVERDUE = 'OVERDUE';

    /**
     * Ditangguhkan menunggu KONFIRMASI MANUAL petugas.
     *
     * Dipakai ketika setoran otomatis TIDAK lolos verifikasi status pembayaran
     * (mis. saldo Sukarela tidak mencukupi, anggota belum melunasi kewajiban
     * lain). Dana TIDAK dipotong sampai petugas mengonfirmasi — inilah gerbang
     * yang meminimalkan risiko kerugian akibat anggota tidak membayar.
     */
    public const STATUS_PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';

    public const STATUS_LIST = [
        self::STATUS_UNPAID,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_PENDING_CONFIRMATION,
    ];

    /** Status yang dianggap belum lunas (menunggu tindakan). */
    public const STATUS_BELUM_LUNAS = [
        self::STATUS_UNPAID,
        self::STATUS_OVERDUE,
        self::STATUS_PENDING_CONFIRMATION,
    ];

    public const STATUS_LABEL = [
        self::STATUS_UNPAID => 'Belum Bayar',
        self::STATUS_PAID => 'Lunas',
        self::STATUS_OVERDUE => 'Overdue',
        self::STATUS_PENDING_CONFIRMATION => 'Ditangguhkan — Menunggu Konfirmasi',
    ];

    public const METODE_CASH = 'CASH';

    public const METODE_AUTO_DEBIT = 'AUTO_DEBIT';

    public const METODE_TRANSFER = 'TRANSFER';

    public const METODE_LIST = [self::METODE_CASH, self::METODE_AUTO_DEBIT, self::METODE_TRANSFER];

    public const METODE_LABEL = [
        self::METODE_CASH => 'Tunai',
        self::METODE_AUTO_DEBIT => 'Auto-Debit Sukarela',
        self::METODE_TRANSFER => 'Transfer',
    ];

    protected $table = 'mandatory_savings_bills';

    protected $fillable = [
        'member_id',
        'bill_period',
        'amount',
        'status',
        'paid_at',
        'payment_method',
        'transaksi_id',
        'jurnal_id',
        'notified_at',
        'deferred_reason',
        'deferred_at',
        'confirmed_by',
        'confirmed_at',
        'confirmation_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'notified_at' => 'datetime',
        'deferred_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'member_id');
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    /** Riwayat tindakan penanganan (audit trail — spesifikasi #5). */
    public function tindakan(): HasMany
    {
        return $this->hasMany(SimpananWajibTindakan::class, 'bill_id')->orderBy('waktu_tindakan');
    }

    /** Tindakan penanganan terakhir atas tagihan ini. */
    public function tindakanTerakhir(): HasOne
    {
        return $this->hasOne(SimpananWajibTindakan::class, 'bill_id')->latestOfMany('waktu_tindakan');
    }

    /** Jadwal cicilan bertahap aktif (bila ada) milik tagihan ini. */
    public function jadwalBertahap(): HasMany
    {
        return $this->hasMany(SimpananWajibTindakan::class, 'bill_id')
            ->where('jenis', SimpananWajibTindakan::JENIS_JADWAL_BERTAHAP);
    }

    /* ── Scope ─────────────────────────────────────────────────────────── */

    public function scopePeriode($query, string $periode)
    {
        return $query->where('bill_period', $periode);
    }

    public function scopeBelumLunas($query)
    {
        return $query->whereIn('status', self::STATUS_BELUM_LUNAS);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    /** Tagihan yang ditangguhkan & menunggu konfirmasi manual petugas. */
    public function scopePendingConfirmation($query)
    {
        return $query->where('status', self::STATUS_PENDING_CONFIRMATION);
    }

    /* ── Helper ────────────────────────────────────────────────────────── */

    /** Format periode wajib 'YYYY-MM' dari tanggal apa pun. */
    public static function periodDari(Carbon|string $tanggal): string
    {
        return Carbon::parse($tanggal)->format('Y-m');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OVERDUE;
    }

    /** Ditangguhkan — menunggu konfirmasi manual petugas sebelum dana dipotong. */
    public function isPendingConfirmation(): bool
    {
        return $this->status === self::STATUS_PENDING_CONFIRMATION;
    }

    /** Belum lunas (UNPAID / OVERDUE / PENDING_CONFIRMATION). */
    public function isBelumLunas(): bool
    {
        return in_array($this->status, self::STATUS_BELUM_LUNAS, true);
    }

    /** Petugas yang mengonfirmasi/menolak penangguhan tagihan ini. */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Label status Indonesia (mis. 'Belum Bayar', 'Overdue'). */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABEL[$this->status] ?? (string) $this->status;
    }

    /** Label periode Indonesia, mis. 'Januari 2026'. */
    public function getNamaPeriodeAttribute(): string
    {
        $namaBulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        [$tahun, $bulan] = array_pad(explode('-', (string) $this->bill_period), 2, null);

        return ($namaBulan[(int) $bulan] ?? $bulan).' '.$tahun;
    }

    public function getAmountFormattedAttribute(): string
    {
        return 'Rp '.number_format((float) $this->amount, 0, ',', '.');
    }

    public function getMetodeLabelAttribute(): string
    {
        return self::METODE_LABEL[$this->payment_method] ?? '—';
    }
}
