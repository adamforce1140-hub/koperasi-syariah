
<div class="card">
    <div class="card-header">
        <span class="card-title">Monitoring Transaksi Terbaru</span>
        <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.7rem;">READ-ONLY</span>
    </div>
    <?php if($transaksiTerbaru->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>No. Transaksi</th>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Jumlah</th>
                <th>Diinput Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $transaksiTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;">
                    <?php echo e($trx->waktu_transaksi ? $trx->waktu_transaksi->format('d/m/Y H:i') : '-'); ?>

                </td>
                <td style="font-family:monospace;font-size:0.78rem;"><?php echo e($trx->no_transaksi); ?></td>
                <td><?php echo e($trx->rekening?->anggota?->nama_lengkap ?? '-'); ?></td>
                <td>
                    <span class="badge <?php echo e(str_contains($trx->jenis_transaksi ?? '', 'Setor') ? 'badge-green' : (str_contains($trx->jenis_transaksi ?? '', 'Tarik') ? 'badge-amber' : 'badge-blue')); ?>">
                        <?php echo e($trx->jenis_transaksi ?? '-'); ?>

                    </span>
                </td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($trx->jumlah, 0, ',', '.')); ?></td>
                <td style="font-size:0.85rem;"><?php echo e($trx->dibuatOleh?->name ?? '-'); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada transaksi</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\dps-transaksi.blade.php ENDPATH**/ ?>