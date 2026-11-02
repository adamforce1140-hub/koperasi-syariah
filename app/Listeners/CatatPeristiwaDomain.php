<?php

namespace App\Listeners;

use App\Events\AngsuranLunas;
use App\Events\DepositoJatuhTempo;
use App\Events\PembiayaanDicairkan;
use App\Events\ShuDidistribusikan;
use Illuminate\Support\Facades\Log;

/**
 * Listener audit domain — mencatat peristiwa bisnis penting ke log aplikasi.
 *
 * IDEMPOTEN & AMAN: tidak menulis ke basis data, tidak mengirim notifikasi,
 * dan SELALU menelan exception. Tujuannya adalah menyediakan titik ekstensi
 * audit yang tidak pernah mengganggu alur transaksi utama (fail-safe).
 *
 * Peristiwa yang ditangani: pencairan pembiayaan, angsuran lunas, distribusi
 * SHU, dan deposito jatuh tempo.
 */
class CatatPeristiwaDomain
{
    /** Pencairan pembiayaan selesai. */
    public function handlePembiayaanDicairkan(PembiayaanDicairkan $event): void
    {
        $this->catat('pembiayaan.dicairkan', [
            'pembiayaan_id' => $event->pembiayaan->id,
            'no_pembiayaan' => $event->pembiayaan->no_pembiayaan,
            'metode' => $event->metode,
            'nominal' => $event->nominal,
            'user_id' => $event->userId,
        ]);
    }

    /** Angsuran dinyatakan lunas. */
    public function handleAngsuranLunas(AngsuranLunas $event): void
    {
        $this->catat('angsuran.lunas', [
            'angsuran_id' => $event->angsuran->id,
            'pembiayaan_id' => $event->angsuran->pembiayaan_id,
            'angsuran_ke' => $event->angsuran->angsuran_ke,
            'nominal_bayar' => $event->nominalBayar,
            'user_id' => $event->userId,
        ]);
    }

    /** SHU periode didistribusikan. */
    public function handleShuDidistribusikan(ShuDidistribusikan $event): void
    {
        $this->catat('shu.didistribusikan', [
            'periode_id' => $event->periode?->id,
            'total_shu' => $event->totalShu,
            'user_id' => $event->userId,
        ]);
    }

    /** Deposito mencapai jatuh tempo. */
    public function handleDepositoJatuhTempo(DepositoJatuhTempo $event): void
    {
        $this->catat('deposito.jatuh_tempo', [
            'deposito_id' => $event->deposito->id,
            'no_rekening' => $event->deposito->no_rekening ?? null,
            'user_id' => $event->userId,
        ]);
    }

    /**
     * Tulis catatan audit ke log. Kegagalan apa pun ditelan agar listener
     * tidak pernah menggagalkan alur bisnis pemanggil.
     */
    private function catat(string $kanal, array $konteks): void
    {
        try {
            Log::channel(config('logging.default'))->info('Peristiwa domain: '.$kanal, $konteks);
        } catch (\Throwable $e) {
            // Sengaja diabaikan — audit tidak boleh mengganggu transaksi.
        }
    }
}
