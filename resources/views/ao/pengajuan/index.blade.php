@extends('layouts.app')
@section('page-title', "Daftar Pengajuan Pembiayaan")

@section('title', 'Daftar Pengajuan Pembiayaan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Pengajuan Pembiayaan</h1>
    <p class="page-subtitle">Kelola pengajuan pembiayaan nasabah</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- Stats --}}
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#f59e0b;">{{ $stats['menunggu'] }}</div>
        <div style="font-size:.85rem;color:#6b7280;">Menunggu / Direview</div>
    </div>
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#10b981;">{{ $stats['disetujui'] }}</div>
        <div style="font-size:.85rem;color:#6b7280;">Disetujui</div>
    </div>
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#ef4444;">{{ $stats['ditolak'] }}</div>
        <div style="font-size:.85rem;color:#6b7280;">Ditolak</div>
    </div>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.8rem;">
        <span class="card-title">Daftar Pengajuan</span>
        <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:.5rem;">
                <input type="text" name="search" value="{{ $search }}"
                    placeholder="Cari nama / no. pengajuan..."
                    style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:220px;">
                <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                    <option value="">Semua Status</option>
                    @foreach($statusList as $val => $label)
                        <option value="{{ $val }}" {{ $status == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
            </form>
            <a href="{{ route('ao.pengajuan.create') }}" class="btn btn-primary" style="font-size:.85rem;padding:.5rem 1rem;">+ Pengajuan Baru</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Produk / Akad</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Status</th>
                <th>Tanggal</th>
                <th>Aksi</th>
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
                <td>
                    <div>{{ $item->produk?->nama_produk ?? '-' }}</div>
                    <div style="font-size:.75rem;color:#6b7280;">{{ $item->jenis_akad }}</div>
                </td>
                <td>Rp {{ number_format($item->jumlah_pengajuan, 0, ',', '.') }}</td>
                <td>{{ $item->tenor }} bln</td>
                <td>
                    @php
                        $badgeMap = [
                            'Menunggu'     => 'badge-amber',
                            'Direview'     => 'badge-blue',
                            'Diproses'     => 'badge-blue',
                            'Disetujui'    => 'badge-green',
                            'Ditolak'      => 'badge-red',
                            'Perlu Revisi' => 'badge-orange',
                        ];
                        $badge = $badgeMap[$item->status] ?? 'badge-gray';
                    @endphp
                    <span class="badge {{ $badge }}">{{ $item->status }}</span>
                </td>
                <td>{{ $item->created_at->format('d/m/Y') }}</td>
                <td>
                    <a href="{{ route('ao.pengajuan.show', $item->id) }}" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Detail</a>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada pengajuan</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>
@endsection
