<?php

namespace App\Observers;

use App\Models\Anggota;
use App\Models\Angsuran;
use App\Models\ApprovalTransaksi;
use App\Models\DanaKebajikan;
use App\Models\Deposito;
use App\Models\Jurnal;
use App\Models\LimitTransaksiRole;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\PengeluaranBeban;
use App\Models\Rekening;
use App\Models\ShuDistribution;
use App\Models\Transaksi;
use App\Support\DenyutData;

/**
 * DenyutObserver — juru bicara perubahan data.
 *
 * Dipasang ke seluruh model yang perubahannya memengaruhi angka pada
 * dashboard/portal. Setiap kali model disimpan atau dihapus, observer
 * menaikkan versi domain terkait pada buku versi `DenyutData`, sehingga
 * peramban lain dapat mengetahui adanya perubahan tanpa perlu memuat ulang
 * halaman secara berkala untuk MEMBANDINGKAN seluruh data.
 *
 * MENGAPA OBSERVER, BUKAN PER-CONTROLLER
 * --------------------------------------
 * Menaruh pemanggilan `DenyutData::bump()` di controller mudah terlewat saat
 * ada jalur tulis baru (command Artisan, seeder, job, tinker). Observer
 * memastikan SEMUA jalur tulis — dari mana pun — ikut menandai perubahan.
 *
 * BIAYA
 * -----
 * Satu panggilan `Cache::increment` per domain per penyimpanan — O(1) pada
 * store apa pun. Tidak ada kueri database tambahan.
 */
class DenyutObserver
{
    /**
     * Peta kelas model → domain versi yang harus dinaikkan.
     *
     * Satu model boleh memengaruhi beberapa domain (mis. transaksi mengubah
     * angka transaksi pada dashboard teller sekaligus portofolio anggota).
     *
     * @return array<class-string, list<string>>
     */
    public static function petaModel(): array
    {
        return [
            Transaksi::class => [DenyutData::TRANSAKSI],
            Rekening::class => [DenyutData::REKENING],
            ApprovalTransaksi::class => [DenyutData::APPROVAL],
            Pembiayaan::class => [DenyutData::PEMBIAYAAN],
            PengajuanPembiayaan::class => [DenyutData::PEMBIAYAAN, DenyutData::APPROVAL],
            Angsuran::class => [DenyutData::PEMBIAYAAN],
            Anggota::class => [DenyutData::ANGGOTA],
            Jurnal::class => [DenyutData::JURNAL],
            Deposito::class => [DenyutData::DEPOSITO],
            DanaKebajikan::class => [DenyutData::JURNAL],
            PengeluaranBeban::class => [DenyutData::JURNAL],
            // Distribusi SHU memengaruhi kartu penahanan (HOLD) pengurus: saat
            // status hold dilepas/dibuat, dashboard pengurus harus ikut berubah.
            ShuDistribution::class => [DenyutData::SHU],
            // Limit transaksi per role (konfigurasi kebijakan) ditampilkan pada
            // dashboard pengurus; perubahannya harus menyegarkan tabel limit.
            LimitTransaksiRole::class => [DenyutData::LIMIT],
        ];
    }

    /**
     * Daftar domain untuk satu model (dipakai pendaftaran observer).
     *
     * @param  class-string  $model
     * @return list<string>
     */
    public static function domainUntuk(string $model): array
    {
        return self::petaModel()[$model] ?? [];
    }

    /**
     * Naikkan versi untuk domain yang dipetakan ke kelas model ini.
     *
     * Mengambil nama kelas dari instance sehingga satu observer dapat dipakai
     * untuk semua model tanpa perlu kelas terpisah tiap model.
     */
    public function saved(object $model): void
    {
        $domain = self::domainUntuk(get_class($model));

        if ($domain !== []) {
            DenyutData::bump(...$domain);
        }
    }

    /**
     * Penghapusan juga merupakan perubahan data.
     */
    public function deleted(object $model): void
    {
        $this->saved($model);
    }
}
