<?php

namespace App\Http\Controllers;

use App\Models\Akun;
use App\Models\Coa;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengelolaan baris detail jurnal manual.
 *
 * KOLOM KANONIK = `coa_id`.
 *
 * Laporan keuangan (LaporanKeuanganSyariahService) membaca jurnal dengan
 * JOIN `coas` melalui `jurnal_detail.coa_id`. Kolom `akun_id` adalah WARISAN
 * skema lama: baris yang hanya mengisi `akun_id` tidak akan pernah muncul di
 * neraca/laba-rugi — "jurnal hantu" yang merusak kepatuhan PSAK 101.
 *
 * Karena itu endpoint ini WAJIB menerima `coa_id`. `akun_id` legacy tetap
 * ditoleransi sebagai MASUKAN (fallback) agar klien lama tidak langsung
 * patah, tetapi selalu diterjemahkan ke `coa_id` sebelum disimpan — dengan
 * urutan: (1) pemetaan kode legacy via `App\Support\Coa`, (2) nama akun yang
 * sama pada bagan akun CoA. Bila padanan tidak ditemukan, baris DITOLAK
 * (422) demi integritas buku besar — bukan disimpan sebagai jurnal hantu.
 */
class JurnalDetailController extends Controller
{
    public function index(Jurnal $jurnal): JsonResponse
    {
        $detail = $jurnal->detail()
            ->with('coa', 'anggota', 'rekening.anggota')
            ->orderBy('baris')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Detail jurnal berhasil diambil',
            'data' => $detail,
        ]);
    }

    public function store(Request $request, Jurnal $jurnal): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:2',
            'items.*.coa_id' => 'nullable|exists:coas,id',
            'items.*.akun_id' => 'nullable|exists:akun,id',
            'items.*.baris' => 'required|integer|min:1',
            'items.*.debet' => 'nullable|numeric|min:0',
            'items.*.kredit' => 'nullable|numeric|min:0',
            'items.*.keterangan' => 'nullable|string',
            'items.*.anggota_id' => 'nullable|exists:anggota,id',
            'items.*.rekening_id' => 'nullable|exists:rekening,id',
        ]);

        if ($jurnal->status === 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal sudah di-post, tidak bisa menambah detail',
            ], 422);
        }

        // Terjemahkan setiap baris ke `coa_id` SEBELUM menghapus baris lama —
        // bila ada satu saja yang tidak terpetakan, jurnal lama tetap utuh.
        $items = [];

        foreach ($validated['items'] as $item) {
            $coaId = $this->resolveCoaId($item['coa_id'] ?? null, $item['akun_id'] ?? null);

            if ($coaId === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Baris jurnal tidak dapat dipetakan ke akun CoA '
                        .'(isi `coa_id`, atau `akun_id` legacy yang punya padanan CoA). '
                        .'Baris tanpa CoA tidak akan muncul di laporan keuangan.',
                ], 422);
            }

            $item['coa_id'] = $coaId;
            $items[] = $item;
        }

        $toleransi = Setting::toleransiSelisihJurnal();

        return DB::transaction(function () use ($jurnal, $items, $toleransi) {
            $jurnal->detail()->delete();

            $totalDebet = 0.0;
            $totalKredit = 0.0;

            foreach ($items as $item) {
                $debet = (float) ($item['debet'] ?? 0);
                $kredit = (float) ($item['kredit'] ?? 0);

                JurnalDetail::create([
                    'jurnal_id' => $jurnal->id,
                    // Kolom kanonik — dibaca seluruh laporan keuangan.
                    'coa_id' => $item['coa_id'],
                    // Kolom warisan sengaja dikosongkan agar tidak ada dua
                    // sumber kebenaran (lihat AutoJournalingService).
                    'akun_id' => null,
                    'baris' => $item['baris'],
                    'debet' => $debet,
                    'kredit' => $kredit,
                    'keterangan' => $item['keterangan'] ?? null,
                    'anggota_id' => $item['anggota_id'] ?? null,
                    'rekening_id' => $item['rekening_id'] ?? null,
                ]);

                $totalDebet += $debet;
                $totalKredit += $kredit;
            }

            $jurnal->update([
                'total_debet' => $totalDebet,
                'total_kredit' => $totalKredit,
                'is_balance' => abs($totalDebet - $totalKredit) < $toleransi,
            ]);

            $jurnal->load('detail.coa');

            return response()->json([
                'success' => true,
                'message' => 'Detail jurnal berhasil disimpan',
                'data' => [
                    'jurnal' => $jurnal,
                    'is_balance' => $jurnal->is_balance,
                    'selisih' => abs($totalDebet - $totalKredit),
                ],
            ]);
        });
    }

    public function show(Jurnal $jurnal, JurnalDetail $detail): JsonResponse
    {
        if ($detail->jurnal_id !== $jurnal->id) {
            return response()->json([
                'success' => false,
                'message' => 'Detail tidak termasuk dalam jurnal ini',
            ], 404);
        }

        $detail->load('coa', 'anggota', 'rekening.anggota', 'jurnal');

        return response()->json([
            'success' => true,
            'message' => 'Detail jurnal berhasil diambil',
            'data' => $detail,
        ]);
    }

    public function update(Request $request, Jurnal $jurnal, JurnalDetail $detail): JsonResponse
    {
        if ($detail->jurnal_id !== $jurnal->id) {
            return response()->json([
                'success' => false,
                'message' => 'Detail tidak termasuk dalam jurnal ini',
            ], 404);
        }

        if ($jurnal->status === 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal sudah di-post, tidak bisa mengubah detail',
            ], 422);
        }

        $validated = $request->validate([
            'coa_id' => 'nullable|exists:coas,id',
            'akun_id' => 'nullable|exists:akun,id',
            'baris' => 'required|integer|min:1',
            'debet' => 'nullable|numeric|min:0',
            'kredit' => 'nullable|numeric|min:0',
            'keterangan' => 'nullable|string',
            'anggota_id' => 'nullable|exists:anggota,id',
            'rekening_id' => 'nullable|exists:rekening,id',
        ]);

        $coaId = $this->resolveCoaId(
            $validated['coa_id'] ?? null,
            $validated['akun_id'] ?? null,
            // Baris yang sudah ada dapat dipertahankan akun CoA-nya.
            $detail->coa_id
        );

        if ($coaId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Baris jurnal tidak dapat dipetakan ke akun CoA.',
            ], 422);
        }

        return DB::transaction(function () use ($jurnal, $detail, $validated, $coaId) {
            $detail->update([
                'coa_id' => $coaId,
                'akun_id' => null,
                'baris' => $validated['baris'],
                'debet' => $validated['debet'] ?? 0,
                'kredit' => $validated['kredit'] ?? 0,
                'keterangan' => $validated['keterangan'] ?? null,
                'anggota_id' => $validated['anggota_id'] ?? null,
                'rekening_id' => $validated['rekening_id'] ?? null,
            ]);

            $jurnal->hitungBalance();
            $jurnal->save();

            $detail->load('coa', 'anggota', 'rekening.anggota', 'jurnal');

            return response()->json([
                'success' => true,
                'message' => 'Detail jurnal berhasil diperbarui',
                'data' => $detail,
            ]);
        });
    }

    public function destroy(Jurnal $jurnal, JurnalDetail $detail): JsonResponse
    {
        if ($detail->jurnal_id !== $jurnal->id) {
            return response()->json([
                'success' => false,
                'message' => 'Detail tidak termasuk dalam jurnal ini',
            ], 404);
        }

        if ($jurnal->status === 'Posted') {
            return response()->json([
                'success' => false,
                'message' => 'Jurnal sudah di-post, tidak bisa menghapus detail',
            ], 422);
        }

        $detail->delete();
        $jurnal->hitungBalance();
        $jurnal->save();

        return response()->json([
            'success' => true,
            'message' => 'Detail jurnal berhasil dihapus',
        ]);
    }

    /**
     * Terjemahkan MASUKAN baris menjadi sebuah `coa_id` kanonik.
     *
     * Urutan: (1) `coa_id` eksplisit (divalidasi ada), (2) pemetaan kode akun
     * legacy `App\Support\Coa::legacy()`, (3) pencocokan nama akun legacy pada
     * bagan akun CoA, (4) nilai cadangan (mis. CoA baris saat ini). Null bila
     * tidak ada padanan — pemanggil menolak baris tersebut.
     */
    private function resolveCoaId(?int $coaId, ?int $akunId, ?int $fallback = null): ?int
    {
        if ($coaId !== null) {
            return (int) $coaId;
        }

        if ($fallback !== null) {
            return (int) $fallback;
        }

        if ($akunId === null) {
            return null;
        }

        $akun = Akun::query()->find($akunId);

        if ($akun === null) {
            return null;
        }

        // (2) Pemetaan kode akun legacy → kode CoA → id CoA.
        $kodeCoa = \App\Support\Coa::coaDariLegacy($akun->kode_akun) ?? $akun->kode_akun;
        $coa = Coa::query()->where('kode_akun', $kodeCoa)->value('id');

        if ($coa !== null) {
            return (int) $coa;
        }

        // (3) Jaring terakhir: cocokkan nama akun (di-trim, case-insensitive).
        $nama = trim((string) $akun->nama_akun);

        if ($nama === '') {
            return null;
        }

        $coa = Coa::query()
            ->whereRaw('LOWER(TRIM(nama_akun)) = ?', [mb_strtolower($nama)])
            ->value('id');

        return $coa === null ? null : (int) $coa;
    }
}
