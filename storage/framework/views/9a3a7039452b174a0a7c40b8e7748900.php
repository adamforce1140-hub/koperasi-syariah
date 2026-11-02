
<?php $__env->startSection('page-title', "Produk Pembiayaan"); ?>

<?php $__env->startSection('title', 'Produk Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
        <h1 class="page-title">Produk Pembiayaan</h1>
        <p class="page-subtitle">Pengaturan produk dan parameter pembiayaan dinamis</p>
    </div>
    <a href="<?php echo e(route('pengelola.produk-pembiayaan.create')); ?>" class="btn-primary" style="padding:0.6rem 1.2rem;border-radius:8px;text-decoration:none;font-size:0.9rem;">+ Produk Baru</a>
</div>

<?php if(session('success')): ?>
<div class="alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.25rem;">
    <?php $__empty_1 = true; $__currentLoopData = $produk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
    <div class="card" style="padding:1.25rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
            <div>
                <h3 style="color:white;margin:0 0 0.25rem;font-size:1rem;"><?php echo e($p->nama_produk); ?></h3>
                <span class="badge badge-teal"><?php echo e($p->akad_syariah); ?></span>
            </div>
            <span class="badge <?php echo e($p->status_aktif ? 'badge-green' : 'badge-red'); ?>">
                <?php echo e($p->status_aktif ? 'Aktif' : 'Nonaktif'); ?>

            </span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.5rem;font-size:0.82rem;color:#94a3b8;margin-bottom:1rem;">
            <div>Min Plafon<br><span style="color:white;">Rp <?php echo e(number_format($p->min_plafon,0,',','.')); ?></span></div>
            <div>Max Plafon<br><span style="color:white;">Rp <?php echo e(number_format($p->max_plafon,0,',','.')); ?></span></div>
            <div>Margin<br><span style="color:#f0c040;"><?php echo e($p->margin_persen); ?>% / bulan</span></div>
            <div>Biaya Admin<br><span style="color:white;">Rp <?php echo e(number_format($p->biaya_admin,0,',','.')); ?></span></div>
            <div>Min Tenor<br><span style="color:white;"><?php echo e($p->min_tenor_bulan); ?> bulan</span></div>
            <div>Max Tenor<br><span style="color:white;"><?php echo e($p->max_tenor_bulan); ?> bulan</span></div>
        </div>
        <?php if($p->syaratDokumen->count() > 0): ?>
        <div style="font-size:0.8rem;color:#64748b;margin-bottom:1rem;">
            <strong style="color:#94a3b8;">Syarat Dokumen:</strong>
            <?php $__currentLoopData = $p->syaratDokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <span style="display:inline-block;background:rgba(255,255,255,0.06);padding:0.15rem 0.5rem;border-radius:4px;margin:0.15rem;"><?php echo e($s->nama_dokumen); ?></span>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:0.5rem;">
            <a href="<?php echo e(route('pengelola.produk-pembiayaan.edit', $p)); ?>" class="btn-sm btn-primary">Edit</a>
            <form method="POST" action="<?php echo e(route('pengelola.produk-pembiayaan.destroy', $p)); ?>" style="display:inline;">
                <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Hapus produk ini?')">Hapus</button>
            </form>
        </div>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
    <p style="color:#64748b;">Belum ada produk. <a href="<?php echo e(route('pengelola.produk-pembiayaan.create')); ?>" style="color:#0d9488;">Buat sekarang</a>.</p>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengelola/produk-pembiayaan/index.blade.php ENDPATH**/ ?>