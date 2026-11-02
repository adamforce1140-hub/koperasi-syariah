<?php

namespace App\Actions\Teller;

use App\Models\KasHarian;
use App\Support\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Use-case Closing Kas Teller (rekap harian).
 *
 * Membandingkan kas fisik hasil hitung teller dengan saldo sistem yang
 * dihitung dari sumber yang sama dengan halaman Opening/Rekap, lalu menyimpan
 * status Balanced/Lebih/Kurang. Validasi HTTP dilakukan di controller.
 */
class TutupKasAction
{
    public function execute(Request $request): RedirectResponse
    {
        $today = now()->toDateString();
        $tellerId = Auth::id();

        $kasHarian = KasHarian::where('user_id', $tellerId)->where('tanggal', $today)->first();

        // Saldo sistem dihitung dari sumber yang sama dengan halaman Rekap/Opening
        // supaya angka selisih closing konsisten dengan ringkasan yang dilihat teller.
        try {
            $saldoSistem = KasHarian::ringkasanPosisiKas($tellerId, $today)['saldo'];
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Closing gagal: posisi kas tidak dapat dihitung. '.$e->getMessage());
        }

        $fisikKas = (float) $request->fisik_kas;
        $selisih = Setting::bulatkanUang($fisikKas - $saldoSistem);

        // BUG-06 — Bandingkan dengan TOLERANSI, bukan `== 0`. Perbandingan
        // float eksak rapuh: sisa pembulatan sen (mis. 1e-13) membuat kas yang
        // sebenarnya seimbang dilaporkan "Lebih/Kurang". Selisih sudah dibulatkan
        // ke rupiah penuh (2 desimal); nilai di bawah setengah sen dianggap nol.
        $toleransi = Setting::toleransiUang();
        $seimbang = abs($selisih) < $toleransi;
        if ($seimbang) {
            $selisih = 0.0;
        }

        $status = $seimbang ? 'Balanced' : ($selisih > 0 ? 'Lebih' : 'Kurang');

        // Simpan closing ke DB
        if ($kasHarian) {
            $kasHarian->update([
                'fisik_kas' => $fisikKas,
                'saldo_sistem' => $saldoSistem,
                'selisih' => $selisih,
                'status_closing' => $status,
                'catatan_closing' => $request->catatan,
                'waktu_closing' => now()->format('H:i:s'),
            ]);
        }

        // Tetap simpan ke session untuk backward compat view closing
        session(['teller_closing_'.$tellerId.'_'.$today => [
            'fisik_kas' => $fisikKas,
            'saldo_sistem' => $saldoSistem,
            'selisih' => $selisih,
            'catatan' => $request->catatan,
            'waktu_closing' => now()->format('H:i:s'),
            'status' => $status,
        ]]);

        $status = $seimbang ? 'balanced' : ($selisih > 0 ? 'lebih' : 'kurang');
        $msg = "Closing berhasil. Kas {$status}";
        if (! $seimbang) {
            $msg .= ' Rp '.number_format(abs($selisih), 0, ',', '.');
        }

        return redirect()->route('teller.kas.closing')
            ->with('success', $msg.'. Laporan telah disimpan.');
    }
}
