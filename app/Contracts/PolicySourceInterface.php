<?php

namespace App\Contracts;

/**
 * KONTRAK — SUMBER KEBIJAKAN BISNIS (zero-hardcoding).
 *
 * Seluruh modul yang membutuhkan parameter kebijakan koperasi (nominal, limit,
 * persentase, toleransi, daftar alokasi, dll.) sebisa mungkin bergantung pada
 * kontrak ini, bukan langsung ke implementasi konkret `App\Support\Setting`.
 *
 * Tujuan:
 *   1. Modul tidak lagi terikat pada satu penyimpanan kebijakan (DB/cache).
 *      Implementasi lain (mis. kebijakan dari API pusat, file, atau mock pada
 *      test) dapat ditukar lewat binding container tanpa menyentuh pemanggil.
 *   2. Menjaga satu sumber kebenaran: nilai kebijakan tetap dibaca lewat satu
 *      antarmuka, sehingga jejak audit & invalidasi cache tetap terpusat.
 *
 * Implementasi bawaan: `App\Support\Setting`.
 *
 * CATATAN: antarmuka ini sengaja tipis (hanya operasi baca generik). Accessor
 * bertipe khusus (mis. `minimalPengajuanPembiayaan()`) tetap dipakai langsung
 * lewat facade statis `Setting` untuk menjaga kompatibilitas seluruh kode yang
 * sudah ada; kontrak ini menjadi jaring longgar untuk kode baru & pengujian.
 */
interface PolicySourceInterface
{
    /**
     * Baca satu nilai kebijakan bertipe apa pun.
     *
     * @param  string  $key  Kunci kebijakan (mis. 'minimal_pengajuan_pembiayaan')
     * @param  mixed  $default  Nilai cadangan bila kunci tidak tersedia / kosong
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Seluruh nilai kebijakan yang berlaku sebagai peta key => value.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Baca nilai kebijakan sebagai nominal uang (float).
     */
    public function nominal(string $key, float $default = 0.0): float;

    /**
     * Baca nilai kebijakan sebagai boolean.
     */
    public function bool(string $key, bool $default = false): bool;

    /**
     * Baca nilai kebijakan sebagai persentase (float).
     */
    public function persen(string $key, float $default = 0.0): float;

    /**
     * Toleransi selisih jurnal (batas pembulatan yang dianggap nol).
     */
    public function toleransiSelisihJurnal(): float;
}
