<?php

namespace App\Http\Controllers;

use App\Models\Anggota;
use App\Models\JenisSimpanan;
use App\Models\Pembiayaan;
use App\Models\Transaksi;
use App\Rules\NikUnik;
use App\Support\PiiHasher;
use Illuminate\Http\Request;

class TellerAnggotaController extends Controller
{
    /**
     * List & search anggota dengan pagination.
     */
    public function index(Request $request)
    {
        $query = Anggota::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                    ->orWhere('no_anggota', 'like', "%{$search}%");

                // NIK dicari lewat blind index (kecocokan tepat), bukan LIKE.
                if (($hash = PiiHasher::hash($search)) !== null) {
                    $q->orWhere('nik_hash', $hash);
                }
            });
        }

        // Filter status memakai nilai kanonik Anggota::STATUS_LIST (sama dengan
        // yang dirender dropdown), tetapi tetap menerima alias legacy dari
        // tautan/bookmark lama.
        $statusFilter = Anggota::normalizeStatus($request->input('status'));

        if ($statusFilter) {
            $query->status($statusFilter);
        }

        $anggota = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        return view('teller.anggota.index', compact('anggota', 'statusFilter'));
    }

    /**
     * Dashboard lookup single view anggota.
     */
    public function show(int $id)
    {
        $anggota = Anggota::with([
            'rekening',
            'pembiayaan' => fn ($q) => $q->with('angsuran')->orderByDesc('created_at'),
        ])->findOrFail($id);

        // Saldo per jenis simpanan (nama produk mengikuti master, DYN-10)
        $saldoPokok = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_POKOK))->sum('saldo');
        $saldoWajib = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_WAJIB))->sum('saldo');
        $saldoSukarela = $anggota->rekening->whereIn('jenis_rekening', JenisSimpanan::namaRekening(JenisSimpanan::TIPE_SUKARELA))->sum('saldo');

        // Pembiayaan aktif & pengajuan terbaru
        $pembiayaanAktif = $anggota->pembiayaan->filter(fn ($p) => $p->isStatus([
            Pembiayaan::STATUS_AKTIF,
            Pembiayaan::STATUS_DISETUJUI,
            Pembiayaan::STATUS_DICAIRKAN,
            Pembiayaan::STATUS_RESTRUKTURISASI,
        ]));
        $pengajuanTerbaru = $anggota->pembiayaan->first();

        // 10 transaksi terakhir anggota ini
        $mutasi = Transaksi::where('anggota_id', $id)
            ->with('rekening')
            ->orderByDesc('waktu_transaksi')
            ->limit(10)
            ->get();

        return view('teller.anggota.show', compact(
            'anggota',
            'saldoPokok', 'saldoWajib', 'saldoSukarela',
            'pembiayaanAktif', 'pengajuanTerbaru',
            'mutasi'
        ));
    }

    /**
     * Form edit data anggota.
     */
    public function edit(int $id)
    {
        $anggota = Anggota::findOrFail($id);

        return view('teller.anggota.edit', compact('anggota'));
    }

    /**
     * Proses update data anggota.
     */
    public function update(Request $request, int $id)
    {
        $anggota = Anggota::findOrFail($id);

        $validated = $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'nik' => ['required', 'digits:16', new NikUnik(Anggota::class, (int) $id)],
            'tempat_lahir' => 'nullable|string|max:100',
            'tanggal_lahir' => 'nullable|date',
            'jenis_kelamin' => 'required|in:Laki-laki,Perempuan',
            'no_hp' => 'required|digits_between:10,15|unique:anggota,no_hp,'.$id,
            'pekerjaan' => 'nullable|string|max:100',
            'alamat' => 'required|string',
            'kota' => 'required|string|max:100',
            'provinsi' => 'required|string|max:100',
            'is_auto_debit' => 'nullable|boolean',
        ], [
            'nama_lengkap.required' => 'Nama lengkap wajib diisi.',
            'nik.required' => 'NIK wajib diisi.',
            'nik.digits' => 'NIK harus terdiri dari 16 digit.',
            'nik.unique' => 'NIK sudah terdaftar pada anggota lain.',
            'jenis_kelamin.required' => 'Jenis kelamin wajib dipilih.',
            'no_hp.required' => 'Nomor HP wajib diisi.',
            'no_hp.digits_between' => 'Nomor HP harus antara 10-15 digit.',
            'no_hp.unique' => 'Nomor HP sudah terdaftar pada anggota lain.',
            'alamat.required' => 'Alamat wajib diisi.',
            'kota.required' => 'Kota/Kabupaten wajib diisi.',
            'provinsi.required' => 'Provinsi wajib diisi.',
        ]);

        /* Teller tidak berwenang mengubah status keanggotaan: field status tidak
         | dirender pada form dan bila tetap dikirim maka akan diabaikan, sehingga
         | nilai lama selalu dipertahankan. */
        unset($validated['status_anggota']);

        $anggota->update($validated);

        return redirect()->route('teller.anggota.show', $id)
            ->with('success', 'Data anggota berhasil diperbarui.');
    }

    /**
     * Tampilan kartu anggota digital.
     */
    public function card(int $id)
    {
        $anggota = Anggota::with('rekening')->findOrFail($id);

        return view('teller.anggota.card', compact('anggota'));
    }
}
