<?php $__env->startSection('page-title', "Detail Approval"); ?>
<?php $__env->startSection('title', 'Detail Approval'); ?>
<?php $__env->startSection('content'); ?>
<div style="max-width:700px;margin:0 auto;">

    <div style="margin-bottom:1rem;">
        <a href="<?php echo e(route('pengelola.approval.index')); ?>"
            style="color:#2563eb;text-decoration:none;font-size:.9rem;">&larr; Kembali ke Daftar</a>
    </div>

    <?php if(session('success')): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        <?php echo e(session('error')); ?>

    </div>
    <?php endif; ?>

    
    <div class="data-card" style="margin-bottom:1.5rem;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title">Detail Approval Transaksi</span>
            <?php
                $statusColor = match($approval->status) {
                    'APPROVED'        => ['bg'=>'#dcfce7','color'=>'#16a34a'],
                    'REJECTED'        => ['bg'=>'#fee2e2','color'=>'#dc2626'],
                    'PENDING_AO'      => ['bg'=>'#ede9fe','color'=>'#6d28d9'],
                    'PENDING_MANAJER' => ['bg'=>'#fef9c3','color'=>'#a16207'],
                    'PENDING_PENGURUS'=> ['bg'=>'#e0f2fe','color'=>'#0369a1'],
                    default           => ['bg'=>'#f1f5f9','color'=>'#64748b'],
                };
                $statusLabel = match(true) {
                    $approval->status === 'APPROVED' => 'Disetujui',
                    $approval->status === 'REJECTED' => 'Ditolak',
                    // Tahap menunggu diturunkan dari hierarki peran, sehingga
                    // tingkat otorisasi baru langsung tampil tanpa ubah view.
                    ! empty(\App\Support\HierarkiRole::peranUntukStatus($approval->status))
                        => 'Menunggu ' . \App\Support\HierarkiRole::label(
                            \App\Support\HierarkiRole::peranUntukStatus($approval->status)[0]
                        ),
                    default => $approval->status,
                };
            ?>
            <span style="padding:.3rem .9rem;border-radius:9999px;font-size:.85rem;font-weight:700;
                background:<?php echo e($statusColor['bg']); ?>;color:<?php echo e($statusColor['color']); ?>;">
                <?php echo e($statusLabel); ?>

            </span>
        </div>

        
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;padding:1.25rem;">
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Kode Approval</div>
                <div style="font-family:monospace;font-weight:600;"><?php echo e($approval->kode_approval); ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Jenis Transaksi</div>
                <div style="font-weight:600;"><?php echo e(str_replace('_',' ', ucwords($approval->jenis_transaksi))); ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Nominal</div>
                <div style="font-weight:700;font-size:1.1rem;color:#2563eb;">
                    Rp <?php echo e(number_format($approval->nominal, 0, ',', '.')); ?>

                </div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Jenis Pembayaran</div>
                <div><?php echo e(ucfirst($approval->jenis_pembayaran)); ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Tanggal Pengajuan</div>
                <div><?php echo e($approval->created_at->format('d/m/Y H:i:s')); ?></div>
            </div>
            <div>
                <div style="font-size:.8rem;color:#64748b;margin-bottom:.2rem;">Catatan Pengaju</div>
                <div><?php echo e($approval->catatan_pengaju ?: '-'); ?></div>
            </div>
        </div>

        
        <div style="border-top:1px solid #e2e8f0;padding:1.25rem;">
            <div style="font-size:.8rem;color:#64748b;font-weight:600;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.05em;">
                Diajukan Oleh
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div style="width:40px;height:40px;border-radius:50%;background:#2563eb;color:#fff;
                    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;">
                    <?php echo e(strtoupper(substr($approval->pengaju->name ?? 'U', 0, 1))); ?>

                </div>
                <div>
                    <div style="font-weight:600;"><?php echo e($approval->pengaju->name ?? '-'); ?></div>
                    <div style="font-size:.85rem;color:#64748b;">
                        <?php echo e(ucfirst($approval->role_pengaju)); ?> &bull; <?php echo e($approval->pengaju->email ?? ''); ?>

                    </div>
                    <div style="font-size:.8rem;color:#94a3b8;">
                        <?php echo e($approval->created_at->format('d/m/Y')); ?> pukul <?php echo e($approval->created_at->format('H:i:s')); ?>

                    </div>
                </div>
            </div>
        </div>

        
        <div style="border-top:1px solid #e2e8f0;padding:1.25rem;">
            <div style="font-size:.8rem;color:#64748b;font-weight:600;margin-bottom:.75rem;text-transform:uppercase;letter-spacing:.05em;">
                Disetujui / Ditolak Oleh
            </div>
            <?php if($approval->approver): ?>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div style="width:40px;height:40px;border-radius:50%;
                    background:<?php echo e($approval->status === 'APPROVED' ? '#16a34a' : ($approval->status === 'REJECTED' ? '#dc2626' : '#94a3b8')); ?>;
                    color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;">
                    <?php echo e(strtoupper(substr($approval->approver->name, 0, 1))); ?>

                </div>
                <div>
                    <div style="font-weight:600;"><?php echo e($approval->approver->name); ?></div>
                    <div style="font-size:.85rem;color:#64748b;">
                        <?php echo e(ucfirst($approval->role_approver)); ?> &bull; <?php echo e($approval->approver->email ?? ''); ?>

                    </div>
                    <div style="font-size:.8rem;color:#94a3b8;">
                        <?php echo e($approval->approved_at?->format('d/m/Y')); ?> pukul <?php echo e($approval->approved_at?->format('H:i:s')); ?>

                    </div>
                    <?php if($approval->catatan_approver): ?>
                    <div style="margin-top:.4rem;padding:.5rem .75rem;background:#f8fafc;border-radius:6px;font-size:.85rem;border-left:3px solid <?php echo e($approval->status === 'APPROVED' ? '#16a34a' : '#dc2626'); ?>;">
                        "<?php echo e($approval->catatan_approver); ?>"
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div style="color:#94a3b8;font-size:.9rem;">Belum ada yang menyetujui/menolak.</div>
            <?php endif; ?>
        </div>

        
        <?php $peranPemutus = \App\Support\HierarkiRole::peranUntukStatus($approval->status); ?>
        <?php if(!empty($peranPemutus) && in_array(auth()->user()->role, $peranPemutus, true)): ?>
            <div style="border-top:1px solid #e2e8f0;padding:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <form method="POST" action="<?php echo e(route('pengelola.approval.approve', $approval)); ?>">
                    <?php echo csrf_field(); ?>
                    <div style="margin-bottom:.5rem;">
                        <input type="text" name="catatan" placeholder="Catatan (opsional)"
                            style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:280px;">
                    </div>
                    <button type="submit" onclick="return confirm('Setujui transaksi ini?')"
                        style="background:#16a34a;color:#fff;border:none;padding:.55rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:600;">
                        &#10003; Setujui
                    </button>
                </form>
                <form method="POST" action="<?php echo e(route('pengelola.approval.reject', $approval)); ?>">
                    <?php echo csrf_field(); ?>
                    <div style="margin-bottom:.5rem;">
                        <input type="text" name="catatan" placeholder="Alasan penolakan (wajib)" required
                            style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;width:280px;">
                    </div>
                    <button type="submit" onclick="return confirm('Tolak transaksi ini?')"
                        style="background:#dc2626;color:#fff;border:none;padding:.55rem 1.5rem;border-radius:8px;cursor:pointer;font-weight:600;">
                        &#10007; Tolak
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengelola/approval/show.blade.php ENDPATH**/ ?>