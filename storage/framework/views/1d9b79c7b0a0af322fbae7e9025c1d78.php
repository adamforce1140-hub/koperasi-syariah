
<?php
    $masuk = \App\Support\JenisTransaksi::cashIn();
?>
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">🔄 Aktivitas Transaksi Terakhir</span>
        <span style="font-size:0.74rem;color:#94a3b8;">
            <?php echo e(number_format($totalTransaksiHariIni ?? 0, 0, ',', '.')); ?> transaksi hari ini
        </span>
    </div>

    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Anggota</th>
                    <th>Jenis</th>
                    <th>Posisi</th>
                    <th style="text-align:right;">Nominal</th>
                    <th>Petugas</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $transaksiTerakhir; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <?php
                        $pertambah = in_array($t->jenis_transaksi, $masuk, true);
                        $nama = $t->anggota->nama ?? ($t->rekening->anggota->nama ?? '-');
                    ?>
                    <tr>
                        <td style="color:#94a3b8;font-size:0.78rem;white-space:nowrap;">
                            <?php echo e($t->waktu_transaksi ? \Carbon\Carbon::parse($t->waktu_transaksi)->format('d/m/Y H:i') : '-'); ?>

                        </td>
                        <td><?php echo e($nama); ?></td>
                        <td>
                            <span class="badge <?php echo e($pertambah ? 'badge-green' : 'badge-amber'); ?>">
                                <?php echo e($t->jenis_transaksi); ?>

                            </span>
                        </td>
                        <td style="color:#64748b;font-size:0.78rem;"><?php echo e($t->posisi ?? '-'); ?></td>
                        <td style="text-align:right;font-weight:600;white-space:nowrap;">
                            Rp <?php echo e(number_format($t->jumlah ?? 0, 0, ',', '.')); ?>

                        </td>
                        <td style="color:#64748b;font-size:0.78rem;"><?php echo e($t->user->name ?? 'Sistem'); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                            Belum ada transaksi
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/super-admin-aktivitas.blade.php ENDPATH**/ ?>