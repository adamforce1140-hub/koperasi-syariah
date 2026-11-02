<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tunggakan Ta'zir (denda) akibat pengajuan keringanan DITOLAK Manajer.
 *
 * Ta'zir belum dibukukan ke Dana Kebajikan (2-3001) selama belum benar-benar
 * dibayar (Cash Basis Syariah / PSAK 109). Tunggakan ditagihkan pada transaksi
 * teller berikutnya, lalu ditandai is_tazir_paid = true saat diterima.
 *
 * Autodebet: bila Manajer menolak keringanan dan saldo Simpanan Sukarela anggota
 * mencukupi, tunggakan langsung dipotong otomatis (flag = AUTODEBET_LUNAS).
 * Bila saldo TIDAK mencukupi, saldo TIDAK dipotong (cegah minus) dan tunggakan
 * diberi flag PENDING_AUTODEBET_SUSULAN untuk dipotong susulan.
 */
class TazirPenangguhan extends Model
{
    protected $table = 'tazir_penangguhans';

    /** Flag: tunggakan menunggu pemotongan autodebet susulan (saldo tidak cukup). */
    public const FLAG_PENDING_AUTODEBET_SUSULAN = 'PENDING_AUTODEBET_SUSULAN';

    /** Flag: tunggakan sudah dipotong otomatis dari Simpanan Sukarela. */
    public const FLAG_AUTODEBET_LUNAS = 'AUTODEBET_LUNAS';

    protected $fillable = [
        'tazir_relief_request_id',
        'jadwal_angsuran_id',
        'pembiayaan_id',
        'anggota_id',
        'nominal_tazir',
        'is_tazir_paid',
        'flag',
        'paid_at',
        'paid_angsuran_id',
        'transaksi_id',
        'catatan',
    ];

    protected $casts = [
        'nominal_tazir' => 'decimal:2',
        'is_tazir_paid' => 'boolean',
        'paid_at' => 'datetime',
    ];

    /* ── Relasi ─────────────────────────────────────────────── */

    public function reliefRequest(): BelongsTo
    {
        return $this->belongsTo(TazirReliefRequest::class, 'tazir_relief_request_id');
    }

    public function angsuran(): BelongsTo
    {
        return $this->belongsTo(Angsuran::class, 'jadwal_angsuran_id');
    }

    public function paidAngsuran(): BelongsTo
    {
        return $this->belongsTo(Angsuran::class, 'paid_angsuran_id');
    }

    public function pembiayaan(): BelongsTo
    {
        return $this->belongsTo(Pembiayaan::class);
    }

    public function anggota(): BelongsTo
    {
        return $this->belongsTo(Anggota::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    /* ── Scope ──────────────────────────────────────────────── */

    public function scopeBelumDibayar(Builder $q): Builder
    {
        return $q->where('is_tazir_paid', false);
    }

    public function scopeSudahDibayar(Builder $q): Builder
    {
        return $q->where('is_tazir_paid', true);
    }

    /** Tunggakan yang menunggu pemotongan autodebet susulan (saldo saat penolakan tidak cukup). */
    public function scopePendingAutodebetSusulan(Builder $q): Builder
    {
        return $q->where('flag', self::FLAG_PENDING_AUTODEBET_SUSULAN)
            ->where('is_tazir_paid', false);
    }

    /* ── Helper ─────────────────────────────────────────────── */

    public function isPendingAutodebetSusulan(): bool
    {
        return $this->flag === self::FLAG_PENDING_AUTODEBET_SUSULAN && ! $this->is_tazir_paid;
    }

    /**
     * Total tunggakan ta'zir yang belum dibayar milik seorang anggota.
     */
    public static function totalTunggakanAnggota(int $anggotaId): float
    {
        return (float) static::belumDibayar()->where('anggota_id', $anggotaId)->sum('nominal_tazir');
    }

    /**
     * Total tunggakan ta'zir yang belum dibayar pada satu pembiayaan.
     */
    public static function totalTunggakanPembiayaan(int $pembiayaanId): float
    {
        return (float) static::belumDibayar()->where('pembiayaan_id', $pembiayaanId)->sum('nominal_tazir');
    }
}
