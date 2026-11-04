<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Peringatan kegagalan pembuatan cadangan basis data.
 *
 * Dikirim `db:cadangkan` kepada pemegang izin `pengurus.operasional` ketika
 * mysqldump, enkripsi, penulisan berkas, atau penyalinan offsite gagal.
 *
 * Setiap kegagalan dikirim (tidak dibatasi per jam) karena kegagalan
 * pencadangan biasanya berbeda-beda penyebabnya dan justru yang kedua sering
 * menjadi penyebab kegagalan yang pertama (mis. disk penuh).
 */
class CadanganGagalNotification extends Notification
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
            'tipe' => 'cadangan_gagal',
            'judul' => 'Cadangan Basis Data GAGAL',
            'pesan' => 'Cadangan basis data gagal dibuat — data terbaru belum aman: '.$this->alasan,
            'url' => route('pengurus.kebijakan.index'),
        ], $this->rincian);
    }
}
