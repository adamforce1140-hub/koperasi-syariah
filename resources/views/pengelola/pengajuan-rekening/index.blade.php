@extends('layouts.app')
@section('page-title', "Pengajuan Rekening")

@section('title', 'Pengajuan Rekening')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Pengajuan Rekening Baru</h1>
        <p style="color:#94a3b8;margin:0;">Daftar pengajuan pembukaan rekening simpanan dari calon nasabah</p>
    </div>
</div>

{{-- Summary Cards --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">📋</div>
        <div class="stat-info">
            <div class="stat-value">{{ $counts['total'] }}</div>
            <div class="stat-label">Total Pengajuan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);">🔍</div>
        <div class="stat-info">
            <div class="stat-value">{{ $counts['menunggu_verifikasi'] }}</div>
            <div class="stat-label">Menunggu Verifikasi</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">💳</div>
        <div class="stat-info">
            <div class="stat-value">{{ $counts['menunggu_konfirmasi'] }}</div>
            <div class="stat-label">Menunggu Konfirmasi Bayar</div>
        </div>
    </div>
</div>

{{-- Filter Tabs --}}
<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    @foreach(['','Menunggu Verifikasi','Disetujui','Ditolak','Menunggu Pembayaran','Menunggu Konfirmasi','Aktif'] as $tab)
    <a href="{{ route('pengelola.pengajuan-rekening.index', $tab ? ['status'=>$tab] : []) }}"
       style="padding:0.4rem 1rem;border-radius:20px;font-size:0.8rem;font-weight:600;text-decoration:none;
              background:{{ $status===$tab ? '#0d9488' : 'rgba(255,255,255,0.06)' }};
              color:{{ $status===$tab ? 'white' : '#94a3b8' }};
              border:1px solid {{ $status===$tab ? '#0d9488' : 'rgba(255,255,255,0.1)' }};">
        {{ $tab ?: 'Semua' }}
    </a>
    @endforeach
</div>

{{-- Tabel --}}
<div class="card">
    @if(session('success'))
    <div style="background:rgba(16,185,129,0.15);border:1px solid #10b981;color:#10b981;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('success') }}
    </div>
    @endif

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Calon Nasabah</th>
                    <th>NIK</th>
                    <th>Jenis Simpanan</th>
                    <th>Nominal Awal</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pengajuan as $p)
                <tr>
                    <td style="font-family:monospace;font-size:0.8rem;">{{ $p->kode_pengajuan }}</td>
                    <td><strong>{{ $p->nama_lengkap }}</strong></td>
                    <td style="font-family:monospace;">{{ $p->nik }}</td>
                    <td>{{ $p->jenis_simpanan }}</td>
                    <td>
                        @if($p->nominal_simpanan_awal > 0)
                            Rp {{ number_format($p->nominal_simpanan_awal, 0, ',', '.') }}
                        @else
                            <span style="color:#94a3b8;">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $p->statusBadgeClass() }}">{{ $p->status }}</span>
                    </td>
                    <td style="color:#94a3b8;font-size:0.8rem;">
                        {{ $p->created_at->format('d/m/Y') }}
                    </td>
                    <td>
                        <a href="{{ route('pengelola.pengajuan-rekening.show', $p) }}"
                           style="padding:0.3rem 0.8rem;background:#0d9488;color:white;border-radius:6px;font-size:0.8rem;text-decoration:none;">
                            Detail
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">
                        Tidak ada data pengajuan.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:1rem;">
        {{ $pengajuan->withQueryString()->links() }}
    </div>
</div>
@endsection
