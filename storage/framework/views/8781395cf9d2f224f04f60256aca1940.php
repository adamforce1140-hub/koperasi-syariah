

<?php $__env->startSection('title', 'Dashboard DPS — ' . config('app.name')); ?>
<?php $__env->startSection('page-title', 'Dashboard Dewan Pengawas Syariah'); ?>

<?php $__env->startSection('content'); ?>

<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Dewan Pengawas Syariah', 'tema' => 'ungu', 'badge' => 'READ-ONLY', 'badgeKetika' => 'Akses Audit'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>


<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.875rem;color:#92400e;">
    Sebagai DPS, Anda hanya memiliki akses <strong>baca (read-only)</strong> untuk keperluan audit kepatuhan syariah. Tidak ada aksi yang dapat dilakukan dari halaman ini.
</div>




<div id="dps-stats"
     data-denyut-domain="anggota pembiayaan transaksi"
     data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
     data-denyut-sasaran="stats">
    <?php echo $__env->make('dashboard.partials.dps-stats', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>

<div id="dps-detail" style="display:flex;flex-direction:column;gap:1.5rem;">

    
    <div id="dps-akad"
         data-denyut-domain="pembiayaan approval"
         data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
         data-denyut-sasaran="akad">
        <?php echo $__env->make('dashboard.partials.dps-akad', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    
    <div id="dps-pendapatan"
         data-denyut-domain="jurnal pembiayaan transaksi"
         data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
         data-denyut-sasaran="pendapatan">
        <?php echo $__env->make('dashboard.partials.dps-pendapatan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    
    <div id="dps-kebajikan"
         data-denyut-domain="jurnal"
         data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
         data-denyut-sasaran="kebajikan">
        <?php echo $__env->make('dashboard.partials.dps-kebajikan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    
    <div id="dps-transaksi"
         data-denyut-domain="transaksi"
         data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
         data-denyut-sasaran="transaksi">
        <?php echo $__env->make('dashboard.partials.dps-transaksi', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

    
    <div id="dps-pembiayaan"
         data-denyut-domain="pembiayaan"
         data-denyut-url="<?php echo e(route('dashboard.dps.ringkas')); ?>"
         data-denyut-sasaran="pembiayaan">
        <?php echo $__env->make('dashboard.partials.dps-pembiayaan', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/dps.blade.php ENDPATH**/ ?>