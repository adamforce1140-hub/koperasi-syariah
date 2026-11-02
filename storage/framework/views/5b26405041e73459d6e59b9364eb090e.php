
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#128203; Jurnal Terbaru</span>
        <a href="<?php echo e(route('laporan.buku-besar')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua &rarr;
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Ref</th>
                <th>Tanggal</th>
                <th>Keterangan</th>
                <th>Status</th>
                <th>Dibuat Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $jurnalTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $j): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><strong style="color:#059669;"><?php echo e($j->no_ref); ?></strong></td>
                <td><?php echo e(optional($j->tanggal_jurnal)->format('d/m/Y')); ?></td>
                <td style="color:#64748b;"><?php echo e(Str::limit($j->keterangan, 45)); ?></td>
                <td>
                    <span class="badge
                        <?php if($j->isPosted()): ?> badge-green
                        <?php elseif($j->isVoid()): ?> badge-red
                        <?php else: ?> badge-amber
                        <?php endif; ?>">
                        <?php echo e($j->status); ?>

                    </span>
                </td>
                <td><?php echo e($j->createdBy->name ?? '-'); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="5" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada jurnal
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/pengurus-jurnal.blade.php ENDPATH**/ ?>