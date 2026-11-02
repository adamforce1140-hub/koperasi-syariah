<?php $__env->startSection('title', 'Dashboard Manajer — ' . config('app.name')); ?>
<?php $__env->startSection('page-title', 'Dashboard Manajer'); ?>



<?php $__env->startSection('content'); ?>

<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Manajer'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<div id="manajer-stats"
     data-denyut-domain="approval transaksi anggota"
     data-denyut-url="<?php echo e(route('dashboard.manajer.ringkas')); ?>"
     data-denyut-sasaran="stats">
    <?php echo $__env->make('dashboard.partials.manajer-stats', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="manajer-approval"
     style="margin-bottom:1.5rem;"
     data-denyut-domain="approval transaksi"
     data-denyut-url="<?php echo e(route('dashboard.manajer.ringkas')); ?>"
     data-denyut-sasaran="approval">
    <?php echo $__env->make('dashboard.partials.manajer-approval', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="manajer-pembiayaan"
     data-denyut-domain="pembiayaan approval"
     data-denyut-url="<?php echo e(route('dashboard.manajer.ringkas')); ?>"
     data-denyut-sasaran="pembiayaan">
    <?php echo $__env->make('dashboard.partials.manajer-pembiayaan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="manajer-keputusan"
     data-denyut-domain="pembiayaan"
     data-denyut-url="<?php echo e(route('dashboard.manajer.ringkas')); ?>"
     data-denyut-sasaran="keputusan">
    <?php echo $__env->make('dashboard.partials.manajer-keputusan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div class="card" id="manajer-beban"
     data-denyut-domain="jurnal"
     data-denyut-url="<?php echo e(route('dashboard.manajer.ringkas')); ?>"
     data-denyut-sasaran="beban">
    <?php echo $__env->make('dashboard.partials.manajer-beban', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\manajer.blade.php ENDPATH**/ ?>