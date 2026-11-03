@extends('layouts.app')
@section('page-title', "Data Anggota")

@section('title', 'Data Anggota')

@section('sidebar')
<div class="nav-section">Teller</div>
<a href="{{ route('dashboard.teller') }}" class="nav-item {{ request()->routeIs('dashboard.teller') ? 'active' : '' }}">
    <span class="icon">🏠</span> Dashboard
</a>
<a href="{{ route('teller.setor') }}" class="nav-item {{ request()->routeIs('teller.setor') ? 'active' : '' }}">
    <span class="icon">💰</span> Setor Tunai
</a>
<a href="{{ route('teller.tarik') }}" class="nav-item {{ request()->routeIs('teller.tarik') ? 'active' : '' }}">
    <span class="icon">💸</span> Tarik Tunai
</a>
<a href="{{ route('teller.angsuran') }}" class="nav-item {{ request()->routeIs('teller.angsuran') ? 'active' : '' }}">
    <span class="icon">📊</span> Bayar Angsuran
</a>
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">👥</span> Data Anggota
</a>
@endsection

@section('content')
<div style="max-width:1000px;margin:0 auto;">

    {{-- Header --}}
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
        <div>
            <h2 style="margin:0;font-size:1.4rem;font-weight:700;color:#1e293b;">👥 Data Anggota</h2>
            <p style="margin:.25rem 0 0;color:#64748b;font-size:.9rem;">Kelola master data anggota koperasi</p>
        </div>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        ✅ {{ session('success') }}
    </div>
    @endif

    {{-- Search & Filter --}}
    <form method="GET" action="{{ route('teller.anggota.index') }}"
          style="display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <input type="text" name="search" value="{{ request('search') }}" aria-label="Cari anggota"
               placeholder="Cari nama, NIK, atau No. Anggota..."
               style="flex:1;min-width:220px;padding:.55rem .9rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;">
        <select name="status" style="padding:.55rem .9rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;">
            <option value="">-- Semua Status --</option>
            @foreach(\App\Models\Anggota::STATUS_LIST as $optSt)
                <option value="{{ $optSt }}" {{ $statusFilter === $optSt ? 'selected' : '' }}>
                    {{ \App\Models\Anggota::STATUS_LABEL[$optSt] ?? $optSt }}
                </option>
            @endforeach
        </select>
        <button type="submit"
                style="background:#2563eb;color:#fff;padding:.55rem 1.1rem;border:none;border-radius:8px;cursor:pointer;font-size:.9rem;font-weight:600;">
            Cari
        </button>
        @if(request('search') || request('status'))
        <a href="{{ route('teller.anggota.index') }}"
           style="padding:.55rem 1rem;border:1px solid #d1d5db;border-radius:8px;color:#374151;text-decoration:none;font-size:.9rem;">
            Reset
        </a>
        @endif
    </form>

    {{-- Tabel --}}
    <div class="data-card" style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:.75rem 1rem;text-align:left;color:#475569;font-weight:600;">No. Anggota</th>
                    <th style="padding:.75rem 1rem;text-align:left;color:#475569;font-weight:600;">Nama Lengkap</th>
                    <th style="padding:.75rem 1rem;text-align:left;color:#475569;font-weight:600;">NIK</th>
                    <th style="padding:.75rem 1rem;text-align:left;color:#475569;font-weight:600;">No. HP</th>
                    <th style="padding:.75rem 1rem;text-align:left;color:#475569;font-weight:600;">Bergabung</th>
                    <th style="padding:.75rem 1rem;text-align:center;color:#475569;font-weight:600;">Status</th>
                    <th style="padding:.75rem 1rem;text-align:center;color:#475569;font-weight:600;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($anggota as $a)
                <tr style="border-bottom:1px solid #f1f5f9;transition:background .15s;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td style="padding:.7rem 1rem;font-weight:600;color:#1e293b;">
                        <a href="{{ route('teller.anggota.show', $a->id) }}" style="color:#2563eb;text-decoration:none;">{{ $a->no_anggota ?? '-' }}</a>
                    </td>
                    <td style="padding:.7rem 1rem;">{{ $a->nama_lengkap }}</td>
                    <td style="padding:.7rem 1rem;color:#64748b;font-family:monospace;">{{ $a->nik }}</td>
                    <td style="padding:.7rem 1rem;color:#64748b;">{{ $a->no_hp ?? '-' }}</td>
                    <td style="padding:.7rem 1rem;color:#64748b;">
                        {{ $a->tanggal_gabung ? $a->tanggal_gabung->format('d/m/Y') : '-' }}
                    </td>
                    <td style="padding:.7rem 1rem;text-align:center;">
                        @php
                            $statusColor = match($a->status_anggota) {
                                'Aktif'      => 'background:#d1fae5;color:#065f46;',
                                'Tidak Aktif'=> 'background:#fef3c7;color:#92400e;',
                                'Keluar'     => 'background:#fee2e2;color:#991b1b;',
                                default      => 'background:#f1f5f9;color:#475569;',
                            };
                        @endphp
                        <span style="{{ $statusColor }} padding:.2rem .65rem;border-radius:999px;font-size:.8rem;font-weight:600;">
                            {{ $a->status_anggota }}
                        </span>
                    </td>
                    <td style="padding:.7rem 1rem;text-align:center;white-space:nowrap;">
                        <a href="{{ route('teller.anggota.show', $a->id) }}"
                           title="Lihat Detail"
                           style="display:inline-flex;align-items:center;gap:.3rem;background:#059669;color:#fff;padding:.3rem .65rem;border-radius:6px;text-decoration:none;font-size:.78rem;font-weight:600;margin-right:.2rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            Detail
                        </a>
                        <a href="{{ route('teller.anggota.edit', $a->id) }}"
                           title="Edit Data"
                           style="display:inline-flex;align-items:center;gap:.3rem;background:#d97706;color:#fff;padding:.3rem .65rem;border-radius:6px;text-decoration:none;font-size:.78rem;font-weight:600;margin-right:.2rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Edit
                        </a>
                        <a href="{{ route('teller.anggota.card', $a->id) }}"
                           title="Cetak Kartu"
                           style="display:inline-flex;align-items:center;gap:.3rem;background:#2563eb;color:#fff;padding:.3rem .65rem;border-radius:6px;text-decoration:none;font-size:.78rem;font-weight:600;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                            Kartu
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="padding:2rem;text-align:center;color:#94a3b8;">
                        Belum ada data anggota.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($anggota->hasPages())
    <div style="margin-top:1rem;">{{ $anggota->links() }}</div>
    @endif

</div>
@endsection
