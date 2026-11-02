
<div class="card-header">
    <span class="card-title">Approval Beban Terakhir</span>
</div>
<?php if($bebanKeputusan->count() > 0): ?>
<table>
    <thead>
        <tr>
            <th>No. Pengeluaran</th>
            <th>Penginput</th>
            <th>Akun Beban</th>
            <th>Nominal</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php $__currentLoopData = $bebanKeputusan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($bb->no_pengeluaran); ?></td>
            <td><?php echo e($bb->penginput?->name ?? '-'); ?></td>
            <td style="font-size:0.82rem;"><?php echo e($bb->coaBeban?->nama_akun ?? '-'); ?></td>
            <td style="font-weight:600;">Rp <?php echo e(number_format($bb->nominal, 0, ',', '.')); ?></td>
            <td>
                <span class="badge <?php echo e($bb->status === 'Approved' ? 'badge-green' : 'badge-red'); ?>">
                    <?php echo e($bb->status); ?>

                </span>
            </td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </tbody>
</table>
<?php else: ?>
<div style="padding:2rem;text-align:center;color:#94a3b8;">
    <div>Belum ada approval beban</div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/manajer-beban.blade.php ENDPATH**/ ?>