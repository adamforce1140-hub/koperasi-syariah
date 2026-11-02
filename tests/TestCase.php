<?php

namespace Tests;

use App\Http\Middleware\PastikanKasTerbuka;
use App\Models\KasHarian;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Session\Store;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Pulihkan guard autentikasi + simpan ulang token sesi setiap kali
     * `actingAs()` dipanggil.
     *
     * Latar belakang (temuan sesi audit Gelombang 3):
     *   Middleware `authenticateSessions()` (S-01) di `bootstrap/app.php`
     *   menolak permintaan ketika hash password pada SESSION tidak sama dengan
     *   hash password pengguna yang sedang login. `Illuminate\Foundation\Testing\
     *   Concerns\InteractsWithAuthentication::actingAs()` hanya mengubah user
     *   pada guard dan TIDAK memperbarui sesi. Akibatnya, pada test yang
     *   memanggil `actingAs()` DUA KALI dalam satu test (mis. loop RBAC
     *   `foreach (['pengurus','manajer'] ...)`) — atau setelah request HTTP
     *   lain yang sudah menulis sesi — pemanggilan kedua
     *   dianggap sebagai sesi tidak sah dan di-redirect ke `/login` (302),
     *   padahal test mengharapkan 200/403.
     *
     *   Test yang memakai `actingAs()` sekali atau memanggil
     *   `post()`/`put()`/`delete()` (yang otomatis menulis token sesi) tidak
     *   terpengaruh, sehingga regresi ini muncul secara sporadis seolah-olah
     *   masalah urutan suite.
     *
     * Perbaikan ini bersifat test-only dan tidak mengubah perilaku produksi.
     */
    public function actingAs(
        Authenticatable $user,
        $guard = null
    ) {
        $result = parent::actingAs($user, $guard);

        // Samakan isi SESSION dengan user yang baru di-`actingAs()` agar
        // `authenticateSessions()` tidak memaksa logout saat berganti user.
        // Sesi mungkin belum "started" (belum ada request HTTP sebelumnya),
        // jadi kita start dulu tanpa syarat.
        $session = $this->app['session']->driver();

        if ($session instanceof Store) {
            if (! $session->isStarted()) {
                $session->start();
            }

            $session->put($this->app['auth']->guard($guard)->getName(), $user->getAuthIdentifier());
            $session->put('password_hash_'.($guard ?: 'web'), $user->getAuthPassword());
            $session->migrate(true);
        }

        return $result;
    }

    /**
     * Sediakan sesi Buka Kas (laci teller) berstatus OPEN untuk tanggal tertentu.
     *
     * Dipakai test yang memanggil rute transaksi tunai — rute tersebut dijaga
     * middleware `kas.terbuka` (lihat {@see PastikanKasTerbuka}).
     * Baris lama pada tanggal yang sama ditimpa menjadi OPEN agar test tidak
     * bergantung pada sisa data dev.
     */
    protected function bukaSesiKas(User $user, ?string $tanggal = null, float $modalAwal = 0.0): KasHarian
    {
        $tanggal ??= now()->toDateString();

        return KasHarian::updateOrCreate(
            ['user_id' => $user->getKey(), 'tanggal' => $tanggal],
            [
                'modal_awal' => $modalAwal,
                'tambah_modal' => [],
                'waktu_opening' => now()->format('H:i:s'),
                'status_opname' => KasHarian::STATUS_OPEN,
            ]
        );
    }
}
