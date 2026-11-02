<?php

namespace App\Support;

use App\Models\Pembiayaan;

/**
 * State machine status pembiayaan — pintu masuk tunggal.
 *
 * Membaca peta transisi dari `config/alur_pembiayaan.php` (`transisi`) dan
 * menyajikannya sebagai pertanyaan yang dapat dipakai controller/service:
 * "dari status X boleh menuju status mana saja?".
 *
 * Sebelumnya daur hidup status pembiayaan hidup sebagai rangkaian guard
 * prosedural yang tersebar di banyak controller/service, sehingga sulit
 * ditelusuri dan diubah. Kelas ini menjadikan daur hidup itu DATA.
 *
 * SIFAT: DESKRIPTIF. Kelas ini tidak menolak transisi apa pun atas inisiatif
 * sendiri — ia hanya menjawab. Pemanggil yang ingin menegakkan aturan memakai
 * {@see bolehTransisi()} sebagai penjaga. Dengan begitu, menambahkan kelas ini
 * TIDAK mengubah perilaku sistem yang sudah berjalan.
 */
class AlurStatusPembiayaan
{
    /**
     * Seluruh peta transisi status (dari → daftar status tujuan).
     *
     * @return array<string, array<int, string>>
     */
    public static function transisi(): array
    {
        return (array) config('alur_pembiayaan.transisi', []);
    }

    /**
     * Daftar status tujuan yang sah dari sebuah status (case-insensitive).
     *
     * @return array<int, string>
     */
    public static function transisiSah(string $dari): array
    {
        $dari = (string) Pembiayaan::normalizeStatus($dari);

        return array_values((array) (static::transisi()[$dari] ?? []));
    }

    /**
     * Bolehkah pembiayaan berpindah dari `$dari` ke `$ke`?
     *
     * Transisi ke status yang SAMA selalu dianggap sah (penulisan ulang tanpa
     * perpindahan tahap — mis. menyegarkan tanggal review pada status yang
     * sama, perilaku yang memang dipakai sistem saat ini).
     */
    public static function bolehTransisi(string $dari, string $ke): bool
    {
        $dari = (string) Pembiayaan::normalizeStatus($dari);
        $ke = (string) Pembiayaan::normalizeStatus($ke);

        if ($dari === $ke) {
            return true;
        }

        return in_array($ke, static::transisiSah($dari), true);
    }

    /**
     * Status terminal — status tanpa transisi keluar (lunas / ditolak /
     * dibatalkan / hapus_buku).
     *
     * @return array<int, string>
     */
    public static function statusAkhir(): array
    {
        return array_keys(array_filter(
            static::transisi(),
            fn ($tujuan) => empty($tujuan)
        ));
    }
}
