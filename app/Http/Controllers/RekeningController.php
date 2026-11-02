<?php

namespace App\Http\Controllers;

use App\Models\JenisSimpanan;
use App\Models\KebijakanKoperasi;
use App\Models\Rekening;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RekeningController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Rekening::with('anggota', 'akun')->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== '') {
            $query->where('status_rekening', $request->status);
        }

        if ($request->has('anggota_id') && $request->anggota_id !== '') {
            $query->where('anggota_id', $request->anggota_id);
        }

        if ($request->has('jenis') && $request->jenis !== '') {
            $query->where('jenis_rekening', $request->jenis);
        }

        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('no_rekening', 'like', "%{$search}%")
                    ->orWhereHas('anggota', function ($qa) use ($search) {
                        $qa->where('nama_lengkap', 'like', "%{$search}%")
                            ->orWhere('no_anggota', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $this->perPage($request);
        $rekenings = $perPage !== null
            ? $query->paginate($perPage)
            : $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Data rekening berhasil diambil',
            'data' => $rekenings,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'no_rekening' => 'required|string|max:30|unique:rekening,no_rekening',
            'anggota_id' => 'required|exists:anggota,id',
            'akun_id' => 'required|exists:akun,id',
            'jenis_rekening' => ['required', Rule::in(JenisSimpanan::daftarAktif())],
            'akad' => 'required|in:Wadiah,Mudharabah,Musharakah',
            // Nominal pembukaan rekening: batas minimum/maksimum dari parameter
            // kebijakan koperasi (dapat dikonfigurasi pengurus; tanpa hardcode).
            'nominal_pembukaan' => KebijakanKoperasi::aturanNominalPembukaan(false),
            'saldo' => 'nullable|numeric|min:0',
            'batas_minimum' => 'nullable|numeric|min:0',
            'nisbah_bank' => 'nullable|numeric|min:0|max:100',
            'nisbah_nasabah' => 'nullable|numeric|min:0|max:100',
            'tanggal_buka' => 'required|date',
            'tanggal_jatuh_tempo' => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($request) {
                    if (JenisSimpanan::tipe((string) $request->jenis_rekening) === JenisSimpanan::TIPE_BERJANGKA && empty($value)) {
                        $fail('Tanggal jatuh tempo wajib diisi untuk Simpanan Berjangka / Deposito.');
                    }
                },
            ],
            'status_rekening' => 'nullable|in:Aktif,Dibekukan,Ditutup,Jatuh Tempo',
            'keterangan' => 'nullable|string',
        ], array_merge(
            KebijakanKoperasi::pesanValidasiNominalPembukaan('Nominal pembukaan rekening'),
            [
                // Bila nominal tidak dikirim, jejak nominal diambil dari saldo awal.
                // Bila saldo juga kosong, nominal pembukaan wajib diisi (not-null).
                'nominal_pembukaan.required' => 'Nominal pembukaan rekening wajib diisi bila saldo awal tidak dikirim.',
            ]
        ));

        $validated['nominal_pembukaan'] = $this->resolveNominalPembukaan($validated);

        $rekening = Rekening::create($validated);
        $rekening->load('anggota', 'akun');

        return response()->json([
            'success' => true,
            'message' => 'Rekening berhasil dibuka',
            'data' => $rekening,
        ], 201);
    }

    public function show(Rekening $rekening): JsonResponse
    {
        $rekening->load(['anggota', 'akun', 'transaksi' => function ($q) {
            $q->orderBy('waktu_transaksi', 'desc')->limit(20);
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Detail rekening berhasil diambil',
            'data' => $rekening,
        ]);
    }

    public function update(Request $request, Rekening $rekening): JsonResponse
    {
        $validated = $request->validate([
            'no_rekening' => [
                'required', 'string', 'max:30',
                Rule::unique('rekening', 'no_rekening')->ignore($rekening->id),
            ],
            'anggota_id' => 'required|exists:anggota,id',
            'akun_id' => 'required|exists:akun,id',
            // Jenis aktif dari master + jenis lama rekening ini agar data
            // historis yang produknya sudah dinonaktifkan tetap dapat diubah.
            'jenis_rekening' => ['required', Rule::in(array_unique(array_merge(
                JenisSimpanan::daftarAktif(),
                [$rekening->jenis_rekening]
            )))],
            'akad' => 'required|in:Wadiah,Mudharabah,Musharakah',
            'nominal_pembukaan' => KebijakanKoperasi::aturanNominalPembukaan(false),
            // K-03 — `saldo` SENGAJA tidak divalidasi pada jalur UPDATE.
            // Saldo adalah turunan buku besar (PSAK 101): hanya boleh berubah
            // lewat transaksi berjurnal, bukan lewat `PUT /api/rekening/{id}`.
            // (Pada `store()` saldo tetap diterima sebagai saldo awal rekening.)
            'batas_minimum' => 'nullable|numeric|min:0',
            'nisbah_bank' => 'nullable|numeric|min:0|max:100',
            'nisbah_nasabah' => 'nullable|numeric|min:0|max:100',
            'tanggal_buka' => 'required|date',
            'tanggal_jatuh_tempo' => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) use ($request) {
                    if (JenisSimpanan::tipe((string) $request->jenis_rekening) === JenisSimpanan::TIPE_BERJANGKA && empty($value)) {
                        $fail('Tanggal jatuh tempo wajib diisi untuk Simpanan Berjangka / Deposito.');
                    }
                },
            ],
            'status_rekening' => 'nullable|in:Aktif,Dibekukan,Ditutup,Jatuh Tempo',
            'keterangan' => 'nullable|string',
        ], KebijakanKoperasi::pesanValidasiNominalPembukaan('Nominal pembukaan rekening'));

        // Sabuk pengaman tambahan: buang kunci sensitif bila ada pihak lain
        // menambahkannya ke `$validated` di kemudian hari.
        unset($validated['saldo']);

        $rekening->update($validated);
        $rekening->load('anggota', 'akun');

        return response()->json([
            'success' => true,
            'message' => 'Rekening berhasil diperbarui',
            'data' => $rekening,
        ]);
    }

    /**
     * Tentukan jejak nominal pembukaan: pakai nilai yang dikirim, atau
     * fallback ke saldo awal. Bila keduanya kosong, validasi gagal (not-null).
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveNominalPembukaan(array $validated): float
    {
        $nominal = $validated['nominal_pembukaan'] ?? null;

        if ($nominal === null || $nominal === '') {
            $nominal = $validated['saldo'] ?? null;
        }

        if ($nominal === null || $nominal === '') {
            throw ValidationException::withMessages([
                'nominal_pembukaan' => 'Nominal pembukaan rekening wajib diisi bila saldo awal tidak dikirim.',
            ]);
        }

        return Setting::bulatkanUang((float) $nominal);
    }

    public function destroy(Rekening $rekening): JsonResponse
    {
        $rekening->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rekening berhasil dihapus',
        ]);
    }
}
