<?php $__env->startSection('title', 'Perhitungan & Distribusi SHU'); ?>
<?php $__env->startSection('page-title', 'Perhitungan & Distribusi SHU'); ?>

<?php $__env->startSection('styles'); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
    /* ── Page Header ── */
    .page-header {
        background: linear-gradient(135deg, #065f46 0%, #059669 100%);
        border-radius: 1rem;
        padding: 1.5rem 2rem;
        color: white;
        margin-bottom: 1.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: 0 4px 20px rgba(5,150,105,0.25);
    }
    .page-header h4 { margin: 0; font-size: 1.2rem; font-weight: 700; }
    .page-header p  { margin: 0.25rem 0 0; font-size: 0.82rem; opacity: 0.85; }

    /* ── Card Form ── */
    .form-card {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        overflow: hidden;
    }
    .form-card-header {
        background: linear-gradient(135deg, #059669, #047857);
        color: white;
        padding: 1rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-weight: 600;
        font-size: 0.95rem;
    }
    .form-card-body { padding: 1.5rem; }

    /* ── Form Elements ── */
    .form-section-title {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .form-section-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #e2e8f0;
    }
    .form-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #374151;
        margin-bottom: 0.35rem;
    }
    .form-control, .input-group-text {
        font-size: 0.875rem;
        border-color: #e2e8f0;
        border-radius: 0.6rem !important;
    }
    .form-control:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5,150,105,0.12);
    }
    .input-group .form-control { border-radius: 0.6rem 0 0 0.6rem !important; }
    .input-group .input-group-text { border-radius: 0 0.6rem 0.6rem 0 !important; background: #f8fafc; color: #64748b; }

    /* ── Alokasi Grid ── */
    .alokasi-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    @media (max-width: 640px) { .alokasi-grid { grid-template-columns: 1fr; } }

    .alokasi-item {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1rem;
        transition: border-color 0.15s;
    }
    .alokasi-item:hover { border-color: #cbd5e1; }
    .alokasi-item-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .alokasi-dot {
        width: 8px; height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .nominal-tag {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 0.5rem;
        padding: 0.3rem 0.6rem;
        background: white;
        border-radius: 0.4rem;
        border: 1px solid #e2e8f0;
    }
    .nominal-tag small { font-size: 0.72rem; color: #94a3b8; }
    .nominal-tag strong { font-size: 0.78rem; }

    /* ── Ringkasan Box ── */
    .ringkasan-box {
        background: linear-gradient(135deg, #eff6ff, #f0fdf4);
        border: 1px solid #bfdbfe;
        border-radius: 0.9rem;
        padding: 1.25rem;
        margin-top: 1.25rem;
    }
    .ringkasan-box-title {
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: #3b82f6;
        margin-bottom: 0.9rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .ringkasan-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.6rem;
    }
    .ringkasan-item {
        background: white;
        border-radius: 0.6rem;
        padding: 0.65rem 0.75rem;
        text-align: center;
        border: 1px solid rgba(0,0,0,0.05);
    }
    .ringkasan-item .r-label { font-size: 0.7rem; color: #64748b; margin-bottom: 0.2rem; }
    .ringkasan-item .r-value { font-size: 0.8rem; font-weight: 700; }
    .persen-badge {
        display: inline-block;
        padding: 0.2rem 0.65rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
    }

    /* ── Action Buttons ── */
    .btn-action-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
        margin-top: 1.25rem;
    }
    @media (max-width: 480px) { .btn-action-group { grid-template-columns: 1fr; } }

    .btn-draft {
        background: #f1f5f9;
        color: #475569;
        border: 1.5px solid #cbd5e1;
        border-radius: 0.65rem;
        padding: 0.7rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
    }
    .btn-draft:hover { background: #e2e8f0; border-color: #94a3b8; }

    .btn-proses {
        background: linear-gradient(135deg, #059669, #047857);
        color: white;
        border: none;
        border-radius: 0.65rem;
        padding: 0.7rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        box-shadow: 0 2px 8px rgba(5,150,105,0.3);
    }
    .btn-proses:hover { background: linear-gradient(135deg, #047857, #065f46); box-shadow: 0 4px 16px rgba(5,150,105,0.4); }

    /* ── Riwayat Card ── */
    .riwayat-card {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        overflow: hidden;
    }
    .riwayat-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        /* Tanpa wrap, judul + badge "N Periode" terdorong keluar layar
           saat sisa ruang kartu tidak cukup. */
        flex-wrap: wrap;
        gap: 0.5rem;
        min-width: 0;
    }
    .riwayat-card-header-title {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
        flex: 1 1 auto;
        overflow-wrap: break-word;
    }
    @media (max-width: 640px) {
        .riwayat-card-header { padding: 0.85rem 1rem; }
        .riwayat-card-header-title { font-size: 0.88rem; }
    }

    /* ── Alert styles ── */
    .alert-shu {
        border-radius: 0.75rem;
        padding: 0.85rem 1.1rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
        font-size: 0.875rem;
    }
    .alert-shu.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .alert-shu.danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .alert-shu.info    { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
    .alert-shu-close { margin-left: auto; background: none; border: none; cursor: pointer; opacity: 0.5; font-size: 1rem; line-height: 1; }
    .alert-shu-close:hover { opacity: 1; }

    /* ── Tambahan utility ── */
    .total-shu-display {
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border: 1px solid #a7f3d0;
        border-radius: 0.75rem;
        padding: 0.85rem 1rem;
        margin-top: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .total-shu-display .label { font-size: 0.78rem; color: #065f46; font-weight: 500; }
    .total-shu-display .value { font-size: 1rem; font-weight: 700; color: #047857; }

    /* ── Indikator validasi total persentase (hijau/merah) ── */
    .alokasi-indicator {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.85rem;
        padding: 0.7rem 0.9rem;
        border-radius: 0.65rem;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid transparent;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .alokasi-indicator .ind-icon {
        width: 20px; height: 20px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        flex-shrink: 0;
        color: #fff;
    }
    /* Hijau — total tepat 100%, form siap submit */
    .alokasi-indicator.is-valid {
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #166534;
    }
    .alokasi-indicator.is-valid .ind-icon { background: #16a34a; }
    /* Merah — total ≠ 100%, submit dikunci */
    .alokasi-indicator.is-invalid {
        background: #fef2f2;
        border-color: #fecaca;
        color: #991b1b;
    }
    .alokasi-indicator.is-invalid .ind-icon { background: #dc2626; }

    /* ── Tombol submit saat terkunci ── */
    .btn-draft:disabled, .btn-proses:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        filter: grayscale(0.4);
    }
    .btn-draft:disabled:hover, .btn-proses:disabled:hover {
        transform: none;
        box-shadow: none;
    }

    /* ══════════════════════════════════════════════════════════
       Modal Detail Rincian SHU per Anggota
       ══════════════════════════════════════════════════════════ */
    .shu-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(2px);
        z-index: 1060;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.18s ease, visibility 0.18s ease;
    }
    .shu-modal-backdrop.is-open { opacity: 1; visibility: visible; }

    .shu-modal {
        background: #fff;
        border-radius: 1rem;
        width: 100%;
        max-width: 960px;
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        transform: translateY(12px) scale(0.98);
        transition: transform 0.18s ease;
        overflow: hidden;
    }
    .shu-modal-backdrop.is-open .shu-modal { transform: translateY(0) scale(1); }

    .shu-modal-header {
        background: linear-gradient(135deg, #059669, #047857);
        color: #fff;
        padding: 1rem 1.25rem;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-shrink: 0;
    }
    .shu-modal-header h5 { margin: 0; font-size: 1rem; font-weight: 700; }
    .shu-modal-header p  { margin: 0.2rem 0 0; font-size: 0.76rem; opacity: 0.9; }
    .shu-modal-close {
        background: rgba(255,255,255,0.18);
        border: none;
        color: #fff;
        width: 30px; height: 30px;
        border-radius: 50%;
        cursor: pointer;
        font-size: 1rem;
        line-height: 1;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s;
    }
    .shu-modal-close:hover { background: rgba(255,255,255,0.35); }

    .shu-modal-toolbar {
        padding: 0.85rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        background: #f8fafc;
        flex-shrink: 0;
    }
    .shu-modal-search {
        position: relative;
        flex: 1;
        min-width: 180px;
    }
    .shu-modal-search input {
        width: 100%;
        font-size: 0.82rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.55rem;
        padding: 0.45rem 0.7rem 0.45rem 2rem;
        outline: none;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .shu-modal-search input:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5,150,105,0.12);
    }
    .shu-modal-search .search-icon {
        position: absolute;
        left: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.8rem;
        pointer-events: none;
    }
    .shu-modal-count {
        font-size: 0.75rem;
        color: #64748b;
        white-space: nowrap;
    }

    .shu-modal-body {
        overflow: auto;
        -webkit-overflow-scrolling: touch;
        flex: 1;
    }
    .shu-modal-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    .shu-modal-table thead th {
        position: sticky;
        top: 0;
        background: #f1f5f9;
        color: #475569;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.6rem 0.75rem;
        text-align: left;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
        z-index: 1;
    }
    .shu-modal-table thead th.sortable { cursor: pointer; user-select: none; }
    .shu-modal-table thead th.sortable:hover { background: #e2e8f0; color: #0f172a; }
    .shu-modal-table thead th .sort-ind { opacity: 0.35; font-size: 0.65rem; margin-left: 0.2rem; }
    .shu-modal-table thead th.sort-aktif .sort-ind { opacity: 1; color: #059669; }
    .shu-modal-table tbody td {
        padding: 0.55rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }
    .shu-modal-table tbody tr:hover { background: #f8fafc; }
    .shu-modal-table .kolom-nominal { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .shu-modal-table .kolom-nama { font-weight: 600; color: #0f172a; }
    .shu-modal-table .kolom-no { font-size: 0.7rem; color: #94a3b8; font-weight: 400; }
    .shu-modal-table tfoot td {
        position: sticky;
        bottom: 0;
        background: #ecfdf5;
        border-top: 2px solid #a7f3d0;
        padding: 0.6rem 0.75rem;
        font-weight: 700;
        color: #065f46;
        font-size: 0.8rem;
    }
    .shu-modal-table tfoot .kolom-nominal { text-align: right; white-space: nowrap; }

    .shu-modal-empty {
        padding: 2.5rem 1rem;
        text-align: center;
        color: #94a3b8;
        font-size: 0.85rem;
    }
    .shu-modal-loading {
        padding: 3rem 1rem;
        text-align: center;
        color: #64748b;
        font-size: 0.85rem;
    }
    .shu-modal-spinner {
        width: 28px; height: 28px;
        border: 3px solid #e2e8f0;
        border-top-color: #059669;
        border-radius: 50%;
        margin: 0 auto 0.75rem;
        animation: shuSpin 0.7s linear infinite;
    }
    @keyframes shuSpin { to { transform: rotate(360deg); } }

    /* ── Responsif: kartu bertumpuk di layar kecil ── */
    @media (max-width: 720px) {
        .shu-modal-backdrop { padding: 0; align-items: flex-end; }
        .shu-modal {
            max-width: 100%;
            max-height: 94vh;
            border-radius: 1rem 1rem 0 0;
            transform: translateY(24px);
        }
        .shu-modal-backdrop.is-open .shu-modal { transform: translateY(0); }
        .shu-modal-toolbar { padding: 0.7rem 0.9rem; }
        .shu-modal-header  { padding: 0.85rem 0.9rem; }
        .shu-modal-table { font-size: 0.75rem; }
        .shu-modal-table thead th,
        .shu-modal-table tbody td { padding: 0.5rem 0.55rem; }
        /* Sembunyikan kolom No. Anggota di layar sangat kecil */
        .shu-modal-table .kol-opsional { display: none; }
    }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="padding: 0 0.25rem;">

    
    <div class="page-header">
        <div>
            <h4>&#128200; Perhitungan & Distribusi SHU</h4>
            <p>Sisa Hasil Usaha &mdash; Berdasarkan Prinsip Koperasi Syariah</p>
        </div>
        <div style="text-align:right; font-size:0.8rem; opacity:0.85;">
            <div style="font-weight:600;"><?php echo e(now()->format('d M Y')); ?></div>
            <div>Tahun Aktif: <?php echo e(now()->year); ?></div>
        </div>
    </div>

    
    <?php if(session('success')): ?>
    <div class="alert-shu success" id="alertSuccess">
        <span>&#10004;</span>
        <span><?php echo e(session('success')); ?></span>
        <button class="alert-shu-close" onclick="document.getElementById('alertSuccess').remove()">&#10005;</button>
    </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
    <div class="alert-shu danger" id="alertError">
        <span>&#9888;</span>
        <span><?php echo e(session('error')); ?></span>
        <button class="alert-shu-close" onclick="document.getElementById('alertError').remove()">&#10005;</button>
    </div>
    <?php endif; ?>
    
    <?php if(session('info')): ?>
    <div class="alert-shu info" id="alertInfo" role="status">
        <span aria-hidden="true">&#8505;</span>
        <span><?php echo e(session('info')); ?></span>
        <button class="alert-shu-close" onclick="document.getElementById('alertInfo').remove()">&#10005;</button>
    </div>
    <?php endif; ?>
    <?php if($errors->any()): ?>
    <?php if (isset($component)) { $__componentOriginal38422736012f5293606d606328e57aed = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal38422736012f5293606d606328e57aed = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.pesan-galat','data' => ['errors' => $errors]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('pesan-galat'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['errors' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($errors)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal38422736012f5293606d606328e57aed)): ?>
<?php $attributes = $__attributesOriginal38422736012f5293606d606328e57aed; ?>
<?php unset($__attributesOriginal38422736012f5293606d606328e57aed); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal38422736012f5293606d606328e57aed)): ?>
<?php $component = $__componentOriginal38422736012f5293606d606328e57aed; ?>
<?php unset($__componentOriginal38422736012f5293606d606328e57aed); ?>
<?php endif; ?>
    <?php endif; ?>

    
    <?php if($jumlahAnggotaHold > 0): ?>
    <div class="alert-shu danger" style="display:block;margin-bottom:1.25rem;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
            <div>
                <div style="font-weight:700;color:#991b1b;">
                    &#9888; Penahanan SHU (HOLD) &mdash; Tunggakan Simpanan Wajib
                </div>
                <div style="font-size:0.78rem;color:#b91c1c;margin-top:0.2rem;">
                    Tahun buku <?php echo e($tahunBerjalan); ?> &middot;
                    Kebijakan <code>shu_hold_on_overdue</code> aktif &middot;
                    <?php echo e($jumlahAnggotaHold); ?> anggota &middot;
                    Total tunggakan Rp <?php echo e(number_format($totalTunggakanHold, 0, ',', '.')); ?>

                </div>
            </div>
        </div>

        <div style="overflow-x:auto;background:#fff;border:1px solid #fecaca;border-radius:0.75rem;margin-top:0.875rem;">
            <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                <thead>
                    <tr style="background:#fee2e2;color:#991b1b;">
                        <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">No. Anggota</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">Nama Anggota</th>
                        <th style="padding:0.6rem 0.75rem;text-align:center;font-weight:700;">Tagihan</th>
                        <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;">Periode</th>
                        <th style="padding:0.6rem 0.75rem;text-align:right;font-weight:700;">Nominal</th>
                        <th style="padding:0.6rem 0.75rem;text-align:center;font-weight:700;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $daftarHold; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $h): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-top:1px solid #fee2e2;">
                        <td style="padding:0.55rem 0.75rem;font-family:monospace;color:#475569;">
                            <?php echo e($h['no_anggota'] ?? '&mdash;'); ?>

                        </td>
                        <td style="padding:0.55rem 0.75rem;font-weight:600;color:#1e293b;">
                            <?php echo e($h['nama_anggota']); ?>

                        </td>
                        <td style="padding:0.55rem 0.75rem;text-align:center;color:#dc2626;font-weight:700;">
                            <?php echo e($h['jumlah_tunggakan']); ?>x
                        </td>
                        <td style="padding:0.55rem 0.75rem;font-family:monospace;color:#64748b;font-size:0.75rem;">
                            <?php echo e($h['periode_awal']); ?> s/d <?php echo e($h['periode_akhir']); ?>

                        </td>
                        <td style="padding:0.55rem 0.75rem;text-align:right;font-weight:700;color:#dc2626;">
                            Rp <?php echo e(number_format($h['total_tunggakan'], 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.55rem 0.75rem;text-align:center;">
                            <?php if($h['url_detail']): ?>
                            <a href="<?php echo e($h['url_detail']); ?>"
                               style="color:#1e40af;font-weight:600;text-decoration:none;font-size:0.75rem;">
                                Detail CIF
                            </a>
                            <?php else: ?>
                            <span style="color:#94a3b8;font-size:0.75rem;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>

        <div style="font-size:0.72rem;color:#b91c1c;margin-top:0.6rem;">
            Pencairan SHU dilepas <strong>otomatis</strong> begitu seluruh tunggakan dilunasi
            di Teller (jurnal: Debet 2-1004 Utang SHU Ditahan / Kredit 2-1001 Simpanan Sukarela).
        </div>
    </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: minmax(0,420px) 1fr; gap:1.5rem; align-items:start;">

        
        <div class="form-card">
            <div class="form-card-header">
                <span>&#128290;</span>
                Input Alokasi SHU Tahunan
            </div>
            <div class="form-card-body">

                
                <form id="formProsesPlaceholder" method="POST" action="" style="display:none;"><?php echo csrf_field(); ?></form>

                <form id="formShu" action="<?php echo e(route('pengurus.shu.store')); ?>" method="POST">
                    <?php echo csrf_field(); ?>

                    
                    <p class="form-section-title">&#128197; Informasi Dasar</p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.85rem; margin-bottom:1.1rem;">
                        <div>
                            <label class="form-label">Tahun Buku <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="tahun_buku" id="tahunBuku"
                                   class="form-control<?php echo e($errors->has('tahun_buku') ? ' is-invalid' : ''); ?>"
                                   placeholder="Contoh: 2025"
                                   value="<?php echo e(old('tahun_buku', now()->year)); ?>" required>
                            <?php $__errorArgs = ['tahun_buku'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div style="font-size:0.75rem;color:#ef4444;margin-top:0.25rem;"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                        <div>
                            <label class="form-label">Total SHU Bersih (Rp) <span style="color:#ef4444;">*</span></label>
                            <input type="number" name="total_shu_bersih" id="totalShu"
                                   class="form-control<?php echo e($errors->has('total_shu_bersih') ? ' is-invalid' : ''); ?>"
                                   placeholder="0" min="1" step="1"
                                   value="<?php echo e(old('total_shu_bersih')); ?>" required>
                            <?php $__errorArgs = ['total_shu_bersih'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div style="font-size:0.75rem;color:#ef4444;margin-top:0.25rem;"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                        </div>
                    </div>

                    
                    <div class="total-shu-display" id="totalShuDisplay" style="<?php echo e(old('total_shu_bersih') ? '' : 'display:none!important;'); ?>">
                        <span class="label">Total SHU yang akan dialokasikan:</span>
                        <span class="value" id="totalShuRp">Rp 0</span>
                    </div>

                    
                    <p class="form-section-title" style="margin-top:1.25rem;">&#9878; Persentase Alokasi
                        <span class="persen-badge" id="totalPersenBadge" style="background:#f1f5f9;color:#475569;">0%</span>
                    </p>

                    <?php
                        // Nilai awal persentase berasal dari kebijakan koperasi
                        // (jembatan 4→5 pos). Total kelima pos harus tepat 100%
                        // — dijaga oleh validasi JS + backend.
                        $defaultAlokasi = $defaultAlokasi ?? \App\Support\Setting::alokasiShuDrafDefault();
                        $alokasi = [
                            ['name'=>'persen_cadangan',       'label'=>'Dana Cadangan Koperasi',  'default'=>$defaultAlokasi['persen_cadangan'],       'dot'=>'#06b6d4', 'color'=>'#0e7490', 'id'=>'sum_cadangan'],
                            ['name'=>'persen_jasa_modal',     'label'=>'SHU Jasa Modal Anggota',  'default'=>$defaultAlokasi['persen_jasa_modal'],     'dot'=>'#22c55e', 'color'=>'#16a34a', 'id'=>'sum_jasa_modal'],
                            ['name'=>'persen_jasa_transaksi', 'label'=>'SHU Jasa Transaksi',      'default'=>$defaultAlokasi['persen_jasa_transaksi'], 'dot'=>'#f59e0b', 'color'=>'#d97706', 'id'=>'sum_jasa_transaksi'],
                            ['name'=>'persen_pengurus',       'label'=>'Dana Pengurus & Karyawan','default'=>$defaultAlokasi['persen_pengurus'],       'dot'=>'#f43f5e', 'color'=>'#be123c', 'id'=>'sum_pengurus'],
                            ['name'=>'persen_sosial',         'label'=>'Dana Pendidikan & Sosial','default'=>$defaultAlokasi['persen_sosial'],         'dot'=>'#8b5cf6', 'color'=>'#6d28d9', 'id'=>'sum_sosial'],
                        ];
                    ?>

                    <div class="alokasi-grid">
                        <?php $__currentLoopData = $alokasi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="alokasi-item">
                            <div class="alokasi-item-label">
                                <span class="alokasi-dot" style="background:<?php echo e($a['dot']); ?>;"></span>
                                <?php echo e($a['label']); ?>

                            </div>
                            <div class="input-group input-group-sm">
                                <input type="number"
                                       name="<?php echo e($a['name']); ?>"
                                       class="form-control persen-input<?php echo e($errors->has($a['name']) ? ' is-invalid' : ''); ?>"
                                       value="<?php echo e(old($a['name'], $a['default'])); ?>"
                                       min="0" max="100" step="0.01" required
                                       data-sum-id="<?php echo e($a['id']); ?>">
                                <span class="input-group-text">%</span>
                            </div>
                            <?php $__errorArgs = [$a['name']];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <div style="font-size:0.72rem;color:#ef4444;margin-top:0.2rem;"><?php echo e($message); ?></div>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                            <div class="nominal-tag">
                                <small>Nominal:</small>
                                <strong id="<?php echo e($a['id']); ?>_inline" style="color:<?php echo e($a['color']); ?>;">Rp 0</strong>
                            </div>
                        </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>

                    
                    <div class="ringkasan-box">
                        <div class="ringkasan-box-title">
                            &#9432; Ringkasan Alokasi
                        </div>
                        <div class="ringkasan-grid">
                            <div class="ringkasan-item">
                                <div class="r-label">Dana Cadangan</div>
                                <div class="r-value" style="color:#0e7490;" id="sum_cadangan">Rp 0</div>
                            </div>
                            <div class="ringkasan-item">
                                <div class="r-label">Jasa Modal</div>
                                <div class="r-value" style="color:#16a34a;" id="sum_jasa_modal">Rp 0</div>
                            </div>
                            <div class="ringkasan-item">
                                <div class="r-label">Jasa Transaksi</div>
                                <div class="r-value" style="color:#d97706;" id="sum_jasa_transaksi">Rp 0</div>
                            </div>
                            <div class="ringkasan-item">
                                <div class="r-label">Dana Pengurus</div>
                                <div class="r-value" style="color:#be123c;" id="sum_pengurus">Rp 0</div>
                            </div>
                            <div class="ringkasan-item">
                                <div class="r-label">Dana Sosial</div>
                                <div class="r-value" style="color:#6d28d9;" id="sum_sosial">Rp 0</div>
                            </div>
                        </div>

                        
                        <div class="alokasi-indicator" id="alokasiIndicator">
                            <span class="ind-icon" id="alokasiIndicatorIcon">&#10004;</span>
                            <span id="alokasiIndicatorText">Total alokasi: 100% &mdash; form siap disubmit.</span>
                        </div>
                        <div style="margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid #bfdbfe; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:0.78rem; color:#475569; font-weight:500;">Total Dialokasikan:</span>
                            <span style="font-size:0.9rem; font-weight:700; color:#1e40af;" id="totalAlokasi">Rp 0</span>
                        </div>
                    </div>

                    
                    <div class="btn-action-group">
                        <button type="submit" name="_action" value="draft" id="btnSimpanDraft" class="btn-draft">
                            &#128190; Simpan Draft SHU
                        </button>
                        <button type="button" class="btn-proses" id="btnProsesLangsung">
                            &#9889; Proses & Distribusikan SHU
                        </button>
                    </div>

                    <p id="alokasiHint" style="display:none; margin-top:0.6rem; font-size:0.75rem; color:#b91c1c; text-align:center;">
                        &#9888; Tombol submit dinonaktifkan sampai total alokasi tepat 100%.
                    </p>

                </form>
            </div>
        </div>

        
        <div class="riwayat-card">
            <div class="riwayat-card-header">
                <div class="riwayat-card-header-title">
                    &#128337; Riwayat Pembagian SHU
                </div>
                <span style="background:#dbeafe;color:#1e40af;padding:0.2rem 0.65rem;border-radius:9999px;font-size:0.75rem;font-weight:600;">
                    <?php echo e($periods->count()); ?> Periode
                </span>
            </div>

            <?php if($periods->isEmpty()): ?>
                <div style="text-align:center; padding:3.5rem 1.5rem; color:#94a3b8;">
                    <div style="font-size:2.5rem; margin-bottom:0.75rem;">&#128450;</div>
                    <div style="font-weight:600; color:#64748b; margin-bottom:0.35rem;">Belum ada data SHU</div>
                    <div style="font-size:0.82rem;">Isi form di sebelah kiri untuk membuat entri pertama.</div>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:0.75rem 1rem;text-align:left;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Tahun Buku</th>
                                <th style="padding:0.75rem 1rem;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Total SHU</th>
                                <th style="padding:0.75rem 1rem;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Jasa Modal</th>
                                <th style="padding:0.75rem 1rem;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;white-space:nowrap;">Jasa Transaksi</th>
                                <th style="padding:0.75rem 1rem;text-align:center;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Status</th>
                                <th style="padding:0.75rem 1rem;text-align:right;color:#64748b;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;border-bottom:2px solid #e2e8f0;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $periods; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                <td style="padding:0.85rem 1rem;font-weight:600;color:#1e293b;"><?php echo e($p->tahun_buku); ?></td>
                                <td style="padding:0.85rem 1rem;text-align:right;color:#3b82f6;font-weight:600;">Rp <?php echo e(number_format($p->total_shu_bersih, 0, ',', '.')); ?></td>
                                <td style="padding:0.85rem 1rem;text-align:right;color:#16a34a;">Rp <?php echo e(number_format($p->nominal_jasa_modal, 0, ',', '.')); ?></td>
                                <td style="padding:0.85rem 1rem;text-align:right;color:#d97706;">Rp <?php echo e(number_format($p->nominal_jasa_transaksi, 0, ',', '.')); ?></td>
                                <td style="padding:0.85rem 1rem;text-align:center;">
                                    <?php if($p->status === 'Selesai'): ?>
                                        <span style="background:#dcfce7;color:#166534;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">&#10003; Selesai</span>
                                    <?php elseif($p->status === 'Diproses'): ?>
                                        <span style="background:#fef3c7;color:#92400e;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">&#9203; Diproses</span>
                                    <?php else: ?>
                                        <span style="background:#f1f5f9;color:#475569;padding:0.2rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">&#128196; Draft</span>
                                    <?php endif; ?>
                                    <?php if($p->is_distributed): ?>
                                        <br><span style="background:#dbeafe;color:#1e40af;padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.68rem;font-weight:600;margin-top:0.25rem;display:inline-block;">&#10004;&#10004; Terdistribusi</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.85rem 1rem;text-align:right;">
                                    <div style="display:flex;gap:0.4rem;justify-content:flex-end;align-items:center;flex-wrap:wrap;">
                                        
                                        <button type="button"
                                           style="background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe;padding:0.3rem 0.65rem;border-radius:0.45rem;font-size:0.78rem;text-decoration:none;font-weight:500;cursor:pointer;"
                                           title="Lihat rincian SHU per anggota"
                                           data-detail-url="<?php echo e(route('pengurus.shu.detail-anggota', $p->id)); ?>"
                                           data-tahun="<?php echo e($p->tahun_buku); ?>"
                                           onclick="bukaModalDetail(this)">&#128065; Detail</button>

                                        
                                        <a href="<?php echo e(route('pengurus.shu.show', $p->id)); ?>"
                                           style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;padding:0.3rem 0.65rem;border-radius:0.45rem;font-size:0.78rem;text-decoration:none;font-weight:500;"
                                           title="Buka halaman detail lengkap">&#128279; Halaman</a>

                                        <?php if($p->isDraft()): ?>
                                        <button type="button"
                                            style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:0.3rem 0.65rem;border-radius:0.45rem;font-size:0.78rem;cursor:pointer;font-weight:500;"
                                            title="Proses & Bagikan"
                                            onclick="konfirmasiProsesRiwayat('<?php echo e($p->tahun_buku); ?>', 'Rp <?php echo e(number_format($p->total_shu_bersih, 0, ',', '.')); ?>', '<?php echo e(route('pengurus.shu.proses', $p->id)); ?>')">
                                            &#9889; Proses
                                        </button>
                                        <form id="formProses_<?php echo e($p->id); ?>" action="<?php echo e(route('pengurus.shu.proses', $p->id)); ?>" method="POST" style="display:none;"><?php echo csrf_field(); ?></form>

                                        <form action="<?php echo e(route('pengurus.shu.destroy', $p->id)); ?>" method="POST" style="display:inline;"
                                              onsubmit="return confirm('Hapus draft SHU <?php echo e($p->tahun_buku); ?>?')">
                                            <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                            <button type="submit"
                                                style="background:#fff1f2;color:#be123c;border:1px solid #fecdd3;padding:0.3rem 0.65rem;border-radius:0.45rem;font-size:0.78rem;cursor:pointer;font-weight:500;"
                                                title="Hapus Draft">&#128465; Hapus</button>
                                        </form>
                                        <?php endif; ?>

                                        <?php if($p->isSelesai()): ?>
                                        <a href="<?php echo e(route('pengurus.shu.export-excel', $p->id)); ?>"
                                           style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:0.3rem 0.65rem;border-radius:0.45rem;font-size:0.78rem;text-decoration:none;font-weight:500;"
                                           title="Export Excel">&#128196; Export</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    
    <div class="shu-modal-backdrop" id="modalDetailShu" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modalDetailJudul">
        <div class="shu-modal">
            <div class="shu-modal-header">
                <div>
                    <h5 id="modalDetailJudul">&#128202; Rincian SHU per Anggota</h5>
                    <p id="modalDetailSubjudul">Memuat data&hellip;</p>
                </div>
                <button type="button" class="shu-modal-close" id="modalDetailTutup" aria-label="Tutup modal" title="Tutup">&times;</button>
            </div>

            <div class="shu-modal-toolbar">
                <div class="shu-modal-search">
                    <span class="search-icon">&#128269;</span>
                    <input type="text" id="modalDetailCari" placeholder="Cari nama atau no. anggota&hellip;" autocomplete="off">
                </div>
                <div class="shu-modal-count" id="modalDetailJumlah"></div>
            </div>

            <div class="shu-modal-body" id="modalDetailBody">
                <div class="shu-modal-loading">
                    <div class="shu-modal-spinner"></div>
                    Memuat rincian&hellip;
                </div>
            </div>
        </div>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── Utility: Format Rupiah ──
// Menampilkan 2 desimal bila ada sen (mis. Rp 25.000.000,50), dan bilangan
// bulat tanpa desimal bila tidak ada (mis. Rp 25.000.000) — sesuai kebiasaan
// penulisan rupiah. Pemisah ribuan '.' dan desimal ',' (format id-ID).
function formatRp(angka) {
    if (!isFinite(angka) || angka === '' || angka === null) return 'Rp 0';
    const punyaSen = Math.abs(angka % 1) > 1e-9;
    return 'Rp ' + angka.toLocaleString('id-ID', {
        minimumFractionDigits: punyaSen ? 2 : 0,
        maximumFractionDigits: punyaSen ? 2 : 0,
    });
}

// ── Utility: Ambil angka dari input secara aman ──
// Mengembalikan 0 untuk input kosong / non-numerik (mis. pengguna menempel
// teks "seratus juta"), sehingga perhitungan tidak pernah menghasilkan NaN
// yang akan tampil sebagai "Rp NaN" di layar.
function angkaDari(el) {
    if (!el) return 0;
    const n = parseFloat(el.value);
    return isFinite(n) ? n : 0;
}

// ── Real-time Calculation ──
function hitungPreview() {
    const totalInput = document.getElementById('totalShu');
    // angkaDari() mengembalikan 0 untuk input non-numerik → tidak pernah NaN
    const total = angkaDari(totalInput);

    // Tampilkan/sembunyikan display total
    const display = document.getElementById('totalShuDisplay');
    if (total > 0) {
        display.style.removeProperty('display');
        display.style.display = 'flex';
    } else {
        display.style.display = 'none';
    }
    document.getElementById('totalShuRp').textContent = formatRp(total);

    const inputs = document.querySelectorAll('.persen-input');
    let totalPersen = 0;
    let totalAlokasi = 0;

    inputs.forEach(inp => {
        const persen = angkaDari(inp);
        totalPersen += persen;

        // Nominal Pos = Total SHU × (Persen Pos / 100)
        const nominal = total * persen / 100;
        totalAlokasi += nominal;

        const sumId = inp.dataset.sumId;

        // Inline nominal di bawah input
        const inlineEl = document.getElementById(sumId + '_inline');
        if (inlineEl) inlineEl.textContent = formatRp(nominal);

        // Ringkasan box
        const sumEl = document.getElementById(sumId);
        if (sumEl) sumEl.textContent = formatRp(nominal);
    });

    // Total alokasi
    document.getElementById('totalAlokasi').textContent = formatRp(totalAlokasi);

    // Dibulatkan ke 2 desimal agar konsisten dengan validasi backend
    // (round($sum, 2) === 100.00) dan bebas galat floating point.
    const persenBulat = Math.round(totalPersen * 100) / 100;
    const badge = document.getElementById('totalPersenBadge');
    badge.textContent = persenBulat.toFixed(2) + '%';

    // Valid hanya bila total persentase TEPAT 100%
    const isValid = persenBulat === 100;

    // ── Indikator hijau/merah + kunci tombol submit ──
    const indicator = document.getElementById('alokasiIndicator');
    const indIcon   = document.getElementById('alokasiIndicatorIcon');
    const indText   = document.getElementById('alokasiIndicatorText');
    const hint      = document.getElementById('alokasiHint');
    const btnDraft  = document.getElementById('btnSimpanDraft');
    const btnProses = document.getElementById('btnProsesLangsung');

    if (isValid) {
        // HIJAU — form siap di-submit
        badge.style.background = '#dcfce7'; badge.style.color = '#166534';
        indicator.classList.remove('is-invalid');
        indicator.classList.add('is-valid');
        indIcon.innerHTML  = '&#10004;';
        indText.textContent = 'Total alokasi: 100% — form siap disubmit.';
        btnDraft.disabled  = false;
        btnProses.disabled = false;
        hint.style.display = 'none';
    } else {
        // MERAH — submit dikunci
        badge.style.background = '#fee2e2'; badge.style.color = '#991b1b';
        indicator.classList.remove('is-valid');
        indicator.classList.add('is-invalid');
        indIcon.innerHTML  = '&#10005;';
        indText.textContent = 'Total alokasi harus tepat 100% (saat ini ' + persenBulat.toFixed(2) + '%).';
        btnDraft.disabled  = true;
        btnProses.disabled = true;
        hint.style.display = 'block';
    }
}

// Pasang event listeners
document.getElementById('totalShu').addEventListener('input', hitungPreview);

// 'change' ikut dipasang agar nilai dari spinner/autofill juga terhitung
document.querySelectorAll('.persen-input').forEach(el => {
    el.addEventListener('input',  hitungPreview);
    el.addEventListener('change', hitungPreview);
});

// ── Validasi input: buang karakter non-numerik ──
// Input bertipe number bisa menerima 'e', '+', '-', dan eksponen (mis. "1e5")
// yang membingungkan pengurus. Kita bersihkan agar hanya digit & satu titik
// desimal yang tersisa, lalu hitung ulang. Nilai tidak sah dikosongkan agar
// perhitungan jatuh ke 0 (bukan NaN).
function bersihkanInputNumerik(el, izinkanDesimal) {
    const asli = el.value;
    if (asli === '') return false;

    let bersih = asli.replace(/[^0-9.,]/g, '');

    if (izinkanDesimal) {
        // Normalisasi koma menjadi titik, izinkan hanya satu titik desimal
        bersih = bersih.replace(/,/g, '.');
        const bagian = bersih.split('.');
        bersih = bagian.shift() + (bagian.length ? '.' + bagian.join('') : '');
    } else {
        bersih = bersih.replace(/[.,]/g, '');
    }

    if (bersih !== asli) {
        el.value = bersih;
        return true;
    }
    return false;
}

// Total SHU: hanya digit (rupiah bulat) — tolak huruf/eksponen/simbol
document.getElementById('totalShu').addEventListener('input', function () {
    if (bersihkanInputNumerik(this, false)) hitungPreview();
});

// Persentase: digit + satu titik desimal, dan dibatasi 0–100
document.querySelectorAll('.persen-input').forEach(el => {
    el.addEventListener('input', function () {
        let diubah = bersihkanInputNumerik(this, true);

        // Batasi rentang 0–100 secara langsung saat mengetik
        const n = parseFloat(this.value);
        if (isFinite(n)) {
            if (n > 100) { this.value = '100'; diubah = true; }
            if (n < 0)   { this.value = '0';   diubah = true; }
        }

        if (diubah) hitungPreview();
    });
});

// Guard terakhir: cegah submit via Enter bila total bukan 100%
document.getElementById('formShu').addEventListener('submit', function (e) {
    const totalPersen = Array.from(document.querySelectorAll('.persen-input'))
        .reduce((s, el) => s + angkaDari(el), 0);
    if (Math.round(totalPersen * 100) / 100 !== 100) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Alokasi Tidak Valid',
            text: 'Total alokasi harus tepat 100%. Saat ini: ' + (Math.round(totalPersen * 100) / 100).toFixed(2) + '%',
            confirmButtonColor: '#059669',
        });
    }
});

hitungPreview(); // Hitung saat load (jika ada old value)

// ── Tombol 'Proses & Distribusikan SHU' dari form baru ──
document.getElementById('btnProsesLangsung').addEventListener('click', function () {
    const tahun   = document.getElementById('tahunBuku').value.trim();
    const total   = angkaDari(document.getElementById('totalShu'));
    const totalPersen = Array.from(document.querySelectorAll('.persen-input'))
                            .reduce((s, el) => s + angkaDari(el), 0);

    if (!tahun) {
        Swal.fire({ icon: 'warning', title: 'Tahun Buku Kosong', text: 'Masukkan tahun buku terlebih dahulu.', confirmButtonColor: '#059669' });
        return;
    }
    if (total <= 0) {
        Swal.fire({ icon: 'warning', title: 'Total SHU Kosong', text: 'Masukkan nilai Total SHU Bersih terlebih dahulu.', confirmButtonColor: '#059669' });
        return;
    }
    if (Math.round(totalPersen * 100) / 100 !== 100) {
        Swal.fire({ icon: 'warning', title: 'Alokasi Tidak Valid', text: 'Total alokasi harus tepat 100%. Saat ini: ' + (Math.round(totalPersen * 100) / 100).toFixed(2) + '%', confirmButtonColor: '#059669' });
        return;
    }

    Swal.fire({
        title: '<strong>Konfirmasi Distribusi SHU</strong>',
        icon: 'question',
        html: `
            <div style="text-align:left;font-size:0.9rem;line-height:1.7;">
                Apakah Anda yakin ingin membagikan <strong>SHU Tahun ${tahun}</strong>
                sebesar <strong style="color:#059669;">${formatRp(total)}</strong>
                ke seluruh anggota?<br><br>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.6rem;padding:0.75rem 1rem;font-size:0.82rem;color:#166534;">
                    &#9888; Transaksi ini akan otomatis:<br>
                    &bull; Mencatat Jurnal Umum pembagian SHU<br>
                    &bull; Memperbarui saldo simpanan seluruh anggota aktif<br>
                    &bull; Mengunci periode SHU (tidak dapat diubah)
                </div>
            </div>`,
        showCancelButton: true,
        confirmButtonText: '&#9889; Ya, Proses & Distribusikan',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#059669',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: true,
    }).then(result => {
        if (result.isConfirmed) {
            // Set action ke store, tambah flag _proses agar controller tahu
            const form = document.getElementById('formShu');
            // Tambah hidden input _proses
            let inp = document.getElementById('_prosesFlag');
            if (!inp) {
                inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = '_proses';
                inp.id    = '_prosesFlag';
                form.appendChild(inp);
            }
            inp.value = '1';
            form.submit();
        }
    });
});

// ── Konfirmasi proses dari tabel riwayat ──
function konfirmasiProsesRiwayat(tahun, nominal, actionUrl) {
    Swal.fire({
        title: '<strong>Proses SHU ' + tahun + '</strong>',
        icon: 'question',
        html: `
            <div style="text-align:left;font-size:0.9rem;line-height:1.7;">
                Apakah Anda yakin ingin membagikan <strong>SHU Tahun ${tahun}</strong>
                sebesar <strong style="color:#059669;">${nominal}</strong>
                ke seluruh anggota?<br><br>
                <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.6rem;padding:0.75rem 1rem;font-size:0.82rem;color:#9a3412;">
                    &#9888; Tindakan ini <strong>tidak dapat dibatalkan</strong>.<br>
                    Jurnal Umum akan dibuat dan saldo anggota akan diperbarui.
                </div>
            </div>`,
        showCancelButton: true,
        confirmButtonText: '&#9889; Ya, Proses',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#059669',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
    }).then(result => {
        if (result.isConfirmed) {
            const form = document.getElementById('formProsesPlaceholder');
            form.action = actionUrl;
            form.submit();
        }
    });
}

// ══════════════════════════════════════════════════════════════════════
//  MODAL DETAIL — Rincian SHU per Anggota
// ══════════════════════════════════════════════════════════════════════
const modalDetail   = document.getElementById('modalDetailShu');
const modalBody     = document.getElementById('modalDetailBody');
const modalSubjudul = document.getElementById('modalDetailSubjudul');
const modalJumlah   = document.getElementById('modalDetailJumlah');
const modalCari     = document.getElementById('modalDetailCari');

// State modal: data lengkap + pengurutan aktif
let stateDetail = {
    data: [],        // seluruh baris dari server (sudah terurut nama dari backend)
    kunciUrut: 'nama_anggota',
    arahUrut: 'asc', // default: urut berdasarkan nama anggota (A→Z)
    cari: '',
};

// Definisi kolom tabel modal — satu sumber kebenaran untuk header, sorting,
// dan pengurutan nilai, sehingga tampilan & logika tidak bisa berbeda.
const KOLOM_DETAIL = [
    { kunci: 'nama_anggota',   label: 'Nama Anggota',    kelas: 'kolom-nama',     sortable: true,  opsional: false },
    { kunci: '__no',           label: 'No. Anggota',     kelas: 'kolom-no',       sortable: true,  opsional: true, tipe: 'teks' },
    { kunci: 'simpanan',       label: 'Simpanan',        kelas: 'kolom-nominal',  sortable: true,  opsional: false, tipe: 'angka' },
    { kunci: 'margin',         label: 'Margin Transaksi',kelas: 'kolom-nominal',  sortable: true,  opsional: true,  tipe: 'angka' },
    { kunci: 'shu_modal',      label: 'Dapat SHU Modal', kelas: 'kolom-nominal',  sortable: true,  opsional: false, tipe: 'angka' },
    { kunci: 'shu_transaksi',  label: 'Dapat SHU Transaksi', kelas: 'kolom-nominal', sortable: true, opsional: true, tipe: 'angka' },
    { kunci: 'total_shu',      label: 'Total SHU Diterima',  kelas: 'kolom-nominal', sortable: true, opsional: false, tipe: 'angka' },
];

// Ambil nilai pembanding untuk pengurutan berdasarkan kunci kolom.
function nilaiUrut(baris, kunci) {
    if (kunci === '__no') return (baris.no_anggota || '').toLowerCase();
    if (kunci === 'nama_anggota') return (baris.nama_anggota || '').toLowerCase();
    const r = baris.raw || {};
    return typeof r[kunci] === 'number' ? r[kunci] : 0;
}

// Filter baris berdasarkan kata kunci pencarian (nama atau no. anggota).
function barisTersaring() {
    const q = stateDetail.cari.trim().toLowerCase();
    if (!q) return stateDetail.data.slice();
    return stateDetail.data.filter(b =>
        (b.nama_anggota || '').toLowerCase().includes(q) ||
        (b.no_anggota || '').toLowerCase().includes(q)
    );
}

// Urutkan baris sesuai state aktif.
function barisTerurut() {
    const rows = barisTersaring();
    const { kunciUrut, arahUrut } = stateDetail;
    const faktor = arahUrut === 'asc' ? 1 : -1;

    rows.sort((a, b) => {
        const va = nilaiUrut(a, kunciUrut);
        const vb = nilaiUrut(b, kunciUrut);

        // Pembanding teks memakai localeCompare agar "Ahmad" < "Budi" benar
        if (typeof va === 'string' || typeof vb === 'string') {
            return String(va).localeCompare(String(vb), 'id') * faktor;
        }
        if (va === vb) return 0;
        return (va < vb ? -1 : 1) * faktor;
    });

    return rows;
}

// Render tabel rincian ke dalam modal.
function renderTabelDetail() {
    const rows = barisTerurut();

    // Hitung total kolom nominal dari SELURUH data (bukan hanya yang tersaring)
    // agar footer valid dengan periode yang dipilih.
    const totalSimpanan = stateDetail.data.reduce((s, b) => s + (b.raw?.simpanan || 0), 0);
    const totalMargin   = stateDetail.data.reduce((s, b) => s + (b.raw?.margin || 0), 0);
    const totalModal    = stateDetail.data.reduce((s, b) => s + (b.raw?.shu_modal || 0), 0);
    const totalTrx      = stateDetail.data.reduce((s, b) => s + (b.raw?.shu_transaksi || 0), 0);
    const totalShu      = stateDetail.data.reduce((s, b) => s + (b.raw?.total_shu || 0), 0);

    modalJumlah.textContent = stateDetail.cari
        ? rows.length + ' dari ' + stateDetail.data.length + ' anggota'
        : stateDetail.data.length + ' anggota';

    if (rows.length === 0) {
        modalBody.innerHTML =
            '<div class="shu-modal-empty">' +
            (stateDetail.data.length === 0
                ? '&#128193; Belum ada data distribusi untuk periode ini.<br><small>Periode masih berstatus draf atau belum diproses.</small>'
                : '&#128269; Tidak ada anggota yang cocok dengan pencarian &ldquo;' + escapeHtml(stateDetail.cari) + '&rdquo;.') +
            '</div>';
        return;
    }

    // Header dengan indikator sorting
    let html = '<table class="shu-modal-table"><thead><tr>';
    KOLOM_DETAIL.forEach(k => {
        const aktif = stateDetail.kunciUrut === k.kunci;
        const ind = aktif ? (stateDetail.arahUrut === 'asc' ? '&#9650;' : '&#9660;') : '&#8645;';
        const kelas = [
            k.sortable ? 'sortable' : '',
            aktif ? 'sort-aktif' : '',
            k.opsional ? 'kol-opsional' : '',
            k.kelas,
        ].filter(Boolean).join(' ');
        html += '<th class="' + kelas + '"' +
            (k.sortable ? ' onclick="urutkanDetail(\'' + k.kunci + '\')"' : '') +
            ' title="' + (k.sortable ? 'Klik untuk mengurutkan' : '') + '">' +
            k.label + '<span class="sort-ind">' + ind + '</span></th>';
    });
    html += '</tr></thead><tbody>';

    rows.forEach(b => {
        html += '<tr>';
        KOLOM_DETAIL.forEach(k => {
            const ops = k.opsional ? ' kol-opsional' : '';
            if (k.kunci === '__no') {
                html += '<td class="' + k.kelas + ops + '">' + escapeHtml(b.no_anggota || '-') + '</td>';
            } else if (k.kunci === 'nama_anggota') {
                html += '<td class="' + k.kelas + ops + '">' + escapeHtml(b.nama_anggota) + '</td>';
            } else {
                html += '<td class="' + k.kelas + ops + '">' + escapeHtml(b[k.kunci] || 'Rp 0') + '</td>';
            }
        });
        html += '</tr>';
    });

    html += '</tbody><tfoot><tr>';
    // Urutan sel footer harus sejajar kolom:
    // Nama(1) + No(2) = colspan 2, lalu Simpanan, Margin, SHU Modal, SHU Trx, Total
    html += '<td colspan="2">TOTAL (' + stateDetail.data.length + ' anggota)</td>';
    html += '<td class="kolom-nominal">' + formatRp(totalSimpanan) + '</td>';
    html += '<td class="kolom-nominal kol-opsional">' + formatRp(totalMargin) + '</td>';
    html += '<td class="kolom-nominal">' + formatRp(totalModal) + '</td>';
    html += '<td class="kolom-nominal kol-opsional">' + formatRp(totalTrx) + '</td>';
    html += '<td class="kolom-nominal">' + formatRp(totalShu) + '</td>';
    html += '</tr></tfoot></table>';

    modalBody.innerHTML = html;
}

// Escape HTML agar nama anggota tidak bisa menyuntikkan markup.
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str === null || str === undefined ? '' : String(str);
    return div.innerHTML;
}

// Ganti kolom pengurutan; klik ulang pada kolom sama membalik arah.
function urutkanDetail(kunci) {
    if (stateDetail.kunciUrut === kunci) {
        stateDetail.arahUrut = stateDetail.arahUrut === 'asc' ? 'desc' : 'asc';
    } else {
        stateDetail.kunciUrut = kunci;
        stateDetail.arahUrut  = 'asc';
    }
    renderTabelDetail();
}

// Buka modal & muat data periode terpilih.
function bukaModalDetail(btn) {
    const url   = btn.getAttribute('data-detail-url');
    const tahun = btn.getAttribute('data-tahun') || '';

    // Reset state setiap kali dibuka agar tidak membawa sisa filter periode lalu
    stateDetail = { data: [], kunciUrut: 'nama_anggota', arahUrut: 'asc', cari: '' };
    modalCari.value = '';
    modalJumlah.textContent = '';
    modalSubjudul.textContent = 'Tahun Buku ' + tahun + ' — memuat data\u2026';
    modalBody.innerHTML =
        '<div class="shu-modal-loading"><div class="shu-modal-spinner"></div>Memuat rincian&hellip;</div>';

    bukaLapisanModal();

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    })
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(json => {
            if (!json.success) throw new Error(json.message || 'Gagal memuat data');

            stateDetail.data = json.data || [];

            const p = json.periode || {};
            modalSubjudul.innerHTML =
                'Tahun Buku <strong>' + escapeHtml(p.tahun_buku || tahun) + '</strong>' +
                ' &middot; Total SHU ' + escapeHtml(p.total_shu_bersih || 'Rp 0') +
                ' &middot; Jasa Modal ' + escapeHtml(p.total_jasa_modal || 'Rp 0') +
                ' &middot; Jasa Transaksi ' + escapeHtml(p.total_jasa_transaksi || 'Rp 0');

            renderTabelDetail();
        })
        .catch(err => {
            modalSubjudul.textContent = 'Tahun Buku ' + tahun;
            modalBody.innerHTML =
                '<div class="shu-modal-empty">&#9888; Gagal memuat rincian: ' +
                escapeHtml(err.message) +
                '<br><small>Silakan tutup modal dan coba lagi.</small></div>';
        });
}

function bukaLapisanModal() {
    modalDetail.classList.add('is-open');
    modalDetail.setAttribute('aria-hidden', 'false');
    // Cegah halaman di belakang ikut ter-scroll saat modal terbuka
    document.body.style.overflow = 'hidden';
}

function tutupModalDetail() {
    modalDetail.classList.remove('is-open');
    modalDetail.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

// Tutup via tombol silang
document.getElementById('modalDetailTutup').addEventListener('click', tutupModalDetail);

// Tutup via klik di luar area modal (pada backdrop saja — klik di dalam
// kartu modal tidak menutup, agar pengurus bisa menyalin angka).
modalDetail.addEventListener('click', function (e) {
    if (e.target === modalDetail) tutupModalDetail();
});

// Tutup via tombol Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modalDetail.classList.contains('is-open')) {
        tutupModalDetail();
    }
});

// Pencarian sederhana — filter langsung saat mengetik
let timerCari = null;
modalCari.addEventListener('input', function () {
    clearTimeout(timerCari);
    const nilai = this.value;
    // Debounce ringan 120ms agar render tetap mulus pada daftar besar
    timerCari = setTimeout(function () {
        stateDetail.cari = nilai;
        renderTabelDetail();
    }, 120);
});
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengurus/shu/index.blade.php ENDPATH**/ ?>