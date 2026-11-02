
<?php $__env->startSection('page-title', "Detail Nasabah"); ?>

<?php $__env->startSection('title', 'Detail Nasabah'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title"><?php echo e($anggota->nama_lengkap); ?></h1>
    <p class="page-subtitle"><?php echo e($anggota->no_anggota); ?></p>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;">

    
    <div>
        <div class="data-card" style="margin-bottom:1rem;">
            <div class="card-header"><span class="card-title">Profil Anggota</span></div>
            <div style="padding:1.5rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <?php
                        $rows = [
                            'NIK'           => $anggota->nik ?? '-',
                            'Jenis Kelamin' => $anggota->jenis_kelamin ?? '-',
                            'Pekerjaan'     => $anggota->pekerjaan ?? '-',
                            'No. HP'        => $anggota->no_hp ?? '-',
                            'Email'         => $anggota->email ?? '-',
                            'Alamat'        => $anggota->alamat ?? '-',
                            'Tanggal Gabung'=> $anggota->tanggal_gabung?->format('d/m/Y') ?? '-',
                            'Status'        => $anggota->status_label,
                        ];
                    ?>
                    <?php $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $val): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:.5rem 0;font-size:.8rem;color:#6b7280;width:120px;"><?php echo e($label); ?></td>
                        <td style="padding:.5rem 0;font-size:.85rem;"><?php echo e($val); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </table>
            </div>
        </div>

        <div class="data-card">
            <div class="card-header"><span class="card-title">Ringkasan Pembiayaan</span></div>
            <div style="padding:1.5rem;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center;">
                    <div style="padding:.8rem;background:#f0fdf4;border-radius:8px;">
                        <div style="font-size:1.2rem;font-weight:700;color:#166534;">Rp <?php echo e(number_format($totalPlafonAktif, 0, ',', '.')); ?></div>
                        <div style="font-size:.75rem;color:#6b7280;">Total Plafon Aktif</div>
                    </div>
                    <div style="padding:.8rem;background:#fff7ed;border-radius:8px;">
                        <div style="font-size:1.2rem;font-weight:700;color:#c2410c;">Rp <?php echo e(number_format($totalSisaPembiayaan, 0, ',', '.')); ?></div>
                        <div style="font-size:.75rem;color:#6b7280;">Sisa Pembiayaan</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
    <div>
        <div class="data-card">
            <div class="card-header"><span class="card-title">Riwayat Pembiayaan</span></div>
            <table>
                <thead>
                    <tr>
                        <th>No. Pembiayaan</th>
                        <th>Akad</th>
                        <th>Jumlah</th>
                        <th>Tenor</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Tanggal Cair</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $riwayatPembiayaan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td><strong><?php echo e($p->no_pembiayaan); ?></strong></td>
                        <td><?php echo e($p->akad_pembiayaan ?? '-'); ?></td>
                        <td>Rp <?php echo e(number_format($p->jumlah_pembiayaan, 0, ',', '.')); ?></td>
                        <td><?php echo e($p->tenor); ?> bln</td>
                        <td>Rp <?php echo e(number_format($p->sisa_pembiayaan ?? 0, 0, ',', '.')); ?></td>
                        <td>
                            <span class="badge <?php echo e($p->isStatus([\App\Models\Pembiayaan::STATUS_AKTIF, \App\Models\Pembiayaan::STATUS_DICAIRKAN]) ? 'badge-green' : 'badge-gray'); ?>">
                                <?php echo e($p->status_pembiayaan); ?>

                            </span>
                        </td>
                        <td><?php echo e($p->tanggal_cair?->format('d/m/Y') ?? '-'); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat pembiayaan</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<div class="data-card" style="margin-top:1.5rem;">
    <div class="card-header"><span class="card-title">Riwayat Transaksi (20 Terakhir)</span></div>
    <table>
        <thead>
            <tr>
                <th>No. Transaksi</th>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Posisi</th>
                <th>Jumlah</th>
                <th>Saldo Setelah</th>
                <th>Status</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $riwayatTransaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $trx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php $isMasuk = $trx->isMasuk(); ?>
            <tr>
                <td style="font-size:.8rem;font-family:monospace;"><?php echo e($trx->no_transaksi); ?></td>
                <td style="font-size:.8rem;"><?php echo e($trx->waktu_transaksi?->format('d/m/Y H:i')); ?></td>
                <td><?php echo e($trx->jenis_transaksi); ?></td>
                <td>
                    <span class="badge <?php echo e($isMasuk ? 'badge-green' : 'badge-red'); ?>">
                        <?php echo e($trx->posisi); ?>

                    </span>
                </td>
                <td style="text-align:right;font-weight:600;color:<?php echo e($isMasuk ? '#059669' : '#dc2626'); ?>;">
                    
                    <?php echo e($isMasuk ? '+' : '-'); ?> Rp <?php echo e(number_format($trx->jumlah, 0, ',', '.')); ?>

                </td>
                <td style="text-align:right;">Rp <?php echo e(number_format($trx->saldo_setelah ?? 0, 0, ',', '.')); ?></td>
                <td>
                    <span class="badge <?php echo e($trx->status_transaksi === 'Sukses' ? 'badge-green' : ($trx->status_transaksi === 'Voided' ? 'badge-red' : 'badge-yellow')); ?>">
                        <?php echo e($trx->status_transaksi); ?>

                    </span>
                </td>
                <td style="font-size:.8rem;color:#6b7280;"><?php echo e($trx->catatan ?? '-'); ?></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat transaksi</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div style="margin-top:1rem;">
    <a href="<?php echo e(route('ao.nasabah.index')); ?>" class="btn btn-secondary">Kembali</a>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/ao/nasabah/show.blade.php ENDPATH**/ ?>