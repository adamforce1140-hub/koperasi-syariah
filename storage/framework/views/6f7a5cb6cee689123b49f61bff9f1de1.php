
<?php $__env->startSection('page-title', "Tracking Status Pengajuan"); ?>

<?php $__env->startSection('title', 'Tracking Status Pengajuan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Tracking Status Pengajuan</h1>
    <p class="page-subtitle">Pantau perkembangan status semua pengajuan</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Semua Pengajuan</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="<?php echo e($search); ?>"
                placeholder="Cari nama / no. pengajuan..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Akad</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Status</th>
                <th>5C</th>
                <th>Diproses Oleh</th>
                <th>Update</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><strong><?php echo e($item->no_pengajuan); ?></strong></td>
                <td>
                    <div><?php echo e($item->anggota?->nama_lengkap ?? '-'); ?></div>
                    <div style="font-size:.75rem;color:#6b7280;"><?php echo e($item->anggota?->no_anggota); ?></div>
                </td>
                <td><?php echo e($item->jenis_akad ?? '-'); ?></td>
                <td>Rp <?php echo e(number_format($item->jumlah_pengajuan, 0, ',', '.')); ?></td>
                <td><?php echo e($item->tenor); ?> bln</td>
                <td>
                    <?php
                        $statusColors = ['Menunggu'=>'#f59e0b','Direview'=>'#3b82f6','Diproses'=>'#3b82f6','Disetujui'=>'#10b981','Ditolak'=>'#ef4444'];
                        $color = $statusColors[$item->status] ?? '#6b7280';
                    ?>
                    <span style="padding:.2rem .6rem;border-radius:4px;font-size:.78rem;font-weight:600;color:#fff;background:<?php echo e($color); ?>;">
                        <?php echo e($item->status); ?>

                    </span>
                </td>
                <td style="text-align:center;">
                    <?php if($item->analisis_5c): ?>
                        <?php $c = is_array($item->analisis_5c) ? $item->analisis_5c : json_decode($item->analisis_5c, true); ?>
                        <span style="font-weight:700;color:#0ea5e9;"><?php echo e($c['skor_total'] ?? '-'); ?>/25</span>
                    <?php else: ?>
                        <span style="color:#d1d5db;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:#6b7280;"><?php echo e($item->diprosesOleh?->name ?? '-'); ?></td>
                <td style="font-size:.8rem;color:#6b7280;"><?php echo e($item->updated_at->format('d/m/Y')); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada data</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($pengajuan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($pengajuan->links()); ?></div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\tracking\index.blade.php ENDPATH**/ ?>