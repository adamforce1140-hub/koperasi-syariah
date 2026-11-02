<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Anggota;
use App\Support\PiiHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API master data anggota (JSON).
 *
 * Sebelumnya `routes/api.php` menunjuk `AnggotaController` (controller web)
 * yang hanya memiliki `index()` dan mengembalikan view Blade, sehingga
 * `GET /api/anggota` membalas HTML dan `show/store/update/destroy`
 * menghasilkan HTTP 500 (method tidak ada).
 *
 * Endpoint di sini SENGAJA hanya baca. Pembuatan/penghapusan anggota harus
 * lewat alur resmi (CIF, setoran awal, exit keanggotaan) agar nomor anggota,
 * NIK terenkripsi + blind index, dan jurnal simpanan tetap konsisten.
 */
class AnggotaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Anggota::query()->latest();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            // NIK tersimpan sebagai ciphertext sehingga tidak bisa dicari dengan
            // LIKE. Pencarian NIK memakai blind index `nik_hash` (cocok tepat)
            // — lihat App\Support\PiiHasher (kepatuhan UU PDP No. 27/2022).
            $hashNik = PiiHasher::hash($search);

            $query->where(function ($q) use ($search, $hashNik) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%");

                if ($hashNik !== null) {
                    $q->orWhere('nik_hash', $hashNik);
                }
            });
        }

        $status = Anggota::normalizeStatus($request->input('status'));
        if ($status !== null) {
            $query->status($status);
        }

        $perPage = (int) $request->input('per_page', 25);
        $perPage = $perPage > 0 ? min($perPage, 100) : 25;

        return response()->json([
            'success' => true,
            'message' => 'Data anggota berhasil diambil',
            'data' => $query->paginate($perPage)->withQueryString(),
        ]);
    }

    public function show(Anggota $anggota): JsonResponse
    {
        $anggota->load(['rekening', 'user']);

        return response()->json([
            'success' => true,
            'message' => 'Detail anggota berhasil diambil',
            'data' => $anggota,
        ]);
    }
}
