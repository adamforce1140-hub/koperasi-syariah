<?php

namespace App\Notifications;

use App\Models\MandatorySavingsBill;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi ADMINISTRATOR: setoran Simpanan Wajib otomatis DITANGGUHKAN.
 *
 * Dikirim ketika verifikasi status pembayaran gagal sehingga setoran otomatis
 * TIDAK dijalankan (dana anggota tidak dipotong) dan tagihan menunggu
 * KONFIRMASI MANUAL petugas. Notifikasi ini adalah bagian dari mekanisme
 * mitigasi risiko kerugian akibat anggota yang tidak membayar.
 *
 * Kanal: `database` (in-app) — konsisten dengan pola ShuDitahanNotification.
 */
class SimpananWajibDitangguhkanNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $ringkasan
     */
    public function __construct(public array $ringkasan) {}

    /**
     * Factory: susun notifikasi dari tagihan yang ditangguhkan.
     */
    public static function dariTagihan(MandatorySavingsBill $bill, string $alasan, ?string $catatan = null): self
    {
        $anggota = $bill->member;

        return new self([
            'tipe' => 'SIMPANAN_WAJIB_DITANGGUHKAN',
            'judul' => 'Setoran Simpanan Wajib Ditangguhkan',
            'pesan' => sprintf(
                'Tagihan Simpanan Wajib periode %s milik %s (%s) DITANGGUHKAN — menunggu konfirmasi manual. Alasan: %s. Dana TIDAK dipotong otomatis.',
                $bill->nama_periode,
                $anggota?->nama_lengkap ?? 'Anggota',
                $anggota?->no_anggota ?? '—',
                $alasan
            ),
            'bill_id' => $bill->id,
            'member_id' => $bill->member_id,
            'no_anggota' => $anggota?->no_anggota,
            'nama_anggota' => $anggota?->nama_lengkap,
            'periode' => $bill->bill_period,
            'nominal' => (float) $bill->amount,
            'alasan' => $alasan,
            'catatan' => $catatan,
            'url' => route('teller.simpanan-wajib.index', ['status' => MandatorySavingsBill::STATUS_PENDING_CONFIRMATION]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->ringkasan;
    }
}
