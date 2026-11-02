@extends('layouts.app')
@section('page-title', "Produk Pembiayaan")

@section('title', 'Produk Pembiayaan')

@section('content')
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1 class="page-title">Produk Pembiayaan</h1>
        <p class="page-subtitle">Pengaturan produk dan parameter pembiayaan dinamis</p>
    </div>
    <a href="{{ route('pengelola.produk-pembiayaan.create') }}" class="btn-primary" style="padding:0.6rem 1.2rem;border-radius:8px;text-decoration:none;font-size:0.9rem;">+ Produk Baru</a>
</div>

@if(session('success'))
<div class="alert-success">{{ session('success') }}</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem;">
    @forelse($produk as $p)
    <div class="card" style="padding:1.25rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
            <div>
                <h3 style="color:white;margin:0 0 0.25rem;font-size:1rem;">{{ $p->nama_produk }}</h3>
                <span class="badge badge-teal">{{ $p->akad_syariah }}</span>
            </div>
            <span class="badge {{ $p->status_aktif ? 'badge-green' : 'badge-red' }}">
                {{ $p->status_aktif ? 'Aktif' : 'Nonaktif' }}
            </span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.5rem;font-size:0.82rem;color:#94a3b8;margin-bottom:1rem;">
            <div>Min Plafon<br><span style="color:white;">Rp {{ number_format($p->min_plafon,0,',','.') }}</span></div>
            <div>Max Plafon<br><span style="color:white;">Rp {{ number_format($p->max_plafon,0,',','.') }}</span></div>
            <div>Margin<br><span style="color:#f0c040;">{{ $p->margin_persen }}% / bulan</span></div>
            <div>Biaya Admin<br><span style="color:white;">Rp {{ number_format($p->biaya_admin,0,',','.') }}</span></div>
            <div>Min Tenor<br><span style="color:white;">{{ $p->min_tenor_bulan }} bulan</span></div>
            <div>Max Tenor<br><span style="color:white;">{{ $p->max_tenor_bulan }} bulan</span></div>
        </div>
        @if($p->syaratDokumen->count() > 0)
        <div style="font-size:0.8rem;color:#64748b;margin-bottom:1rem;">
            <strong style="color:#94a3b8;">Syarat Dokumen:</strong>
            @foreach($p->syaratDokumen as $s)
            <span style="display:inline-block;background:rgba(255,255,255,0.06);padding:0.15rem 0.5rem;border-radius:4px;margin:0.15rem;">{{ $s->nama_dokumen }}</span>
            @endforeach
        </div>
        @endif
        <div style="display:flex;gap:0.5rem;">
            <a href="{{ route('pengelola.produk-pembiayaan.edit', $p) }}" class="btn-sm btn-primary">Edit</a>
            <form method="POST" action="{{ route('pengelola.produk-pembiayaan.destroy', $p) }}" style="display:inline;">
                @csrf @method('DELETE')
                <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Hapus produk ini?')">Hapus</button>
            </form>
        </div>
    </div>
    @empty
    <p style="color:#64748b;">Belum ada produk. <a href="{{ route('pengelola.produk-pembiayaan.create') }}" style="color:#0d9488;">Buat sekarang</a>.</p>
    @endforelse
</div>
@endsection
