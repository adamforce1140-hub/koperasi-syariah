<?php

namespace App\Notifications;

use App\Models\Anggota;
use App\Models\MandatorySavingsBill;
use App\Support\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Pemberitahuan penahanan (HOLD) SHU anggota.
 *
 * Dikirim ke Pengurus ketika pada penutupan tahun buku anggota masih memiliki
 * tagihan Simpanan Wajib berstatus OVERDUE dan kebijakan
 * `shu_hold_on_overdue` aktif. Pencairan SHU ditahan sampai tunggakan lunas.
 *
 * Disimpan pada tabel `notifications` (channel database) sehingga dapat
 * ditelusuri kembali dari dashboard Pengurus / halaman SHU.
 */
class ShuDitahanNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{
     *     anggota_id:int,
     *     nama_anggota:string,
     *     no_anggota:?string,
     *     periode:?string,
     *     total_shu:float,
     *     jumlah_tunggakan:int,
     *     total_tunggakan:float,
     *     periode_awal:?string,
     *     periode_akhir:?string,
     *     hold_reason:?string,
     * }  $ringkasan
     */
    public function __construct(public array $ringkasan) {}

    /**
     * Bangun payload notifikasi langsung dari data anggota + status penahanan.
     *
     * @param  array{is_hold:bool, hold_reason:?string}  $status
     * @param  Collection<int, MandatorySavingsBill>  $tunggakan
     */
    public static function dariAnggota(
        Anggota $anggota,
        array $status,
        $tunggakan,
        float $totalShu = 0.0,
        ?string $periode = null
    ): self {
        return new self([
            'anggota_id' => (int) $anggota->id,
            'nama_anggota' => (string) $anggota->nama_lengkap,
            'no_anggota' => $anggota->no_anggota ?? $anggota->no_cif ?? null,
            'periode' => $periode,
            'total_shu' => Setting::bulatkanUang($totalShu),
            'jumlah_tunggakan' => (int) $tunggakan->count(),
            'total_tunggakan' => Setting::bulatkanUang((float) $tunggakan->sum('amount')),
            'periode_awal' => $tunggakan->first()->bill_period ?? null,
            'periode_akhir' => $tunggakan->last()->bill_period ?? null,
            'hold_reason' => $status['hold_reason'] ?? null,
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
            'tipe' => 'shu_hold',
            'judul' => 'SHU Ditahan — Tunggakan Simpanan Wajib',
            'pesan' => $r['hold_reason']
                ?? sprintf('SHU anggota %s ditahan karena tunggakan Simpanan Wajib.', $r['nama_anggota']),
            'anggota_id' => $r['anggota_id'],
            'nama_anggota' => $r['nama_anggota'],
            'no_anggota' => $r['no_anggota'],
            'periode' => $r['periode'],
            'total_shu' => $r['total_shu'],
            'jumlah_tunggakan' => $r['jumlah_tunggakan'],
            'total_tunggakan' => $r['total_tunggakan'],
            'periode_awal' => $r['periode_awal'],
            'periode_akhir' => $r['periode_akhir'],
            'url' => route('pengurus.shu.index'),
        ];
    }
}
