<?php

namespace App\Models;

use App\Support\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kas Opname Teller — pengajuan hitung fisik kas yang harus diverifikasi Manajer.
 *
 * Alur status:
 *   pending ──(Manajer approve)──> closed   (jurnal pemindahan kas terbentuk)
 *           └─(Manajer reject)──> rejected  (teller wajib opname ulang)
 */
class TellerOpname extends Model
{
    protected $table = 'teller_opnames';

    /** Pecahan rupiah yang diopname, dari terbesar ke terkecil. */
    public const PECAHAN = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REJECTED = 'rejected';

    public const SELISIH_BALANCED = 'balanced';

    public const SELISIH_OVER = 'over';

    public const SELISIH_SHORT = 'short';

    protected $fillable = [
        'kas_harian_id',
        'user_id',
        'tanggal',
        'saldo_sistem',
        'total_fisik',
        'selisih',
        'rincian_pecahan',
        'status',
        'status_selisih',
        'catatan',
        'approved_by',
        'approved_at',
        'catatan_approval',
        'jurnal_id',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'saldo_sistem' => 'decimal:2',
        'total_fisik' => 'decimal:2',
        'selisih' => 'decimal:2',
        'rincian_pecahan' => 'array',
        'approved_at' => 'datetime',
    ];

    // ─── Relasi ───────────────────────────────────────────────

    /** Teller yang mengajukan opname. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Manajer yang memverifikasi & menerima kas. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Sesi Buka Kas yang diopname. */
    public function kasHarian(): BelongsTo
    {
        return $this->belongsTo(KasHarian::class, 'kas_harian_id');
    }

    /** Jurnal pemindahan kas hasil approve. */
    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class, 'jurnal_id');
    }

    // ─── Scope ────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // ─── Helper ───────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Sumber tunggal penentuan status selisih — dipakai backend & frontend
     * agar indikator warna di UI selalu konsisten dengan data tersimpan.
     */
    public static function tentukanStatusSelisih(float $selisih): string
    {
        if (Setting::bulatkanUang($selisih) === 0.0) {
            return self::SELISIH_BALANCED;
        }

        return $selisih > 0 ? self::SELISIH_OVER : self::SELISIH_SHORT;
    }

    public static function labelStatusSelisih(string $status): string
    {
        return match ($status) {
            self::SELISIH_OVER => 'LEBIH / CASH OVER',
            self::SELISIH_SHORT => 'KURANG / CASH SHORT',
            default => 'BALANCED',
        };
    }

    public static function labelStatus(string $status): string
    {
        return match ($status) {
            self::STATUS_CLOSED => 'Diterima',
            self::STATUS_REJECTED => 'Ditolak',
            default => 'Menunggu Verifikasi',
        };
    }

    /**
     * Warna indikator UI per status selisih.
     * balanced = hijau, over = kuning/oranye, short = merah.
     */
    public static function warnaStatusSelisih(string $status): array
    {
        return match ($status) {
            self::SELISIH_OVER => [
                'bg' => '#fef3c7',
                'border' => '#fcd34d',
                'text' => '#92400e',
                'badge' => '#f59e0b',
                'icon' => '⚠️',
            ],
            self::SELISIH_SHORT => [
                'bg' => '#fee2e2',
                'border' => '#fca5a5',
                'text' => '#991b1b',
                'badge' => '#dc2626',
                'icon' => '🔻',
            ],
            default => [
                'bg' => '#d1fae5',
                'border' => '#6ee7b7',
                'text' => '#065f46',
                'badge' => '#059669',
                'icon' => '✅',
            ],
        };
    }

    /**
     * Menormalkan input pecahan dari form menjadi baris rapi + total.
     *
     * Menerima dua bentuk: ['100000' => 3, ...] (lembar per pecahan) atau
     * [['pecahan' => 100000, 'lembar' => 3], ...]. Nilai pecahan yang tidak
     * termasuk daftar resmi diabaikan untuk mencegah data kotor.
     *
     * @return array{baris: array<int, array{pecahan: int, lembar: int, subtotal: float}>, total: float}
     */
    public static function hitungRincianPecahan(array $input): array
    {
        $lembarPerPecahan = [];

        foreach ($input as $key => $nilai) {
            if (is_array($nilai)) {
                $pecahan = (int) ($nilai['pecahan'] ?? 0);
                $lembar = (int) ($nilai['lembar'] ?? 0);
            } else {
                $pecahan = (int) $key;
                $lembar = (int) $nilai;
            }

            if (! in_array($pecahan, self::PECAHAN, true)) {
                continue;
            }

            $lembarPerPecahan[$pecahan] = max(0, $lembar);
        }

        $baris = [];
        $total = 0.0;

        foreach (self::PECAHAN as $pecahan) {
            $lembar = $lembarPerPecahan[$pecahan] ?? 0;
            $subtotal = (float) ($pecahan * $lembar);

            $baris[] = [
                'pecahan' => $pecahan,
                'lembar' => $lembar,
                'subtotal' => $subtotal,
            ];

            $total += $subtotal;
        }

        return ['baris' => $baris, 'total' => $total];
    }
}
