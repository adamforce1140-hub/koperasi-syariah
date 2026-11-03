@extends('layouts.app')
@section('page-title', "Verifikasi Keringanan Ta'zir")

@section('title', 'Verifikasi Keringanan Ta\'zir')

@section('content')
<div class="page-header">
    <h1 class="page-title">Verifikasi Keringanan Ta'zir</h1>
    <p class="page-subtitle">Tinjau pengajuan keringanan denda (ta'zir) dari Teller dan berikan rekomendasi lapangan</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

@php
    $statCards = [
        ['label' => 'Menunggu Verifikasi AO', 'value' => $totalPendingAo,      'color' => '#f59e0b'],
        ['label' => 'Menunggu Manajer',       'value' => $totalPendingManajer, 'color' => '#2563eb'],
        ['label' => 'Disetujui',              'value' => $totalApproved,       'color' => '#16a34a'],
        ['label' => 'Ditolak',                'value' => $totalRejected,       'color' => '#dc2626'],
    ];
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;">
    @foreach($statCards as $card)
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;">
        <div style="font-size:.8rem;color:#64748b;margin-bottom:.35rem;">{{ $card['label'] }}</div>
        <div style="font-size:1.5rem;font-weight:700;color:{{ $card['color'] }};">{{ $card['value'] }}</div>
    </div>
    @endforeach
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <span class="card-title">Daftar Pengajuan ({{ $pengajuan->total() }})</span>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                <option value="PENDING_AO"      {{ $status === 'PENDING_AO' ? 'selected' : '' }}>Menunggu Verifikasi AO</option>
                <option value="PENDING_MANAJER" {{ $status === 'PENDING_MANAJER' ? 'selected' : '' }}>Menunggu Manajer</option>
                <option value="APPROVED"        {{ $status === 'APPROVED' ? 'selected' : '' }}>Disetujui</option>
                <option value="REJECTED"        {{ $status === 'REJECTED' ? 'selected' : '' }}>Ditolak</option>
            </select>
            <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama / no. anggota"
                placeholder="Cari nama / no. anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:220px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Anggota</th>
                <th>No. Pembiayaan</th>
                <th>Angsuran Ke</th>
                <th>Jatuh Tempo</th>
                <th style="text-align:right;">Ta'zir Awal</th>
                <th>Alasan Teller</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan as $item)
            @php
                $badgeStyle = [
                    'yellow' => 'background:#fef3c7;color:#92400e;',
                    'green'  => 'background:#d1fae5;color:#065f46;',
                    'red'    => 'background:#fee2e2;color:#991b1b;',
                    'gray'   => 'background:#f1f5f9;color:#475569;',
                ][$item->status_badge_color] ?? 'background:#f1f5f9;color:#475569;';
            @endphp
            <tr>
                <td>{{ $item->id }}</td>
                <td>
                    <div><strong>{{ $item->anggota?->nama_lengkap ?? '-' }}</strong></div>
                    <div style="font-size:.75rem;color:#6b7280;">{{ $item->anggota?->no_anggota }}</div>
                </td>
                <td>{{ $item->pembiayaan?->no_pembiayaan ?? '-' }}</td>
                <td style="text-align:center;">{{ $item->angsuran?->angsuran_ke ?? '-' }}</td>
                <td>{{ $item->angsuran?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-' }}</td>
                <td style="text-align:right;">Rp {{ number_format((float) $item->nominal_tazir_awal, 0, ',', '.') }}</td>
                <td style="font-size:.8rem;color:#475569;max-width:220px;">
                    {{ \Illuminate\Support\Str::limit($item->alasan_teller, 70) }}
                </td>
                <td>
                    <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;{{ $badgeStyle }}">
                        {{ $item->status_label }}
                    </span>
                </td>
                <td>
                    <a href="{{ route('ao.keringanan-tazir.show', $item->id) }}"
                        class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem;">
                        {{ $item->isPendingAo() ? 'Verifikasi' : 'Detail' }}
                    </a>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan keringanan ta'zir</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>
@endsection
