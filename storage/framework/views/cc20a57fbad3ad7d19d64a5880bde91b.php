<?php $__env->startSection('title', 'Kinerja Staf — Manajer'); ?>
<?php $__env->startSection('page-title', 'Kinerja Staf (AO & Teller)'); ?>

<?php $__env->startSection('content'); ?>

<div class="page-header" style="margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0;">📊 Evaluasi Kinerja Staf Operasional</h1>
        <p style="color:#64748b;font-size:0.85rem;margin:0.25rem 0 0;">Monitoring produktivitas Account Officer (AO) dan volume pelayanan Teller</p>
    </div>
</div>


<div class="card" style="margin-bottom:1.25rem;">
    <form method="GET" action="<?php echo e(route('manajer.kinerja-staf.index')); ?>" style="display:flex;gap:0.75rem;align-items:center;">
        <select name="bulan" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            <?php for($m=1; $m<=12; $m++): ?>
                <option value="<?php echo e($m); ?>" <?php echo e($bulan == $m ? 'selected' : ''); ?>>
                    <?php echo e(\Carbon\Carbon::create()->month($m)->translatedFormat('F')); ?>

                </option>
            <?php endfor; ?>
        </select>
        <select name="tahun" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                <option value="<?php echo e($y); ?>" <?php echo e($tahun == $y ? 'selected' : ''); ?>><?php echo e($y); ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:0.55rem 1.25rem;">Terapkan Filter</button>
    </form>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
            <span class="card-title" style="font-size:0.95rem;">👤 Kinerja Account Officer (AO)</span>
        </div>
        <table style="width:100%;">
            <thead>
                <tr>
                    <th>Nama Staf AO</th>
                    <th>Jumlah Pengajuan</th>
                    <th>Total Nominal</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $kinerjaAO; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ao): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php $kosongAO = (int) $ao->total_pengajuan === 0; ?>
                <tr style="<?php echo e($kosongAO ? 'opacity:0.55;' : ''); ?>">
                    <td style="font-weight:600;">
                        <?php echo e($ao->nama); ?>

                        <?php if($ao->peran): ?>
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#e0e7ff;color:#3730a3;"><?php echo e(strtoupper($ao->peran)); ?></span>
                        <?php endif; ?>
                        <?php if($kosongAO): ?>
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#64748b;">Tanpa Aktivitas</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($ao->total_pengajuan); ?> berkas</td>
                    <td style="color:#059669;font-weight:600;">Rp <?php echo e(number_format($ao->total_nominal, 0, ',', '.')); ?></td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="3" style="text-align:center;padding:1.5rem;color:#94a3b8;">Belum ada staf ber-role AO terdaftar.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if($kinerjaAO->isNotEmpty()): ?>
            <tfoot>
                <tr style="background:#f8fafc;border-top:1px solid #e2e8f0;">
                    <td style="font-weight:700;color:#475569;">Total</td>
                    <td style="font-weight:700;color:#475569;"><?php echo e($totalPengajuan); ?> berkas</td>
                    <td style="font-weight:700;color:#059669;">Rp <?php echo e(number_format($totalNominalPengajuan, 0, ',', '.')); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
            <span class="card-title" style="font-size:0.95rem;">💼 Kinerja Kasir / Teller</span>
        </div>
        <table style="width:100%;">
            <thead>
                <tr>
                    <th>Nama Teller</th>
                    <th>Jumlah Transaksi</th>
                    <th>Volume Kas</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $kinerjaTeller; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <?php $kosongTeller = (int) $t->total_transaksi === 0; ?>
                <tr style="<?php echo e($kosongTeller ? 'opacity:0.55;' : ''); ?>">
                    <td style="font-weight:600;">
                        <?php echo e($t->nama); ?>

                        <?php if($t->peran): ?>
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#e0e7ff;color:#3730a3;"><?php echo e(strtoupper($t->peran)); ?></span>
                        <?php endif; ?>
                        <?php if($kosongTeller): ?>
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#64748b;">Tanpa Aktivitas</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($t->total_transaksi); ?> transaksi</td>
                    <td style="color:#2563eb;font-weight:600;">Rp <?php echo e(number_format($t->volume_transaksi, 0, ',', '.')); ?></td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr>
                    <td colspan="3" style="text-align:center;padding:1.5rem;color:#94a3b8;">Belum ada staf ber-role Teller terdaftar.</td>
                </tr>
                <?php endif; ?>
            </tbody>
            <?php if($kinerjaTeller->isNotEmpty()): ?>
            <tfoot>
                <tr style="background:#f8fafc;border-top:1px solid #e2e8f0;">
                    <td style="font-weight:700;color:#475569;">Total</td>
                    <td style="font-weight:700;color:#475569;"><?php echo e($totalTransaksi); ?> transaksi</td>
                    <td style="font-weight:700;color:#2563eb;">Rp <?php echo e(number_format($totalVolumeTransaksi, 0, ',', '.')); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

</div>

<p style="margin-top:1rem;font-size:0.78rem;color:#94a3b8;">
    Catatan: kolom kinerja hanya menghitung berkas dan transaksi yang berhasil.
    Transaksi berstatus Gagal/Voided/Dibatalkan serta proses otomatis sistem
    (mis. distribusi SHU massal dan autodebet denda) tidak dihitung sebagai
    kinerja pelayanan staf. Staf tanpa aktivitas tetap ditampilkan dengan nilai 0
    agar pencatatan kinerja merata.
</p>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/manajer/kinerja-staf.blade.php ENDPATH**/ ?>