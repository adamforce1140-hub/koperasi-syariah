@extends('layouts.app')
@section('page-title', "Approval Beban Operasional")

@section('title', 'Approval Beban Operasional — Manajer')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">
            Approval Beban Operasional
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Otorisasi pengeluaran beban di atas Rp {{ number_format($limitApproval, 0, ',', '.') }} — jurnal CoA baru terposting setelah disetujui.
        </p>
    </div>

    @if(session('success'))
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;">
        ✅ {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        ⚠️ {{ session('error') }}
    </div>
    @endif

    {{-- ── STAT ── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">⏳ Menunggu Approval</div>
            <div style="font-size:1.35rem;font-weight:700;color:#d97706;margin-top:.25rem;">Rp {{ number_format($totalPending, 0, ',', '.') }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">✅ Total Disetujui</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;margin-top:.25rem;">Rp {{ number_format($totalApproved, 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- ── TABEL ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($pengeluarans->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No / Tanggal</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Penginput</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Keterangan</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Jurnal (D / K)</th>
                        <th style="padding:.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Nominal</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pengeluarans as $item)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $item->no_pengeluaran }}</div>
                            <div style="font-size:.72rem;color:#64748b;">{{ $item->tanggal->format('d M Y') }}</div>
                        </td>
                        <td style="padding:.75rem 1rem;color:#334155;">{{ $item->penginput?->name }}</td>
                        <td style="padding:.75rem 1rem;color:#334155;">{{ $item->keterangan }}</td>
                        <td style="padding:.75rem 1rem;font-size:.75rem;">
                            <div style="color:#991b1b;font-weight:600;">D: {{ $item->coaBeban?->kode_akun }} {{ $item->coaBeban?->nama_akun }}</div>
                            <div style="color:#065f46;font-weight:600;">K: {{ $item->coaKas?->kode_akun }} {{ $item->coaKas?->nama_akun }}</div>
                        </td>
                        <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp {{ number_format($item->nominal, 0, ',', '.') }}</td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            @php
                                $badge = ['Approved' => ['#d1fae5', '#065f46', '✅ Approved'], 'Pending' => ['#fef3c7', '#92400e', '⏳ Pending'], 'Ditolak' => ['#fee2e2', '#991b1b', '❌ Ditolak']][$item->status];
                            @endphp
                            <span style="padding:.25rem .75rem;border-radius:9999px;font-size:.7rem;font-weight:700;background:{{ $badge[0] }};color:{{ $badge[1] }};">{{ $badge[2] }}</span>
                            @if($item->catatan_manajer)
                                <div style="font-size:0.7rem;color:#64748b;margin-top:.25rem;">"{{ $item->catatan_manajer }}"</div>
                            @endif
                        </td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            @if($item->isPending())
                            <form method="POST" action="{{ route('manajer.beban.approve', $item) }}" style="display:inline;">
                                @csrf
                                <button type="submit" onclick="return confirm('Setujui pengeluaran {{ $item->no_pengeluaran }} sebesar Rp {{ number_format($item->nominal, 0, ',', '.') }}? Jurnal akan langsung terposting.')"
                                    style="padding:.4rem .9rem;background:#059669;color:#fff;border:none;border-radius:.4rem;font-size:.72rem;font-weight:700;cursor:pointer;">
                                    Approve
                                </button>
                            </form>
                            <details style="margin-top:.35rem;">
                                <summary style="font-size:.7rem;color:#dc2626;cursor:pointer;list-style:none;">Tolak</summary>
                                <form method="POST" action="{{ route('manajer.beban.reject', $item) }}" style="margin-top:.35rem;">
                                    @csrf
                                    <input type="text" name="catatan" aria-label="Alasan penolakan" placeholder="Alasan penolakan" required
                                        style="width:100%;padding:.35rem .5rem;border:1px solid #cbd5e1;border-radius:.35rem;font-size:.72rem;">
                                    <button type="submit" style="margin-top:.25rem;padding:.35rem .8rem;background:#dc2626;color:#fff;border:none;border-radius:.4rem;font-size:.72rem;font-weight:700;cursor:pointer;">
                                        Konfirmasi Tolak
                                    </button>
                                </form>
                            </details>
                            @else
                                <span style="font-size:.7rem;color:#94a3b8;">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:.75rem 1rem;border-top:1px solid #e2e8f0;">
            {{ $pengeluarans->links() }}
        </div>
        @else
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:.875rem;">Belum ada pengeluaran beban.</div>
        @endif
    </div>
</div>
@endsection
