@extends('layouts.app')
@section('page-title', "Data Nasabah Binaan")

@section('title', 'Data Nasabah Binaan')

@section('content')
<div class="page-header">
    <h1 class="page-title">Nasabah Binaan</h1>
    <p class="page-subtitle">Anggota dengan riwayat pembiayaan</p>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Daftar Nasabah ({{ $nasabah->total() }})</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ $search }}"
                placeholder="Cari nama / no. anggota / HP..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:260px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Anggota</th>
                <th>Nama</th>
                <th>HP</th>
                <th>Pekerjaan</th>
                <th>Jumlah Pembiayaan</th>
                <th>Status Anggota</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($nasabah as $a)
            <tr>
                <td><strong>{{ $a->no_anggota }}</strong></td>
                <td>{{ $a->nama_lengkap }}</td>
                <td>{{ $a->no_hp ?? '-' }}</td>
                <td>{{ $a->pekerjaan ?? '-' }}</td>
                <td>{{ $a->pembiayaan->count() }} kontrak</td>
                <td>
                    <span class="badge {{ $a->isActive() ? 'badge-green' : 'badge-gray' }}">
                        {{ $a->status_label }}
                    </span>
                </td>
                <td>
                    <a href="{{ route('ao.nasabah.show', $a->id) }}" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Detail</a>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada data nasabah</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($nasabah->hasPages())
    <div style="padding:1rem;">{{ $nasabah->links() }}</div>
    @endif
</div>
@endsection
