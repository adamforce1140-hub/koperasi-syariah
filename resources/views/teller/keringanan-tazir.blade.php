@extends('layouts.app')

@section('title', "Keringanan Ta'zir — Teller")
@section('page-title', "Riwayat Keringanan Ta'zir")

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Riwayat Pengajuan Keringanan Ta'zir
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Pantau status pengajuan keringanan denda (ta'zir) yang Anda ajukan — alur Teller → AO → Manajer.
        </p>
    </div>

    {{-- ── FLASH ───────────────────────────────────────── --}}
    @foreach(['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️']] as $type=>$s)
    @if(session($type))
    <div style="padding:0.875rem 1.25rem;background:{{ $s[0] }};border:1px solid {{ $s[1] }};border-radius:0.625rem;color:{{ $s[2] }};font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span>{{ $s[3] }}</span> {{ session($type) }}
    </div>
    @endif
    @endforeach

    {{-- ── CARA MENGAJUKAN (ALUR BARU) ─────────────────── --}}
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.875rem;padding:1.25rem 1.5rem;
                display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div>
            <div style="font-size:0.95rem;font-weight:700;color:#1e40af;">Cara mengajukan keringanan ta'zir</div>
            <div style="font-size:0.825rem;color:#1e3a8a;margin-top:0.2rem;">
                Buka menu <strong>Bayar Angsuran</strong>, lalu centang
                <strong>“Ajukan Keringanan / Pembebasan Ta'zir”</strong> dan isi alasan.
                Pokok &amp; margin tetap dibayar, komponen ta'zir ditangguhkan hingga ada keputusan AO &amp; Manajer.
            </div>
        </div>
        <a href="{{ route('teller.angsuran') }}"
           style="padding:0.6rem 1.25rem;background:#2563eb;color:#fff;border-radius:0.5rem;
                  font-size:0.825rem;font-weight:600;text-decoration:none;white-space:nowrap;">
            Bayar Angsuran
        </a>
    </div>

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Menunggu',  'value' => $totalPending,  'color' => '#d97706', 'bg' => '#fef3c7', 'icon' => '⏳'],
            ['label' => 'Disetujui', 'value' => $totalApproved, 'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
            ['label' => 'Ditolak',   'value' => $totalRejected, 'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => '❌'],
        ];
        @endphp
        @foreach($cards as $c)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;
                    display:flex;align-items:center;gap:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:38px;height:38px;border-radius:0.5rem;background:{{ $c['bg'] }};
                        display:flex;align-items:center;justify-content:center;font-size:1rem;">{{ $c['icon'] }}</div>
            <div>
                <div style="font-size:1.25rem;font-weight:700;color:{{ $c['color'] }};">{{ $c['value'] }}</div>
                <div style="font-size:0.75rem;color:#64748b;">{{ $c['label'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── FILTER STATUS ────────────────────────────────── --}}
    <form method="GET" action="{{ route('teller.keringanan-tazir.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
            <option value="">Semua Status</option>
            <option value="PENDING_AO"      {{ $status === 'PENDING_AO'      ? 'selected' : '' }}>Menunggu Verifikasi AO</option>
            <option value="PENDING_MANAJER" {{ $status === 'PENDING_MANAJER' ? 'selected' : '' }}>Menunggu Approval Manajer</option>
            <option value="APPROVED"        {{ $status === 'APPROVED'        ? 'selected' : '' }}>Disetujui</option>
            <option value="REJECTED"        {{ $status === 'REJECTED'        ? 'selected' : '' }}>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1rem;background:#2563eb;color:#fff;border:none;border-radius:0.5rem;
                       font-size:0.825rem;font-weight:600;cursor:pointer;">Filter</button>
        @if($status)
        <a href="{{ route('teller.keringanan-tazir.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">Reset</a>
        @endif
    </form>

    {{-- ── RIWAYAT PENGAJUAN ────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($keringanans->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Tgl Pengajuan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No Pembiayaan</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Ta'zir Diajukan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Alasan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($keringanans as $k)
                @php
                $statusStyle = match($k->status) {
                    'PENDING_AO', 'PENDING_MANAJER' => 'background:#fef3c7;color:#92400e;',
                    'APPROVED'                      => 'background:#d1fae5;color:#065f46;',
                    'REJECTED'                      => 'background:#fee2e2;color:#991b1b;',
                    default                         => 'background:#f1f5f9;color:#475569;',
                };
                $statusLabel = match($k->status) {
                    'PENDING_AO'      => '⏳ Menunggu AO',
                    'PENDING_MANAJER' => '⏳ Menunggu Manajer',
                    'APPROVED'        => '✅ Disetujui',
                    'REJECTED'        => '❌ Ditolak',
                    default           => $k->status,
                };
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        {{ $k->created_at?->format('d/m/Y H:i') }}
                    </td>
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;">{{ $k->anggota->nama_lengkap ?? '-' }}</div>
                        <div style="font-size:0.75rem;color:#64748b;">{{ $k->anggota->no_anggota ?? '-' }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        {{ $k->pembiayaan->no_pembiayaan ?? '-' }}
                        <div style="font-size:0.75rem;color:#94a3b8;">Ang. ke-{{ $k->angsuran->angsuran_ke ?? '?' }}</div>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#dc2626;white-space:nowrap;">
                        Rp {{ number_format((float) $k->nominal_tazir_awal, 0, ',', '.') }}
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;max-width:200px;">
                        {{ \Illuminate\Support\Str::limit($k->alasan_teller, 70) }}
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.25rem 0.625rem;border-radius:9999px;font-size:0.75rem;font-weight:600;{{ $statusStyle }}">
                            {{ $statusLabel }}
                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#64748b;max-width:220px;">
                        @if($k->catatan_manajer)
                            {{ \Illuminate\Support\Str::limit($k->catatan_manajer, 60) }}
                            <div style="font-size:0.75rem;color:#94a3b8;">— {{ $k->manajer->name ?? '-' }}</div>
                        @elseif($k->catatan_ao)
                            {{ \Illuminate\Support\Str::limit($k->catatan_ao, 60) }}
                            <div style="font-size:0.75rem;color:#94a3b8;">— AO: {{ $k->ao->name ?? '-' }}</div>
                        @else
                            <span style="color:#cbd5e1;">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($keringanans->hasPages())
        <div style="padding:0.875rem 1rem;border-top:1px solid #f1f5f9;">
            {{ $keringanans->links() }}
        </div>
        @endif
        @else
        <div style="padding:3rem;text-align:center;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
            <div style="font-weight:600;color:#64748b;">Belum ada pengajuan keringanan</div>
            <div style="font-size:0.825rem;margin-top:0.25rem;">
                @if($status) Tidak ada pengajuan dengan status tersebut. @else Ajukan melalui menu Bayar Angsuran. @endif
            </div>
        </div>
        @endif
    </div>

</div>
@endsection
