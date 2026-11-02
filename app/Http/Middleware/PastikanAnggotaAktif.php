<?php

namespace App\Http\Middleware;

use App\Models\Anggota;
use App\Models\Rekening;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memblokir transaksi keuangan bagi anggota yang statusnya belum ACTIVE.
 *
 * Status PENDING (menunggu setoran awal), SUSPENDED (dibekukan), INACTIVE
 * (tidak aktif), dan Keluar (CLOSED) tidak boleh melakukan transaksi lanjutan
 * seperti pembukaan rekening baru, peminjaman, atau penarikan Simpanan
 * Sukarela. Anggota PENDING baru memperoleh akses setelah Teller memvalidasi
 * setoran awal (lihat CifController::store()).
 *
 * Anggota diresolusi dari, berurutan:
 *  1. parameter route eksplisit — middleware('anggota.aktif:id');
 *  2. route model binding `{anggota}`;
 *  3. input form `anggota_id` (mis. transaksi Teller);
 *  4. input form `rekening_id` → pemilik rekening.
 *
 * Bila tidak ada anggota yang dapat diresolusi, permintaan diteruskan agar
 * controller yang menangani galat 404 seperti biasa.
 */
class PastikanAnggotaAktif
{
    public function handle(Request $request, Closure $next, ?string $parameter = null): Response
    {
        $anggota = $this->resolusiAnggota($request, $parameter);

        if ($anggota && ! $anggota->isActive()) {
            abort(403, 'Transaksi tidak dapat diproses. Status keanggotaan saat ini '
                .$anggota->status_label.'. Hanya anggota berstatus '
                .Anggota::STATUS_LABEL[Anggota::STATUS_ACTIVE]
                .' yang dapat melakukan transaksi — status PENDING berubah otomatis '
                .'setelah setoran awal tervalidasi Teller.');
        }

        return $next($request);
    }

    private function resolusiAnggota(Request $request, ?string $parameter): ?Anggota
    {
        // 1. Parameter route eksplisit.
        if ($parameter) {
            return $this->dariNilai($request->route($parameter));
        }

        // 2. Route model binding `{anggota}`.
        if ($anggota = $this->dariNilai($request->route('anggota'))) {
            return $anggota;
        }

        // 3. Input form transaksi Teller.
        if ($anggota = $this->dariNilai($request->input('anggota_id'))) {
            return $anggota;
        }

        // 4. Pemilik rekening pada transaksi berbasis rekening.
        $rekeningId = $request->input('rekening_id');

        if ($rekeningId) {
            return Rekening::find($rekeningId)?->anggota;
        }

        return null;
    }

    private function dariNilai(mixed $nilai): ?Anggota
    {
        if ($nilai instanceof Anggota) {
            return $nilai;
        }

        return $nilai ? Anggota::find($nilai) : null;
    }
}
