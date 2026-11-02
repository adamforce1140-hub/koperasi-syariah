@extends('layouts.app')
@section('page-title', "Daftar Simpanan Berjangka")

@section('title', 'Daftar Simpanan Berjangka')

@section('sidebar')
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">👥</span> Data & Daftar Anggota
</a>
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
<a href="{{ route('teller.deposito') }}" class="nav-item {{ request()->routeIs('teller.deposito') ? 'active' : '' }}">
    <span class="icon">🏦</span> Buka Deposito
</a>
<a href="{{ route('teller.deposito.index') }}" class="nav-item active">
    <span class="icon">📋</span> Daftar Deposito
</a>
@endsection

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Header --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Simpanan Berjangka / Deposito</h1>
            <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">Manajemen deposito mudharabah seluruh anggota koperasi.</p>
        </div>
        <a href="{{ route('teller.deposito') }}"
           style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;
                  background:#059669;color:#fff;font-size:.82rem;font-weight:600;
                  border-radius:.5rem;text-decoration:none;">
            + Buka Deposito Baru
        </a>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
        ✅ {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
        ⚠️ {{ session('error') }}
    </div>
    @endif

    {{-- Stat Cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div style="background:linear-gradient(135deg,#059669,#047857);color:#fff;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.2rem;font-weight:700;">Rp {{ number_format($totalNominal, 0, ',', '.') }}</div>
            <div style="font-size:.75rem;opacity:.85;margin-top:.2rem;">Total Dana Deposito</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#059669;">{{ $totalAktif }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Deposito Aktif</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#d97706;">{{ $totalJatuhTempo }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Jatuh Tempo</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.4rem;font-weight:700;color:#94a3b8;">{{ $totalDitutup }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Ditutup</div>
        </div>
    </div>

    {{-- Filter --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1rem 1.25rem;">
        <form method="GET" action="{{ route('teller.deposito.index') }}"
              style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <label style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Cari Anggota / No. Rekening</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Nama, No. Anggota, No. Rekening…"
                       style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;box-sizing:border-box;">
            </div>
            <div style="min-width:160px;">
                <label style="display:block;font-size:.76rem;font-weight:600;color:#475569;margin-bottom:.375rem;">Status</label>
                <select name="status" style="width:100%;padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;">
                    <option value="">Semua Status</option>
                    <option value="Aktif" {{ request('status') === 'Aktif' ? 'selected' : '' }}>Aktif</option>
                    <option value="Jatuh Tempo" {{ request('status') === 'Jatuh Tempo' ? 'selected' : '' }}>Jatuh Tempo</option>
                    <option value="Ditutup" {{ request('status') === 'Ditutup' ? 'selected' : '' }}>Ditutup</option>
                </select>
            </div>
            <div style="display:flex;gap:.5rem;">
                <button type="submit"
                        style="padding:.5rem 1.125rem;background:#059669;color:#fff;font-size:.825rem;font-weight:600;border:none;border-radius:.5rem;cursor:pointer;">
                    Cari
                </button>
                @if(request('search') || request('status'))
                <a href="{{ route('teller.deposito.index') }}"
                   style="display:inline-flex;align-items:center;padding:.5rem .875rem;background:#f1f5f9;color:#64748b;font-size:.825rem;font-weight:600;border-radius:.5rem;text-decoration:none;">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Tabel --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:.825rem;color:#64748b;">
                <strong style="color:#1e293b;">{{ $deposito->total() }}</strong> rekening ditemukan
            </span>
            <span style="font-size:.75rem;color:#94a3b8;">Hal. {{ $deposito->currentPage() }} / {{ $deposito->lastPage() }}</span>
        </div>

        @if($deposito->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:.75rem 1.25rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">No. Rekening</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Anggota</th>
                        <th style="padding:.75rem 1rem;text-align:right;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Nominal</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Nisbah</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Dibuka</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Jatuh Tempo</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Status</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-size:.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($deposito as $d)
                    @php
                        $hariSisa = $d->tanggal_jatuh_tempo ? now()->diffInDays($d->tanggal_jatuh_tempo, false) : null;
                        $statusColor = match($d->status_rekening) {
                            'Aktif'       => ['bg' => '#d1fae5', 'text' => '#065f46'],
                            'Jatuh Tempo' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                            'Ditutup'     => ['bg' => '#f1f5f9', 'text' => '#64748b'],
                            default       => ['bg' => '#f1f5f9', 'text' => '#64748b'],
                        };
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:.8rem;font-weight:600;color:#7c3aed;background:#ede9fe;padding:.2rem .5rem;border-radius:.375rem;">
                                {{ $d->no_rekening }}
                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;">
                            <div style="font-size:.875rem;font-weight:600;color:#1e293b;">{{ $d->anggota?->nama_lengkap ?? '—' }}</div>
                            <div style="font-size:.75rem;color:#94a3b8;">{{ $d->anggota?->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:right;">
                            <span style="font-size:.875rem;font-weight:700;color:#059669;">
                                Rp {{ number_format($d->saldo, 0, ',', '.') }}
                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <span style="font-size:.8rem;color:#374151;">
                                {{ $d->nisbah_nasabah ?? 60 }}% / {{ $d->nisbah_bank ?? 40 }}%
                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;font-size:.825rem;color:#64748b;">
                            {{ $d->tanggal_buka?->format('d M Y') ?? '—' }}
                        </td>
                        <td style="padding:.875rem 1rem;">
                            <div style="font-size:.825rem;color:#374151;">
                                {{ $d->tanggal_jatuh_tempo?->format('d M Y') ?? '—' }}
                            </div>
                            @if($hariSisa !== null && $d->status_rekening === 'Aktif')
                                @if($hariSisa <= 7 && $hariSisa >= 0)
                                <div style="font-size:.72rem;color:#d97706;font-weight:600;">⚠ {{ $hariSisa }} hari lagi</div>
                                @elseif($hariSisa < 0)
                                <div style="font-size:.72rem;color:#dc2626;font-weight:600;">Lewat {{ abs((int)$hariSisa) }} hari</div>
                                @endif
                            @endif
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;padding:.2rem .65rem;
                                         background:{{ $statusColor['bg'] }};color:{{ $statusColor['text'] }};
                                         font-size:.75rem;font-weight:600;border-radius:9999px;">
                                {{ $d->status_rekening }}
                            </span>
                        </td>
                        <td style="padding:.875rem 1rem;text-align:center;">
                            <a href="{{ route('teller.deposito.show', $d->id) }}"
                               style="display:inline-flex;align-items:center;padding:.35rem .75rem;background:#f1f5f9;color:#374151;font-size:.78rem;font-weight:600;border-radius:.375rem;text-decoration:none;">
                                Detail
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($deposito->hasPages())
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $deposito->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="font-size:.95rem;font-weight:600;color:#1e293b;margin-bottom:.375rem;">Belum ada data deposito</div>
            <p style="font-size:.825rem;color:#94a3b8;">Belum ada rekening simpanan berjangka yang sesuai filter.</p>
            <a href="{{ route('teller.deposito') }}"
               style="display:inline-block;margin-top:1rem;padding:.5rem 1.25rem;background:#059669;color:#fff;font-size:.85rem;font-weight:600;border-radius:.5rem;text-decoration:none;">
                + Buka Deposito Pertama
            </a>
        </div>
        @endif
    </div>

</div>
@endsection
