<?php

namespace App\Services;

use App\Contracts\ApprovalGuardInterface;
use App\Support\PemisahanTugas;

/**
 * Adaptor kontrak `ApprovalGuardInterface` → penjaga statis `PemisahanTugas`.
 *
 * Menjaga aturan maker ≠ checker tetap satu sumber kebenaran (pesan & logika
 * seragam), sekaligus membuka jalur DI: modul approval dapat bergantung pada
 * kontrak dan menukar implementasi pada test tanpa menyentuh pemanggil lama.
 *
 * Lapisan aditif — perilaku identik dengan pemakaian statis sebelumnya.
 */
class PemisahanTugasGuard implements ApprovalGuardInterface
{
    public function pesan(): string
    {
        return PemisahanTugas::PESAN;
    }

    public function dilanggar(int|string|null $idPemohon, int|string|null $idPemutus): bool
    {
        return PemisahanTugas::dilanggar($idPemohon, $idPemutus);
    }

    public function dilanggarSalahSatu(array $idPemohonList, int|string|null $idPemutus): bool
    {
        return PemisahanTugas::dilanggarSalahSatu($idPemohonList, $idPemutus);
    }
}
