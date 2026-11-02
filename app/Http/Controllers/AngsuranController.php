<?php

namespace App\Http\Controllers;

use App\Models\Angsuran;
use App\Support\RentangPeriode;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AngsuranController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Angsuran::with('pembiayaan.anggota', 'transaksi', 'user')
            ->orderBy('tanggal_jatuh_tempo', 'asc');

        if ($request->has('pembiayaan_id') && $request->pembiayaan_id !== '') {
            $query->where('pembiayaan_id', $request->pembiayaan_id);
        }

        if ($request->has('status') && $request->status !== '') {
            $statuses = explode(',', $request->status);
            if (count($statuses) > 1) {
                $query->whereIn('status_angsuran', $statuses);
            } else {
                $query->where('status_angsuran', $request->status);
            }
        }

        // Rentang eksplisit (sargable) bila bulan & tahun keduanya diberikan;
        // bulan saja dipertahankan memakai whereMonth (tidak dapat dijadikan rentang).
        if ($request->filled('bulan') && $request->filled('tahun')) {
            RentangPeriode::padaBulan(
                $query,
                'tanggal_jatuh_tempo',
                (int) $request->bulan,
                (int) $request->tahun
            );
        } elseif ($request->filled('bulan')) {
            $query->whereMonth('tanggal_jatuh_tempo', $request->bulan);
        } elseif ($request->filled('tahun')) {
            RentangPeriode::padaTahun($query, 'tanggal_jatuh_tempo', (int) $request->tahun);
        }

        if ($request->has('anggota_id') && $request->anggota_id !== '') {
            $query->whereHas('pembiayaan', function ($q) use ($request) {
                $q->where('anggota_id', $request->anggota_id);
            });
        }

        $perPage = $this->perPage($request);
        $angsurans = $perPage !== null
            ? $query->paginate($perPage)
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data angsuran berhasil diambil',
            'data' => $angsurans,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pembiayaan_id' => 'required|exists:pembiayaan,id',
            'transaksi_id' => 'nullable|exists:transaksi,id',
            'angsuran_ke' => 'required|integer|min:1',
            'tanggal_jatuh_tempo' => 'required|date',
            'tanggal_bayar' => 'nullable|date',
            'pokok_angsuran' => 'nullable|numeric|min:0',
            'margin_ujroh' => 'nullable|numeric|min:0',
            'jumlah_angsuran' => 'required|numeric|min:0',
            'denda' => 'nullable|numeric|min:0',
            'total_bayar' => 'nullable|numeric|min:0',
            'sisa_pokok' => 'required|numeric|min:0',
            'status_angsuran' => 'nullable|in:Belum Jatuh Tempo,Jatuh Tempo,Terlambat,Sudah Dibayar,Dibayar Sebagian,Lewat',
            'hari_terlambat' => 'nullable|integer|min:0',
            'user_id' => 'nullable|exists:users,id',
            'catatan' => 'nullable|string',
        ]);

        $angsuran = Angsuran::create($validated);
        $angsuran->load('pembiayaan.anggota', 'transaksi', 'user');

        return response()->json([
            'success' => true,
            'message' => 'Angsuran berhasil dibuat',
            'data' => $angsuran,
        ], 201);
    }

    public function show(Angsuran $angsuran): JsonResponse
    {
        $angsuran->load('pembiayaan.anggota', 'transaksi', 'user');

        return response()->json([
            'success' => true,
            'message' => 'Detail angsuran berhasil diambil',
            'data' => $angsuran,
        ]);
    }

    public function update(Request $request, Angsuran $angsuran): JsonResponse
    {
        $validated = $request->validate([
            'pembiayaan_id' => 'required|exists:pembiayaan,id',
            'transaksi_id' => 'nullable|exists:transaksi,id',
            'angsuran_ke' => 'required|integer|min:1',
            'tanggal_jatuh_tempo' => 'required|date',
            'tanggal_bayar' => 'nullable|date',
            'pokok_angsuran' => 'nullable|numeric|min:0',
            'margin_ujroh' => 'nullable|numeric|min:0',
            'jumlah_angsuran' => 'required|numeric|min:0',
            'denda' => 'nullable|numeric|min:0',
            'total_bayar' => 'nullable|numeric|min:0',
            'sisa_pokok' => 'required|numeric|min:0',
            'status_angsuran' => 'nullable|in:Belum Jatuh Tempo,Jatuh Tempo,Terlambat,Sudah Dibayar,Dibayar Sebagian,Lewat',
            'hari_terlambat' => 'nullable|integer|min:0',
            'user_id' => 'nullable|exists:users,id',
            'catatan' => 'nullable|string',
        ]);

        /* BUG-03 — LINDUNGI INTEGRITAS KEUANGAN ANGSURAN.
         | Sebelumnya update() menulis seluruh kolom apa adanya, termasuk kolom
         | keuangan (total_bayar, sisa_pokok, status_angsuran, denda, dst.).
         | Untuk angsuran yang SUDAH terhubung transaksi/jurnal, mengubah angka
         | ini langsung membuat saldo pembiayaan & buku besar tidak lagi cocok
         | dengan data angsuran — tanpa jurnal penyesuaian apa pun.
         |
         | Aturan: kolom keuangan hanya boleh berubah lewat alur transaksi
         | (AngsuranService) yang memposting jurnal & menyesuaikan sisa
         | pembiayaan. Pada angsuran yang sudah terhubung, perubahan kolom
         | keuangan ditolak dan harus melalui pembatalan (void) transaksi
         | terkait. Kolom non-keuangan (mis. catatan) tetap boleh diperbarui. */
        if ($this->terhubungTransaksi($angsuran)) {
            $berubah = $this->kolomKeuanganBerubah($angsuran, $validated);

            if ($berubah !== []) {
                return response()->json([
                    'success' => false,
                    'message' => 'Angsuran ini sudah terhubung transaksi/jurnal. Kolom keuangan ('
                        .implode(', ', $berubah).') tidak boleh diubah langsung — batalkan transaksi '
                        .'terkait agar sisa pembiayaan dan jurnal ikut disesuaikan, lalu susun ulang jadwal.',
                ], 422);
            }
        }

        $angsuran->update($validated);
        $angsuran->load('pembiayaan.anggota', 'transaksi', 'user');

        return response()->json([
            'success' => true,
            'message' => 'Angsuran berhasil diperbarui',
            'data' => $angsuran,
        ]);
    }

    public function destroy(Angsuran $angsuran): JsonResponse
    {
        /* BUG-03 — angsuran yang sudah terhubung transaksi/jurnal tidak boleh
         | dihapus langsung: menghapusnya akan menghilangkan jejak pembayaran
         | sementara saldo pembiayaan & jurnal tetap memuat efeknya. Pembatalan
         | harus lewat void transaksi (ReversalService). */
        if ($this->terhubungTransaksi($angsuran)) {
            return response()->json([
                'success' => false,
                'message' => 'Angsuran ini sudah terhubung transaksi/jurnal dan tidak dapat dihapus langsung. '
                    .'Batalkan transaksi terkait (void) agar sisa pembiayaan dan jurnal ikut dibalik.',
            ], 422);
        }

        $angsuran->delete();

        return response()->json([
            'success' => true,
            'message' => 'Angsuran berhasil dihapus',
        ]);
    }

    /** Apakah angsuran sudah punya jejak keuangan (transaksi/jurnal/terbayar)? */
    private function terhubungTransaksi(Angsuran $angsuran): bool
    {
        return $angsuran->transaksi_id !== null
            || (float) $angsuran->total_bayar > 0
            || in_array($angsuran->status_angsuran, ['Sudah Dibayar', 'Dibayar Sebagian'], true);
    }

    /**
     * Daftar kolom keuangan yang nilainya benar-benar berubah pada payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function kolomKeuanganBerubah(Angsuran $angsuran, array $validated): array
    {
        $kolomKeuangan = [
            'transaksi_id', 'tanggal_bayar', 'total_bayar', 'sisa_pokok',
            'status_angsuran', 'denda', 'pokok_angsuran', 'margin_ujroh',
            'jumlah_angsuran',
        ];

        $berubah = [];

        foreach ($kolomKeuangan as $kolom) {
            if (array_key_exists($kolom, $validated) && ! $this->nilaiSama($angsuran, $kolom, $validated[$kolom])) {
                $berubah[] = $kolom;
            }
        }

        return $berubah;
    }

    /** Bandingkan nilai lama & baru dengan normalisasi tanggal/numerik. */
    private function nilaiSama(Angsuran $angsuran, string $kolom, mixed $nilaiBaru): bool
    {
        $nilaiLama = $angsuran->{$kolom};

        if ($nilaiLama === null && ($nilaiBaru === null || $nilaiBaru === '')) {
            return true;
        }

        if ($nilaiLama === null || $nilaiBaru === null || $nilaiBaru === '') {
            return false;
        }

        if (in_array($kolom, ['tanggal_bayar'], true)) {
            try {
                return Carbon::parse($nilaiLama)->toDateString() === Carbon::parse($nilaiBaru)->toDateString();
            } catch (\Throwable) {
                return (string) $nilaiLama === (string) $nilaiBaru;
            }
        }

        if (is_numeric($nilaiLama) && is_numeric($nilaiBaru)) {
            return abs((float) $nilaiLama - (float) $nilaiBaru) < Setting::toleransiUang();
        }

        return (string) $nilaiLama === (string) $nilaiBaru;
    }
}
