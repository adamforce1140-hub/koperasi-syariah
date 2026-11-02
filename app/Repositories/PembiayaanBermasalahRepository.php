<?php

namespace App\Repositories;

use App\Contracts\PembiayaanBermasalahRepositoryInterface;
use App\Models\Pembiayaan;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Implementasi Eloquent untuk daftar pembiayaan bermasalah (NPF) Manajer.
 *
 * Kueri dipindahkan dari `ManajerController::npf()` — bentuk SQL (relasi
 * terfilter, `orderByRaw` subkueri, relasi cari) dipertahankan; yang diubah
 * hanya DASAR penentuan "sudah dibayar".
 *
 * Audit a8 (#30): sebelumnya status lunas ditentukan oleh kolom
 * `angsuran.status_angsuran`. Kolom itu bisa basi — pada data hasil seeder
 * lama, angsuran yang sudah dibayar tetap tertulis 'Jatuh Tempo' sehingga
 * eager-load `jadwalAngsuran` ikut menjumlah baris yang sudah lunas dan NPF
 * jadi overstated. Sumber kebenaran pembayaran adalah `tanggal_bayar`
 * (diisi bersama transaksi pembayaran, dikosongkan lagi saat void oleh
 * `ReversalService`), jadi filter tunggakan sekarang memakai kolom itu.
 */
final class PembiayaanBermasalahRepository implements PembiayaanBermasalahRepositoryInterface
{
    /** Status angsuran yang pokoknya belum tertagih penuh. */
    private const STATUS_ANGSURAN_SEBAGIAN = 'Dibayar Sebagian';

    public function statusBerjalan(): array
    {
        return [
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_RESTRUKTURISASI,
            Pembiayaan::STATUS_PENDING_RESTRUKTURISASI,
            Pembiayaan::STATUS_MACET,
        ];
    }

    public function menunggak(
        ?CarbonInterface $perTanggal = null,
        ?string $cari = null,
        int $perHalaman = 20
    ): LengthAwarePaginator {
        $today = $perTanggal ?? Carbon::today();

        // Tunggakan = lewat jatuh tempo DAN belum dibayar (`tanggal_bayar` null).
        // Perlakukan 'Dibayar Sebagian' sebagai belum lunas walau tanggal_bayar
        // sudah terisi: pokoknya masih menjadi kewajiban anggota.
        $menunggak = fn ($q) => $q
            ->where('tanggal_jatuh_tempo', '<', $today)
            ->where(fn ($sub) => $sub->whereNull('tanggal_bayar')
                ->orWhere('status_angsuran', self::STATUS_ANGSURAN_SEBAGIAN));

        // Prasyarat yang sama, dinyatakan sebagai SQL mentah untuk subkueri ORDER BY.
        $syaratMentah = '(angsuran.tanggal_bayar IS NULL OR angsuran.status_angsuran = ?)';

        $query = Pembiayaan::with([
            'anggota',
            'produk',
            'jadwalAngsuran' => $menunggak,
        ])
            ->whereHas('jadwalAngsuran', $menunggak)
            ->whereIn('status_pembiayaan', $this->statusBerjalan())
            // Urutkan dari tunggakan terlama (paling parah) terlebih dahulu.
            ->orderByRaw(
                "(SELECT MIN(tanggal_jatuh_tempo) FROM angsuran
                  WHERE angsuran.pembiayaan_id = pembiayaan.id
                    AND angsuran.tanggal_jatuh_tempo < ?
                    AND {$syaratMentah}) ASC",
                [$today, self::STATUS_ANGSURAN_SEBAGIAN]
            );

        if ($cari) {
            $query->whereHas('anggota', fn ($q) => $q->where('nama_lengkap', 'like', "%{$cari}%")
                ->orWhere('no_anggota', 'like', "%{$cari}%"));
        }

        return $query->paginate($perHalaman)->withQueryString();
    }
}
