<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Peringatan cadangan basis data bermasalah (tidak ada / kedaluwarsa).
 *
 * Dikirim oleh `denyut:pemeriksaan` kepada pemegang izin
 * `pengurus.operasional` ketika indikator kesegaran cadangan gagal.
 *
 * MENGAPA DI DALAM APLIKASI, BUKAN HANYA LOG
 * ------------------------------------------
 * Log berkas `denyut` hanya terbaca bila seseorang membukanya. Notifikasi ini
 * masuk tabel `notifications` sehingga peringatan "data belum aman" terlihat
 * di tempat kerja sehari-hari pengurus — tanpa bergantung pada SMTP yang
 * belum tentu dikonfigurasi.
 *
 * Isi notifikasi sengaja hanya menyebut IDENTITAS berkas dan umurnya; lokasi
 * direktori tidak ditampilkan agar tidak membocorkan struktur server.
 */
class CadanganKedaluwarsaNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $rincian
     */
    public function __construct(
        public string $alasan,
        public array $rincian = []
    ) {}

    /**
     * @param  array<string, mixed>  $rincian
     */
    public static function dari(string $alasan, array $rincian = []): self
    {
        return new self($alasan, $rincian);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'tipe' => 'cadangan_kedaluwarsa',
            'judul' => 'Cadangan Basis Data Bermasalah',
            'pesan' => $this->alasan,
            'url' => route('pengurus.kebijakan.index'),
        ], $this->rincian);
    }
}
