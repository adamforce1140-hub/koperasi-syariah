@extends('layouts.app')
@section('page-title', "Tracking Status Pengajuan")

@section('title', 'Tracking Status Pengajuan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Tracking Status Pengajuan</h1>
    <p class="page-subtitle">Pantau perkembangan status semua pengajuan</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Semua Pengajuan</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama / no. pengajuan"
                placeholder="Cari nama / no. pengajuan..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Akad</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Status</th>
                <th>5C</th>
                <th>Diproses Oleh</th>
                <th>Update</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan as $item)
            <tr>
                <td><strong>{{ $item->no_pengajuan }}</strong></td>
                <td>
                    <div>{{ $item->anggota?->nama_lengkap ?? '-' }}</div>
                    <div style="font-size:.75rem;color:#6b7280;">{{ $item->anggota?->no_anggota }}</div>
                </td>
                <td>{{ $item->jenis_akad ?? '-' }}</td>
                <td>Rp {{ number_format($item->jumlah_pengajuan, 0, ',', '.') }}</td>
                <td>{{ $item->tenor }} bln</td>
                <td>
                    @php
                        $statusColors = ['Menunggu'=>'#f59e0b','Direview'=>'#3b82f6','Diproses'=>'#3b82f6','Disetujui'=>'#10b981','Ditolak'=>'#ef4444'];
                        $color = $statusColors[$item->status] ?? '#6b7280';
                    @endphp
                    <span style="padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;color:#fff;background:{{ $color }};">
                        {{ $item->status }}
                    </span>
                </td>
                <td style="text-align:center;">
                    @if($item->analisis_5c)
                        @php $c = is_array($item->analisis_5c) ? $item->analisis_5c : json_decode($item->analisis_5c, true); @endphp
                        <span style="font-weight:700;color:#0ea5e9;">{{ $c['skor_total'] ?? '-' }}/25</span>
                    @else
                        <span style="color:#d1d5db;">—</span>
                    @endif
                </td>
                <td style="font-size:.8rem;color:#6b7280;">{{ $item->diprosesOleh?->name ?? '-' }}</td>
                <td style="font-size:.8rem;color:#6b7280;">{{ $item->updated_at->format('d/m/Y') }}</td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada data</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>
@endsection
