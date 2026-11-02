<?php

namespace App\Contracts;

use App\Models\Pembiayaan;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * KONTRAK — DATA AKSES PEMBIAYAAN BERMASALAH (NPF).
 *
 * Memisahkan kueri NPF yang kompleks (relasi terfilter + `orderByRaw`
 * subkueri + paginasi) dari controller Manajer. Aturan "apa yang dianggap
 * bermasalah" dan "bagaimana diurutkan" jadi satu tempat dan dapat ditukar
 * implementasinya tanpa menyentuh pemanggil.
 *
 * Implementasi bawaan: `App\Repositories\PembiayaanBermasalahRepository`.
 */
interface PembiayaanBermasalahRepositoryInterface
{
    /**
     * Status pembiayaan yang masih berjalan sehingga layak dinilai NPF.
     *
     * @return list<string>
     */
    public function statusBerjalan(): array;

    /**
     * Daftar pembiayaan menunggak, terurut dari tunggakan terlama.
     *
     * Memuat relasi `anggota`, `produk`, dan `jadwalAngsuran` yang HANYA berisi
     * angsuran lewat jatuh tempo dan belum dibayar — sehingga pemanggil dapat
     * langsung menjumlahkannya sebagai tunggakan.
     *
     * @return LengthAwarePaginator<int, Pembiayaan>
     */
    public function menunggak(
        ?CarbonInterface $perTanggal = null,
        ?string $cari = null,
        int $perHalaman = 20
    ): LengthAwarePaginator;
}
