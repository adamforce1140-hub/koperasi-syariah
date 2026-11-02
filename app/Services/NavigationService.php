<?php

namespace App\Services;

use App\Models\AutodebitUsulan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengajuanRekening;
use App\Models\TazirReliefRequest;
use App\Models\TellerOpname;
use App\Support\HierarkiRole;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Navigasi sidebar — pembaca data dari `config/navigasi.php`.
 *
 * Kelas ini TIDAK lagi menyimpan menu per peran di dalam kode. Definisi menu,
 * peta ikon, warna badge, dan alias peran seluruhnya berada di konfigurasi,
 * sehingga menambah peran baru cukup menambah entri `navigasi.menu.<peran>`.
 *
 * Bila sebuah peran DIKENAL sistem (ada di `role_hierarki.peran`) tetapi belum
 * punya entri menu, kelas ini tidak lagi mengembalikan `[]` secara senyap:
 * ia menurunkan menu minimal dari `HierarkiRole::dashboardRoute()` dan menulis
 * peringatan ke log. Peran yang sama sekali tidak dikenal tetap `[]`.
 */
class NavigationService
{
    /**
     * Menu sidebar untuk satu peran.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getMenu(string $role): array
    {
        $kunci = static::kunciMenu($role);

        $menu = config('navigasi.menu.'.$kunci);

        if (is_array($menu)) {
            return $menu;
        }

        if (! HierarkiRole::dikenal($role)) {
            return [];
        }

        Log::warning('NavigationService: peran dikenal tetapi belum punya menu.', [
            'role' => $role,
            'kunci_menu' => $kunci,
            'petunjuk' => 'Tambahkan entri navigasi.menu.'.$kunci.' pada config/navigasi.php.',
        ]);

        return static::menuMinimal($role);
    }

    /** Kunci menu efektif setelah alias peran diterapkan (mis. nasabah → anggota). */
    public static function kunciMenu(string $role): string
    {
        $alias = (array) config('navigasi.alias', []);

        return (string) ($alias[$role] ?? $role);
    }

    /** Emoji ikon dari konfigurasi. Nama tak dikenal → pin. */
    public static function ikon(?string $nama): string
    {
        if ($nama === null || $nama === '') {
            return '📌';
        }

        return (string) config('navigasi.ikon.'.$nama, '📌');
    }

    /** Apakah sebuah nama ikon terdaftar (dipakai untuk membedakan "tanpa ikon"). */
    public static function adaIkon(?string $nama): bool
    {
        return $nama !== null && $nama !== '' && config('navigasi.ikon.'.$nama) !== null;
    }

    /** Kelas badge untuk sebuah peran (tabel Limit Transaksi). */
    public static function warnaRole(?string $peran): string
    {
        $peta = (array) config('navigasi.warna_role', []);

        return (string) ($peta[$peran] ?? config('navigasi.warna_role_default', 'badge-purple'));
    }

    /**
     * Nilai badge dinamis berdasarkan kunci.
     *
     * Query sengaja dipusatkan di sini (bukan di Blade) agar dapat diuji dan
     * tidak mencampur lapisan presentasi dengan akses data.
     */
    public static function badge(string $kunci): int
    {
        return match ($kunci) {
            'tazir_pending_ao' => Schema::hasTable('tazir_relief_requests')
                ? TazirReliefRequest::pendingAo()->count()
                : 0,
            'closing_pending_manajer' => Schema::hasTable('teller_opnames')
                ? TellerOpname::where('status', TellerOpname::STATUS_PENDING)->count()
                : 0,
            'autodebit_pending' => Schema::hasTable('autodebit_usulans')
                ? AutodebitUsulan::menungguKonfirmasi()->count()
                : 0,
            'super_admin_antrean' => static::badgeSuperAdmin(),
            default => 0,
        };
    }

    /** Antrean Super Admin: kegiatan yang memerlukan perhatian/persetujuan. */
    private static function badgeSuperAdmin(): int
    {
        $jumlah = 0;

        if (Schema::hasTable('pembiayaans')) {
            $jumlah += Pembiayaan::query()
                ->whereIn('status', Pembiayaan::statusPascaPersetujuan())
                ->count();
        }

        if (Schema::hasTable('pengajuan_pembiayaans')) {
            $jumlah += PengajuanPembiayaan::menunggu()->count();
        }

        if (Schema::hasTable('pengajuan_rekenings')) {
            $jumlah += PengajuanRekening::query()->statusIn([
                PengajuanRekening::STATUS_MENUNGGU_VERIFIKASI,
                PengajuanRekening::STATUS_MENUNGGU_PEMBAYARAN,
                PengajuanRekening::STATUS_MENUNGGU_KONFIRMASI,
            ])->count();
        }

        return $jumlah;
    }

    /**
     * Menu minimal untuk peran yang dikenal tetapi belum didaftarkan.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function menuMinimal(string $role): array
    {
        $rute = HierarkiRole::dashboardRoute($role);

        if ($rute === null) {
            return [];
        }

        return [[
            'label' => 'Dashboard',
            'route' => $rute,
            'icon' => 'home',
            'children' => [],
        ]];
    }
}
