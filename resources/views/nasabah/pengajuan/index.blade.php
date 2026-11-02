@extends('layouts.app')
@section('page-title', "Riwayat Pengajuan")

@section('title', 'Riwayat Pengajuan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Riwayat Pengajuan</h1>
    <p class="page-subtitle">Daftar semua pengajuan pembiayaan Anda</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Pengajuan Pembiayaan</span>
        <a href="{{ route('nasabah.pengajuan.create') }}" class="btn btn-primary" style="font-size:.85rem;padding:.5rem 1rem;">
            + Ajukan Baru
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Tujuan</th>
                <th>Status</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan as $item)
            <tr>
                <td><strong>{{ $item->no_pengajuan }}</strong></td>
                <td>{{ $item->produk?->nama_produk ?? $item->jenis_akad }}</td>
                <td>Rp {{ number_format($item->jumlah_pengajuan, 0, ',', '.') }}</td>
                <td>{{ $item->tenor }} bulan</td>
                <td>{{ $item->tujuan }}</td>
                <td>
                    @if($item->status === 'Pending' || $item->status === 'Menunggu')
                        <span class="badge badge-amber">Menunggu</span>
                    @elseif($item->status === 'Diproses')
                        <span class="badge badge-blue">Diproses</span>
                    @elseif($item->status === 'Disetujui')
                        <span class="badge badge-green">Disetujui</span>
                    @else
                        <span class="badge badge-red">{{ $item->status }}</span>
                    @endif
                </td>
                <td>{{ $item->created_at->format('d/m/Y') }}</td>
            </tr>
            @if($item->catatan_pengelola)
            <tr style="background:#fafafa;">
                <td colspan="7" style="font-size:.8rem;color:#6b7280;padding:.4rem 1rem;">
                    <strong>Catatan Petugas:</strong> {{ $item->catatan_pengelola }}
                </td>
            </tr>
            @endif
            @empty
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat pengajuan</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($pengajuan->hasPages())
    <div style="padding:1rem;">{{ $pengajuan->links() }}</div>
    @endif
</div>
@endsection
