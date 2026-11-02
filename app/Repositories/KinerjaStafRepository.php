<?php

namespace App\Repositories;

use App\Contracts\KinerjaStafRepositoryInterface;
use App\Models\PengajuanPembiayaan;
use App\Models\Transaksi;
use App\Models\User;
use App\Support\HierarkiRole;
use App\Support\RentangPeriode;
use Illuminate\Support\Collection;

/**
 * Implementasi Eloquent untuk laporan kinerja staf (Manajer).
 *
 * Seluruh kueri agregat yang sebelumnya berada di `ManajerController`
 * dipindahkan ke sini tanpa mengubah bentuk SQL-nya, sehingga hasil laporan
 * identik dengan sebelumnya (behavior-preserving).
 */
final class KinerjaStafRepository implements KinerjaStafRepositoryInterface
{
    /**
     * Jenis transaksi yang TIDAK dihitung sebagai kinerja pelayanan Teller,
     * karena dibuat otomatis oleh sistem (bukan hasil pelayanan kasir).
     */
    private const JENIS_TRANSAKSI_SISTEM = [
        'AUTODEBET_TAZIR',
    ];

    /**
     * Pola nama transaksi sistem yang bervariasi tiap periode,
     * contoh: 'Setoran SHU Tahun Buku 2026'.
     */
    private const POLA_TRANSAKSI_SISTEM = [
        'Setoran SHU%',
    ];

    public function penggunaKelompok(string $kelompok): Collection
    {
        return User::whereIn('role', HierarkiRole::kelompok($kelompok))
            ->orderBy('name')
            ->get();
    }

    public function agregatPengajuan(int $bulan, int $tahun): Collection
    {
        return RentangPeriode::padaBulan(
            PengajuanPembiayaan::query(),
            'created_at', $bulan, $tahun
        )
            ->selectRaw('COALESCE(diproses_oleh, ao_id, created_by) as user_id')
            ->selectRaw('count(*) as total_pengajuan')
            ->selectRaw('COALESCE(sum(jumlah_pengajuan), 0) as total_nominal')
            ->groupByRaw('COALESCE(diproses_oleh, ao_id, created_by)')
            ->get()
            ->filter(fn ($r) => $r->user_id !== null)
            ->keyBy(fn ($r) => (int) $r->user_id);
    }

    public function agregatTransaksi(int $bulan, int $tahun): Collection
    {
        return $this->kecualikanTransaksiSistem(
            RentangPeriode::padaBulan(
                Transaksi::query()->where('status_transaksi', 'Sukses'),
                'waktu_transaksi', $bulan, $tahun
            )
                ->selectRaw('user_id')
                ->selectRaw('count(*) as total_transaksi')
                ->selectRaw('COALESCE(sum(jumlah), 0) as volume_transaksi')
                ->groupBy('user_id')
        )
            ->get()
            ->filter(fn ($r) => $r->user_id !== null)
            ->keyBy(fn ($r) => (int) $r->user_id);
    }

    public function identitasPengguna(Collection $userIds): Collection
    {
        return User::whereIn('id', $userIds->filter()->unique())
            ->get(['id', 'name', 'role'])
            ->keyBy('id');
    }

    /**
     * Kecualikan transaksi yang dibuat otomatis oleh sistem dari perhitungan
     * kinerja Teller (transaksi massal / autodebet bukan hasil pelayanan kasir).
     */
    private function kecualikanTransaksiSistem($query)
    {
        return $query
            ->whereNotIn('jenis_transaksi', self::JENIS_TRANSAKSI_SISTEM)
            ->where(function ($q) {
                foreach (self::POLA_TRANSAKSI_SISTEM as $pola) {
                    $q->where('jenis_transaksi', 'not like', $pola);
                }
            });
    }
}
