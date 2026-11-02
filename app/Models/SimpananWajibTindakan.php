<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat tindakan penanganan tunggakan Simpanan Wajib (audit trail).
 *
 * APPEND-ONLY: setiap tindakan sistem/petugas atas satu tagihan dicatat
 * sebagai baris baru. Baris tidak pernah diubah setelah dibuat sehingga
 * riwayat tetap utuh untuk audit dan transparansi anggota (spesifikasi #5).
 *
 * Prinsip syariah: TIDAK ADA DENDA atas keterlambatan. Kolom `denda` selalu
 * 0 dan hanya disimpan sebagai bukti kepatuhan pada saat audit.
 */
class SimpananWajibTindakan extends Model
{
    use HasFactory;

    public const JENIS_AUTO_DEBIT_BERHASIL = 'AUTO_DEBIT_BERHASIL';

    public const JENIS_AUTO_DEBIT_GAGAL = 'AUTO_DEBIT_GAGAL';

    public const JENIS_NOTIFIKASI = 'NOTIFIKASI';

    public const JENIS_JADWAL_BERTAHAP = 'JADWAL_BERTAHAP';

    public const JENIS_CICILAN_DIBAYAR = 'CICILAN_DIBAYAR';

    public const JENIS_RESCHEDULE = 'RESCHEDULE';

    public const JENIS_BAYAR_TELLER = 'BAYAR_TELLER';

    public const JENIS_TANDAI_OVERDUE = 'TANDAI_OVERDUE';

    public const JENIS_VERIFIKASI_GAGAL = 'VERIFIKASI_GAGAL';

    public const JENIS_DITANGGUHKAN = 'DITANGGUHKAN';

    public const JENIS_KONFIRMASI_MANUAL = 'KONFIRMASI_MANUAL';

    public const JENIS_TOLAK_KONFIRMASI = 'TOLAK_KONFIRMASI';

    public const JENIS_LABEL = [
        self::JENIS_AUTO_DEBIT_BERHASIL => 'Auto-Debit Berhasil',
        self::JENIS_AUTO_DEBIT_GAGAL => 'Auto-Debit Gagal (Saldo Kurang)',
        self::JENIS_NOTIFIKASI => 'Notifikasi Terkirim',
        self::JENIS_JADWAL_BERTAHAP => 'Jadwal Pembayaran Bertahap',
        self::JENIS_CICILAN_DIBAYAR => 'Cicilan Dibayar',
        self::JENIS_RESCHEDULE => 'Penjadwalan Ulang (Tanpa Denda)',
        self::JENIS_BAYAR_TELLER => 'Pelunasan di Teller',
        self::JENIS_TANDAI_OVERDUE => 'Ditandai Overdue',
        self::JENIS_VERIFIKASI_GAGAL => 'Verifikasi Status Pembayaran Gagal',
        self::JENIS_DITANGGUHKAN => 'Setoran Ditangguhkan (Menunggu Konfirmasi)',
        self::JENIS_KONFIRMASI_MANUAL => 'Konfirmasi Manual Petugas',
        self::JENIS_TOLAK_KONFIRMASI => 'Konfirmasi Ditolak Petugas',
    ];

    public const STATUS_BERHASIL = 'BERHASIL';

    public const STATUS_GAGAL = 'GAGAL';

    public const STATUS_INFO = 'INFO';

    protected $table = 'simpanan_wajib_tindakans';

    protected $fillable = [
        'bill_id',
        'member_id',
        'jenis',
        'periode',
        'nominal',
        'saldo_sukarela',
        'status_hasil',
        'cicilan_ke',
        'total_cicilan',
        'nominal_cicilan',
        'tenggat_lama',
        'tenggat_baru',
        'denda',
        'alasan',
        'catatan',
        'user_id',
        'waktu_tindakan',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'saldo_sukarela' => 'decimal:2',
        'nominal_cicilan' => 'decimal:2',
        'denda' => 'decimal:2',
        'tenggat_lama' => 'date',
        'tenggat_baru' => 'date',
        'waktu_tindakan' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(MandatorySavingsBill::class, 'bill_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Anggota::class, 'member_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ── Scope ─────────────────────────────────────────────────────────── */

    public function scopeJenis($query, string $jenis)
    {
        return $query->where('jenis', $jenis);
    }

    public function scopePeriode($query, string $periode)
    {
        return $query->where('periode', $periode);
    }

    /* ── Helper ────────────────────────────────────────────────────────── */

    public function getJenisLabelAttribute(): string
    {
        return self::JENIS_LABEL[$this->jenis] ?? $this->jenis;
    }

    public function getNominalFormattedAttribute(): string
    {
        return 'Rp '.number_format((float) $this->nominal, 0, ',', '.');
    }

    /** Apakah tindakan ini terkait penjadwalan (bertahap / reschedule)? */
    public function isPenjadwalan(): bool
    {
        return in_array($this->jenis, [
            self::JENIS_JADWAL_BERTAHAP,
            self::JENIS_RESCHEDULE,
        ], true);
    }

    /** Waktu tindakan dalam format tanggal-waktu lokal (helper tampilan). */
    public function getWaktuFormattedAttribute(): string
    {
        return $this->waktu_tindakan
            ? Carbon::parse($this->waktu_tindakan)->format('d/m/Y H:i')
            : '—';
    }
}
