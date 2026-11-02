<?php

namespace App\Repositories;

use App\Contracts\AuditAkadRepositoryInterface;
use App\Models\Pembiayaan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Implementasi Eloquent untuk modul Audit Pembiayaan & Akad (DPS).
 *
 * Kueri dipindahkan apa adanya dari `DpsController::auditAkad()` — bentuk SQL
 * (`pascaPersetujuan()`, `whereDate('created_at')`, `selectRaw`, `groupBy`,
 * `pluck`, `latest()->paginate()`) tidak diubah sehingga hasil, urutan, dan
 * angka badge identik dengan sebelumnya (behavior-preserving).
 *
 * Whitelist akad dan normalisasi status tetap menjadi tanggung jawab
 * controller karena berkaitan dengan validasi masukan HTTP.
 */
final class AuditAkadRepository implements AuditAkadRepositoryInterface
{
    public function daftar(
        ?string $akad = null,
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null,
        int $perHalaman = 25
    ): LengthAwarePaginator {
        $query = $this->basis($cari, $tglMulai, $tglSelesai)
            ->with(['anggota', 'approvedBy']);

        if ($akad) {
            $query->where('akad_pembiayaan', $akad);
        }
        if ($status) {
            $query->statusIn([$status]);
        }

        return $query->latest()->paginate($perHalaman)->withQueryString();
    }

    public function hitungPerAkad(
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null
    ): Collection {
        return $this->basis($cari, $tglMulai, $tglSelesai)
            ->when($status, fn ($q) => $q->statusIn([$status]))
            ->selectRaw('akad_pembiayaan, COUNT(*) as jumlah')
            ->whereNotNull('akad_pembiayaan')
            ->groupBy('akad_pembiayaan')
            ->pluck('jumlah', 'akad_pembiayaan');
    }

    public function total(
        ?string $status = null,
        ?string $cari = null,
        ?string $tglMulai = null,
        ?string $tglSelesai = null
    ): int {
        return $this->basis($cari, $tglMulai, $tglSelesai)
            ->when($status, fn ($q) => $q->statusIn([$status]))
            ->count();
    }

    public function ringkasanPerAkadDanStatus(): Collection
    {
        return Pembiayaan::query()
            ->selectRaw('akad_pembiayaan, status_pembiayaan, COUNT(*) as jumlah, SUM(jumlah_pembiayaan) as total')
            ->pascaPersetujuan()
            ->whereNotNull('akad_pembiayaan')
            ->groupBy('akad_pembiayaan', 'status_pembiayaan')
            ->orderBy('akad_pembiayaan')
            ->get();
    }

    /**
     * Basis query bersama untuk tabel dan badge.
     *
     * Sengaja TIDAK memuat filter akad: badge tiap tab harus menghitung
     * grup akadnya masing-masing, bukan hanya tab yang sedang aktif.
     */
    private function basis(?string $cari, ?string $tglMulai, ?string $tglSelesai): Builder
    {
        $query = Pembiayaan::query()->pascaPersetujuan();

        if ($cari) {
            $query->where(function ($w) use ($cari) {
                $w->where('no_pembiayaan', 'like', "%{$cari}%")
                    ->orWhereHas('anggota', fn ($a) => $a->where('nama_lengkap', 'like', "%{$cari}%"));
            });
        }

        if ($tglMulai) {
            $query->whereDate('created_at', '>=', $tglMulai);
        }
        if ($tglSelesai) {
            $query->whereDate('created_at', '<=', $tglSelesai);
        }

        return $query;
    }
}
