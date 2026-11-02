
<div class="card">
    <div class="card-header">
        <span class="card-title">Sampling Pembiayaan Aktif</span>
        <a href="<?php echo e(route('dps.audit-akad')); ?>" style="font-size:0.78rem;color:#7c3aed;text-decoration:none;">Lihat Semua →</a>
    </div>
    <?php if($pembiayaanAktif->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Anggota</th>
                <th>Akad</th>
                <th>Pokok</th>
                <th>Margin / Nisbah</th>
                <th>Tenor</th>
                <th>Disetujui Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $pembiayaanAktif; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td><strong><?php echo e($pb->anggota?->nama_lengkap ?? '-'); ?></strong></td>
                <td>
                    <span class="badge badge-blue" style="font-size:0.72rem;"><?php echo e($pb->akad_pembiayaan ?? '-'); ?></span>
                </td>
                <td>Rp <?php echo e(number_format($pb->jumlah_pembiayaan ?? 0, 0, ',', '.')); ?></td>
                <td><?php echo e($pb->margin_nisbah ?? '-'); ?>%</td>
                <td><?php echo e($pb->tenor ?? '-'); ?> bln</td>
                <td style="font-size:0.85rem;">
                    <?php if($pb->approvedBy): ?>
                        <?php echo e($pb->approvedBy->name); ?>

                        <div style="font-size:0.72rem;color:#64748b;"><?php echo e(ucfirst($pb->approvedBy->role)); ?></div>
                    <?php else: ?>
                        <span style="color:#94a3b8;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Tidak ada pembiayaan aktif</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/dps-pembiayaan.blade.php ENDPATH**/ ?>