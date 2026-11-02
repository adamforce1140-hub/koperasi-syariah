<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat Pengujian — satu baris = satu sesi `run test` yang dijalankan dari UI.
 *
 * Lihat migrasi `2026_10_13_000200_create_test_runs_table.php` untuk skema.
 */
class TestRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'modul',
        'target_uji',
        'tanggal_uji',
        'status',
        'exit_code',
        'jumlah_tes',
        'lulus',
        'gagal',
        'dilewati',
        'assertions',
        'durasi_ms',
        'detail',
        'cuplikan_keluaran',
        'dijalankan_oleh',
        'nama_pelaksana',
    ];

    protected $casts = [
        'tanggal_uji' => 'date',
        'detail' => 'array',
        'jumlah_tes' => 'integer',
        'lulus' => 'integer',
        'gagal' => 'integer',
        'dilewati' => 'integer',
        'assertions' => 'integer',
        'durasi_ms' => 'integer',
    ];

    public function pelaksana(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dijalankan_oleh');
    }

    /**
     * Label status yang ramah untuk UI.
     */
    public function labelStatus(): string
    {
        return match ($this->status) {
            self::STATUS_PASSED => 'LULUS',
            self::STATUS_FAILED => 'GAGAL',
            self::STATUS_ERROR => 'ERROR',
            self::STATUS_RUNNING => 'BERJALAN',
            default => strtoupper((string) $this->status),
        };
    }

    public function berhasil(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }
}
