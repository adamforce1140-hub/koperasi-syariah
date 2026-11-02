
<?php $__env->startSection('page-title', "Riwayat Pengajuan"); ?>

<?php $__env->startSection('title', 'Riwayat Pengajuan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Riwayat Pengajuan</h1>
    <p class="page-subtitle">Daftar semua pengajuan pembiayaan Anda</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Pengajuan Pembiayaan</span>
        <a href="<?php echo e(route('nasabah.pengajuan.create')); ?>" class="btn btn-primary" style="font-size:.85rem;padding:.5rem 1rem;">
            + Ajukan Baru
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Tujuan</th>
                <th>Status</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><strong><?php echo e($item->no_pengajuan); ?></strong></td>
                <td><?php echo e($item->produk?->nama_produk ?? $item->jenis_akad); ?></td>
                <td>Rp <?php echo e(number_format($item->jumlah_pengajuan, 0, ',', '.')); ?></td>
                <td><?php echo e($item->tenor); ?> bulan</td>
                <td><?php echo e($item->tujuan); ?></td>
                <td>
                    <?php if($item->status === 'Pending' || $item->status === 'Menunggu'): ?>
                        <span class="badge badge-amber">Menunggu</span>
                    <?php elseif($item->status === 'Diproses'): ?>
                        <span class="badge badge-blue">Diproses</span>
                    <?php elseif($item->status === 'Disetujui'): ?>
                        <span class="badge badge-green">Disetujui</span>
                    <?php else: ?>
                        <span class="badge badge-red"><?php echo e($item->status); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo e($item->created_at->format('d/m/Y')); ?></td>
            </tr>
            <?php if($item->catatan_pengelola): ?>
            <tr style="background:#fafafa;">
                <td colspan="7" style="font-size:.8rem;color:#6b7280;padding:.4rem 1rem;">
                    <strong>Catatan Petugas:</strong> <?php echo e($item->catatan_pengelola); ?>

                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat pengajuan</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($pengajuan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($pengajuan->links()); ?></div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/nasabah/pengajuan/index.blade.php ENDPATH**/ ?>