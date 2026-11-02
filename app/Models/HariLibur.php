<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kalender Hari Libur Nasional / Cuti Bersama / Libur Koperasi.
 *
 * Dipakai penjadwalan angsuran: tanggal jatuh tempo yang jatuh pada hari libur
 * digeser ke hari kerja terdekat. Data tidak ditanam di kode (zero-hardcoding)
 * dan dapat dipelihara lewat seeder maupun menu master.
 */
class HariLibur extends Model
{
    protected $table = 'hari_liburs';

    public const JENIS_NASIONAL = 'Nasional';

    public const JENIS_CUTI_BERSAMA = 'Cuti Bersama';

    public const JENIS_KOPERASI = 'Koperasi';

    /** Label manusiawi tiap jenis — untuk dropdown & tampilan tabel. */
    public const LABEL_JENIS = [
        self::JENIS_NASIONAL => 'Libur Nasional',
        self::JENIS_CUTI_BERSAMA => 'Cuti Bersama',
        self::JENIS_KOPERASI => 'Libur Koperasi',
    ];

    protected $fillable = [
        'tanggal',
        'keterangan',
        'jenis',
        'is_active',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'is_active' => 'boolean',
    ];

    /** Daftar jenis libur (untuk dropdown / validasi dinamis). */
    public static function daftarJenis(): array
    {
        return [
            self::JENIS_NASIONAL,
            self::JENIS_CUTI_BERSAMA,
            self::JENIS_KOPERASI,
        ];
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    /** Apakah tanggal tertentu terdaftar sebagai hari libur aktif? */
    public static function merupakanLibur(\DateTimeInterface|string $tanggal): bool
    {
        $tgl = $tanggal instanceof \DateTimeInterface
            ? $tanggal->format('Y-m-d')
            : (string) $tanggal;

        return static::query()->aktif()->whereDate('tanggal', $tgl)->exists();
    }
}
