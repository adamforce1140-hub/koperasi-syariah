<?php
    // Klasifikasi arah transaksi memakai registri tunggal App\Support\JenisTransaksi
    // (bukan daftar literal hardcode) agar seluruh varian nama lama tetap terbaca.
    $jenisMasuk = \App\Support\JenisTransaksi::cashIn();
?>

<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#8644; Transaksi Terakhir</span>
        <a href="<?php echo e(route('pengurus.kas.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua &rarr;
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Nominal</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $transaksiTerakhir; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><?php echo e($t->namaAnggota ?? '-'); ?></td>
                <td>
                    <span class="badge <?php echo e(in_array($t->jenis_transaksi, $jenisMasuk, true) ? 'badge-green' : 'badge-amber'); ?>">
                        <?php echo e($t->jenis_transaksi); ?>

                    </span>
                </td>
                <td>Rp <?php echo e(number_format($t->jumlah ?? 0, 0, ',', '.')); ?></td>
                <td style="color:#94a3b8;font-size:0.8rem;">
                    <?php echo e(\Carbon\Carbon::parse($t->waktu_transaksi)->format('d/m/Y')); ?>

                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr>
                <td colspan="4" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada transaksi
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\pengurus-transaksi.blade.php ENDPATH**/ ?>