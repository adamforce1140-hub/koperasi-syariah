
<?php $__env->startSection('page-title', "Pengajuan Rekening"); ?>

<?php $__env->startSection('title', 'Pengajuan Rekening'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Pengajuan Rekening Baru</h1>
        <p style="color:#94a3b8;margin:0;">Daftar pengajuan pembukaan rekening simpanan dari calon nasabah</p>
    </div>
</div>


<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">📋</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($counts['total']); ?></div>
            <div class="stat-label">Total Pengajuan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);">🔍</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($counts['menunggu_verifikasi']); ?></div>
            <div class="stat-label">Menunggu Verifikasi</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">💳</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($counts['menunggu_konfirmasi']); ?></div>
            <div class="stat-label">Menunggu Konfirmasi Bayar</div>
        </div>
    </div>
</div>


<div style="display:flex;gap:0.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <?php $__currentLoopData = ['','Menunggu Verifikasi','Disetujui','Ditolak','Menunggu Pembayaran','Menunggu Konfirmasi','Aktif']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tab): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <a href="<?php echo e(route('pengelola.pengajuan-rekening.index', $tab ? ['status'=>$tab] : [])); ?>"
       style="padding:0.4rem 1rem;border-radius:20px;font-size:0.8rem;font-weight:600;text-decoration:none;
              background:<?php echo e($status===$tab ? '#0d9488' : 'rgba(255,255,255,0.06)'); ?>;
              color:<?php echo e($status===$tab ? 'white' : '#94a3b8'); ?>;
              border:1px solid <?php echo e($status===$tab ? '#0d9488' : 'rgba(255,255,255,0.1)'); ?>;">
        <?php echo e($tab ?: 'Semua'); ?>

    </a>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>


<div class="card">
    <?php if(session('success')): ?>
    <div style="background:rgba(16,185,129,0.15);border:1px solid #10b981;color:#10b981;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>

    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Calon Nasabah</th>
                    <th>NIK</th>
                    <th>Jenis Simpanan</th>
                    <th>Nominal Awal</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($p->kode_pengajuan); ?></td>
                    <td><strong><?php echo e($p->nama_lengkap); ?></strong></td>
                    <td style="font-family:monospace;"><?php echo e($p->nik); ?></td>
                    <td><?php echo e($p->jenis_simpanan); ?></td>
                    <td>
                        <?php if($p->nominal_simpanan_awal > 0): ?>
                            Rp <?php echo e(number_format($p->nominal_simpanan_awal, 0, ',', '.')); ?>

                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo e($p->statusBadgeClass()); ?>"><?php echo e($p->status); ?></span>
                    </td>
                    <td style="color:#94a3b8;font-size:0.8rem;">
                        <?php echo e($p->created_at->format('d/m/Y')); ?>

                    </td>
                    <td>
                        <a href="<?php echo e(route('pengelola.pengajuan-rekening.show', $p)); ?>"
                           style="padding:0.3rem 0.8rem;background:#0d9488;color:white;border-radius:6px;font-size:0.8rem;text-decoration:none;">
                            Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">
                        Tidak ada data pengajuan.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:1rem;">
        <?php echo e($pengajuan->withQueryString()->links()); ?>

    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengelola/pengajuan-rekening/index.blade.php ENDPATH**/ ?>