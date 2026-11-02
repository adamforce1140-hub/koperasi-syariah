@extends('layouts.app')

@section('title', 'Pembiayaan Aktif — Manajer')
@section('page-title', 'Pembiayaan Aktif')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── FLASH MESSAGE ─────────────────────────────────
         Halaman ini dirender ulang setelah aksi pada baris pembiayaan
         (mis. perubahan status / pencatatan), sehingga tanpa blok ini hasil
         aksi tidak pernah terlihat oleh manajer. --}}
    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
        &#10003; {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
        &#9888; {{ session('error') }}
    </div>
    @endif
    @if($errors->any())
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;" role="alert">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
    @endif

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Pembiayaan Aktif
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Daftar seluruh akad pembiayaan yang sedang berjalan.
        </p>
    </div>

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Pembiayaan Aktif', 'value' => $totalAktif, 'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '📂'],
            ['label' => 'Sudah Lunas',       'value' => $totalLunas, 'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
        ];
        @endphp
        @foreach($cards as $c)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:40px;height:40px;border-radius:0.625rem;background:{{ $c['bg'] }};
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;">
                {{ $c['icon'] }}
            </div>
            <div>
                <div style="font-size:1.35rem;font-weight:700;color:{{ $c['color'] }};line-height:1;">
                    {{ number_format($c['value']) }}
                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">{{ $c['label'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── FILTER ───────────────────────────────────────── --}}
    <form method="GET" action="{{ route('manajer.pembiayaan-aktif.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama / no. anggota…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#047857;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
            Cari
        </button>
        @if($search)
        <a href="{{ route('manajer.pembiayaan-aktif.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        @endif
    </form>

    {{-- ── TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($pembiayaan->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Produk / Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Sisa Pokok</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Tenor</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Cair</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pembiayaan as $pb)
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">
                            {{ ($pembiayaan->currentPage() - 1) * $pembiayaan->perPage() + $loop->iteration }}
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $pb->anggota?->nama_lengkap ?? '—' }}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $pb->anggota?->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="color:#1e293b;">{{ $pb->produk?->nama_produk ?? $pb->akad_pembiayaan ?? '—' }}</div>
                            <div style="font-size:0.72rem;color:#94a3b8;">{{ $pb->akad_pembiayaan ?? $pb->produk?->akad_syariah ?? '' }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#1e293b;">
                            Rp {{ number_format($pb->jumlah_pembiayaan ?? $pb->total_pembiayaan ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;color:#dc2626;font-weight:600;">
                            Rp {{ number_format($pb->sisa_pembiayaan ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#475569;">
                            {{ $pb->tenor ?? '—' }} bln
                        </td>
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;">
                            {{-- Kolom tanggal akad pembiayaan adalah `tanggal_akad` (cast date).
                                 `tanggal_pencairan` bukan kolom tabel pembiayaan, sehingga
                                 cabang tersebut selalu jatuh ke fallback. --}}
                            {{ $pb->tanggal_akad?->format('d/m/Y') ?? '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($pembiayaan->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $pembiayaan->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">📂</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada pembiayaan aktif</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tidak ada akad pembiayaan yang sedang berjalan.</p>
        </div>
        @endif
    </div>

</div>
@endsection
