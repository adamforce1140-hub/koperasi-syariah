<?php

namespace App\Models;

use App\Support\RentangPeriode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Angsuran extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'angsuran';

    protected $fillable = [
        'pembiayaan_id',
        'transaksi_id',
        'angsuran_ke',
        'tanggal_jatuh_tempo',
        'tanggal_bayar',
        'pokok_angsuran',
        'margin_ujroh',
        'jumlah_angsuran',
        'denda',
        'status_tazir',
        'total_bayar',
        'sisa_pokok',
        'status_angsuran',
        'hari_terlambat',
        'user_id',
        'catatan',
    ];

    protected $casts = [
        'tanggal_jatuh_tempo' => 'date',
        'tanggal_bayar' => 'date',
        'pokok_angsuran' => 'decimal:2',
        'margin_ujroh' => 'decimal:2',
        'jumlah_angsuran' => 'decimal:2',
        'denda' => 'decimal:2',
        'total_bayar' => 'decimal:2',
        'sisa_pokok' => 'decimal:2',
        'hari_terlambat' => 'integer',
    ];

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Riwayat catatan penagihan AO (terbaru di atas). */
    public function catatanPenagihan(): HasMany
    {
        return $this->hasMany(CatatanPenagihan::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function scopeBelumDibayar($query)
    {
        return $query->whereIn('status_angsuran', self::STATUS_BELUM_LUNAS);
    }

    /**
     * Status yang berarti angsuran BELUM lunas.
     * Satu sumber agar kartu "Tagihan Mendatang" pada portal anggota
     * tidak kehilangan angsuran berstatus "Dibayar Sebagian"
     * (sebelumnya daftar ini tersebar dan tidak konsisten antar query).
     *
     * @var list<string>
     */
    public const STATUS_BELUM_LUNAS = [
        'Belum Jatuh Tempo',
        'Jatuh Tempo',
        'Terlambat',
        'Lewat',
        'Dibayar Sebagian',
    ];

    public function scopeSudahDibayar($query)
    {
        return $query->where('status_angsuran', 'Sudah Dibayar');
    }

    /**
     * Angsuran yang sudah dibayar namun pokoknya belum tertagih penuh.
     * Selisihnya tersimpan di kolom sisa_pokok dan tetap menjadi kewajiban anggota.
     */
    public function scopeDibayarSebagian($query)
    {
        return $query->where('status_angsuran', 'Dibayar Sebagian');
    }

    /** Total pokok yang masih menjadi kewajiban anggota pada pembiayaan ini. */
    public static function totalSisaPokok(int $pembiayaanId): float
    {
        return (float) static::where('pembiayaan_id', $pembiayaanId)
            ->where('status_angsuran', 'Dibayar Sebagian')
            ->sum('sisa_pokok');
    }

    public function scopeTerlambat($query)
    {
        return $query->where('status_angsuran', 'Terlambat');
    }

    public function scopeJatuhTempoBulanIni($query)
    {
        $bulanIni = now();

        // Rentang eksplisit (sargable) agar indeks `tanggal_jatuh_tempo` dapat
        // dipakai MySQL. whereMonth()/whereYear() membungkus kolom dengan
        // fungsi sehingga indeks diabaikan (full table scan).
        return RentangPeriode::padaBulan(
            $query->getQuery(),
            'tanggal_jatuh_tempo',
            (int) $bulanIni->month,
            (int) $bulanIni->year
        );
    }

    /**
     * Accessor: status dinamis berdasarkan tanggal real (Carbon), bukan nilai DB.
     * Gunakan $angsuran->status_dinamis di view / blade.
     */
    public function getStatusDinamisAttribute(): string
    {
        if ($this->tanggal_bayar !== null) {
            return 'Sudah Dibayar';
        }
        if (! $this->tanggal_jatuh_tempo) {
            return 'Belum Jatuh Tempo';
        }
        $jatuhTempo = Carbon::parse($this->tanggal_jatuh_tempo)->startOfDay();
        $today = Carbon::today();

        if ($jatuhTempo->lt($today)) {
            return 'Terlambat';
        }
        if ($jatuhTempo->isToday()) {
            return 'Jatuh Tempo Hari Ini';
        }

        return 'Belum Jatuh Tempo';
    }

    /**
     * Accessor: status_badge — label + warna untuk ditampilkan di view/badge.
     * Gunakan $angsuran->status_badge['label'] dan $angsuran->status_badge['color'].
     */
    public function getStatusBadgeAttribute(): array
    {
        if ($this->tanggal_bayar !== null) {
            return ['label' => 'Sudah Dibayar', 'color' => 'green'];
        }
        if (! $this->tanggal_jatuh_tempo) {
            return ['label' => 'Belum Jatuh Tempo', 'color' => 'gray'];
        }
        $jatuhTempo = Carbon::parse($this->tanggal_jatuh_tempo)->startOfDay();
        $today = Carbon::today();

        if ($jatuhTempo->lt($today)) {
            return ['label' => 'Terlambat', 'color' => 'red'];
        }
        if ($jatuhTempo->isToday()) {
            return ['label' => 'Jatuh Tempo Hari Ini', 'color' => 'yellow'];
        }

        return ['label' => 'Belum Jatuh Tempo', 'color' => 'gray'];
    }

    /**
     * Hitung hari terlambat secara dinamis (real-time, bukan dari kolom DB).
     *
     * Arah selisih: dari Jatuh Tempo → Hari Ini, sehingga nilai POSITIF berarti
     * sudah melewati jatuh tempo (Carbon 3 mengembalikan nilai bertanda).
     */
    public function getHariTerlambatDinamisAttribute(): int
    {
        if ($this->tanggal_bayar !== null || ! $this->tanggal_jatuh_tempo) {
            return 0;
        }

        $hariTerlambat = Carbon::parse($this->tanggal_jatuh_tempo)
            ->startOfDay()
            ->diffInDays(Carbon::today(), false);

        return max(0, (int) round($hariTerlambat));
    }

    public function isSudahDibayar(): bool
    {
        return $this->status_angsuran === 'Sudah Dibayar';
    }

    public function isTerlambat(): bool
    {
        return in_array($this->status_angsuran, ['Terlambat', 'Lewat']);
    }

    /**
     * Hari terlambat sebuah jadwal angsuran yang belum dibayar.
     *
     * Sebelumnya arah selisihnya TERBALIK: `$sekarang->diffInDays($jatuhTempo)`
     * menghasilkan angka NEGATIF untuk angsuran yang sudah lewat jatuh tempo,
     * sehingga selisihnya -68 hari (dan memicu deprecation "implicit conversion
     * from float to int"). Sekarang memakai accessor dinamis yang arahnya
     * sudah benar (jatuh tempo → hari ini) sehingga hanya ada satu rumus.
     */
    public function hitungHariTerlambat(): int
    {
        return $this->hari_terlambat_dinamis;
    }
}
