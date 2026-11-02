<?php

namespace App\Models;

use App\Support\HierarkiRole;
use Illuminate\Database\Eloquent\Model;

class LimitTransaksiRole extends Model
{
    protected $table = 'limit_transaksi_role';

    protected $fillable = ['role', 'jenis_transaksi', 'limit_per_transaksi', 'limit_harian', 'butuh_approval', 'approval_role'];

    protected $casts = ['butuh_approval' => 'boolean', 'limit_per_transaksi' => 'decimal:2', 'limit_harian' => 'decimal:2'];

    /**
     * Tabel ini adalah lapisan OVERRIDE atas default di `config/role_hierarki.php`.
     * Setiap perubahan harus segera terlihat oleh penegakan batas, jadi cache
     * HierarkiRole dibersihkan otomatis (pola yang sama dengan
     * KebijakanKoperasi::booted() -> Setting::flush()).
     */
    protected static function booted(): void
    {
        // PELINDUNG PUNCAK ADMINISTRATIF — langkah #3. Override limit adalah
        // cara paling halus "mencabut" hak akses: menetapkan limit 0 atau
        // mewajibkan approval membuat Super Admin tidak dapat bertindak.
        // Pihak lain tidak boleh melakukannya; Super Admin boleh mengatur
        // dirinya sendiri (tanpa membatasi diri selamanya).
        static::saving(function (self $limit): void {
            $peranTerdampak = $limit->exists
                ? (string) $limit->getOriginal('role')
                : (string) $limit->role;

            $aktor = auth()->user()?->role;

            if (! HierarkiRole::bolehMengubahPeran($aktor, $peranTerdampak)) {
                throw new \RuntimeException(
                    "Batas operasional '{$peranTerdampak}' terkunci: hanya "
                    ."{$peranTerdampak} yang boleh mengubahnya."
                );
            }
        });

        static::saved(fn () => HierarkiRole::flush());
        static::deleted(fn () => HierarkiRole::flush());
    }

    public static function getLimit(string $role, string $jenis): ?self
    {
        return static::where('role', $role)->where('jenis_transaksi', $jenis)->first();
    }
}
