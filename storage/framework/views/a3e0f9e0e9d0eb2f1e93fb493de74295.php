
<?php $__env->startSection('page-title', "Riwayat Penanganan Simpanan Wajib"); ?>

<?php $__env->startSection('title', 'Riwayat Penanganan Simpanan Wajib'); ?>

<?php $__env->startSection('sidebar'); ?>
<div class="nav-section">Kelola Anggota</div>
<a href="<?php echo e(route('teller.anggota.index')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.anggota.*') ? 'active' : ''); ?>">
    <span class="icon">&#128101;</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="<?php echo e(route('dashboard.teller')); ?>" class="nav-item <?php echo e(request()->routeIs('dashboard.teller') ? 'active' : ''); ?>">
    <span class="icon">&#127968;</span> Dashboard
</a>
<a href="<?php echo e(route('teller.setor')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.setor') ? 'active' : ''); ?>">
    <span class="icon">&#128176;</span> Setor Tunai
</a>
<a href="<?php echo e(route('teller.tarik')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.tarik') ? 'active' : ''); ?>">
    <span class="icon">&#128184;</span> Tarik Tunai
</a>
<a href="<?php echo e(route('teller.angsuran')); ?>" class="nav-item <?php echo e(request()->routeIs('teller.angsuran') ? 'active' : ''); ?>">
    <span class="icon">&#128200;</span> Bayar Angsuran
</a>
<a href="<?php echo e(route('teller.simpanan-wajib.index')); ?>" class="nav-item active">
    <span class="icon">&#127974;</span> Simpanan Wajib
