
<div class="card-header">
    <span class="card-title">Keputusan Approval Terakhir</span>
</div>
<?php if($keputusanTerakhir->count() > 0): ?>
<table>
    <thead>
        <tr>
            <th>No. Kontrak</th>
            <th>Anggota</th>
            <th>Jumlah</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php $__currentLoopData = $keputusanTerakhir; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $st = strtolower((string) $p->status_pembiayaan); ?>
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($p->no_kontrak ?? '-'); ?></td>
            <td><strong><?php echo e($p->anggota?->nama_lengkap ?? '-'); ?></strong></td>
            <td style="font-weight:600;">Rp <?php echo e(number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.')); ?></td>
            <td>
                <?php if($st === 'disetujui'): ?>
                    <span class="badge badge-green">Disetujui</span>
                <?php elseif($st === 'ditolak'): ?>
                    <span class="badge badge-red">Ditolak</span>
                <?php elseif(in_array($st, ['pending_pengurus', 'pending_dps'])): ?>
                    <span class="badge badge-blue">Diteruskan ke <?php echo e($st === 'pending_pengurus' ? 'Pengurus' : 'DPS'); ?></span>
                <?php else: ?>
                    <span class="badge badge-amber"><?php echo e($p->status_pembiayaan); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </tbody>
</table>
<?php else: ?>
<div style="padding:2rem;text-align:center;color:#94a3b8;">
    <div>Belum ada keputusan approval</div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\manajer-keputusan.blade.php ENDPATH**/ ?>