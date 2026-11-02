@extends('layouts.app')

@section('title', 'Pembiayaan Bermasalah (NPF) — Manajer')
@section('page-title', 'Pembiayaan Bermasalah / NPF')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Pembiayaan Bermasalah / NPF
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Monitoring nasabah yang menunggak dan mitigasi risiko kredit.
        </p>
    </div>

    {{-- ── ALERT BANNER ─────────────────────────────────── --}}
    @if($totalNpf > 0)
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.75rem;
                padding:0.875rem 1.25rem;display:flex;align-items:center;gap:0.75rem;">
        <span style="font-size:1.25rem;">⚠️</span>
        <div>
            <span style="font-weight:600;color:#c2410c;">{{ number_format($totalNpf) }} pembiayaan bermasalah</span>
            <span style="color:#9a3412;font-size:0.825rem;"> memerlukan tindak lanjut segera.</span>
        </div>
    </div>
    @endif

    {{-- ── FILTER ───────────────────────────────────────── --}}
    <form method="GET" action="{{ route('manajer.npf.index') }}"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama / no. anggota…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#dc2626'" onblur="this.style.borderColor='#cbd5e1'">
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#dc2626;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'">
            Cari
        </button>
        @if($search)
        <a href="{{ route('manajer.npf.index') }}"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        @endif
    </form>

    {{-- ── TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($pembiayaanBermasalah->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">No. Pembiayaan & Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Jatuh Tempo Terlama</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Hari Menunggak</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Total Nominal Tunggakan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Kolektibilitas</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pembiayaanBermasalah as $item)
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#fff1f2'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $item->no_pembiayaan ?? 'N/A' }}</div>
                            <div style="font-size:0.75rem;color:#475569;font-weight:500;">{{ $item->anggota->nama_lengkap ?? 'Anggota Tidak Ditemukan' }}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $item->anggota->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                            {{ $item->angsuran_terlama?->tanggal_jatuh_tempo?->format('d/m/Y') ?? 'N/A' }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#dc2626;white-space:nowrap;">
                            {{ $item->hari_tunggakan }} Hari
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#dc2626;white-space:nowrap;">
                            Rp {{ number_format($item->total_tunggakan ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold {{ $item->badge_color }}" style="{{ $item->badge_style }}display:inline-block;padding:0.25rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:600;">
                                {{ $item->kolektibilitas }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            <div style="display:flex;gap:0.375rem;justify-content:center;">
                                <a href="{{ route('manajer.pembiayaan.show', $item->id) }}?v={{ optional($item->updated_at)->timestamp ?? time() }}"
                                   target="_blank" rel="noopener"
                                   style="display:inline-block;padding:0.375rem 0.75rem;background:#3b82f6;color:#fff;font-size:0.75rem;font-weight:600;border-radius:0.375rem;text-decoration:none;cursor:pointer;">
                                    Lihat Detail
                                </a>
                                <a href="{{ route('manajer.approval-restrukturisasi.index') }}"
                                   style="padding:0.375rem 0.75rem;background:#eab308;color:#fff;font-size:0.75rem;font-weight:600;border-radius:0.375rem;text-decoration:none;">
                                    Ajukan Restrukturisasi
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($pembiayaanBermasalah->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $pembiayaanBermasalah->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#d1fae5;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">✅</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Tidak ada pembiayaan bermasalah</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Portofolio pembiayaan dalam kondisi sehat.</p>
        </div>
        @endif
    </div>

</div>
@endsection
