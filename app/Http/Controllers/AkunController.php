<?php

namespace App\Http\Controllers;

use App\Models\Akun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AkunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Akun::with('parent', 'children')->orderBy('kode_akun', 'asc');

        if ($request->has('klasifikasi') && $request->klasifikasi !== '') {
            $query->where('klasifikasi', $request->klasifikasi);
        }

        if ($request->has('jenis') && $request->jenis !== '') {
            $query->where('jenis_akun', $request->jenis);
        }

        if ($request->has('is_active') && $request->is_active !== '') {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('parent_only') && filter_var($request->parent_only, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('parent_id');
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_akun', 'like', "%{$search}%")
                    ->orWhere('nama_akun', 'like', "%{$search}%");
            });
        }

        $akuns = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data akun berhasil diambil',
            'data' => $akuns,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kode_akun' => 'required|string|max:20|unique:akun,kode_akun',
            'nama_akun' => 'required|string|max:150',
            'klasifikasi' => 'required|in:Aset,Kewajiban,Modal,Pendapatan,Beban',
            'jenis_akun' => 'required|in:Header,Detail',
            'parent_id' => 'nullable|exists:akun,id',
            'saldo_normal' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'keterangan' => 'nullable|string',
        ]);

        $akun = Akun::create($validated);
        $akun->load('parent', 'children');

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dibuat',
            'data' => $akun,
        ], 201);
    }

    public function show(Akun $akun): JsonResponse
    {
        $akun->load(['parent', 'children', 'rekening.anggota', 'pembiayaan.anggota', 'jurnalDetail.jurnal']);

        return response()->json([
            'success' => true,
            'message' => 'Detail akun berhasil diambil',
            'data' => $akun,
        ]);
    }

    public function update(Request $request, Akun $akun): JsonResponse
    {
        $validated = $request->validate([
            'kode_akun' => [
                'required', 'string', 'max:20',
                Rule::unique('akun', 'kode_akun')->ignore($akun->id),
            ],
            'nama_akun' => 'required|string|max:150',
            'klasifikasi' => 'required|in:Aset,Kewajiban,Modal,Pendapatan,Beban',
            'jenis_akun' => 'required|in:Header,Detail',
            'parent_id' => 'nullable|exists:akun,id',
            'saldo_normal' => 'nullable|numeric',
            'is_active' => 'nullable|boolean',
            'keterangan' => 'nullable|string',
        ]);

        $akun->update($validated);
        $akun->load('parent', 'children');

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil diperbarui',
            'data' => $akun,
        ]);
    }

    /**
     * Hapus akun master (CoA).
     *
     * Akun yang JEJAK AKUNTANSINYA masih dipakai TIDAK boleh dihapus: baris
     * `jurnal_detail.coa_id` memakai `nullOnDelete`, sehingga penghapusan akan
     * mengosongkan rujukan akun pada jurnal historis dan merusak telusur buku
     * besar (PSAK 101: catatan atas laporan keuangan harus dapat ditelusuri).
     * Penonaktifan (`is_active = false`) tetap jalurnya lewat `update`.
     */
    public function destroy(Akun $akun): JsonResponse
    {
        $rujukan = [];

        if ($akun->jurnalDetail()->exists()) {
            $rujukan[] = 'jurnal';
        }
        if ($akun->rekening()->exists()) {
            $rujukan[] = 'rekening';
        }
        // Akun induk dengan sub-akun juga tidak boleh hilang, agar hierarki
        // akun tidak meninggalkan anak tanpa induk.
        if ($akun->children()->exists()) {
            $rujukan[] = 'sub-akun';
        }

        if ($rujukan !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Akun tidak dapat dihapus karena masih dipakai oleh '.implode(', ', $rujukan)
                    .'. Nonaktifkan akun (is_active = false) sebagai gantinya.',
            ], 409);
        }

        $akun->delete();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dihapus',
        ]);
    }
}
