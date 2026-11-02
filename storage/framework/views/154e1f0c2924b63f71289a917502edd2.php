<?php $__env->startSection('title', 'Rekening Saya'); ?>
<?php $__env->startSection('page-title', 'Rekening Saya'); ?>
<?php $__env->startSection('content'); ?>
<div class="card">
    <div class="card-header"><span class="card-title">Daftar Rekening</span></div>
    <?php $__empty_1 = true; $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <div style="background:#f8fafc;border-radius:0.75rem;padding:1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-size:0.75rem;color:#64748b;">No. Rekening</div>
            <div style="font-size:1.1rem;font-weight:700;letter-spacing:0.05em;"><?php echo e($r->no_rekening); ?></div>
            <div style="font-size:0.82rem;color:#64748b;margin-top:0.25rem;"><?php echo e($r->jenis_rekening ?? '-'); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:0.75rem;color:#64748b;">Saldo</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;">Rp <?php echo e(number_format($r->saldo, 0, ',', '.')); ?></div>
        </div>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <p style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada rekening</p>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\rekening.blade.php ENDPATH**/ ?>