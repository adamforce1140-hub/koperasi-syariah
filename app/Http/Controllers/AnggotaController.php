<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Support\PiiHasher;
use Illuminate\Http\Request;

class AnggotaController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        // Nilai kanonik (Anggota::STATUS_LIST) — alias legacy tetap diterima
        // agar tautan filter lama tidak berakhir kosong.
        $statusFilter = Anggota::normalizeStatus($request->input('status'));

        $query = Anggota::latest();

        if ($search) {
            // NIK tersimpan sebagai ciphertext sehingga tidak bisa dicari dengan LIKE.
            // Pencarian NIK memakai blind index `nik_hash` (kecocokan tepat) —
            // lihat App\Support\PiiHasher (kepatuhan UU PDP No. 27/2022).
            $hashNik = PiiHasher::hash($search);

            $query->where(function ($q) use ($search, $hashNik) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%");

                if ($hashNik !== null) {
                    $q->orWhere('nik_hash', $hashNik);
                }
            });
        }

        if ($statusFilter) {
            $query->status($statusFilter);
        }

        $anggota = $query->paginate(25)->withQueryString();
        $totalAnggota = Anggota::count();
        $totalAktif = Anggota::aktif()->count();

        return view('pengurus.anggota', compact(
            'anggota', 'totalAnggota', 'totalAktif', 'search', 'statusFilter'
        ));
    }
}
