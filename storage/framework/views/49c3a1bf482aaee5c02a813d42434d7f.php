
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#128274; Limit Transaksi per Role</span>
        <span style="font-size:0.78rem;color:#94a3b8;">Sumber: tabel limit_transaksi_role (dapat diubah)</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Role</th>
                <th>Jenis Transaksi</th>
                <th>Limit per Transaksi</th>
                <th>Butuh Approval</th>
                <th>Approval Oleh</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $limitTransaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td>
                    <span class="badge <?php echo e(\App\Services\NavigationService::warnaRole($l->role)); ?>">
                        <?php echo e(\App\Support\HierarkiRole::label($l->role)); ?>

                    </span>
                </td>
                <td><?php echo e(str_replace('_', ' ', ucfirst($l->jenis_transaksi))); ?></td>
                <td>
                    <?php if(is_null($l->limit_per_transaksi)): ?>
                        <span style="color:#059669;font-weight:600;">&#8734; Unlimited</span>
                    <?php elseif($l->limit_per_transaksi == 0): ?>
                        <span style="color:#ef4444;font-weight:600;">&#128683; Dilarang</span>
                    <?php else: ?>
                        <strong>Rp <?php echo e(number_format($l->limit_per_transaksi, 0, ',', '.')); ?></strong>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($l->butuh_approval): ?>
                        <span class="badge badge-amber">Ya</span>
                    <?php else: ?>
                        <span class="badge badge-green">Tidak</span>
                    <?php endif; ?>
                </td>
                <td><?php echo e($l->approval_role ? \App\Support\HierarkiRole::label($l->approval_role) : '-'); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="5" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada konfigurasi limit
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/pengurus-limit.blade.php ENDPATH**/ ?>