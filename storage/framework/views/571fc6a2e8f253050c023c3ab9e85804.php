
<?php $__env->startSection('page-title', "Daftar Pengajuan Pembiayaan"); ?>

<?php $__env->startSection('title', 'Daftar Pengajuan Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Pengajuan Pembiayaan</h1>
    <p class="page-subtitle">Kelola pengajuan pembiayaan nasabah</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#f59e0b;"><?php echo e($stats['menunggu']); ?></div>
        <div style="font-size:.85rem;color:#6b7280;">Menunggu / Direview</div>
    </div>
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#10b981;"><?php echo e($stats['disetujui']); ?></div>
        <div style="font-size:.85rem;color:#6b7280;">Disetujui</div>
    </div>
    <div class="data-card" style="padding:1.2rem;text-align:center;">
        <div style="font-size:1.8rem;font-weight:700;color:#ef4444;"><?php echo e($stats['ditolak']); ?></div>
        <div style="font-size:.85rem;color:#6b7280;">Ditolak</div>
    </div>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.8rem;">
        <span class="card-title">Daftar Pengajuan</span>
        <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;">
            <form method="GET" style="display:flex;gap:.5rem;">
                <input type="text" name="search" value="<?php echo e($search); ?>"
                    placeholder="Cari nama / no. pengajuan..."
                    style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:220px;">
                <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                    <option value="">Semua Status</option>
                    <?php $__currentLoopData = $statusList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $val => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($val); ?>" <?php echo e($status == $val ? 'selected' : ''); ?>><?php echo e($label); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
                <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
            </form>
            <a href="<?php echo e(route('ao.pengajuan.create')); ?>" class="btn btn-primary" style="font-size:.85rem;padding:.5rem 1rem;">+ Pengajuan Baru</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Produk / Akad</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Status</th>
                <th>Tanggal</th>
                <th>Aksi</th>
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
                <td>
                    <div><?php echo e($item->produk?->nama_produk ?? '-'); ?></div>
                    <div style="font-size:.75rem;color:#6b7280;"><?php echo e($item->jenis_akad); ?></div>
                </td>
                <td>Rp <?php echo e(number_format($item->jumlah_pengajuan, 0, ',', '.')); ?></td>
                <td><?php echo e($item->tenor); ?> bln</td>
                <td>
                    <?php
                        $badgeMap = [
                            'Menunggu'     => 'badge-amber',
                            'Direview'     => 'badge-blue',
                            'Diproses'     => 'badge-blue',
                            'Disetujui'    => 'badge-green',
                            'Ditolak'      => 'badge-red',
                            'Perlu Revisi' => 'badge-orange',
                        ];
                        $badge = $badgeMap[$item->status] ?? 'badge-gray';
                    ?>
                    <span class="badge <?php echo e($badge); ?>"><?php echo e($item->status); ?></span>
                </td>
                <td><?php echo e($item->created_at->format('d/m/Y')); ?></td>
                <td>
                    <a href="<?php echo e(route('ao.pengajuan.show', $item->id)); ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Detail</a>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada pengajuan</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($pengajuan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($pengajuan->links()); ?></div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\ao\pengajuan\index.blade.php ENDPATH**/ ?>