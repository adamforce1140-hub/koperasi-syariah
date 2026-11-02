@extends('layouts.app')
@section('page-title', "Survei & Analisis 5C")

@section('title', 'Survei & Analisis 5C')

@section('content')
<div class="page-header">
    <h1 class="page-title">Survei & Analisis 5C</h1>
    <p class="page-subtitle">Daftar pengajuan yang perlu disurvei</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Antrian Survei ({{ $pengajuan->total() }} pengajuan)</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ $search }}"
                placeholder="Cari nama / no. pengajuan..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    @forelse($pengajuan as $item)
    <div style="border-bottom:1px solid #f3f4f6;padding:1.2rem 1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
            <div style="flex:1;">
                <div style="font-weight:600;font-size:.95rem;">{{ $item->anggota?->nama_lengkap ?? '-' }}
                    <span style="font-size:.8rem;color:#6b7280;font-weight:400;">({{ $item->anggota?->no_anggota }})</span>
                </div>
                <div style="font-size:.85rem;color:#374151;margin-top:.3rem;">
                    {{ $item->no_pengajuan }} &bull; {{ $item->jenis_akad }} &bull;
                    Rp {{ number_format($item->jumlah_pengajuan, 0, ',', '.') }} / {{ $item->tenor }} bulan
                </div>
                <div style="font-size:.8rem;color:#6b7280;margin-top:.2rem;">{{ $item->tujuan ?? '-' }}</div>
            </div>
            <div>
                <span class="badge badge-amber" style="margin-bottom:.5rem;display:block;text-align:center;">{{ $item->status }}</span>
                <div style="font-size:.75rem;color:#6b7280;text-align:right;">{{ $item->created_at->format('d/m/Y') }}</div>
            </div>
        </div>

        {{-- Form 5C --}}
        <form method="POST" action="{{ route('ao.survei.store', $item->id) }}" style="margin-top:1rem;background:#f9fafb;padding:1rem;border-radius:8px;">
            @csrf
            <div style="font-weight:600;font-size:.85rem;color:#374151;margin-bottom:.8rem;">Analisis 5C</div>
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.8rem;margin-bottom:.8rem;">
                @foreach(['character'=>'Character','capacity'=>'Capacity','capital'=>'Capital','collateral'=>'Collateral','condition'=>'Condition'] as $key => $label)
                <div>
                    <label style="display:block;font-size:.75rem;color:#6b7280;margin-bottom:.3rem;">{{ $label }}</label>
                    <select name="{{ $key }}" required style="width:100%;padding:.4rem .5rem;border:1px solid #d1d5db;border-radius:4px;font-size:.85rem;">
                        @for($i=1;$i<=5;$i++)
                        <option value="{{ $i }}">{{ $i }}</option>
                        @endfor
                    </select>
                </div>
                @endforeach
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem;">
                <div>
                    <label style="display:block;font-size:.75rem;color:#6b7280;margin-bottom:.3rem;">Rekomendasi</label>
                    <select name="rekomendasi" required style="width:100%;padding:.4rem .5rem;border:1px solid #d1d5db;border-radius:4px;font-size:.85rem;">
                        <option value="teruskan">Teruskan ke Manajer</option>
                        <option value="tolak">Tolak</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.75rem;color:#6b7280;margin-bottom:.3rem;">Catatan Survei</label>
                    <input type="text" name="catatan_survei" placeholder="Catatan opsional..."
                        style="width:100%;padding:.4rem .5rem;border:1px solid #d1d5db;border-radius:4px;font-size:.85rem;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary" style="font-size:.85rem;padding:.4rem 1rem;">Simpan Hasil Survei</button>
        </form>
    </div>
    @empty
    <div style="padding:3rem;text-align:center;color:#94a3b8;">Tidak ada pengajuan yang perlu disurvei</div>
    @endforelse

    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>
@endsection
