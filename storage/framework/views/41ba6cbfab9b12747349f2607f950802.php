
<?php $__env->startSection('title', 'Dashboard Saya'); ?>
<?php $__env->startSection('page-title', 'Dashboard Saya'); ?>
<?php $__env->startSection('content'); ?>


<?php echo $__env->make('dashboard.partials.sapaan', ['peran' => 'Anggota', 'salam' => 'Selamat datang kembali,', 'nama' => $anggota->nama_lengkap], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>




<div id="nasabah-angsuran"
     data-denyut-domain="pembiayaan"
     data-denyut-url="<?php echo e(route('nasabah.dashboard.ringkas')); ?>"
     data-denyut-sasaran="angsuran"
     style="margin-bottom:1.75rem;">
    <?php echo $__env->make('nasabah.partials.dashboard-angsuran', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>


<div id="nasabah-metrik"
     data-denyut-domain="rekening transaksi pembiayaan"
     data-denyut-url="<?php echo e(route('nasabah.dashboard.ringkas')); ?>"
     data-denyut-sasaran="metrik"
     style="margin-bottom:1.75rem;">
    <?php echo $__env->make('nasabah.partials.dashboard-metrik', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>



<div class="card">
    <div class="card-header">
        <span class="card-title">Aksi Cepat</span>
    </div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <a href="<?php echo e(route('nasabah.pengajuan.create')); ?>" class="btn btn-primary">
            <span>➕</span> Ajukan Pembiayaan
        </a>
        <a href="<?php echo e(route('nasabah.mutasi.index')); ?>" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;">
            <span>📊</span> Lihat Mutasi
        </a>
        <a href="<?php echo e(route('nasabah.simpanan.index')); ?>" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;">
            <span>💳</span> Info Saldo
        </a>
    </div>
</div>


<div id="nasabah-transaksi"
     data-denyut-domain="transaksi"
     data-denyut-url="<?php echo e(route('nasabah.dashboard.ringkas')); ?>"
     data-denyut-sasaran="transaksi">
    <?php echo $__env->make('nasabah.partials.dashboard-transaksi', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/nasabah/dashboard.blade.php ENDPATH**/ ?>