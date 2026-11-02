<?php

namespace App\Notifications;

use App\Models\AutodebitUsulan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi PUSAT KONFIRMASI AUTO-DEBIT.
 *
 * Dikirim ke pengguna berwenang (manajer/super_admin/admin — peran yang lolos
 * `izin:autodebit.konfirmasi`) setiap kali sebuah kanal (Simpanan Wajib,
 * Angsuran, Ta'zir) mengusulkan pemotongan dana. Notifikasi menegaskan bahwa
 * dana BELUM dipotong dan menunggu konfirmasi pengguna — bagian dari kebijakan
 * "tidak ada auto-debit tanpa konfirmasi".
 *
 * Kanal: `database` (in-app) — konsisten dengan pola ShuDitahanNotification.
 */
class AutodebitUsulanNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $ringkasan
     */
    public function __construct(public array $ringkasan) {}

    /** Factory: susun notifikasi dari usulan auto-debit. */
    public static function dari(AutodebitUsulan $usulan): self
    {
        $anggota = $usulan->anggota;

        return new self([
            'tipe' => 'AUTODEBIT_USULAN',
            'judul' => 'Usulan Auto-Debit Menunggu Konfirmasi',
            'pesan' => sprintf(
                'Usulan auto-debit %s untuk %s (%s) sebesar Rp %s menunggu konfirmasi Anda. Dana BELUM dipotong.',
                $usulan->kanal_label,
                $anggota?->nama_lengkap ?? 'Anggota',
                $anggota?->no_anggota ?? '—',
                number_format((float) $usulan->nominal, 0, ',', '.')
            ),
            'usulan_id' => $usulan->id,
            'kanal' => $usulan->kanal,
            'kanal_label' => $usulan->kanal_label,
            'anggota_id' => $usulan->anggota_id,
            'no_anggota' => $anggota?->no_anggota,
            'nama_anggota' => $anggota?->nama_lengkap,
            'periode' => $usulan->periode,
            'nominal' => (float) $usulan->nominal,
            'url' => route('autodebit.index'),
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
