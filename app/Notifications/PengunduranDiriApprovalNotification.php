<?php

namespace App\Notifications;

use App\Models\MemberTermination;
use App\Support\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Permintaan persetujuan pencairan dana pengunduran diri anggota.
 *
 * Dikirim ke SELURUH pengguna berperan `manajer` ketika total net pencairan
 * melampaui parameter `high_value_withdrawal_limit`. Selama persetujuan belum
 * diberikan, `ExitKeanggotaanService::eksekusi()` menolak mencairkan dana.
 *
 * Kanal: `database` saja (in-app, tabel `notifications`). Kanal `mail` sengaja
 * tidak dipakai karena server ini tidak mengirim email (`MAIL_MAILER=log`),
 * sehingga kanal tersebut hanya menjadi jalur mati.
 */
class PengunduranDiriApprovalNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{
     *     terminasi_id:int,
     *     no_referensi:string,
     *     no_terminasi:string,
     *     nama_anggota:string,
     *     no_cif:?string,
     *     total_net:float,
     *     limit:float,
     * }  $ringkasan
     */
    public function __construct(public array $ringkasan) {}

    public static function dariTerminasi(MemberTermination $terminasi): self
    {
        $anggota = $terminasi->anggota;

        return new self([
            'terminasi_id' => (int) $terminasi->id,
            'no_referensi' => (string) ($terminasi->no_referensi ?? $terminasi->no_terminasi),
            'no_terminasi' => (string) $terminasi->no_terminasi,
            'nama_anggota' => (string) ($anggota->nama_lengkap ?? '-'),
            'no_cif' => $anggota->no_cif ?? null,
            'total_net' => Setting::bulatkanUang((float) ($terminasi->total_net ?? 0)),
            'limit' => Setting::highValueWithdrawalLimit(),
        ]);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $r = $this->ringkasan;

        return [
            'tipe' => 'pengunduran_diri_approval',
            'judul' => 'Persetujuan Pencairan Pengunduran Diri Anggota',
            'pesan' => sprintf(
                'Pengajuan %s atas nama %s (net Rp %s) melampaui limit Rp %s dan membutuhkan persetujuan Manajer.',
                $r['no_referensi'],
                $r['nama_anggota'],
                number_format($r['total_net'], 2, ',', '.'),
                number_format($r['limit'], 2, ',', '.')
            ),
            'terminasi_id' => $r['terminasi_id'],
            'no_referensi' => $r['no_referensi'],
            'nama_anggota' => $r['nama_anggota'],
            'total_net' => $r['total_net'],
            'limit' => $r['limit'],
            'url' => route('member-terminations.show', $r['terminasi_id']),
        ];
    }
}
