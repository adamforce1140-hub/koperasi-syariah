@extends('layouts.app')

@section('title', 'Perhitungan Bagi Hasil & SHU — Manajer')
@section('page-title', 'Perhitungan Bagi Hasil / SHU')

@section('content')

<div class="page-header" style="margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0;">📈 Distribusi Bagi Hasil & Rekap SHU</h1>
        <p style="color:#64748b;font-size:0.85rem;margin:0.25rem 0 0;">Simulasi dan rekapitulasi distribusi nisbah keuntungan pembiayaan syariah bulanan</p>
    </div>
</div>

{{-- Filter Periode --}}
<div class="card" style="margin-bottom:1.25rem;">
    <form method="GET" action="{{ route('manajer.bagi-hasil.index') }}" style="display:flex;gap:0.75rem;align-items:center;">
        <select name="bulan" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            @for($m=1; $m<=12; $m++)
                <option value="{{ $m }}" {{ $bulan == $m ? 'selected' : '' }}>
                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                </option>
            @endfor
        </select>
        <select name="tahun" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            @for($y=date('Y')-2; $y<=date('Y')+1; $y++)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
        <button type="submit" class="btn btn-primary" style="padding:0.55rem 1.25rem;">Hitung Distribusi</button>
    </form>
</div>

<div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:1.25rem;margin-bottom:1.5rem;">
    <div class="card">
        <div style="font-size:0.825rem;color:#64748b;margin-bottom:0.25rem;">Pendapatan Angsuran / Margin Masuk</div>
        <div style="font-size:1.35rem;font-weight:700;color:#059669;">Rp {{ number_format($pendapatanPembiayaan, 0, ',', '.') }}</div>
    </div>
    <div class="card">
        <div style="font-size:0.825rem;color:#64748b;margin-bottom:0.25rem;">Total Saldo Simpanan Syariah</div>
        <div style="font-size:1.35rem;font-weight:700;color:#2563eb;">Rp {{ number_format($totalSimpananMudharabah, 0, ',', '.') }}</div>
    </div>
    <div class="card">
        <div style="font-size:0.825rem;color:#64748b;margin-bottom:0.25rem;">Estimasi Alokasi Bagi Hasil Anggota</div>
        <div style="font-size:1.35rem;font-weight:700;color:#7c3aed;">
            Rp {{ number_format($pendapatanPembiayaan * 0.4, 0, ',', '.') }}
            <span style="font-size:0.75rem;font-weight:normal;color:#64748b;">(Nisbah 40%)</span>
        </div>
    </div>
</div>

<div class="card" style="padding:1.5rem;">
    <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Skema Proporsi Distribusi SHU Koperasi</h3>
    <ul style="color:#475569;font-size:0.875rem;line-height:1.8;padding-left:1.25rem;margin:0;">
        <li>Cadangan Modal Koperasi: <strong>30%</strong></li>
        <li>Jasa Modal / Simpanan Anggota: <strong>40%</strong></li>
        <li>Jasa Anggota / Transaksi Pembiayaan: <strong>20%</strong></li>
        <li>Dana Pendidikan, Pengurus, & Sosial (Ta'awun): <strong>10%</strong></li>
    </ul>
</div>

@endsection