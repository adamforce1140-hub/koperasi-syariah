@extends('layouts.app')

@section('title', 'Audit Log — Manajer')
@section('page-title', 'Audit Log')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Audit Log
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Catatan aktivitas pengguna dan perubahan data di sistem.
            </p>
        </div>

        {{-- Filter Bulan/Tahun --}}
        <form method="GET" action="{{ route('manajer.audit.index') }}"
              style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari..."
                   style="width:160px;padding:0.45rem 0.75rem;border:1px solid #cbd5e1;
                          border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
                   onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#cbd5e1'">
            <select name="bulan"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <option value="">-- Semua Bulan --</option>
                @foreach(range(1,12) as $m)
                <option value="{{ $m }}" {{ $bulan == $m ? 'selected' : '' }}>
                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                </option>
                @endforeach
            </select>
            <select name="tahun"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <option value="">-- Semua Tahun --</option>
                @foreach(range(now()->year - 2, now()->year) as $y)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
            <button type="submit"
                    style="padding:0.45rem 1rem;background:#6366f1;color:#fff;font-size:0.825rem;
                           font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='#6366f1'">
                Filter
            </button>
            @if($search || $bulan || $tahun)
            <a href="{{ route('manajer.audit.index') }}"
               style="padding:0.45rem 0.875rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                      font-weight:600;border-radius:0.5rem;text-decoration:none;">
                Reset
            </a>
            @endif
        </form>
    </div>

    {{-- ── SUMMARY ──────────────────────────────────────── --}}
    <div style="font-size:0.825rem;color:#64748b;">
        Menampilkan <strong style="color:#1e293b;">{{ number_format($logs->total()) }}</strong> aktivitas
        @if($bulan && $tahun)
            pada periode <strong style="color:#1e293b;">{{ \Carbon\Carbon::create()->month($bulan)->translatedFormat('F') }} {{ $tahun }}</strong>
        @endif
    </div>

    {{-- ── LOG TABLE ────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($logs->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;width:50px;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Rekening & Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Jenis Transaksi</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Nominal</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Keterangan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $index => $log)
                    @php
                        // Fallback handling untuk model AuditLog maupun Transaksi legacy
                        $noRekening = $log->no_rekening 
                                    ?? $log->rekening?->no_rekening 
                                    ?? $log->pembiayaan?->no_pembiayaan 
                                    ?? '-';
                        
                        $namaAnggota = $log->rekening?->anggota?->nama_lengkap 
                                     ?? $log->anggota?->nama_lengkap 
                                     ?? $log->pembiayaan?->anggota?->nama_lengkap 
                                     ?? null;

                        $jenisTransaksi = $log->jenis_transaksi ?? '-';
                        $nominalVal     = $log->nominal ?? $log->jumlah ?? 0;
                        $keteranganVal  = $log->keterangan ?? $log->catatan ?? ("Aktivitas " . $jenisTransaksi);
                        $waktuVal       = $log->created_at ?? $log->waktu_transaksi ?? now();
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}"
                        onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background='{{ $loop->even ? '#fafafa' : '#fff' }}'">
                        <td style="padding:0.75rem 1rem;text-align:center;color:#94a3b8;">
                            {{ $logs->firstItem() + $index }}
                        </td>

                        {{-- REKENING & NAMA ANGGOTA --}}
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">
                                {{ $noRekening }}
                            </div>
                            @if($namaAnggota)
                                <div style="font-size:0.75rem;color:#64748b;">{{ $namaAnggota }}</div>
                            @endif
                        </td>

                        {{-- JENIS TRANSAKSI --}}
                        <td style="padding:0.75rem 1rem;">
                            <span style="font-size:0.72rem;font-weight:600;padding:0.2rem 0.6rem;
                                         border-radius:9999px;background:#eff6ff;color:#2563eb;">
                                {{ $jenisTransaksi }}
                            </span>
                        </td>

                        {{-- NOMINAL --}}
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;font-family:monospace;color:#1e293b;">
                            Rp {{ number_format($nominalVal, 0, ',', '.') }}
                        </td>

                        {{-- KETERANGAN --}}
                        <td style="padding:0.75rem 1rem;color:#475569;max-width:280px;">
                            <span style="display:block;white-space:normal;line-height:1.4;">
                                {{ $keteranganVal }}
                            </span>
                        </td>

                        {{-- WAKTU --}}
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;font-size:0.775rem;">
                            {{ \Carbon\Carbon::parse($waktuVal)->format('d/m/Y H:i') }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $logs->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#ede9fe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">👁️</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada catatan aktivitas transaksi pada periode ini</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Silakan sesuaikan filter pencarian atau periode tanggal.</p>
        </div>
        @endif
    </div>

</div>
@endsection
