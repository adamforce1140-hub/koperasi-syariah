<?php

namespace App\Http\Controllers;

use App\Models\Pembiayaan;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PembiayaanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Pembiayaan::with('anggota', 'akun', 'rekening', 'approvedBy')
            ->orderBy('created_at', 'desc');

        if ($request->has('anggota_id') && $request->anggota_id !== '') {
            $query->where('anggota_id', $request->anggota_id);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->statusIn([$request->status]);
        }

        if ($request->has('akad') && $request->akad !== '') {
            $query->where('akad_pembiayaan', $request->akad);
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('no_pembiayaan', 'like', "%{$search}%")
                    ->orWhereHas('anggota', function ($qa) use ($search) {
                        $qa->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('no_anggota', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $this->perPage($request);
        $pembiayaans = $perPage !== null
            ? $query->paginate($perPage)
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data pembiayaan berhasil diambil',
            'data' => $pembiayaans,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'no_pembiayaan' => 'required|string|max:30|unique:pembiayaan,no_pembiayaan',
            'anggota_id' => 'required|exists:anggota,id',
            'akun_id' => 'required|exists:akun,id',
            'akad_pembiayaan' => Pembiayaan::aturanValidasiAkad(),
            'tujuan_pembiayaan' => 'required|in:Modal Usaha,Konsumsi,Investasi,Pendidikan,Kesehatan,Perumahan,Kendaraan,Lainnya',
            'jumlah_pembiayaan' => 'required|numeric|min:0',
            'margin_nisbah' => 'nullable|numeric|min:0|max:100',
            'total_ujroh' => 'nullable|numeric|min:0',
            'total_pembiayaan' => 'required|numeric|min:0',
            'tenor' => 'required|integer|min:1',
            'frekuensi_angsuran' => 'required|in:Mingguan,Bulanan,Triwulan,Semesteran,Tahunan',
            'jumlah_angsuran' => 'required|numeric|min:0',
            'nisbah_pengelola' => 'nullable|numeric|min:0|max:100',
            'nisbah_pemilik' => 'nullable|numeric|min:0|max:100',
            'tanggal_akad' => 'required|date',
            'tanggal_cair' => 'nullable|date',
            'tanggal_jatuh_tempo' => 'required|date',
            'rekening_id' => 'nullable|exists:rekening,id',
            'status_pembiayaan' => ['nullable', Rule::in(Pembiayaan::canonicalStatuses())],
            'sisa_pembiayaan' => 'required|numeric|min:0',
            'agunan' => 'nullable|string',
            'user_approved' => 'nullable|exists:users,id',
            'keterangan' => 'nullable|string',
        ]);

        $this->pastikanNisbahUtuh($validated);

        $pembiayaan = Pembiayaan::create($validated);
        $pembiayaan->load('anggota', 'akun', 'rekening', 'approvedBy');

        return response()->json([
            'success' => true,
            'message' => 'Pembiayaan berhasil diajukan',
            'data' => $pembiayaan,
        ], 201);
    }

    public function show(Pembiayaan $pembiayaan): JsonResponse
    {
        $pembiayaan->load([
            'anggota',
            'akun',
            'rekening',
            'approvedBy',
            'angsuran' => function ($q) {
                $q->orderBy('angsuran_ke');
            },
            'transaksi' => function ($q) {
                $q->orderBy('waktu_transaksi', 'desc');
            },
            'jurnal',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Detail pembiayaan berhasil diambil',
            'data' => $pembiayaan,
        ]);
    }

    public function update(Request $request, Pembiayaan $pembiayaan): JsonResponse
    {
        $validated = $request->validate([
            'no_pembiayaan' => 'required|string|max:30|unique:pembiayaan,no_pembiayaan,'.$pembiayaan->id,
            'anggota_id' => 'required|exists:anggota,id',
            'akun_id' => 'required|exists:akun,id',
            'akad_pembiayaan' => Pembiayaan::aturanValidasiAkad(),
            'tujuan_pembiayaan' => 'required|in:Modal Usaha,Konsumsi,Investasi,Pendidikan,Kesehatan,Perumahan,Kendaraan,Lainnya',
            'jumlah_pembiayaan' => 'required|numeric|min:0',
            'margin_nisbah' => 'nullable|numeric|min:0|max:100',
            'total_ujroh' => 'nullable|numeric|min:0',
            // T-1 (TINGGI) — Field TURUNAN & mesin-status SENGAJA tidak
            // divalidasi pada jalur UPDATE (pola sama dengan `saldo` pada
            // RekeningController, K-03):
            //   - sisa_pembiayaan  : turunan dari angsuran yang telah dibayar
            //   - total_pembiayaan : = pokok + total ujroh (turunan)
            //   - jumlah_angsuran  : = total_pembiayaan / tenor (turunan)
            //   - status_pembiayaan: mengikuti alur approval, bukan input bebas
            // Menulisnya langsung lewat PUT/PATCH membuat buku besar (piutang,
            // margin, kas) tidak lagi mencerminkan kontrak.
            'tenor' => 'required|integer|min:1',
            'frekuensi_angsuran' => 'required|in:Mingguan,Bulanan,Triwulan,Semesteran,Tahunan',
            'nisbah_pengelola' => 'nullable|numeric|min:0|max:100',
            'nisbah_pemilik' => 'nullable|numeric|min:0|max:100',
            'tanggal_akad' => 'required|date',
            'tanggal_cair' => 'nullable|date',
            'tanggal_jatuh_tempo' => 'required|date',
            'rekening_id' => 'nullable|exists:rekening,id',
            'agunan' => 'nullable|string',
            'user_approved' => 'nullable|exists:users,id',
            'keterangan' => 'nullable|string',
        ]);

        $this->pastikanNisbahUtuh($validated);

        // Sabuk pengaman tambahan: buang kunci turunan/status bila ada pihak
        // lain menambahkannya ke `$validated` di kemudian hari.
        unset(
            $validated['sisa_pembiayaan'],
            $validated['status_pembiayaan'],
            $validated['total_pembiayaan'],
            $validated['jumlah_angsuran'],
        );

        $pembiayaan->update($validated);
        $pembiayaan->load('anggota', 'akun', 'rekening', 'approvedBy');

        return response()->json([
            'success' => true,
            'message' => 'Pembiayaan berhasil diperbarui',
            'data' => $pembiayaan,
        ]);
    }

    public function destroy(Pembiayaan $pembiayaan): JsonResponse
    {
        $pembiayaan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pembiayaan berhasil dihapus',
        ]);
    }

    /**
     * B6 — nisbah pengelola + pemilik wajib berjumlah tepat 100%.
     *
     * Hanya diperiksa bila KEDUA nilai dikirim, sehingga klien yang mengirim
     * sebagian (patch parsial) tetap dapat menyimpan. Aturan ini sama dengan
     * `MasterProdukPembiayaan::nisbahValid()` agar kontrak tidak lahir dengan
     * porsi bagi hasil yang tidak utuh.
     *
     * @param  array<string, mixed>  $data
     */
    private function pastikanNisbahUtuh(array $data): void
    {
        $pengelola = $data['nisbah_pengelola'] ?? null;
        $pemilik = $data['nisbah_pemilik'] ?? null;

        if ($pengelola === null || $pemilik === null) {
            return;
        }

        if (abs((float) $pengelola + (float) $pemilik - 100.0) >= Setting::toleransiPersen()) {
            throw ValidationException::withMessages([
                'nisbah_pemilik' => 'Nisbah pengelola dan nisbah pemilik harus berjumlah tepat 100%.',
            ]);
        }
    }
}
