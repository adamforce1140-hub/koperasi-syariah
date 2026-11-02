@extends('layouts.app')

@section('title', 'Dashboard Pengurus — ' . config('app.name'))
@section('page-title', 'Dashboard Pengurus')

@section('styles')
<style>
         /* Kartu statistik memakai definisi tunggal di layouts/app.blade.php
            (audit D1/T4): .stats-grid, .stat-card, .stat-icon, .stat-value,
            .stat-label, .stat-info dan hover TIDAK lagi didefinisikan ulang
            di sini, supaya keenam dashboard punya tampilan yang sama. */


    /* ── CHART ROW ──────────────────────────────────────────── */
    .chart-row { display: grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
    .chart-row-2 { display: grid; grid-template-columns: repeat(auto-fit,minmax(230px,1fr)); gap: 1.25rem; margin-bottom: 1.75rem; }
    .chart-wrap { background: white; border-radius: 1rem; padding: 1.4rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; }
    .chart-title { font-size: 0.95rem; font-weight: 600; color: #1e293b; margin-bottom: 1rem; }
    .chart-subtitle { font-size: 0.75rem; color: #94a3b8; font-weight: 400; margin-left: 0.5rem; }

    /* Kanvas diberi tinggi tetap supaya Chart.js (maintainAspectRatio:false)
       tidak lagi memaksa donut menjadi persegi raksasa. */
    .chart-canvas { position: relative; width: 100%; height: 240px; }
    .chart-canvas--donut { height: 210px; }
    .chart-canvas--line { height: 220px; }

    /* status legend donut */
    .donut-legend { display: flex; flex-direction: column; gap: 0.6rem; margin-top: 1rem; }
    .legend-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.82rem; color: #475569; }
    .legend-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; display: inline-block; }

    /* Fokus keyboard yang jelas untuk aksesibilitas (audit A1). */
    .chart-wrap canvas:focus-visible,
    .legend-item:focus-visible { outline: 2px solid #d97706; outline-offset: 2px; border-radius: 4px; }

    @media (max-width: 992px) {
        .chart-row, .chart-row-2 { grid-template-columns: 1fr; }
    }

    /* Tablet kecil & ponsel: rapatkan padding kartu grafik (audit R1). */
    @media (max-width: 768px) {
        .chart-wrap { padding: 1.1rem; }
        .chart-canvas { height: 220px; }
        .chart-canvas--donut { height: 200px; }
        .chart-canvas--line { height: 200px; }
    }

    /* Ponsel: tumpuk legenda agar tetap terbaca tanpa scroll horizontal (audit R1/F3). */
    @media (max-width: 600px) {
        .chart-wrap { padding: 0.95rem; margin-bottom: 1.25rem; }
        .chart-row, .chart-row-2 { gap: 1rem; margin-bottom: 1.25rem; }
        .donut-legend { flex-direction: column; gap: 0.5rem; }
        .legend-item { font-size: 0.78rem; }
        .chart-title { font-size: 0.9rem; }
    }
</style>
@endsection

@section('content')
{{-- Salam --}}
@include('dashboard.partials.sapaan', ['peran' => 'Pengurus', 'tema' => 'emas'])

{{-- Stats --}}
<div id="pengurus-stats" data-denyut-domain="anggota pembiayaan approval"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="stats">
    @include('dashboard.partials.pengurus-stats')
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     GRAFIK RINGKASAN (dirender sekali oleh Chart.js; bukan area mesin denyut)
     Dipindahkan dari dashboard "Pengelola" yang dihapus.
═════════════════════════════════════════════════════════════════════ --}}
<div class="chart-row">
    <div class="chart-wrap">
        <div class="chart-title">
            Pembiayaan vs Angsuran
            <span class="chart-subtitle">6 bulan terakhir</span>
        </div>
        <div class="chart-canvas">
            <canvas id="barChart" role="img"
                    aria-label="Diagram batang jumlah pembiayaan dan angsuran enam bulan terakhir"></canvas>
        </div>
    </div>

    <div class="chart-wrap">
        <div class="chart-title">Status Pembiayaan</div>
        @php $statusMap = json_decode($statusPembiayaan, true) ?: []; @endphp
        <div class="chart-canvas chart-canvas--donut">
            <canvas id="donutChart" role="img"
                    aria-label="Diagram donat sebaran status pembiayaan koperasi"></canvas>
        </div>
        {{--
            Legend dirender dari SATU sumber: $urutanStatus/$labelStatus/$warnaStatus
            yang berasal dari Pembiayaan::statusMeta(). Warna titik di sini identik
            dengan warna potongan donut chart (variabel `warnaStatus` di JS),
            sehingga tidak ada lagi ketidakcocokan legend vs chart (audit F2/C2).
        --}}
        <div class="donut-legend" role="list" aria-label="Keterangan status pembiayaan">
            @forelse($urutanStatus as $i => $kunci)
                <div class="legend-item" role="listitem">
                    <span class="legend-dot" style="background:{{ $warnaStatus[$i] }};" aria-hidden="true"></span>
                    <span>{{ $labelStatus[$i] }}</span>
                    <span style="margin-left:auto;font-weight:600;color:#1e293b;">
                        {{ $statusMap[$kunci] ?? 0 }}
                    </span>
                </div>
            @empty
                <div class="legend-item" role="listitem" style="color:#94a3b8;">
                    <span>Belum ada data pembiayaan.</span>
                </div>
            @endforelse
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════════
     KARTU RINGKASAN PENAHANAN SHU (HOLD)
     Anggota yang masih menunggak Simpanan Wajib (OVERDUE) tidak menerima
     pencairan SHU-nya pada penutupan tahun buku. Sumber data tunggal:
     ShuCalculatorService::ringkasanPenahanan() — real-time, sehingga angka
     pada dashboard selalu konsisten dengan hasil kalkulasi SHU.
═════════════════════════════════════════════════════════════════════ --}}
<div id="pengurus-hold" data-denyut-domain="shu anggota"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="hold">
    @include('dashboard.partials.pengurus-hold')
