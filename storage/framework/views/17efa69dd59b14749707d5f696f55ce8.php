
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Pengajuan Saya</span>
        <a href="<?php echo e(route('ao.pengajuan.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    <?php if($pengajuanTerakhir->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Jumlah</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $pengajuanTerakhir; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($p->no_pengajuan); ?></td>
                <td><strong><?php echo e($p->anggota?->nama_lengkap ?? '-'); ?></strong></td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($p->jumlah_pengajuan, 0, ',', '.')); ?></td>
                <td>
                    <?php $st = strtolower($p->status ?? ''); ?>
                    <?php if(in_array($st, ['disetujui', 'approved'])): ?>
                        <span class="badge badge-green">Disetujui</span>
                    <?php elseif(in_array($st, ['ditolak', 'rejected'])): ?>
                        <span class="badge badge-red">Ditolak</span>
                    <?php elseif(in_array($st, ['direview'])): ?>
                        <span class="badge badge-blue">Direview</span>
                    <?php else: ?>
                        <span class="badge badge-amber"><?php echo e($p->status); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada riwayat pengajuan</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/ao-riwayat.blade.php ENDPATH**/ ?>