</a>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Riwayat Penanganan Tagihan</h1>
            <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">
                Audit trail seluruh pemotongan auto-debit, cicilan, penjadwalan ulang, dan notifikasi
                untuk tagihan periode <strong><?php echo e($tagihan->nama_periode); ?></strong> —
                transparansi kepada anggota &amp; bahan audit pengurus.
            </p>
        </div>
        <a href="<?php echo e(route('teller.simpanan-wajib.index')); ?>"
           style="padding:.5rem .9rem;background:#f1f5f9;color:#374151;font-size:.8rem;font-weight:600;border-radius:.5rem;text-decoration:none;">
            &larr; Kembali ke Daftar
        </a>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;
                display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Anggota</div>
            <div style="font-size:.95rem;font-weight:700;color:#1e293b;margin-top:.15rem;">
                <?php echo e($tagihan->member?->nama_lengkap ?? '—'); ?>

            </div>
            <div style="font-size:.78rem;color:#64748b;"><?php echo e($tagihan->member?->no_anggota ?? ''); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Nominal Tagihan</div>
            <div style="font-size:.95rem;font-weight:700;color:#b45309;margin-top:.15rem;">
                Rp <?php echo e(number_format((float) $tagihan->amount, 0, ',', '.')); ?>

            </div>
        </div>
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Status</div>
            <div style="font-size:.95rem;font-weight:700;color:#1e293b;margin-top:.15rem;"><?php echo e($tagihan->status); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Saldo Sukarela</div>
            <div style="font-size:.95rem;font-weight:700;color:#1e293b;margin-top:.15rem;">
                Rp <?php echo e(number_format($ringkasan['saldo_sukarela'], 0, ',', '.')); ?>

            </div>
        </div>
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Sudah Dicicil</div>
            <div style="font-size:.95rem;font-weight:700;color:#059669;margin-top:.15rem;">
                Rp <?php echo e(number_format($ringkasan['sudah_dicicil'], 0, ',', '.')); ?>

            </div>
        </div>
        <div>
            <div style="font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Denda</div>
            <div style="font-size:.95rem;font-weight:700;color:#059669;margin-top:.15rem;">
                Rp 0 <span style="font-size:.72rem;font-weight:500;color:#64748b;">(prinsip syariah)</span>
            </div>
        </div>
    </div>

    
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.875rem;padding:1rem 1.25rem;
                font-size:.82rem;color:#475569;display:flex;flex-wrap:wrap;gap:1.5rem;">
        <div>
            <span style="color:#94a3b8;">Kekurangan Saldo:</span>
            <strong style="color:<?php echo e($ringkasan['kekurangan'] > 0 ? '#dc2626' : '#059669'); ?>;">
                Rp <?php echo e(number_format($ringkasan['kekurangan'], 0, ',', '.')); ?>

            </strong>
        </div>
        <div>
            <span style="color:#94a3b8;">Jadwal Bertahap:</span>
            <?php if($ringkasan['ada_jadwal']): ?>
            <strong><?php echo e($ringkasan['total_cicilan']); ?> &times; Rp <?php echo e(number_format($ringkasan['nominal_cicilan'], 0, ',', '.')); ?></strong>
            <?php else: ?>
            <strong>Belum dijadwalkan</strong>
            <?php endif; ?>
        </div>
        <div>
            <span style="color:#94a3b8;">Reschedule:</span>
            <strong><?php echo e($ringkasan['jumlah_reschedule']); ?>&times;</strong>
            <?php if($ringkasan['maks_reschedule'] > 0): ?>
            <span style="color:#94a3b8;">/ maks <?php echo e($ringkasan['maks_reschedule']); ?>&times;</span>
            <?php endif; ?>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="padding:.875rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <h2 style="font-size:.95rem;font-weight:700;color:#1e293b;margin:0;">Riwayat Tindakan</h2>
            <p style="margin:.15rem 0 0;font-size:.78rem;color:#94a3b8;">
                Catatan permanen (append-only) — tidak dapat diubah atau dihapus.
            </p>
        </div>

        <?php if($tindakan->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Waktu</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Tindakan</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:right;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Nominal</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:center;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Hasil</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Keterangan</th>
                        <th scope="col" style="padding:.75rem 1rem;text-align:left;font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $tindakan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $hasilColor = match($t->status_hasil) {
                            'BERHASIL' => ['bg' => '#d1fae5', 'text' => '#065f46'],
                            'GAGAL'    => ['bg' => '#fee2e2', 'text' => '#991b1b'],
                            default    => ['bg' => '#e0e7ff', 'text' => '#3730a3'],
                        };
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.75rem 1rem;font-size:.78rem;color:#475569;white-space:nowrap;">
                            <?php echo e($t->waktu_tindakan?->format('d/m/Y H:i') ?? '—'); ?>

                        </td>
                        <td style="padding:.75rem 1rem;font-size:.8rem;font-weight:600;color:#1e293b;">
                            <?php echo e($t->jenis_label); ?>

                            <?php if($t->total_cicilan): ?>
                            <div style="font-size:.72rem;font-weight:500;color:#64748b;">
                                Cicilan <?php echo e($t->cicilan_ke); ?>/<?php echo e($t->total_cicilan); ?>

                                @ Rp <?php echo e(number_format((float) $t->nominal_cicilan, 0, ',', '.')); ?>

                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding:.75rem 1rem;text-align:right;font-size:.8rem;color:#b45309;white-space:nowrap;">
                            Rp <?php echo e(number_format((float) $t->nominal, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            <span style="display:inline-flex;padding:.15rem .55rem;background:<?php echo e($hasilColor['bg']); ?>;
                                         color:<?php echo e($hasilColor['text']); ?>;font-size:.72rem;font-weight:600;border-radius:9999px;">
                                <?php echo e($t->status_hasil); ?>

                            </span>
                        </td>
                        <td style="padding:.75rem 1rem;font-size:.78rem;color:#64748b;max-width:360px;">
                            <?php echo e($t->catatan ?? '—'); ?>

                        </td>
                        <td style="padding:.75rem 1rem;font-size:.78rem;color:#475569;">
                            <?php echo e($t->user?->name ?? 'Sistem'); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($tindakan->hasPages()): ?>
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($tindakan->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3rem 1.5rem;text-align:center;">
            <div style="font-size:.95rem;font-weight:600;color:#1e293b;margin-bottom:.375rem;">Belum ada riwayat tindakan</div>
            <p style="font-size:.825rem;color:#94a3b8;">
                Tagihan ini belum melalui pemotongan auto-debit, penjadwalan bertahap, atau penjadwalan ulang.
            </p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\teller\simpanan-wajib-riwayat.blade.php ENDPATH**/ ?>