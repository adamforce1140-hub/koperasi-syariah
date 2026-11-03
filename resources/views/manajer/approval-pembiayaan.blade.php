@extends('layouts.app')

@section('title', 'Approval Pembiayaan — Manajer')
@section('page-title', 'Approval Pembiayaan')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── FLASH MESSAGE ─────────────────────────────────
         Halaman ini adalah tujuan redirect aksi approval; tanpa blok ini
         pesan sukses/kegagalan dari controller tidak pernah terlihat
         (umpan balik senyap). --}}
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
    @if(session('warning'))
    <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;">
        &#9888; {{ session('warning') }}
    </div>
    @endif
    @if($errors->any())
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
    @endif

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Approval Pembiayaan
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Tinjau dan setujui pengajuan pembiayaan dari anggota koperasi.
        </p>
    </div>

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Menunggu Approval', 'value' => $totalMenunggu,  'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '⏳'],
            ['label' => 'Disetujui',          'value' => $totalDisetujui, 'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
            ['label' => 'Ditolak',            'value' => $totalDitolak,   'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '❌'],
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
    <form method="GET" action="{{ route('manajer.approval-pembiayaan.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="{{ $search }}" aria-label="Cari nama / no. anggota" placeholder="Cari nama / no. anggota…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
            <option value="">Semua Status</option>
            <option value="pending_manajer"  {{ $status === 'pending_manajer'  ? 'selected' : '' }}>Menunggu Manajer</option>
            <option value="pending_pengurus" {{ $status === 'pending_pengurus' ? 'selected' : '' }}>Menunggu Pengurus</option>
            <option value="pending_dps"      {{ $status === 'pending_dps'      ? 'selected' : '' }}>Menunggu DPS</option>
            <option value="disetujui"        {{ $status === 'disetujui'        ? 'selected' : '' }}>Disetujui</option>
            <option value="dicairkan"        {{ $status === 'dicairkan'        ? 'selected' : '' }}>Dicairkan</option>
            <option value="ditolak"          {{ $status === 'ditolak'          ? 'selected' : '' }}>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#047857;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
            Filter
        </button>
        @if($search || $status)
        <a href="{{ route('manajer.approval-pembiayaan.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        @endif
    </form>

    {{-- ── TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($pengajuan->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Produk</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Tenor</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Pengajuan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pengajuan as $p)
                    @php
                        $badge = match($p->status_pembiayaan) {
                            'pending_manajer'  => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => '⏳ Menunggu Manajer'],
                            'pending_pengurus' => ['bg' => '#ede9fe', 'color' => '#6d28d9', 'label' => '⏳ Menunggu Pengurus'],
                            'pending_dps'      => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'label' => '⏳ Menunggu DPS'],
                            'disetujui'        => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => '✅ Disetujui'],
                            'dicairkan'        => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'label' => '💵 Dicairkan'],
                            'ditolak'          => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => '❌ Ditolak'],
                            default            => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => ucfirst($p->status_pembiayaan ?? 'Menunggu')],
                        };
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">
                            {{ ($pengajuan->currentPage() - 1) * $pengajuan->perPage() + $loop->iteration }}
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            @if($p->anggota)
                            <a href="{{ route('manajer.cif.show', $p->anggota->id) }}"
                               style="font-weight:600;color:#2563eb;text-decoration:none;"
                               title="Lihat portofolio anggota"
                               onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                                {{ $p->anggota->nama_lengkap }}
                            </a>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $p->anggota->no_anggota }}</div>
                            @else
                            <div style="font-weight:600;color:#94a3b8;">—</div>
                            @endif
                        </td>
                        <td style="padding:0.75rem 1rem;color:#475569;">
                            {{ $p->akad_pembiayaan ?? '—' }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#1e293b;">
                            Rp {{ number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#475569;">
                            {{ $p->tenor ?? '—' }} bln
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.625rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:600;
                                         background:{{ $badge['bg'] }};color:{{ $badge['color'] }};">
                                {{ $badge['label'] }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;">
                            {{ $p->created_at?->format('d/m/Y') ?? '—' }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            <a href="{{ route('manajer.approval-pembiayaan.show', $p->id) }}"
                               style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.875rem;
                                      background:#047857;color:#fff;font-size:0.75rem;font-weight:600;
                                      border-radius:0.45rem;text-decoration:none;"
                               onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
                                🔍 Tinjau
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($pengajuan->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $pengajuan->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">📋</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Tidak ada pengajuan pembiayaan</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Belum ada data yang sesuai dengan filter.</p>
            @if($search || $status)
            <a href="{{ route('manajer.approval-pembiayaan.index') }}"
               style="display:inline-flex;align-items:center;padding:0.5rem 1.125rem;background:#047857;
                      color:#fff;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;margin-top:0.5rem;">
                Reset Filter
            </a>
            @endif
        </div>
        @endif
    </div>

</div>
@endsection
