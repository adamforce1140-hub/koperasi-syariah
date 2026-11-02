<?php

namespace App\Http\Controllers;

use App\Services\CkpnService;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Halaman CKPN — Cadangan Kerugian Penurunan Nilai piutang pembiayaan
 * (PSAK 71 instrumen keuangan, disajikan sesuai PSAK 101).
 *
 * Halaman ini menyandingkan TARGET penyisihan (berbasis kolektibilitas tiap
 * pembiayaan) dengan SALDO TERCATAT akun 1-2100, lalu menyediakan tombol untuk
 * memposting jurnal penyesuaian atas selisihnya. Seluruh persentase dibaca dari
 * parameter kebijakan (zero-hardcoding), bukan ditulis di kode.
 */
class CkpnController extends Controller
{
    /** Rincian kolektibilitas + posisi target vs tercatat. */
    public function index(Request $request, CkpnService $ckpn)
    {
        $tanggal = $request->filled('tanggal')
            ? Carbon::parse($request->tanggal)
            : Carbon::today();

        $rincian = $ckpn->rincian($tanggal);

        // Agregasi per tingkat kolektibilitas untuk tabel ringkas.
        $agregat = collect($rincian)
            ->groupBy('kolektibilitas')
            ->map(function ($baris, $kode) {
                return [
                    'kode' => $kode,
                    'jumlah' => $baris->count(),
                    'sisa_pokok' => Setting::bulatkanUang($baris->sum('sisa_pokok')),
                    'persen' => (float) ($baris->first()['persen'] ?? 0.0),
                    'ckpn' => Setting::bulatkanUang($baris->sum('ckpn')),
                ];
            })
            ->sortKeys()
            ->values();

        return view('laporan.ckpn', [
            'tanggal' => $tanggal,
            'rincian' => $rincian,
            'agregat' => $agregat,
            'targetSaldo' => $ckpn->targetSaldo($tanggal),
            'saldoTercatat' => $ckpn->saldoTercatat($tanggal),
            'selisih' => $ckpn->selisih($tanggal),
            'persen' => Setting::persenCkpn(),
            'ambang' => Setting::ambangKolektibilitas(),
            'toleransi' => Setting::toleransiSelisihJurnal(),
        ]);
    }

    /** Posting jurnal penyesuaian CKPN agar saldo tercatat = target. */
    public function posting(Request $request, CkpnService $ckpn)
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
        ]);

        $tanggal = Carbon::parse($data['tanggal']);

        try {
            $jurnal = $ckpn->catatPenyesuaian($tanggal, Auth::id());
        } catch (\Throwable $e) {
            Log::error('CkpnController: gagal memposting penyesuaian CKPN.', ['pesan' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }

        if (! $jurnal) {
            return back()->with('info', 'Selisih sudah berada dalam batas toleransi kebijakan; tidak ada penyesuaian yang dibuat.');
        }

        return back()->with('success', "Jurnal penyesuaian CKPN {$jurnal->no_jurnal} berhasil diposting.");
    }
}
