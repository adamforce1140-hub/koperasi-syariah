@extends('layouts.app')
@section('page-title', "Produk Pembiayaan")

@section('title', $produk ? 'Edit Produk Pembiayaan' : 'Produk Pembiayaan Baru')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $produk ? 'Edit Produk Pembiayaan' : 'Produk Pembiayaan Baru' }}</h1>
        <p class="page-subtitle">Parameter produk mengikuti kebijakan Pengurus & disetujui DPS</p>
    </div>
    <a href="{{ route('pengelola.produk-pembiayaan.index') }}" class="btn-sm" style="padding:0.5rem 1rem;border-radius:8px;text-decoration:none;">Batal</a>
</div>

<div class="card" style="max-width:860px;padding:1.5rem;">
    <form method="POST" action="{{ $produk ? route('pengelola.produk-pembiayaan.update', $produk) : route('pengelola.produk-pembiayaan.store') }}">
        @csrf
        @if($produk) @method('PUT') @endif

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;">
            <div style="grid-column:1/-1;">
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Nama Produk *</label>
                <input type="text" name="nama_produk" value="{{ old('nama_produk', $produk->nama_produk ?? '') }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Akad Syariah *</label>
                <select name="akad_syariah" required
                        style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
                    @foreach(\App\Models\Pembiayaan::daftarAkad() as $akad)
                    <option value="{{ $akad }}" @selected(old('akad_syariah', $produk->akad_syariah ?? '') === $akad)>{{ $akad }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Margin (%) *</label>
                <input type="number" name="margin_persen" step="0.01" min="0" max="100" value="{{ old('margin_persen', $produk->margin_persen ?? 12) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Nisbah Anggota (%)</label>
                <input type="number" name="nisbah_anggota" step="0.001" min="0" max="100" value="{{ old('nisbah_anggota', $produk->nisbah_anggota ?? '') }}"
                       placeholder="cth. 40 (wajib untuk Mudharabah/Musyarakah)"
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Nisbah Koperasi (%)</label>
                <input type="number" name="nisbah_koperasi" step="0.001" min="0" max="100" value="{{ old('nisbah_koperasi', $produk->nisbah_koperasi ?? '') }}"
                       placeholder="cth. 60 (anggota + koperasi = 100)"
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Min Plafon (Rp) *</label>
                <input type="number" name="min_plafon" min="100000" value="{{ old('min_plafon', $produk->min_plafon ?? 1000000) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Max Plafon (Rp) *</label>
                <input type="number" name="max_plafon" min="100000" value="{{ old('max_plafon', $produk->max_plafon ?? 50000000) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Biaya Admin (Rp) *</label>
                <input type="number" name="biaya_admin" min="0" value="{{ old('biaya_admin', $produk->biaya_admin ?? 50000) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Min Lama Menjadi Anggota (bulan) *</label>
                <input type="number" name="min_bulan_anggota" min="0" value="{{ old('min_bulan_anggota', $produk->min_bulan_anggota ?? 3) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Min Tenor (bulan) *</label>
                <input type="number" name="min_tenor_bulan" min="1" value="{{ old('min_tenor_bulan', $produk->min_tenor_bulan ?? 3) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div>
                <label style="display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.35rem;">Max Tenor (bulan) *</label>
                <input type="number" name="max_tenor_bulan" min="1" value="{{ old('max_tenor_bulan', $produk->max_tenor_bulan ?? 36) }}" required
                       style="width:100%;padding:.6rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <input type="hidden" name="status_aktif" value="0">
                <input type="checkbox" name="status_aktif" value="1" id="status_aktif" @checked(old('status_aktif', $produk->status_aktif ?? true))>
                <label for="status_aktif" style="font-size:.85rem;color:#94a3b8;">Produk Aktif</label>
            </div>
        </div>

        {{-- ── SYARAT DOKUMEN ── --}}
        <h3 style="font-size:.95rem;font-weight:700;color:#e2e8f0;margin:1.5rem 0 .75rem;padding-bottom:.5rem;border-bottom:1px solid #334155;">Syarat Dokumen</h3>
        <div id="syarat-wrap">
            @foreach(old('syarat', $produk?->syaratDokumen->pluck('nama_dokumen')->toArray() ?? []) as $i => $nama)
            <div class="syarat-row" style="display:flex;gap:.75rem;align-items:center;margin-bottom:.5rem;">
                <input type="text" name="syarat[{{ $i }}]" value="{{ $nama }}" placeholder="Nama dokumen"
                       style="flex:1;padding:.55rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
                <label style="font-size:.8rem;color:#94a3b8;display:flex;gap:.35rem;align-items:center;white-space:nowrap;">
                    <input type="hidden" name="syarat_required[{{ $i }}]" value="0">
                    <input type="checkbox" name="syarat_required[{{ $i }}]" value="1" @checked($produk?->syaratDokumen[$i]->is_required ?? true)> Wajib
                </label>
                <button type="button" onclick="this.closest('.syarat-row').remove()" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:.85rem;">Hapus</button>
            </div>
            @endforeach
        </div>
        <button type="button" onclick="tambahSyarat()" style="background:rgba(255,255,255,.06);border:1px dashed #475569;color:#94a3b8;padding:.45rem .9rem;border-radius:8px;cursor:pointer;font-size:.85rem;">+ Tambah Syarat</button>

        <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
            <button type="submit" class="btn-primary" style="padding:.6rem 1.5rem;border-radius:8px;border:none;cursor:pointer;font-size:.9rem;">Simpan Produk</button>
            <a href="{{ route('pengelola.produk-pembiayaan.index') }}" class="btn-sm" style="padding:.6rem 1rem;border-radius:8px;text-decoration:none;">Batal</a>
        </div>
    </form>
</div>

<script>
let syaratIdx = 100;
function tambahSyarat() {
    const wrap = document.getElementById('syarat-wrap');
    const row = document.createElement('div');
    row.className = 'syarat-row';
    row.style.cssText = 'display:flex;gap:.75rem;align-items:center;margin-bottom:.5rem;';
    row.innerHTML = `
        <input type="text" name="syarat[\${syaratIdx}]" placeholder="Nama dokumen"
               style="flex:1;padding:.55rem .75rem;border-radius:8px;border:1px solid #334155;background:rgba(255,255,255,.05);color:white;">
        <label style="font-size:.8rem;color:#94a3b8;display:flex;gap:.35rem;align-items:center;white-space:nowrap;">
            <input type="hidden" name="syarat_required[\${syaratIdx}]" value="0">
            <input type="checkbox" name="syarat_required[\${syaratIdx}]" value="1" checked> Wajib
        </label>
        <button type="button" onclick="this.closest('.syarat-row').remove()" style="background:none;border:none;color:#f87171;cursor:pointer;font-size:.85rem;">Hapus</button>`;
    wrap.appendChild(row);
    syaratIdx++;
}
</script>
@endsection
