<?php

namespace App\Http\Controllers;

use App\Models\MasterProdukPembiayaan;
use App\Models\Pembiayaan;
use App\Models\PengajuanPembiayaan;
use App\Models\ProdukSyaratDokumen;
use App\Support\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DynamicLoanController extends Controller
{
    /** Aturan validasi nisbah bagi hasil produk (R10). */
    private const ATURAN_NISBAH = [
        'nisbah_anggota' => 'nullable|numeric|min:0|max:100',
        'nisbah_koperasi' => 'nullable|numeric|min:0|max:100',
    ];

    /**
     * R10 — Pastikan nisbah bagi hasil utuh 100% dan wajib ada pada akad
     * bagi hasil (Mudharabah/Musyarakah), setara `DepositoProduct::nisbahValid()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function validasiNisbah(array $data, ?MasterProdukPembiayaan $produk = null): void
    {
        // Saat update, field yang tidak dikirim mewarisi nilai tersimpan —
        // bukan dianggap kosong. Tanpa ini, produk bagi hasil yang sudah punya
        // nisbah di DB selalu gagal diupdate saat form tidak mengirim nisbah.
        $akad = $data['akad_syariah'] ?? $produk?->akad_syariah;
        $anggota = $data['nisbah_anggota'] ?? ($produk?->nisbah_anggota ?? null);
        $koperasi = $data['nisbah_koperasi'] ?? ($produk?->nisbah_koperasi ?? null);

        $kosong = fn ($v) => $v === null || $v === '';

        if ($kosong($anggota) && $kosong($koperasi)) {
            if (in_array($akad, MasterProdukPembiayaan::akadBagiHasil(), true)) {
                throw ValidationException::withMessages([
                    'nisbah_anggota' => "Produk berakad {$akad} wajib mencantumkan nisbah bagi hasil anggota dan koperasi.",
                ]);
            }

            return;
        }

        if ($kosong($anggota) || $kosong($koperasi)) {
            throw ValidationException::withMessages([
                'nisbah_anggota' => 'Nisbah anggota dan nisbah koperasi harus diisi keduanya.',
            ]);
        }

        if (abs((float) $anggota + (float) $koperasi - 100.0) >= Setting::toleransiPersen()) {
            throw ValidationException::withMessages([
                'nisbah_anggota' => 'Total nisbah anggota + nisbah koperasi harus tepat 100%.',
            ]);
        }
    }

    public function index()
    {
        $produk = MasterProdukPembiayaan::with('syaratDokumen')->orderBy('nama_produk')->get();

        return view('pengelola.produk-pembiayaan.index', compact('produk'));
    }

    public function create()
    {
        return view('pengelola.produk-pembiayaan.form', ['produk' => null]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nama_produk' => 'required|string|max:150',
            'akad_syariah' => Pembiayaan::aturanValidasiAkad(),
            'min_plafon' => 'required|numeric|min:100000',
            'max_plafon' => 'required|numeric|gt:min_plafon',
            'margin_persen' => 'required|numeric|min:0|max:100',
            'biaya_admin' => 'required|numeric|min:0',
            'min_bulan_anggota' => 'required|integer|min:0',
            'min_tenor_bulan' => 'required|integer|min:1',
            'max_tenor_bulan' => 'required|integer|gt:min_tenor_bulan',
            'status_aktif' => 'boolean',
            'syarat_dokumen' => 'nullable|array',
            'syarat.*' => 'nullable|string|max:200',
            'syarat_required.*' => 'nullable|boolean',
        ] + self::ATURAN_NISBAH);

        $this->validasiNisbah($data);

        $produk = MasterProdukPembiayaan::create($data + ['status_aktif' => $request->boolean('status_aktif', true)]);

        if ($request->filled('syarat')) {
            foreach ($request->syarat as $i => $nama) {
                if ($nama) {
                    ProdukSyaratDokumen::create([
                        'produk_id' => $produk->id,
                        'nama_dokumen' => $nama,
                        'is_required' => $request->syarat_required[$i] ?? true,
                    ]);
                }
            }
        }

        return redirect()->route('pengelola.produk-pembiayaan.index')->with('success', 'Produk pembiayaan berhasil dibuat.');
    }

    public function edit(MasterProdukPembiayaan $produk)
    {
        $produk->load('syaratDokumen');

        return view('pengelola.produk-pembiayaan.form', compact('produk'));
    }

    public function update(Request $request, MasterProdukPembiayaan $produk)
    {
        $data = $request->validate([
            'nama_produk' => 'required|string|max:150',
            'akad_syariah' => Pembiayaan::aturanValidasiAkad(),
            'min_plafon' => 'required|numeric|min:100000',
            'max_plafon' => 'required|numeric|gt:min_plafon',
            'margin_persen' => 'required|numeric|min:0|max:100',
            'biaya_admin' => 'required|numeric|min:0',
            'min_bulan_anggota' => 'required|integer|min:0',
            'min_tenor_bulan' => 'required|integer|min:1',
            'max_tenor_bulan' => 'required|integer|gt:min_tenor_bulan',
            'status_aktif' => 'boolean',
        ] + self::ATURAN_NISBAH);

        $this->validasiNisbah($data, $produk);

        // `status_aktif` hanya diubah bila memang dikirim form. Default `true`
        // tanpa penjagaan ini memaksa produk nonaktif kembali aktif diam-diam.
        $ubah = $data;
        if ($request->has('status_aktif')) {
            $ubah['status_aktif'] = $request->boolean('status_aktif');
        }

        $produk->update($ubah);

        // Sync syarat dokumen HANYA bila field dikirim. Tanpa penjagaan ini,
        // pembaruan yang tidak menyertakan `syarat` (mis. hanya mengubah margin)
        // menghapus seluruh syarat dokumen produk.
        if ($request->has('syarat')) {
            $produk->syaratDokumen()->delete();
            foreach ($request->syarat as $i => $nama) {
                if ($nama) {
                    ProdukSyaratDokumen::create([
                        'produk_id' => $produk->id,
                        'nama_dokumen' => $nama,
                        'is_required' => $request->syarat_required[$i] ?? true,
                    ]);
                }
            }
        }

        return redirect()->route('pengelola.produk-pembiayaan.index')->with('success', 'Produk pembiayaan diperbarui.');
    }

    public function destroy(MasterProdukPembiayaan $produk)
    {
        // Produk yang sudah disertifikasi DPS tidak boleh dihapus: sertifikasi
        // melekat pada akad, dan penghapusan menghilangkan jejak audit DPS.
        if ($produk->sudahDisertifikasi()) {
            return redirect()->route('pengelola.produk-pembiayaan.index')
                ->with('error', "Produk '{$produk->nama_produk}' sudah disertifikasi DPS dan tidak dapat dihapus. Nonaktifkan produk bila tidak dipakai lagi.");
        }

        // Produk yang sudah dipakai pengajuan/pembiayaan anggota tidak boleh
        // dihapus agar data pengajuan lama tidak kehilangan rujukan produknya.
        $dipakai = PengajuanPembiayaan::where('produk_id', $produk->id)->exists();
        if ($dipakai) {
            return redirect()->route('pengelola.produk-pembiayaan.index')
                ->with('error', "Produk '{$produk->nama_produk}' sudah dipakai pada pengajuan pembiayaan anggota dan tidak dapat dihapus. Nonaktifkan produk bila tidak dipakai lagi.");
        }

        $produk->syaratDokumen()->delete();
        $produk->delete();

        return redirect()->route('pengelola.produk-pembiayaan.index')->with('success', 'Produk dihapus.');
    }

    /** API: daftar produk aktif untuk mobile */
    public function apiIndex()
    {
        $produk = MasterProdukPembiayaan::with('syaratDokumen')->where('status_aktif', true)->get();

        return response()->json(['data' => $produk]);
    }
}
