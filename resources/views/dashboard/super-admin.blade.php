@extends('layouts.app')

@section('title', 'Dashboard Super Admin — ' . config('app.name'))
@section('page-title', 'Dashboard Super Admin')

@section('styles')
<style>
    /* Kartu statistik memakai definisi tunggal di layouts/app.blade.php
       (.stats-grid, .stat-card, .stat-icon, .stat-value, .stat-label) agar
       konsisten dengan seluruh dashboard lain — tidak didefinisikan ulang. */

    /* ── BARIS GRAFIK ───────────────────────────────────────── */
    .chart-row   { display: grid; grid-template-columns: repeat(auto-fit,minmax(280px,1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
    .chart-row-2 { display: grid; grid-template-columns: repeat(auto-fit,minmax(230px,1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
    .chart-wrap  { background: #fff; border-radius: 1rem; padding: 1.4rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; min-width: 0; }
    .chart-title { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-bottom: 1rem; }
    .chart-sub   { font-size: 0.72rem; color: #94a3b8; font-weight: 400; margin-left: 0.5rem; }
    .chart-canvas{ position: relative; width: 100%; height: 260px; }
    .chart-canvas--donut { height: 220px; }

    /* ── PANEL ANTREAN PERSETUJUAN (notifikasi) ─────────────── */
    .notif-list { display: grid; grid-template-columns: repeat(auto-fit,minmax(210px,1fr)); gap: 0.875rem; }
    .notif-card { display: flex; align-items: center; gap: 0.85rem; padding: 0.95rem 1.05rem;
        border-radius: 0.85rem; background: #fff; border: 1px solid #e2e8f0;
        text-decoration: none; color: inherit; transition: transform 0.15s, box-shadow 0.15s; }
    .notif-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
    .notif-card--kosong { opacity: 0.62; }
    .notif-icon { font-size: 1.5rem; line-height: 1; flex-shrink: 0; }
    .notif-body { min-width: 0; }
    .notif-jumlah { font-size: 1.35rem; font-weight: 700; color: #1e293b; line-height: 1.1; }
    .notif-label { font-size: 0.74rem; color: #64748b; margin-top: 0.15rem; }
    .notif-card[data-warna="red"]    .notif-jumlah { color: #dc2626; }
    .notif-card[data-warna="amber"]  .notif-jumlah { color: #d97706; }
    .notif-card[data-warna="blue"]   .notif-jumlah { color: #2563eb; }
    .notif-card[data-warna="purple"] .notif-jumlah { color: #7c3aed; }

    /* ── PANEL KEUANGAN SYARIAH ─────────────────────────────── */
    .fin-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(190px,1fr)); gap: 0.875rem; }
    .fin-cell { padding: 0.95rem 1.05rem; border-radius: 0.85rem; background: #f8fafc; border: 1px solid #eef2f7; }
    .fin-cap  { font-size: 0.72rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
    .fin-val  { font-size: 1.2rem; font-weight: 700; color: #0f172a; margin-top: 0.3rem; }
    .fin-note { font-size: 0.7rem; color: #94a3b8; margin-top: 0.2rem; }
    .badge-patuh   { background: #dcfce7; color: #166534; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; }
    .badge-temuan  { background: #fee2e2; color: #b91c1c; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 700; }

    /* ── DONUT LEGEND ───────────────────────────────────────── */
    .donut-legend { display: flex; flex-wrap: wrap; gap: 0.5rem 1.1rem; margin-top: 1rem; }
    .legend-item  { display: flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; color: #475569; }
    .legend-dot   { width: 11px; height: 11px; border-radius: 50%; flex-shrink: 0; display: inline-block; }

    /* ── MINI GRID SISTEM ───────────────────────────────────── */
    .sys-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(150px,1fr)); gap: 0.75rem; }
    .sys-cell { padding: 0.8rem 0.9rem; border-radius: 0.75rem; background: #f8fafc; border: 1px solid #eef2f7; text-align: center; }
    .sys-val  { font-size: 1.25rem; font-weight: 700; color: #7c3aed; }
    .sys-cap  { font-size: 0.72rem; color: #64748b; margin-top: 0.15rem; }
    .peran-list { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.9rem; }
    .peran-chip { font-size: 0.72rem; background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe;
        padding: 0.25rem 0.6rem; border-radius: 999px; font-weight: 600; }

    @media (max-width: 992px) { .chart-row, .chart-row-2 { grid-template-columns: 1fr; } }
    @media (max-width: 768px) { .chart-wrap { padding: 1.1rem; } .chart-canvas { height: 230px; } }
    @media (max-width: 600px) { .chart-wrap { padding: 0.95rem; } .notif-list { grid-template-columns: 1fr; } }

    .chart-wrap canvas:focus-visible { outline: 2px solid #7c3aed; outline-offset: 2px; border-radius: 4px; }
</style>
@endsection

@section('content')
@php
    // Format Rupiah ringkas untuk kartu besar (juta/miliar) agar tidak meluber
    // di layar kecil.
    $rp = function ($angka) {
        $angka = (float) $angka;
        if (abs($angka) >= 1_000_000_000) return 'Rp ' . number_format($angka / 1_000_000_000, 2, ',', '.') . ' M';
        if (abs($angka) >= 1_000_000)     return 'Rp ' . number_format($angka / 1_000_000, 1, ',', '.') . ' jt';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    };
    $npf = $kolektibilitas['npf'] ?? 0;
@endphp

@include('dashboard.partials.sapaan', [
    'peran' => 'Super Admin',
    'tema'  => 'ungu',
    'badge' => 'AKSES PENUH',
    'badgeKetika' => now()->translatedFormat('d M Y'),
])

{{-- ── KARTU RINGKASAN UTAMA (real-time) ───────────────────────────── --}}
<div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="stats">
    @include('dashboard.partials.super-admin-stats')
</div>

{{-- ── NOTIFIKASI: kegiatan yang menunggu persetujuan Super Admin ──── --}}
<div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="notifikasi"
     style="margin-bottom:1.75rem;">
    @include('dashboard.partials.super-admin-notifikasi')
</div>

{{-- ── GRAFIK: tren 6 bulan & komposisi kolektibilitas ─────────────── --}}
<div class="chart-row">
    <div class="chart-wrap">
        <div class="chart-title">Tren Penyaluran &amp; Angsuran<span class="chart-sub">6 bulan terakhir</span></div>
        <div class="chart-canvas"><canvas id="grafikTren" aria-label="Grafik tren pembiayaan dan angsuran 6 bulan terakhir" role="img"></canvas></div>
    </div>
    <div class="chart-wrap">
        <div class="chart-title">Kualitas Pembiayaan (Kolektibilitas)</div>
        <div class="chart-canvas chart-canvas--donut"><canvas id="grafikKolektibilitas" aria-label="Diagram donat komposisi kolektibilitas pembiayaan" role="img"></canvas></div>
        <div class="donut-legend">
            @foreach(['Lancar (Kol-1)','Perhatian Khusus (Kol-2)','Kurang Lancar (Kol-3)','Diragukan (Kol-4)','Macet (Kol-5)'] as $i => $lbl)
                <span class="legend-item">
                    <span class="legend-dot" style="background:{{ ['#16a34a','#ca8a04','#ea580c','#dc2626','#7f1d1d'][$i] }}"></span>{{ $lbl }}
                </span>
            @endforeach
        </div>
    </div>
</div>

<div class="chart-row-2">
    <div class="chart-wrap">
        <div class="chart-title">Sebaran Status Anggota</div>
        <div class="chart-canvas chart-canvas--donut"><canvas id="grafikStatus" aria-label="Diagram sebaran status anggota" role="img"></canvas></div>
    </div>
    <div class="chart-wrap">
        <div class="chart-title">Penyaluran per Produk<span class="chart-sub">top 6</span></div>
        <div class="chart-canvas"><canvas id="grafikProduk" aria-label="Diagram batang penyaluran per produk pembiayaan" role="img"></canvas></div>
    </div>
</div>

{{-- ── LAPORAN KEUANGAN SYARIAH KOMPREHENSIF (real-time) ───────────── --}}
<div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="keuangan"
     style="margin-bottom:1.75rem;">
    @include('dashboard.partials.super-admin-keuangan')
</div>

{{-- ── KUALITAS PEMBIAYAAN + PENGATURAN SISTEM ─────────────────────── --}}
<div class="chart-row">
    <div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="kolektibilitas">
        @include('dashboard.partials.super-admin-kolektibilitas')
    </div>
    <div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="sistem">
        @include('dashboard.partials.super-admin-sistem')
    </div>
</div>

{{-- ── AKTIVITAS TRANSAKSI TERAKHIR (real-time) ────────────────────── --}}
<div data-denyut-url="{{ route('dashboard.super-admin.ringkas') }}" data-denyut-sasaran="aktivitas">
    @include('dashboard.partials.super-admin-aktivitas')
</div>
@endsection

@section('scripts')
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
<script>
(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;

    const ungu = '#7c3aed';
    const emas = '#d97706';
    const rupiah = v => 'Rp ' + Number(v || 0).toLocaleString('id-ID');

    // 1) Tren 6 bulan — pembiayaan cair vs angsuran.
    const elTren = document.getElementById('grafikTren');
    if (elTren) {
        new Chart(elTren, {
            type: 'line',
            data: {
                labels: {!! $grafikBulanLabels !!},
                datasets: [
                    { label: 'Pembiayaan Cair', data: {!! $grafikPembiayaan !!},
                      borderColor: ungu, backgroundColor: 'rgba(124,58,237,0.10)',
                      borderWidth: 2.5, pointRadius: 4, pointHoverRadius: 6, fill: true, tension: 0.35 },
                    { label: 'Angsuran Masuk', data: {!! $grafikAngsuran !!},
                      borderColor: emas, backgroundColor: 'rgba(217,119,6,0.08)',
                      borderWidth: 2.5, pointRadius: 4, pointHoverRadius: 6, fill: false, tension: 0.35 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + rupiah(c.parsed.y) } } },
                scales: { x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' },
                         ticks: { font: { size: 11 }, callback: v => new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(v) } } },
            },
        });
    }

    // 2) Donut komposisi kolektibilitas.
    const elKol = document.getElementById('grafikKolektibilitas');
    if (elKol) {
        new Chart(elKol, {
            type: 'doughnut',
            data: { labels: {!! $grafikKolLabel !!},
                datasets: [{ data: {!! $grafikKolData !!}, backgroundColor: {!! $grafikKolWarna !!}, borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: c => ' ' + c.label + ': ' + rupiah(c.parsed) } } } },
        });
    }

    // 3) Donut sebaran status anggota.
    const elStatus = document.getElementById('grafikStatus');
    if (elStatus) {
        new Chart(elStatus, {
            type: 'doughnut',
            data: { labels: {!! $grafikStatusLabel !!},
                datasets: [{ data: {!! $grafikStatusData !!},
                    backgroundColor: ['#16a34a','#2563eb','#94a3b8','#dc2626','#7c3aed','#d97706'], borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        });
    }

    // 4) Batang penyaluran per produk.
    const elProduk = document.getElementById('grafikProduk');
    if (elProduk) {
        new Chart(elProduk, {
            type: 'bar',
            data: { labels: {!! $grafikProdukLabel !!},
                datasets: [{ label: 'Penyaluran', data: {!! $grafikProdukData !!},
                    backgroundColor: 'rgba(124,58,237,0.75)', borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: c => ' ' + rupiah(c.parsed.y) } } },
                scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' },
                         ticks: { font: { size: 11 }, callback: v => new Intl.NumberFormat('id-ID', { notation: 'compact' }).format(v) } } } },
        });
    }
})();
</script>
@endsection
