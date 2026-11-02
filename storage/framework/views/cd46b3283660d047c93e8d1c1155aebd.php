
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">
            🔔 Notifikasi Persetujuan
            <?php if(($totalMenunggu ?? 0) > 0): ?>
                <span class="badge badge-amber" style="margin-left:0.5rem;"><?php echo e($totalMenunggu); ?> menunggu</span>
            <?php else: ?>
                <span class="badge badge-green" style="margin-left:0.5rem;">Semua tertangani</span>
            <?php endif; ?>
        </span>
        <span style="font-size:0.74rem;color:#94a3b8;">Pembaruan otomatis · real-time</span>
    </div>

    <div class="notif-list">
        <?php $__currentLoopData = $antrean['item']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $n): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $adaTujuan = ! empty($n['route']) && \Illuminate\Support\Facades\Route::has($n['route']);
                $tag = $adaTujuan ? 'a' : 'div';
            ?>
            <<?php echo e($tag); ?>

                <?php if($adaTujuan): ?> href="<?php echo e(route($n['route'])); ?>" <?php endif; ?>
                class="notif-card <?php echo e($n['jumlah'] > 0 ? '' : 'notif-card--kosong'); ?>"
                data-warna="<?php echo e($n['warna']); ?>">
                <span class="notif-icon" aria-hidden="true"><?php echo e($n['ikon']); ?></span>
                <span class="notif-body">
                    <span class="notif-jumlah"><?php echo e(number_format($n['jumlah'], 0, ',', '.')); ?></span>
                    <span class="notif-label"><?php echo e($n['label']); ?></span>
                </span>
            </<?php echo e($tag); ?>>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\super-admin-notifikasi.blade.php ENDPATH**/ ?>