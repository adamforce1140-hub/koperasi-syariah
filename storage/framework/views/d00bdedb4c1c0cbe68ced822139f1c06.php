<?php $__env->startSection('title', 'Riwayat Keringanan Penalti Deposito — Teller'); ?>
<?php $__env->startSection('page-title', 'Riwayat Keringanan Penalti Deposito'); ?>

<?php $__env->startSection('content'); ?>
<?php $statusFilter = request('status'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Riwayat Pengajuan Keringanan Penalti Deposito
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Pantau status pengajuan keringanan penalti pencairan dini deposito yang Anda ajukan — alur Teller → AO → Manajer.
            </p>
        </div>
        <a href="<?php echo e(route('teller.keringanan-penalti.create')); ?>"
           style="padding:0.55rem 1.1rem;background:#2563eb;color:#fff;border-radius:0.5rem;
                  font-size:0.83rem;font-weight:600;text-decoration:none;white-space:nowrap;">
            + Ajukan Keringanan
        </a>
    </div>

    
    <?php $__currentLoopData = ['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type=>$s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php if(session($type)): ?>
    <div style="padding:0.875rem 1.25rem;background:<?php echo e($s[0]); ?>;border:1px solid <?php echo e($s[1]); ?>;border-radius:0.625rem;color:<?php echo e($s[2]); ?>;font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span><?php echo e($s[3]); ?></span> <?php echo e(session($type)); ?>

    </div>
    <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    
    <form method="GET" action="<?php echo e(route('teller.keringanan-penalti.index')); ?>"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;">
        <select name="status"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                       font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
            <option value="">Semua Status</option>
            <option value="PENDING_AO"      <?php echo e($statusFilter === 'PENDING_AO'      ? 'selected' : ''); ?>>Menunggu Verifikasi AO</option>
            <option value="PENDING_MANAJER" <?php echo e($statusFilter === 'PENDING_MANAJER' ? 'selected' : ''); ?>>Menunggu Approval Manajer</option>
            <option value="APPROVED"        <?php echo e($statusFilter === 'APPROVED'        ? 'selected' : ''); ?>>Disetujui</option>
            <option value="REJECTED"        <?php echo e($statusFilter === 'REJECTED'        ? 'selected' : ''); ?>>Ditolak</option>
        </select>
        <button type="submit"
                style="padding:0.5rem 1rem;background:#2563eb;color:#fff;border:none;border-radius:0.5rem;
                       font-size:0.825rem;font-weight:600;cursor:pointer;">Filter</button>
        <?php if($statusFilter): ?>
        <a href="<?php echo e(route('teller.keringanan-penalti.index')); ?>"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">Reset</a>
        <?php endif; ?>
    </form>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if($pengajuan->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Tgl Pengajuan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No. Bilyet</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Penalti Awal</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Keringanan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                <?php $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <?php
                $statusStyle = match($k->status) {
                    'PENDING_AO', 'PENDING_MANAJER' => 'background:#fef3c7;color:#92400e;',
                    'APPROVED'                      => 'background:#d1fae5;color:#065f46;',
                    'REJECTED'                      => 'background:#fee2e2;color:#991b1b;',
                    default                         => 'background:#f1f5f9;color:#475569;',
                };
                $statusLabel = match($k->status) {
                    'PENDING_AO'      => '⏳ Menunggu AO',
                    'PENDING_MANAJER' => '⏳ Menunggu Manajer',
                    'APPROVED'        => '✅ Disetujui',
                    'REJECTED'        => '❌ Ditolak',
                    default           => $k->status,
                };
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        <?php echo e($k->created_at?->format('d/m/Y H:i')); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;"><?php echo e($k->anggota->nama_lengkap ?? '-'); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo e($k->anggota->no_anggota ?? '-'); ?></div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;white-space:nowrap;">
                        <?php echo e($k->deposito->deposito_number ?? '-'); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#b45309;white-space:nowrap;">
                        Rp <?php echo e(number_format((float) $k->nominal_penalti_awal, 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#059669;white-space:nowrap;">
                        <?php if($k->isApproved()): ?>
                            Rp <?php echo e(number_format((float) $k->nominal_penalti_disetujui, 0, ',', '.')); ?>

                            <?php if($k->sudahDipakai()): ?>
                            <div style="font-size:0.7rem;color:#94a3b8;font-weight:400;">sudah dipakai</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:0.25rem 0.625rem;border-radius:9999px;font-size:0.75rem;font-weight:600;<?php echo e($statusStyle); ?>">
                            <?php echo e($statusLabel); ?>

                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#64748b;max-width:220px;">
                        <?php if($k->catatan_manajer): ?>
                            <?php echo e(\Illuminate\Support\Str::limit($k->catatan_manajer, 60)); ?>

                            <div style="font-size:0.75rem;color:#94a3b8;">— <?php echo e($k->manajer->name ?? '-'); ?></div>
                        <?php elseif($k->catatan_ao): ?>
                            <?php echo e(\Illuminate\Support\Str::limit($k->catatan_ao, 60)); ?>

                            <div style="font-size:0.75rem;color:#94a3b8;">— AO: <?php echo e($k->ao->name ?? '-'); ?></div>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($pengajuan->hasPages()): ?>
        <div style="padding:0.875rem 1rem;border-top:1px solid #f1f5f9;">
            <?php echo e($pengajuan->links()); ?>

        </div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3rem;text-align:center;color:#94a3b8;">
            <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
            <div style="font-weight:600;color:#64748b;">Belum ada pengajuan keringanan</div>
            <div style="font-size:0.825rem;margin-top:0.25rem;">
                <?php if($statusFilter): ?> Tidak ada pengajuan dengan status tersebut. <?php else: ?> Ajukan melalui tombol “+ Ajukan Keringanan”. <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/keringanan-penalti-deposito/index.blade.php ENDPATH**/ ?>