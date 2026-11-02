<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Peristiwa: baris kebijakan koperasi (`kebijakan_koperasis`) selesai disimpan.
 *
 * Dikirim dari model `KebijakanKoperasi` pada event `saved`.
 *
 * SIFAT: HANYA INFORMASI (fire-and-forget). Tidak ada listener yang boleh
 * melempar exception ke pemanggil, dan tidak ada perubahan perilaku uang/jurnal
 * yang digantungkan pada peristiwa ini. Pengiriman event dibungkus try/catch di
 * sisi pengirim agar kegagalan listener tidak pernah menggagalkan penyimpanan
 * kebijakan.
 */
class KebijakanBerubah
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $kolomBerubah  Nama kolom yang berubah (audit).
     * @param  int|null  $userId  Pengguna yang menyimpan (bila ada).
     */
    public function __construct(
        public readonly array $kolomBerubah = [],
        public readonly ?int $userId = null,
    ) {}
}
