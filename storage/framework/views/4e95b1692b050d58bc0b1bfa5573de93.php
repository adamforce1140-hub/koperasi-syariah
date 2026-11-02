<?php $__env->startSection('title', 'Dashboard Pengurus — ' . config('app.name')); ?>
<?php $__env->startSection('page-title', 'Dashboard Pengurus'); ?>

<?php $__env->startSection('styles'); ?>
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
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>

<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Pengurus', 'tema' => 'emas'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<div id="pengurus-stats" data-denyut-domain="anggota pembiayaan approval"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="stats">
    <?php echo $__env->make('dashboard.partials.pengurus-stats', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


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
        <?php $statusMap = json_decode($statusPembiayaan, true) ?: []; ?>
        <div class="chart-canvas chart-canvas--donut">
            <canvas id="donutChart" role="img"
                    aria-label="Diagram donat sebaran status pembiayaan koperasi"></canvas>
        </div>
        
        <div class="donut-legend" role="list" aria-label="Keterangan status pembiayaan">
            <?php $__empty_1 = true; $__currentLoopData = $urutanStatus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $kunci): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="legend-item" role="listitem">
                    <span class="legend-dot" style="background:<?php echo e($warnaStatus[$i]); ?>;" aria-hidden="true"></span>
                    <span><?php echo e($labelStatus[$i]); ?></span>
                    <span style="margin-left:auto;font-weight:600;color:#1e293b;">
                        <?php echo e($statusMap[$kunci] ?? 0); ?>

                    </span>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="legend-item" role="listitem" style="color:#94a3b8;">
                    <span>Belum ada data pembiayaan.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>


<div id="pengurus-hold" data-denyut-domain="shu anggota"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="hold">
    <?php echo $__env->make('dashboard.partials.pengurus-hold', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div id="pengurus-validasi" data-denyut-domain="pembiayaan approval"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="validasi">
    <?php echo $__env->make('dashboard.partials.pengurus-validasi', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="chart-row-2">
    <div id="pengurus-transaksi" data-denyut-domain="transaksi"
         data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="transaksi">
        <?php echo $__env->make('dashboard.partials.pengurus-transaksi', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    <div id="pengurus-jurnal" data-denyut-domain="jurnal"
         data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="jurnal">
        <?php echo $__env->make('dashboard.partials.pengurus-jurnal', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>
</div>


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


<div class="card" id="pengurus-riwayat" data-denyut-domain="approval"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="riwayat">
    <?php echo $__env->make('dashboard.partials.pengurus-riwayat', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="pengurus-pengajuan" data-denyut-domain="pembiayaan"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="pengajuan">
    <?php echo $__env->make('dashboard.partials.pengurus-pengajuan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div id="pengurus-limit" data-denyut-domain="limit"
     data-denyut-url="<?php echo e(route('dashboard.pengurus.ringkas')); ?>" data-denyut-sasaran="limit">
    <?php echo $__env->make('dashboard.partials.pengurus-limit', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>

<script src="<?php echo e(asset('js/chart.umd.min.js')); ?>"></script>
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
        labels: <?php echo $bulanLabels; ?>,
        datasets: [
            {
                label: 'Pembiayaan',
                data: <?php echo $dataPembiayaan; ?>,
                backgroundColor: 'rgba(5,150,105,0.75)',
                borderRadius: 4,
                borderSkipped: false,
            },
            {
                label: 'Angsuran',
                data: <?php echo $dataAngsuran; ?>,
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
const statusData  = <?php echo $statusPembiayaan; ?>;
const statusLabel = <?php echo json_encode($labelStatus, 15, 512) ?>;
const warnaStatus = <?php echo $warnaStatus; ?>;

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
        labels: <?php echo $hariLabels; ?>,
        datasets: [{
            label: 'Transaksi',
            data: <?php echo $dataTransaksi; ?>,
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
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\pengurus.blade.php ENDPATH**/ ?>