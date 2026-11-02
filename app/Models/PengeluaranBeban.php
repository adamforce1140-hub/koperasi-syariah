<?php

namespace App\Models;

use App\Support\NomorDokumen;
use App\Support\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Pengeluaran Beban Operasional Koperasi — auto-posting ke CoA kelompok Beban (5-XXXX).
 * DEBET: Akun Beban (mis. 5-1001) | KREDIT: Sumber Kas/Bank (1-1001 / 1-1002).
 */
class PengeluaranBeban extends Model
{
    use SoftDeletes;

    /**
     * Batas kewenangan Teller/AO: di atas ini butuh approval Manajer.
     *
     * Dipertahankan sebagai nilai fallback/kompatibilitas. Nilai operasional
     * yang berlaku dibaca dari kebijakan koperasi via static::limitApproval()
     * sehingga dapat diubah pengurus tanpa deploy ulang.
     */
    public const LIMIT_APPROVAL = 1000000;

    /**
     * Nilai status pada kolom `status` (enum). Hanya STATUS_APPROVED yang boleh
     * masuk ke perhitungan card 'Total Beban Disetujui' — Pending & Ditolak
     * wajib dikecualikan.
     */
    public const STATUS_APPROVED = 'Approved';

    public const STATUS_PENDING = 'Pending';

    public const STATUS_DITOLAK = 'Ditolak';

    /** Seluruh status sah pada kolom enum — dasar validasi anti status liar. */
    public const STATUS_SAH = [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_DITOLAK];

    protected $table = 'pengeluaran_beban';

    protected $fillable = [
        'no_pengeluaran',
        'tanggal',
        'coa_beban_id',
        'coa_kas_id',
        'nominal',
        'keterangan',
        'status',
        'jurnal_id',
        'created_by',
        'approved_by',
        'approved_at',
        'catatan_manajer',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'nominal' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function coaBeban(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_beban_id');
    }

    public function coaKas(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'coa_kas_id');
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(Jurnal::class);
    }

    public function penginput(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Generator nomor: BBK-YYYYMMDD-0001 (Bukti Bank/Kas Keluar, harian, aman terhadap record terhapus). */
    public static function buatNoPengeluaran(): string
    {
        // Awalan dapat diubah Pengurus (Setting `prefix_nomor_pengeluaran_beban`);
        // kosong = tetap `BBK`.
        $prefix = NomorDokumen::prefix('pengeluaran_beban');

        // Ambil urutan MAKSIMAL (bukan count) agar tidak duplikat jika ada record terhapus
        $maxNo = (string) static::where('no_pengeluaran', 'like', $prefix.'%')->max('no_pengeluaran');
        $urut = $maxNo ? ((int) substr($maxNo, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urut, 4, '0', STR_PAD_LEFT);
    }

    /** Nominal ini butuh approval Manajer? */
    public function butuhApprovalManajer(): bool
    {
        return (float) $this->nominal > static::limitApproval();
    }

    /**
     * Limit approval beban operasional yang berlaku saat ini.
     *
     * Dibaca dari kebijakan koperasi (dapat diubah pengurus melalui Master
     * Settings) dengan konstanta LIMIT_APPROVAL sebagai nilai fallback bila
     * parameter kebijakan belum tersedia.
     */
    public static function limitApproval(): float
    {
        try {
            return Setting::limitApprovalBeban();
        } catch (\Throwable $e) {
            // Fallback ke konstanta agar approval beban tetap dapat berjalan;
            // kegagalan pembacaan kebijakan dicatat agar tidak senyap.
            Log::warning('PengeluaranBeban: gagal membaca limit approval dari kebijakan, memakai konstanta.', [
                'error' => $e->getMessage(),
            ]);

            return (float) self::LIMIT_APPROVAL;
        }
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
