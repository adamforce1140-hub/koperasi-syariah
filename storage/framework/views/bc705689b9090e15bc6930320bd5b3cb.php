<?php $__env->startSection('title', 'Informasi Saldo Simpanan'); ?>
<?php $__env->startSection('page-title', 'Informasi Saldo'); ?>
<?php $__env->startSection('content'); ?>


<div style="background: linear-gradient(135deg, #065f46 0%, #059669 100%); border-radius: 1rem; padding: 2rem; margin-bottom: 1.75rem; color: white; box-shadow: 0 4px 16px rgba(5,150,105,0.25);">
    <div style="font-size: 0.85rem; opacity: 0.85; margin-bottom: 0.4rem;">Total Saldo Seluruh Rekening</div>
    <div style="font-size: 2.25rem; font-weight: 700; letter-spacing: -0.5px;">Rp <?php echo e(number_format($totalSaldo, 0, ',', '.')); ?></div>
    <div style="font-size: 0.78rem; opacity: 0.75; margin-top: 0.5rem;"><?php echo e(count($rekening)); ?> rekening terdaftar</div>
</div>


<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Rekening Simpanan</span>
    </div>

    <?php if($rekening->isEmpty()): ?>
        <div style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🏦</div>
            <div style="font-size: 1rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem;">Belum ada rekening simpanan</div>
            <div style="font-size: 0.85rem;">Hubungi petugas untuk membuka rekening simpanan</div>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem;">
            <?php $__currentLoopData = $rekening; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $rek): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $jenis = strtolower($rek->jenis_rekening ?? $rek->jenis_simpanan ?? '');
                if (str_contains($jenis, 'pokok')) {
                    $badgeClass = 'badge-blue';
                    $iconBg    = '#dbeafe';
                    $icon      = '🔵';
                } elseif (str_contains($jenis, 'wajib')) {
                    $badgeClass = 'badge-green';
                    $iconBg    = '#dcfce7';
                    $icon      = '🟢';
                } elseif (str_contains($jenis, 'sukarela')) {
                    $badgeClass = 'badge-purple';
                    $iconBg    = '#f3e8ff';
                    $icon      = '🟣';
                } elseif (str_contains($jenis, 'mudharabah')) {
                    $badgeClass = 'badge-amber';
                    $iconBg    = '#fef3c7';
                    $icon      = '🟠';
                } else {
                    $badgeClass = 'badge-gray';
                    $iconBg    = '#f1f5f9';
                    $icon      = '⚪';
                }
                $isAktif = ($rek->status_rekening ?? 'Aktif') === 'Aktif';
            ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,0.05); transition: box-shadow 0.15s;">
                
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="width: 46px; height: 46px; border-radius: 0.75rem; background: <?php echo e($iconBg); ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                            <?php echo e($icon); ?>

                        </div>
                        <div>
                            <span class="badge <?php echo e($badgeClass); ?>" style="margin-bottom: 0.25rem; display: inline-block;">
                                <?php echo e(ucfirst($rek->jenis_rekening ?? $rek->jenis_simpanan ?? 'Simpanan')); ?>

                            </span>
                            <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.15rem;"><?php echo e($rek->no_rekening); ?></div>
                        </div>
                    </div>
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; <?php echo e($isAktif ? 'background: #dcfce7; color: #166534;' : 'background: #f1f5f9; color: #64748b;'); ?>">
                        <span style="width: 6px; height: 6px; border-radius: 50%; <?php echo e($isAktif ? 'background: #16a34a;' : 'background: #94a3b8;'); ?>; display: inline-block;"></span>
                        <?php echo e($isAktif ? 'Aktif' : 'Tidak Aktif'); ?>

                    </span>
                </div>

                
                <div style="border-top: 1px solid #f1f5f9; padding-top: 1rem;">
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.05em;">Saldo</div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #1e293b;">Rp <?php echo e(number_format($rek->saldo, 0, ',', '.')); ?></div>
                </div>

                
                <a href="<?php echo e(route('nasabah.buku-tabungan.index', $rek)); ?>"
                   style="display: flex; align-items: center; justify-content: center; gap: 0.4rem; margin-top: 1rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.82rem; font-weight: 600; text-decoration: none; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;">
                    📖 Buku Tabungan
                </a>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/nasabah/simpanan.blade.php ENDPATH**/ ?>