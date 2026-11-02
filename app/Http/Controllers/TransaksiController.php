<?php

namespace App\Http\Controllers;

use App\Models\Transaksi;
use App\Services\PeriodeAkuntansiService;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransaksiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaksi::with(['anggota', 'pembiayaan.anggota', 'rekening.anggota', 'user'])
            ->orderBy('waktu_transaksi', 'desc');

        if ($request->has('rekening_id') && $request->rekening_id !== '') {
            $query->where('rekening_id', $request->rekening_id);
        }

        if ($request->has('anggota_id') && $request->anggota_id !== '') {
            $query->where('anggota_id', $request->anggota_id);
        }

        if ($request->has('pembiayaan_id') && $request->pembiayaan_id !== '') {
            $query->where('pembiayaan_id', $request->pembiayaan_id);
        }

        if ($request->has('jenis') && $request->jenis !== '') {
            $query->where('jenis_transaksi', $request->jenis);
        }

        if ($request->has('status') && $request->status !== '') {
            $query->where('status_transaksi', $request->status);
        }

        if ($request->has('tanggal_awal') && $request->has('tanggal_akhir')
            && $request->tanggal_awal !== '' && $request->tanggal_akhir !== '') {
            $query->whereBetween('waktu_transaksi', [
                $request->tanggal_awal.' 00:00:00',
                $request->tanggal_akhir.' 23:59:59',
            ]);
        }

        $perPage = $this->perPage($request);
        $transaksis = $perPage !== null
            ? $query->paginate($perPage)
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data transaksi berhasil diambil',
            'data' => $transaksis,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'no_transaksi' => 'required|string|max:30|unique:transaksi,no_transaksi',
            // TAHAP 5 — integritas periode: transaksi tidak boleh bertanggal
            // pada periode yang sudah ditutup buku (backdate).
            'waktu_transaksi' => ['required', 'date', app(PeriodeAkuntansiService::class)->rule()],
            'rekening_id' => 'nullable|exists:rekening,id',
            'anggota_id' => 'nullable|exists:anggota,id',
            'pembiayaan_id' => 'nullable|exists:pembiayaan,id',
            'jenis_transaksi' => 'required|in:Setoran Tunai,Penarikan Tunai,Transfer Masuk,Transfer Keluar,Pembayaran Angsuran,Pencairan Pembiayaan,Simpanan Pokok,Simpanan Wajib,Simpanan Sukarela,Penarikan Simpanan,Bagi Hasil,Biaya Administrasi,Denda,Lainnya',
            'posisi' => 'required|in:Debet,Kredit',
            // Nominal transaksi wajib > 0: baris transaksi bernilai nol tidak
            // punya makna akuntansi dan hanya menambah derau di buku besar.
            'jumlah' => 'required|numeric|min:0.01',
            'saldo_setelah' => 'nullable|numeric|min:0',
            'user_id' => 'nullable|exists:users,id',
            'sumber_transaksi' => 'nullable|in:Teller,Mobile,Internet,Transfer,Auto',
            'status_transaksi' => 'nullable|in:Pending,Sukses,Gagal,Dibatalkan,Pending Void,Voided',
            'referensi' => 'nullable|string|max:100',
            'catatan' => 'nullable|string',
        ]);

        $validated = $this->bulatkanNominal($validated);

        // Status tidak boleh menggantung pada default kolom. Bila pemanggil
        // tidak menyebutkan status, transaksi dianggap BELUM selesai (Pending)
        // dan status teknisnya diturunkan — konsisten dengan
        // `Transaksi::booted()`. Ini mencegah jurnal terposting untuk transaksi
        // yang sebenarnya baru dicatat sebagai draf.
        $validated['status_transaksi'] ??= Transaksi::STATUS_TRANSAKSI_PENDING;

        $transaksi = Transaksi::create($validated);
        $transaksi->load('rekening.anggota', 'anggota', 'pembiayaan', 'user');

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil dicatat',
            'data' => $transaksi,
        ], 201);
    }

    /**
     * TAHAP 5 — Samakan presisi nominal dengan kebijakan pembulatan koperasi
     * agar nilai tersimpan konsisten dengan nilai yang diposting ke jurnal
     * (mencegah selisih sen antara buku besar dan data transaksi).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function bulatkanNominal(array $validated): array
    {
        if (isset($validated['jumlah'])) {
            $validated['jumlah'] = Setting::pembulatanNominal((float) $validated['jumlah']);

            // Kebijakan pembulatan bisa memakai 0 desimal (satuan rupiah penuh),
            // sehingga nominal kecil (mis. 0,40) akan menjadi 0 setelah dibulatkan.
            // Tolak di sini agar tidak ada baris transaksi bernilai nol.
            if ($validated['jumlah'] <= 0) {
                throw ValidationException::withMessages([
                    'jumlah' => 'Nominal transaksi menjadi 0 setelah pembulatan kebijakan. Gunakan nominal yang lebih besar.',
                ]);
            }
        }

        if (isset($validated['saldo_setelah'])) {
            $validated['saldo_setelah'] = Setting::pembulatanNominal((float) $validated['saldo_setelah']);
        }

        return $validated;
    }

    public function show(Transaksi $transaksi): JsonResponse
    {
        $transaksi->load('rekening.anggota', 'anggota', 'pembiayaan', 'user', 'jurnal.detail.akun', 'angsuran');

        return response()->json([
            'success' => true,
            'message' => 'Detail transaksi berhasil diambil',
            'data' => $transaksi,
        ]);
    }

    public function update(Request $request, Transaksi $transaksi): JsonResponse
    {
        $validated = $request->validate([
            'no_transaksi' => 'required|string|max:30|unique:transaksi,no_transaksi,'.$transaksi->id,
            'waktu_transaksi' => ['required', 'date', app(PeriodeAkuntansiService::class)->rule()],
            'rekening_id' => 'nullable|exists:rekening,id',
            'anggota_id' => 'nullable|exists:anggota,id',
            'pembiayaan_id' => 'nullable|exists:pembiayaan,id',
            'jenis_transaksi' => 'required|in:Setoran Tunai,Penarikan Tunai,Transfer Masuk,Transfer Keluar,Pembayaran Angsuran,Pencairan Pembiayaan,Simpanan Pokok,Simpanan Wajib,Simpanan Sukarela,Penarikan Simpanan,Bagi Hasil,Biaya Administrasi,Denda,Lainnya',
            'posisi' => 'required|in:Debet,Kredit',
            'jumlah' => 'required|numeric|min:0.01',
            'saldo_setelah' => 'nullable|numeric|min:0',
            'user_id' => 'nullable|exists:users,id',
            'sumber_transaksi' => 'nullable|in:Teller,Mobile,Internet,Transfer,Auto',
            // K-03 — `status_transaksi` SENGAJA tidak divalidasi pada jalur
            // UPDATE. Status transaksi adalah penanda audit (PSAK 101): ia hanya
            // boleh berubah lewat alur terkendali (approval/void service), bukan
            // lewat `PUT /api/transaksi/{id}` yang dapat memalsukan riwayat.
            'referensi' => 'nullable|string|max:100',
            'catatan' => 'nullable|string',
        ]);

        $this->pastikanTransaksiDapatDimutasi($transaksi, 'diubah');

        // Sabuk pengaman tambahan: buang kunci status bila disisipkan kemudian.
        unset($validated['status_transaksi'], $validated['status'], $validated['approved_by']);

        $validated = $this->bulatkanNominal($validated);

        $transaksi->update($validated);
        $transaksi->load('rekening.anggota', 'anggota', 'pembiayaan', 'user');

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil diperbarui',
            'data' => $transaksi,
        ]);
    }

    public function destroy(Transaksi $transaksi): JsonResponse
    {
        $this->pastikanTransaksiDapatDimutasi($transaksi, 'dihapus');

        $transaksi->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil dihapus',
        ]);
    }

    /**
     * PSAK 101 — transaksi yang sudah diposting ke jurnal bersifat IMMUTABLE.
     *
     * CRUD generik (`PUT`/`DELETE /api/transaksi/{id}`) bukan jalur pembatalan.
     * Bila transaksi yang sudah terjurnal boleh diubah nominal/rekeningnya atau
     * dihapus, jurnal tetap terposting sementara buktinya hilang: buku besar
     * menjadi tidak seimbang dan jejak audit lenyap tanpa otorisasi.
     *
     * Jalur yang benar adalah `AjukanVoidAction`: status menjadi "Pending Void",
     * persetujuan manajer diajukan, lalu jurnal dibalik — bukan dihapus.
     * Transaksi yang masih draf (belum terjurnal) tetap bebas dikoreksi.
     */
    private function pastikanTransaksiDapatDimutasi(Transaksi $transaksi, string $aksi): void
    {
        abort_if(
            $transaksi->jurnal()->where('status', 'Posted')->exists(),
            422,
            "Transaksi ini sudah terjurnal (Posted) dan tidak dapat {$aksi}. Gunakan alur void melalui approval."
        );

        abort_if(
            in_array($transaksi->status_transaksi, [
                Transaksi::STATUS_TRANSAKSI_PENDING_VOID,
                Transaksi::STATUS_TRANSAKSI_VOIDED,
            ], true),
            422,
            "Transaksi berstatus {$transaksi->status_transaksi} tidak dapat {$aksi}."
        );
    }
}
