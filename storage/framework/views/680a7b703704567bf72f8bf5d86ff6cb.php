<?php $__env->startSection('title', 'Dashboard Account Officer — ' . config('app.name')); ?>
<?php $__env->startSection('page-title', 'Dashboard Account Officer'); ?>


<?php $__env->startSection('content'); ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Account Officer'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<div id="ao-stats" data-denyut-domain="pembiayaan approval"
     data-denyut-url="<?php echo e(route('dashboard.ao.ringkas')); ?>" data-denyut-sasaran="stats">
    <?php echo $__env->make('dashboard.partials.ao-stats', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="ao-menunggu" data-denyut-domain="pembiayaan"
     data-denyut-url="<?php echo e(route('dashboard.ao.ringkas')); ?>" data-denyut-sasaran="menunggu">
    <?php echo $__env->make('dashboard.partials.ao-menunggu', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="ao-riwayat" data-denyut-domain="pembiayaan"
     data-denyut-url="<?php echo e(route('dashboard.ao.ringkas')); ?>" data-denyut-sasaran="riwayat">
    <?php echo $__env->make('dashboard.partials.ao-riwayat', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\ao.blade.php ENDPATH**/ ?>