</div>

{{-- Validasi konsistensi data: pengajuan Disetujui yang belum punya kontrak --}}
<div id="pengurus-validasi" data-denyut-domain="pembiayaan approval"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="validasi">
    @include('dashboard.partials.pengurus-validasi')
</div>

{{-- Transaksi terakhir + Jurnal terbaru (real-time) --}}
<div class="chart-row-2">
    <div id="pengurus-transaksi" data-denyut-domain="transaksi"
         data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="transaksi">
        @include('dashboard.partials.pengurus-transaksi')
    </div>

    <div id="pengurus-jurnal" data-denyut-domain="jurnal"
         data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="jurnal">
        @include('dashboard.partials.pengurus-jurnal')
    </div>
</div>

{{-- Tren transaksi 7 hari (chart statis) --}}
<div class="chart-wrap" style="margin-bottom:1.75rem;">
    <div class="chart-title">
        Tren Transaksi
        <span class="chart-subtitle">7 hari terakhir</span>
    </div>
    <div class="chart-canvas chart-canvas--line">
        <canvas id="lineChart" role="img"
                aria-label="Diagram garis tren jumlah transaksi tujuh hari terakhir"></canvas>
    </div>
</div>

{{-- Riwayat Approval — siapa yang menyetujui setiap transaksi --}}
<div class="card" id="pengurus-riwayat" data-denyut-domain="approval"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="riwayat">
    @include('dashboard.partials.pengurus-riwayat')
</div>

{{-- Pengajuan Pembiayaan Terbaru --}}
<div class="card" id="pengurus-pengajuan" data-denyut-domain="pembiayaan"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="pengajuan">
    @include('dashboard.partials.pengurus-pengajuan')
</div>

{{-- Limit Transaksi per Role --}}
<div id="pengurus-limit" data-denyut-domain="limit"
     data-denyut-url="{{ route('dashboard.pengurus.ringkas') }}" data-denyut-sasaran="limit">
    @include('dashboard.partials.pengurus-limit')
</div>
@endsection

@section('scripts')
{{--
    Chart.js di-HOST SENDIRI di public/js (bukan CDN). Ini menghilangkan
    ketergantungan pada pihak ketiga saat runtime, mencegah skrip berubah
    tanpa kendali (tanpa SRI yang rapuh), dan mempercepat muat karena
    disajikan dari domain yang sama (audit S1).
--}}
<script src="{{ asset('js/chart.umd.min.js') }}"></script>
<script>
// ── Warna tema ──────────────────────────────────────────────
// (Warna status pembiayaan TIDAK lagi hardcode di sini — diturunkan dari
//  server via `warnaStatus`, satu sumber dengan legend. Audit C1/F2.)
const primary = '#059669';
const blue    = '#3b82f6';
const red     = '#ef4444';

// ── 1. BAR CHART — Pembiayaan vs Angsuran ──────────────────
new Chart(document.getElementById('barChart'), {
    type: 'bar',
    data: {
        labels: {!! $bulanLabels !!},
        datasets: [
            {
                label: 'Pembiayaan',
                data: {!! $dataPembiayaan !!},
                backgroundColor: 'rgba(5,150,105,0.75)',
                borderRadius: 4,
                borderSkipped: false,
            },
            {
                label: 'Angsuran',
                data: {!! $dataAngsuran !!},
                backgroundColor: 'rgba(217,119,6,0.75)',
                borderRadius: 4,
                borderSkipped: false,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } },
                 grid: { color: '#f1f5f9' } }
        }
    }
});

// ── 2. DONUT CHART — Status Pembiayaan ─────────────────────
// Label & warna diturunkan dari server (labelStatus/warnaStatus) sehingga
// potongan donut selalu identik dengan titik legend — jumlah status tidak
// lagi dibatasi 3 warna seperti sebelumnya (audit F2).
const statusData  = {!! $statusPembiayaan !!};
const statusLabel = @json($labelStatus);
const warnaStatus = {!! $warnaStatus !!};

new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabel,
        datasets: [{
            data: Object.values(statusData),
            backgroundColor: warnaStatus,
            borderWidth: 2,
            borderColor: '#ffffff',
            hoverOffset: 6,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: {
                label: ctx => ` ${ctx.label}: ${ctx.parsed} akad`
            }}
        }
    }
});

// ── 3. LINE CHART — Tren Transaksi 7 Hari ──────────────────
new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: {!! $hariLabels !!},
        datasets: [{
            label: 'Transaksi',
            data: {!! $dataTransaksi !!},
            borderColor: primary,
            backgroundColor: 'rgba(5,150,105,0.08)',
            borderWidth: 2.5,
            pointBackgroundColor: primary,
            pointRadius: 5,
            pointHoverRadius: 7,
            fill: true,
            tension: 0.35,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: {
                label: ctx => ` ${ctx.parsed.y} transaksi`
            }}
        },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11 } } },
            y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } },
                 grid: { color: '#f1f5f9' } }
        }
    }
});
</script>
@endsection
