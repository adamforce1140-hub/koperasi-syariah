@extends('layouts.app')

@section('title', 'Kinerja Seluruh Unit — ' . config('app.name'))
@section('page-title', 'Kinerja Seluruh Unit / Cabang')

@section('styles')
<style>
    /* Kartu statistik memakai definisi tunggal di layouts/app.blade.php. */

    .kin-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(300px,1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
    .kin-wrap { background: #fff; border-radius: 1rem; padding: 1.4rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; min-width: 0; }
    .kin-title { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-bottom: 1rem; }
    .kin-sub { font-size: 0.72rem; color: #94a3b8; font-weight: 400; margin-left: 0.5rem; }

    .bar-row { display: flex; align-items: center; gap: 0.75rem; font-size: 0.8rem; margin-bottom: 0.55rem; }
    .bar-label { width: 130px; flex-shrink: 0; color: #475569; font-weight: 600;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .bar-track { flex: 1; height: 9px; border-radius: 999px; background: #f1f5f9; overflow: hidden; min-width: 40px; }
    .bar-fill  { display: block; height: 100%; border-radius: 999px; }
    .bar-val   { width: 120px; text-align: right; color: #0f172a; font-weight: 600; white-space: nowrap; }
    .bar-akad  { width: 64px; text-align: right; color: #94a3b8; }

    @media (max-width: 992px) { .kin-grid { grid-template-columns: 1fr; } }
    @media (max-width: 600px) {
        .kin-wrap { padding: 1rem; }
        .bar-label { width: 96px; }
        .bar-val { width: 92px; }
    }
</style>
@endsection

@section('content')
@php
    $ringkasRp = function ($angka) {
        $angka = (float) $angka;
        if (abs($angka) >= 1_000_000_000) return 'Rp ' . number_format($angka / 1_000_000_000, 2, ',', '.') . ' M';
        if (abs($angka) >= 1_000_000)     return 'Rp ' . number_format($angka / 1_000_000, 1, ',', '.') . ' jt';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    };

    // Skala batang relatif terhadap nilai terbesar pada tiap panel.
    $maksProduk   = max(1, (float) ($perProduk->max('total') ?? 0));
    $maksAO       = max(1, (float) ($perAO->max('total') ?? 0));
    $maksSimpanan = max(1, (float) ($perSimpanan->max('saldo') ?? 0));
@endphp

@include('dashboard.partials.sapaan', [
    'peran' => 'Super Admin',
    'tema'  => 'ungu',
    'badge' => 'KINERJA UNIT',
    'badgeKetika' => now()->translatedFormat('d M Y'),
])

<div class="stats-grid" style="margin-bottom:1.75rem;">
    <div class="stat-card">
        <div class="stat-icon purple">📦</div>
        <div class="stat-info">
            <div class="stat-value">{{ number_format($ringkasan['totalProduk'] ?? 0, 0, ',', '.') }}</div>
            <div class="stat-label">Produk Pembiayaan Berjalan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">🧑‍💼</div>
        <div class="stat-info">
            <div class="stat-value">{{ number_format($ringkasan['totalAO'] ?? 0, 0, ',', '.') }}</div>
            <div class="stat-label">Account Officer Aktif Menyalurkan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🏦</div>
        <div class="stat-info">
            <div class="stat-value">{{ number_format($ringkasan['totalCabangTipe'] ?? 0, 0, ',', '.') }}</div>
            <div class="stat-label">Jenis Simpanan Terhimpun</div>
        </div>
    </div>
</div>

<div class="kin-grid">
    {{-- (a) Kinerja per produk pembiayaan --}}
    <div class="kin-wrap">
        <div class="kin-title">Penyaluran per Produk Pembiayaan<span class="kin-sub">seluruh akad cair</span></div>
        @forelse($perProduk as $p)
            <div class="bar-row">
                <span class="bar-label" title="{{ $p['nama'] }}">{{ $p['nama'] }}</span>
                <span class="bar-track">
                    <span class="bar-fill" style="width:{{ round($p['total'] / $maksProduk * 100, 1) }}%;background:#7c3aed;"></span>
                </span>
                <span class="bar-val">{{ $ringkasRp($p['total']) }}</span>
                <span class="bar-akad">{{ $p['akad'] }} akad</span>
            </div>
        @empty
            <div style="color:#94a3b8;font-size:0.82rem;padding:0.5rem 0;">Belum ada pembiayaan cair.</div>
        @endforelse
    </div>

    {{-- (b) Kinerja per Account Officer --}}
    <div class="kin-wrap">
        <div class="kin-title">Kinerja per Account Officer<span class="kin-sub">total penyaluran</span></div>
        @forelse($perAO as $ao)
            <div class="bar-row">
                <span class="bar-label" title="{{ $ao['nama'] }}">{{ $ao['nama'] }}</span>
                <span class="bar-track">
                    <span class="bar-fill" style="width:{{ round($ao['total'] / $maksAO * 100, 1) }}%;background:#2563eb;"></span>
                </span>
                <span class="bar-val">{{ $ringkasRp($ao['total']) }}</span>
                <span class="bar-akad">{{ $ao['akad'] }} akad</span>
            </div>
        @empty
            <div style="color:#94a3b8;font-size:0.82rem;padding:0.5rem 0;">Belum ada penyaluran per AO.</div>
        @endforelse
    </div>

    {{-- (c) Kinerja per jenis simpanan --}}
    <div class="kin-wrap">
        <div class="kin-title">Penghimpunan per Jenis Simpanan<span class="kin-sub">saldo aktif</span></div>
        @forelse($perSimpanan as $s)
            <div class="bar-row">
                <span class="bar-label" title="{{ $s['nama'] }}">{{ $s['nama'] }}</span>
                <span class="bar-track">
                    <span class="bar-fill" style="width:{{ round($s['saldo'] / $maksSimpanan * 100, 1) }}%;background:#16a34a;"></span>
                </span>
                <span class="bar-val">{{ $ringkasRp($s['saldo']) }}</span>
                <span class="bar-akad"></span>
            </div>
        @empty
            <div style="color:#94a3b8;font-size:0.82rem;padding:0.5rem 0;">Belum ada saldo simpanan.</div>
        @endforelse
    </div>
</div>

<div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
    <a href="{{ route('dashboard.super-admin') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.45rem 0.9rem;">
        ← Kembali ke Dashboard
    </a>
    <a href="{{ route('laporan.psak101') }}" class="btn" style="font-size:0.8rem;padding:0.45rem 0.9rem;background:#f1f5f9;color:#334155;">
        Laporan Keuangan Syariah
    </a>
</div>
@endsection
