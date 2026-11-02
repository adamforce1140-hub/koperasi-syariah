
<div class="card">
    <div class="card-header">
        <span class="card-title">5 Transaksi Terbaru</span>
        <a href="<?php echo e(route('nasabah.mutasi.index')); ?>" style="font-size: 0.8rem; color: #059669; text-decoration: none; font-weight: 500;">Lihat semua &rarr;</a>
    </div>

    <?php if($transaksiTerbaru->isEmpty()): ?>
        <div style="text-align: center; padding: 3rem 1rem; color: #94a3b8;">
            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📭</div>
            <div style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Belum ada transaksi</div>
            <div style="font-size: 0.82rem; margin-top: 0.25rem;">Transaksi Anda akan muncul di sini</div>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Jenis</th>
                    <th>Keterangan</th>
                    <th style="text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $transaksiTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                    // Arah mutasi mengikuti satu sumber kebenaran: Transaksi::isMasuk().
                    $isMasuk = $trx->isMasuk();
                ?>
                <tr>
                    <td style="white-space: nowrap; color: #64748b; font-size: 0.82rem;">
                        <?php echo e(\Carbon\Carbon::parse($trx->waktu_transaksi)->format('d/m/Y')); ?>

                    </td>
                    <td>
                        <span class="badge <?php echo e($isMasuk ? 'badge-green' : 'badge-red'); ?>">
                            <?php echo e($trx->jenis_transaksi); ?>

                        </span>
                    </td>
                    <td style="color: #475569;"><?php echo e($trx->catatan ?? '-'); ?></td>
                    <td style="text-align: right; font-weight: 600; color: <?php echo e($isMasuk ? '#16a34a' : '#dc2626'); ?>;">
                        <?php echo e($isMasuk ? '+' : '-'); ?>Rp <?php echo e(number_format($trx->jumlah, 0, ',', '.')); ?>

                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\partials\dashboard-transaksi.blade.php ENDPATH**/ ?>