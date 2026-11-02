
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Approval Transaksi</span>
        <a href="<?php echo e(route('pengurus.approval.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua →
        </a>
    </div>
    <?php if($riwayatApproval->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Diajukan Oleh</th>
                <th>Disetujui/Ditolak Oleh</th>
                <th>Jumlah</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $riwayatApproval; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ap): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;">
                    <?php echo e($ap->updated_at->format('d/m/Y H:i')); ?>

                </td>
                <td><?php echo e($ap->pengaju?->name ?? '-'); ?></td>
                <td>
                    <?php if($ap->approver): ?>
                        <strong><?php echo e($ap->approver->name); ?></strong>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo e(ucfirst($ap->approver->role)); ?></div>
                    <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($ap->nominal ?? 0, 0, ',', '.')); ?></td>
                <td>
                    <?php if($ap->status === 'APPROVED'): ?>
                        <span class="badge badge-green">Disetujui</span>
                    <?php elseif($ap->status === 'REJECTED'): ?>
                        <span class="badge" style="background:#fee2e2;color:#dc2626;">Ditolak</span>
                    <?php else: ?>
                        <span class="badge badge-amber">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
        <div>Belum ada riwayat approval</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/pengurus-riwayat.blade.php ENDPATH**/ ?>