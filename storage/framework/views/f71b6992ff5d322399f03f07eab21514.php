<?php $__env->startSection('title', 'Approval Transaksi — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Approval Transaksi'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Approval Transaksi
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Pantau dan tinjau status persetujuan seluruh transaksi koperasi.
        </p>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        <?php
        $cards = [
            ['label' => 'Menunggu',   'value' => $totalPending,  'color' => '#d97706', 'bg' => '#fef3c7'],
            ['label' => 'Disetujui',  'value' => $totalApproved, 'color' => '#059669', 'bg' => '#d1fae5'],
            ['label' => 'Ditolak',    'value' => $totalRejected, 'color' => '#dc2626', 'bg' => '#fee2e2'],
        ];
        ?>
        <?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:40px;height:40px;border-radius:0.625rem;background:<?php echo e($c['bg']); ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;
                        font-size:1.1rem;">☑️</div>
            <div>
                <div style="font-size:1.35rem;font-weight:700;color:<?php echo e($c['color']); ?>;line-height:1;">
                    <?php echo e(number_format($c['value'])); ?>

                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;"><?php echo e($c['label']); ?></div>
            </div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <form method="GET" action="<?php echo e(route('pengurus.approval.index')); ?>"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Cari nama / no. anggota…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
            <option value="">Semua Status</option>
            <option value="PENDING"  <?php echo e($status === 'PENDING'   ? 'selected' : ''); ?>>Menunggu</option>
            <option value="APPROVED" <?php echo e($status === 'APPROVED'  ? 'selected' : ''); ?>>Disetujui</option>
            <option value="REJECTED" <?php echo e($status === 'REJECTED'  ? 'selected' : ''); ?>>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#059669;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
            Filter
        </button>
        <?php if($search || $status): ?>
        <a href="<?php echo e(route('pengurus.approval.index')); ?>"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        <?php endif; ?>
    </form>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if($approvals->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Jenis Transaksi</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Jumlah</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Diajukan Oleh</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Approver</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Update</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $approvals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $ap): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $statusColor = match($ap->status) {
                            'APPROVED' => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'Disetujui'],
                            'REJECTED' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Ditolak'],
                            default    => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'Menunggu'],
                        };
                        $anggota = $ap->transaksi?->rekening?->anggota;
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;<?php echo e($loop->even ? 'background:#fafafa;' : ''); ?>"
                        onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='<?php echo e($loop->even ? '#fafafa' : '#fff'); ?>'">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">
                            <?php echo e(($approvals->currentPage() - 1) * $approvals->perPage() + $loop->iteration); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;"><?php echo e($anggota?->nama_lengkap ?? '—'); ?></div>
                            <div style="font-size:0.75rem;color:#94a3b8;"><?php echo e($anggota?->no_anggota ?? ''); ?></div>
                        </td>
                        <td style="padding:0.75rem 1rem;color:#475569;">
                            <?php echo e($ap->transaksi?->jenis_transaksi ?? '—'); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#1e293b;">
                            Rp <?php echo e(number_format($ap->transaksi?->jumlah ?? 0, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;color:#475569;">
                            <?php echo e($ap->pengaju?->name ?? '—'); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;color:#475569;">
                            <?php echo e($ap->approver?->name ?? '—'); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.625rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:600;
                                         background:<?php echo e($statusColor['bg']); ?>;color:<?php echo e($statusColor['color']); ?>;">
                                <?php echo e($statusColor['label']); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;">
                            <?php echo e($ap->updated_at?->format('d/m/Y H:i') ?? '—'); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($approvals->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($approvals->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#fef3c7;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">☑️</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada data approval</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tidak ada transaksi yang sesuai dengan filter.</p>
            <?php if($search || $status): ?>
            <a href="<?php echo e(route('pengurus.approval.index')); ?>"
               style="display:inline-flex;align-items:center;padding:0.5rem 1.125rem;background:#059669;
                      color:#fff;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;margin-top:0.5rem;">
                Reset Filter
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengurus/approval.blade.php ENDPATH**/ ?>