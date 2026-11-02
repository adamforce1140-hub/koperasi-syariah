
<?php
    $rolePengguna     = auth()->user()?->role;
    $bolehLihatMutasi = \App\Support\HierarkiRole::dalamKelompok($rolePengguna, 'lihat_mutasi');
?>

<?php if($bolehLihatMutasi): ?>
<div id="tabel-mutasi-terakhir"
     style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
            box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;">
        <div style="font-size:0.85rem;font-weight:700;color:#1e293b;">10 Mutasi Terakhir</div>
    </div>
    <?php if($mutasi->count() > 0): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.65rem 1.25rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Tanggal</th>
                    <th style="padding:0.65rem 1rem;text-align:left;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jenis</th>
                    <th style="padding:0.65rem 1rem;text-align:center;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Posisi</th>
                    <th style="padding:0.65rem 1rem;text-align:right;font-size:0.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nominal</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $mutasi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php $isMasuk = $trx->isMasuk(); ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1.25rem;font-size:0.8rem;color:#64748b;">
                        <?php echo e($trx->waktu_transaksi ? \Carbon\Carbon::parse($trx->waktu_transaksi)->format('d M Y') : '—'); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;font-size:0.825rem;color:#1e293b;"><?php echo e($trx->jenis_transaksi); ?></td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.2rem 0.6rem;
                                     background:<?php echo e($isMasuk ? '#d1fae5' : '#fee2e2'); ?>;
                                     color:<?php echo e($isMasuk ? '#065f46' : '#991b1b'); ?>;
                                     font-size:0.75rem;font-weight:600;border-radius:9999px;">
                            <?php echo e($trx->posisi); ?>

                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-size:0.825rem;font-weight:600;
                               color:<?php echo e($isMasuk ? '#059669' : '#dc2626'); ?>;">
                        
                        <?php echo e($isMasuk ? '+' : '-'); ?> Rp <?php echo e(number_format($trx->jumlah, 0, ',', '.')); ?>

                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;font-size:0.825rem;color:#94a3b8;">Belum ada mutasi transaksi.</div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\partials\mutasi-terakhir.blade.php ENDPATH**/ ?>