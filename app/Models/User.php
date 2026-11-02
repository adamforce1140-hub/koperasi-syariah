<?php

namespace App\Models;

use App\Support\HierarkiRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        // SEC-02: password otomatis di-hash saat ditetapkan. Menghilangkan
        // ketergantungan pada pemanggil agar tidak ada jalur (seeder, command,
        // panel admin) yang menyimpan password dalam bentuk teks polos.
        'password' => 'hashed',
    ];

    /**
     * DYN-01 — validasi peran di aplikasi, bukan di ENUM basis data.
     *
     * Kolom `users.role` kini VARCHAR; tanpa penjaga ini nilai salah ketik
     * (mis. `manajerx`) akan tersimpan diam-diam dan pengguna hanya melihat
     * 403 tanpa sebab yang jelas. Daftar peran sah dibaca dari SSOT RBAC
     * (`config/role_hierarki.php`), sehingga peran baru langsung diterima.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $peran = $user->role;

            if ($peran !== null && ! HierarkiRole::dikenal((string) $peran)) {
                throw new \InvalidArgumentException(
                    "Peran '{$peran}' tidak dikenal. Tambahkan entri pada "
                    .'config/role_hierarki.php (bagian `peran`) sebelum menyimpan pengguna.'
                );
            }

            // PELINDUNG PUNCAK ADMINISTRATIF — langkah #3 permintaan pemilik
            // sistem: hak akses `super_admin` tidak dapat dicabut role mana pun
            // selain dirinya sendiri. Penjaga ini diletakkan di MODEL agar
            // berlaku untuk SEMUA jalur (controller, seeder, command, tinker),
            // bukan hanya lapisan HTTP.
            //
            // Menyimpan ULANG nilai yang sama (idempoten) tetap diizinkan
            // supaya seeder/toggle is_active tidak pecah; yang ditolak adalah
            // PERUBAHAN peran puncak menjadi peran lain.
            if ($user->exists && $user->isDirty('role')) {
                $peranLama = (string) $user->getOriginal('role');
                $aktor = auth()->user()?->role;

                if (! HierarkiRole::bolehMengubahPeran($aktor, $peranLama)) {
                    throw new \RuntimeException(
                        "Hak akses '{$peranLama}' terkunci: hanya {$peranLama} yang boleh mengubahnya."
                    );
                }
            }
        });
    }

    public function anggota(): HasOne
    {
        return $this->hasOne(Anggota::class);
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function angsuran(): HasMany
    {
        return $this->hasMany(Angsuran::class);
    }

    public function pembiayaanApproved(): HasMany
    {
        return $this->hasMany(Pembiayaan::class, 'user_approved');
    }

    public function jurnalPosted(): HasMany
    {
        return $this->hasMany(Jurnal::class, 'user_posted');
    }

    public function jurnalCreated(): HasMany
    {
        return $this->hasMany(Jurnal::class, 'user_id');
    }

    /**
     * Apakah pengguna berperan TUNGGAL $peran.
     *
     * Semua helper `is*()` di bawah adalah pembungkus tipis method ini, sehingga
     * nama peran hidup di SATU titik saja. Menambah peran baru cukup menambah
     * helper sejenis — tidak ada literal `'role'` yang tersebar.
     */
    public function berperan(string $peran): bool
    {
        return $this->role === $peran;
    }

    public function isTeller(): bool
    {
        return $this->berperan('teller');
    }

    public function isManajer(): bool
    {
        return $this->berperan('manajer');
    }

    // Roles valid: admin, manajer, teller, pengurus, ao, dps
    public function isPengurus(): bool
    {
        return $this->berperan('pengurus');
    }

    public function isAO(): bool
    {
        return $this->berperan('ao');
    }

    public function isDPS(): bool
    {
        return $this->berperan('dps');
    }

    /**
     * Super Admin — administrator sistem dengan kewenangan tertinggi di antara
     * seluruh peran administratif. Bukan peran operasional/tata kelola.
     */
    public function isSuperAdmin(): bool
    {
        return $this->berperan('super_admin');
    }

    /**
     * Peran sistem (administratif) — memegang wewenang konfigurasi &
     * pemeliharaan sistem, bukan transaksi operasional.
     *
     * Sumber daftar: `role_hierarki.kelompok.sistem` (peran terpusat) —
     * bukan lagi array literal di kelas ini.
     */
    public function isAdminSistem(): bool
    {
        return HierarkiRole::dalamKelompok($this->role, 'sistem');
    }

    // Helper untuk cek apakah user adalah staf internal koperasi
    // (pengawas + pengurus + pengelola operasional).
    // Sumber daftar: `role_hierarki` kategori peran terpusat.
    public function isPengelola(): bool
    {
        return HierarkiRole::dalamKelompok($this->role, 'pengawas')
            || HierarkiRole::dalamKelompok($this->role, 'pengurus')
            || HierarkiRole::dalamKelompok($this->role, 'pengelola');
    }

    // Helper untuk cek apakah user punya akses operasional (bukan read-only).
    // Sumber daftar: `role_hierarki.kelompok.operasional`.
    public function hasOperationalAccess(): bool
    {
        return HierarkiRole::dalamKelompok($this->role, 'operasional');
    }

    // Helper untuk label tampilan peran (mis. "Super Admin", "Teller").
    // Sumber label: `role_hierarki.peran.<role>.label` — satu tempat saja.
    public function labelPeran(): string
    {
        return HierarkiRole::label($this->role);
    }
}
