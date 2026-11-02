<?php

namespace App\Http\Controllers;

use App\Models\MasterProdukPembiayaan;
use Illuminate\Http\Request;

class ProdukAkadController extends Controller
{
    public function index(Request $request)
    {
        $produk = MasterProdukPembiayaan::orderBy('nama_produk')
            ->paginate(20)
            ->withQueryString();

        return view('pengurus.produk', compact('produk'));
    }

    public function edit(int $id)
    {
        $produk = MasterProdukPembiayaan::findOrFail($id);

        return view('pengurus.produk-edit', compact('produk'));
    }

    public function update(Request $request, int $id)
    {
        $produk = MasterProdukPembiayaan::findOrFail($id);

        $validated = $request->validate([
            'margin_persen' => 'required|numeric|min:0|max:100',
            'min_plafon' => 'required|numeric|min:0',
            'max_plafon' => 'required|numeric|min:0|gte:min_plafon',
            'min_tenor_bulan' => 'required|integer|min:1',
            'max_tenor_bulan' => 'required|integer|min:1|gte:min_tenor_bulan',
            'biaya_admin' => 'nullable|numeric|min:0',
            'status_aktif' => 'required|boolean',
        ]);

        $produk->update($validated);

        return redirect()->route('pengurus.produk.index')
            ->with('success', "Margin dan parameter produk '{$produk->nama_produk}' berhasil diperbarui.");
    }
}
