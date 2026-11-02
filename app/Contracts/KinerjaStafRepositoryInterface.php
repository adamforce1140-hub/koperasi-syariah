<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * KONTRAK — DATA AKSES LAPORAN KINERJA STAF.
 *
 * Memisahkan kueri agregat (GROUP BY / COALESCE / SUM) dari controller
 * sehingga controller hanya mengurus HTTP + penyajian. Seluruh aturan
 * "apa yang dihitung" tetap satu tempat, dan implementasi dapat ditukar
 * (mis. versi cache atau view SQL) tanpa menyentuh pemanggil.
 *
 * Implementasi bawaan: `App\Repositories\KinerjaStafRepository`.
 */
interface KinerjaStafRepositoryInterface
{
    /**
     * Daftar pengguna menurut kelompok peran dari `role_hierarki.kelompok`
     * (mis. 'kinerja_ao', 'kinerja_teller' — lihat `config/role_hierarki.php`).
     *
     * @return Collection<int, User>
     */
    public function penggunaKelompok(string $kelompok): Collection;

    /**
     * Agregat pengajuan pembiayaan per penanggung jawab pada satu bulan.
     *
     * Kunci koleksi = `user_id`; nilai memuat `total_pengajuan` dan
     * `total_nominal`.
     *
     * @return Collection<int, object>
     */
    public function agregatPengajuan(int $bulan, int $tahun): Collection;

    /**
     * Agregat transaksi pelayanan kasir per staf pada satu bulan.
     *
     * Hanya transaksi 'Sukses' dan mengecualikan transaksi sistem otomatis.
     * Kunci koleksi = `user_id`; nilai memuat `total_transaksi` dan
     * `volume_transaksi`.
     *
     * @return Collection<int, object>
     */
    public function agregatTransaksi(int $bulan, int $tahun): Collection;

    /**
     * Identitas ringkas (id, name, role) untuk sekumpulan id pengguna.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, User>
     */
    public function identitasPengguna(Collection $userIds): Collection;
}
