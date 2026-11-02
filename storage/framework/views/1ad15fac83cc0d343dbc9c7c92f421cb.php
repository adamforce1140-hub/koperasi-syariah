
<?php $__env->startSection('page-title', "Data Nasabah Binaan"); ?>

<?php $__env->startSection('title', 'Data Nasabah Binaan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Nasabah Binaan</h1>
    <p class="page-subtitle">Anggota dengan riwayat pembiayaan</p>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Daftar Nasabah (<?php echo e($nasabah->total()); ?>)</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="<?php echo e($search); ?>"
                placeholder="Cari nama / no. anggota / HP..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:260px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Anggota</th>
                <th>Nama</th>
                <th>HP</th>
                <th>Pekerjaan</th>
                <th>Jumlah Pembiayaan</th>
                <th>Status Anggota</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $nasabah; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><strong><?php echo e($a->no_anggota); ?></strong></td>
                <td><?php echo e($a->nama_lengkap); ?></td>
                <td><?php echo e($a->no_hp ?? '-'); ?></td>
                <td><?php echo e($a->pekerjaan ?? '-'); ?></td>
                <td><?php echo e($a->pembiayaan->count()); ?> kontrak</td>
                <td>
                    <span class="badge <?php echo e($a->isActive() ? 'badge-green' : 'badge-gray'); ?>">
                        <?php echo e($a->status_label); ?>

                    </span>
                </td>
                <td>
                    <a href="<?php echo e(route('ao.nasabah.show', $a->id)); ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Detail</a>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada data nasabah</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($nasabah->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($nasabah->links()); ?></div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\nasabah\index.blade.php ENDPATH**/ ?>