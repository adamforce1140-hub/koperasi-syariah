<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifikasi;
use Throwable;

/**
 * Pengirim notifikasi internal kepada pemegang satu izin.
 *
 * Dipakai perintah pemeliharaan (`db:cadangkan`, `denyut:pemeriksaan`) untuk
 * memberi tahu pengurus saat data belum aman. Penerima ditentukan dari
 * `config/izin.php` — sumber wewenang yang sama dengan gerbang rute —
 * sehingga mustahil mengirim peringatan ke peran yang tidak berwenang
 * membuka halaman terkait.
 */
final class NotifikasiOperator
{
    /**
     * Kirim `$notifikasi` ke seluruh pengguna aktif pemegang izin `$izin`.
     *
     * `$kunciPembatas` (opsional) membatasi pengiriman maksimal sekali per jam
     * untuk kondisi yang sama — misalnya indikator cadangan yang diperiksa
     * tiap jam tetapi tidak berubah. Kegagalan pengiriman tidak pernah
     * menggagalkan proses pemanggil: pemantauan tidak boleh menjadi sumber
     * kegagalan baru.
     */
    public static function kirim(Notification $notifikasi, string $izin, ?string $kunciPembatas = null): void
    {
        try {
            if ($kunciPembatas !== null && ! Cache::add('notifikasi:'.$kunciPembatas, true, now()->addHour())) {
                return;
            }

            $peran = Izin::peran($izin);

            if ($peran === []) {
                return;
            }

            $penerima = User::query()
                ->whereIn('role', $peran)
                ->where('is_active', true)
                ->get();

            if ($penerima->isNotEmpty()) {
                Notifikasi::send($penerima, $notifikasi);
            }
        } catch (Throwable $e) {
            Log::notice('Notifikasi operator gagal dikirim.', [
                'izin' => $izin,
                'notifikasi' => $notifikasi::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
