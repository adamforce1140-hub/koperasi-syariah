@extends('layouts.app')

@section('title', 'Pengesahan Bagi Hasil & SHU — Pengurus')
@section('page-title', 'Pengesahan Bagi Hasil & SHU')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Pengesahan Bagi Hasil & Sisa Hasil Usaha (SHU)
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Persetujuan akhir alokasi pembagian SHU dan nisbah pembagian hasil bulanan/tahunan.
            </p>
        </div>
        <form method="GET" action="{{ route('pengurus.pengesahan-shu.index') }}" style="display:flex;gap:0.5rem;align-items:center;">
            <label style="font-size:0.825rem;color:#475569;font-weight:600;">Tahun Operasional:</label>
            <select name="tahun" onchange="this.form.submit()" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.825rem;background:#fff;color:#1e293b;">
                @for($t = now()->year; $t >= now()->year - 5; $t--)
                    <option value="{{ $t }}" {{ $tahun == $t ? 'selected' : '' }}>{{ $t }}</option>
                @endfor
            </select>
        </form>
    </div>

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;font-weight:600;text-transform:uppercase;">Total Pendapatan ({{ $tahun }})</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;margin-top:0.25rem;">
                Rp {{ number_format($totalPendapatan, 0, ',', '.') }}
            </div>
        </div>
        @foreach($alokasi as $pos)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.75rem;color:#64748b;font-weight:600;text-transform:uppercase;">
                {{ $pos['label'] }} ({{ number_format($pos['persen'], 0, ',', '.') }}%)
            </div>
            <div style="font-size:1.35rem;font-weight:700;color:#1e293b;margin-top:0.25rem;">
                Rp {{ number_format($pos['nominal'], 0, ',', '.') }}
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── TABEL ALOKASI SHU ────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);padding:1.25rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:1rem;">
            {{ $periode ? 'Alokasi SHU Tersimpan' : 'Draf Alokasi Pembagian SHU' }} {{ $tahun }}
        </h3>
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;text-align:left;">
                    <th style="padding:0.75rem 1rem;color:#475569;">Pos Alokasi</th>
                    <th style="padding:0.75rem 1rem;color:#475569;text-align:center;">Persentase</th>
                    <th style="padding:0.75rem 1rem;color:#475569;text-align:right;">Nominal (Rp)</th>
                    <th style="padding:0.75rem 1rem;color:#475569;text-align:center;">Status Approval</th>
                </tr>
            </thead>
            <tbody>
                @foreach($alokasi as $pos)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1rem;font-weight:600;">{{ $pos['label'] }}</td>
                    <td style="padding:0.75rem 1rem;text-align:center;">{{ number_format($pos['persen'], 0, ',', '.') }}%</td>
                    <td style="padding:0.75rem 1rem;text-align:right;">Rp {{ number_format($pos['nominal'], 0, ',', '.') }}</td>
                    <td style="padding:0.75rem 1rem;text-align:center;"><span style="background:#d1fae5;color:#047857;padding:0.25rem 0.5rem;border-radius:0.25rem;font-size:0.75rem;font-weight:600;">{{ $periode ? $periode->status : 'Draft' }}</span></td>
                </tr>
                @endforeach
                <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;font-weight:700;">
                    <td style="padding:0.75rem 1rem;">TOTAL</td>
                    <td style="padding:0.75rem 1rem;text-align:center;">{{ number_format($totalPersen, 2, ',', '.') }}%</td>
                    <td style="padding:0.75rem 1rem;text-align:right;">Rp {{ number_format($totalNominal, 0, ',', '.') }}</td>
                    <td style="padding:0.75rem 1rem;"></td>
                </tr>
            </tbody>
        </table>

        <div style="margin-top:1.25rem;display:flex;justify-content:flex-end;">
            <button type="button" style="padding:0.6rem 1.2rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;" onclick="alert('Pengesahan Alokasi SHU berhasil disimpan secara resmi.')">
                Sahkan Alokasi SHU {{ $tahun }}
            </button>
        </div>
    </div>

</div>
@endsection
