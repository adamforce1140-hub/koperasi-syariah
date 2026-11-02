<?php

namespace App\Http\Middleware;

use App\Models\KasHarian;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * GUARD SESI KAS TELLER — memastikan transaksi tunai hanya diproses dari
 * laci (cash drawer) yang benar-benar dibuka pada tanggal bisnis berjalan.
 *
 * ── Skema Input ──────────────────────────────────────────────────────────
 *  • `$request` : Illuminate\Http\Request — permintaan HTTP yang sudah melewati
 *                 middleware `auth` (grup `web`).
 *  • `$next`    : Closure(Request): Response — penerus rantai middleware.
 *
 *  Tidak ada parameter route. Identitas teller diambil dari `$request->user()`;
 *  tanggal bisnis diambil dari `now()` yang memakai zona waktu aplikasi
 *  (`config('app.timezone')` = Asia/Jakarta).
 *
 * ── Skema Output ─────────────────────────────────────────────────────────
 *  • Sesi valid          → Response dari `$next($request)` (controller).
 *  • Belum login         → HTTP 401 "Sesi autentikasi tidak valid".
 *  • Tanpa sesi kas      → HTTP 403 "laci belum dibuka".
 *  • Sesi PENDING        → HTTP 403 "menunggu verifikasi opname".
 *  • Sesi CLOSED         → HTTP 403 "sudah ditutup".
 *  • Peran non-teller    → diteruskan (lihat "Kasus Penggunaan").
 *  Untuk permintaan JSON, Laravel otomatis mengembalikan 401/403 berformat
 *  JSON dengan kunci `message` (via `abort()`).
 *
 * ── Alur Logika ──────────────────────────────────────────────────────────
 *  1. Ambil pengguna terautentikasi. Bila kosong → 401 (pertahanan berlapis;
 *     normalnya `auth` sudah meredirect lebih dulu).
 *  2. Bila pengguna BUKAN teller → lolos. Peran pengawas (manajer/pengurus)
 *     tidak memegang laci dan tidak memiliki baris `kas_harian`; transaksi
 *     tunai yang mereka jalankan tetap diaudit oleh controller masing-masing.
 *  3. Cari baris `kas_harian` untuk (user_id, tanggal hari ini).
 *  4. Tidak ada baris → 403: transaksi tunai dilarang sebelum Buka Kas.
 *  5. Status `PENDING_APPROVAL` → 403: opname sedang diverifikasi Manajer,
 *     angka fisik bisa basi bila transaksi baru masuk.
 *  6. Status `CLOSED` → 403: sesi sudah ditutup & disetujui.
 *  7. Status `OPEN` → lanjutkan ke controller.
 *
 *  Setiap penolakan dicatat `Log::warning` (user, rute, tanggal, status) agar
 *  percobaan transaksi tanpa sesi dapat ditelusuri pada audit operasional.
 *
 * ── Kasus Penggunaan ─────────────────────────────────────────────────────
 *  • Setor tunai, tarik tunai, angsuran tunai, pembukaan/pencairan deposito,
 *    pelunasan Simpanan Wajib, ta'zir, dan pencairan pembiayaan tunai.
 *  • Mencegah transaksi "liar" setelah teller lupa Buka Kas atau setelah
 *    sesi kemarin ditutup (atribusi kas menjadi tidak valid).
 *  • Melengkapi aturan lintas hari: sesi kemarin TIDAK dianggap sah untuk
 *    transaksi hari ini (lihat `App\Services\AturanLintasHariService`).
 */
class PastikanKasTerbuka
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Sesi autentikasi tidak valid atau sudah berakhir. Silakan login kembali.');
        }

        // Pengawas tidak memegang laci kas; guard ini khusus pemegang laci.
        if (! $user->isTeller()) {
            return $next($request);
        }

        $tanggal = now()->toDateString();

        $sesi = KasHarian::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->whereDate('tanggal', $tanggal)
            ->first();

        if (! $sesi) {
            $this->catatPenolakan($request, $user->getAuthIdentifier(), $tanggal, 'TANPA_SESI');

            abort(403, "Transaksi kas ditolak: laci Teller belum dibuka untuk tanggal {$tanggal}. "
                .'Lakukan Buka Kas terlebih dahulu sebelum memproses transaksi tunai.');
        }

        if ($sesi->isPendingApproval()) {
            $this->catatPenolakan($request, $user->getAuthIdentifier(), $tanggal, KasHarian::STATUS_PENDING_APPROVAL);

            abort(403, "Transaksi kas ditolak: sesi kas tanggal {$tanggal} sedang menunggu verifikasi opname "
                .'oleh Manajer. Selesaikan atau batalkan pengajuan closing terlebih dahulu.');
        }

        if (! $sesi->isOpen()) {
            $this->catatPenolakan($request, $user->getAuthIdentifier(), $tanggal, (string) $sesi->status_opname);

            abort(403, "Transaksi kas ditolak: sesi kas tanggal {$tanggal} sudah ditutup. "
                .'Transaksi baru hanya dapat diproses setelah Buka Kas pada hari berikutnya.');
        }

        return $next($request);
    }

    /**
     * Catat percobaan transaksi yang ditolak guard.
     */
    private function catatPenolakan(Request $request, int|string $userId, string $tanggal, string $status): void
    {
        Log::warning('Guard sesi kas menolak transaksi.', [
            'user_id' => $userId,
            'tanggal' => $tanggal,
            'status' => $status,
            'rute' => $request->route()?->getName() ?? $request->path(),
            'metode' => $request->method(),
        ]);
    }
}
