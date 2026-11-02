<?php $__env->startSection('title', 'Deposito Saya'); ?>
<?php $__env->startSection('page-title', 'Deposito / Simpanan Berjangka'); ?>
<?php $__env->startSection('content'); ?>

<?php
    $labelStatus = [
        \App\Models\Deposito::STATUS_PENDING => ['Menunggu', 'badge-amber'],
        \App\Models\Deposito::STATUS_ACTIVE  => ['Aktif', 'badge-green'],
        \App\Models\Deposito::STATUS_MATURED => ['Jatuh Tempo', 'badge-blue'],
        \App\Models\Deposito::STATUS_CLOSED  => ['Ditutup', 'badge-gray'],
    ];
?>


<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div style="background: linear-gradient(135deg, #065f46 0%, #059669 100%); border-radius: 1rem; padding: 1.5rem; color: white; box-shadow: 0 4px 16px rgba(5,150,105,0.25);">
        <div style="font-size: 0.8rem; opacity: 0.85; margin-bottom: 0.35rem;">Total Pokok Deposito Aktif</div>
        <div style="font-size: 1.75rem; font-weight: 700; letter-spacing: -0.5px;">Rp <?php echo e(number_format($totalPokok, 0, ',', '.')); ?></div>
    </div>
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,0.05);">
        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.35rem;">Akumulasi Bagi Hasil Diterima</div>
        <div style="font-size: 1.75rem; font-weight: 700; color: #047857; letter-spacing: -0.5px;">Rp <?php echo e(number_format($totalBagiHasil, 0, ',', '.')); ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Bilyet Deposito Saya</span>
    </div>

    <?php if($deposito->isEmpty()): ?>
        <div style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">📄</div>
            <div style="font-size: 1rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem;">Belum ada deposito</div>
            <div style="font-size: 0.85rem;">Hubungi petugas untuk membuka Simpanan Berjangka (Mudharabah Muthlaqah)</div>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem;">
            <?php $__currentLoopData = $deposito; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                [$statusLabel, $statusBadge] = $labelStatus[$d->status] ?? [ucfirst((string) $d->status), 'badge-gray'];
            ?>
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1rem;">
                    <div>
                        <span class="badge <?php echo e($statusBadge); ?>"><?php echo e($statusLabel); ?></span>
                        <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.35rem;"><?php echo e($d->deposito_number); ?></div>
                    </div>
                    <div style="text-align: right;">
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Nisbah Bagi Hasil</div>
                        <div style="font-size: 1rem; font-weight: 700; color: #1e293b;">
                            <?php echo e(rtrim(rtrim(number_format((float) $d->nisbah_anggota, 2, ',', '.'), '0'), ',')); ?>% : <?php echo e(rtrim(rtrim(number_format((float) $d->nisbah_koperasi, 2, ',', '.'), '0'), ',')); ?>%
                        </div>
                        <div style="font-size: 0.68rem; color: #94a3b8;">Anggota : Koperasi</div>
                    </div>
                </div>

                <div style="border-top: 1px solid #f1f5f9; padding-top: 1rem; display: grid; grid-template-columns: repeat(auto-fit,minmax(230px,1fr)); gap: 0.75rem;">
                    <div>
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Pokok</div>
                        <div style="font-size: 1.1rem; font-weight: 700; color: #1e293b;"><?php echo e($d->principal_formatted); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Tenor</div>
                        <div style="font-size: 1.1rem; font-weight: 700; color: #1e293b;"><?php echo e($d->tenorMonths()); ?> bulan</div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Mulai</div>
                        <div style="font-size: 0.85rem; color: #334155;"><?php echo e($d->start_date?->format('d/m/Y') ?? '-'); ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Jatuh Tempo</div>
                        <div style="font-size: 0.85rem; color: #334155;"><?php echo e($d->maturity_date?->format('d/m/Y') ?? '-'); ?></div>
                    </div>
                </div>

                <div style="margin-top: 1rem; border-top: 1px solid #f1f5f9; padding-top: 1rem; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Akumulasi Bagi Hasil</div>
                        <div style="font-size: 1.1rem; font-weight: 700; color: #047857;">Rp <?php echo e(number_format($d->akumulasiBagiHasil(), 0, ',', '.')); ?></div>
                    </div>
                    <a href="<?php echo e(route('nasabah.deposito.bagi-hasil', ['deposito' => $d->id])); ?>" class="btn btn-primary btn-sm">
                        Rincian Bagi Hasil
                    </a>
                </div>

                <div style="margin-top: 0.75rem; font-size: 0.72rem; color: #64748b;">
                    Mode perpanjangan: <strong><?php echo e($d->aro_label); ?></strong>
                </div>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    <?php endif; ?>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/nasabah/deposito.blade.php ENDPATH**/ ?>