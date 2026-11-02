<?php $__env->startSection('title', 'Review & Sertifikasi Produk Baru — DPS'); ?>
<?php $__env->startSection('page-title', 'Review & Sertifikasi Produk Baru'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Review & Sertifikasi Produk Pembiayaan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Pemeriksaan kepatuhan akad syariah dan penerbitan rekomendasi/sertifikasi opini syariah untuk produk baru.
            </p>
        </div>
        <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.125rem;
                  background:#ede9fe;color:#6d28d9;font-size:0.825rem;font-weight:600;
                  border-radius:0.5rem;white-space:nowrap;">
            🛡️ Dewan Pengawas Syariah (DPS)
        </span>
    </div>

    <?php if(session('success')): ?>
        <div style="background:#d1fae5;border:1px solid #a7f3d0;color:#065f46;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <?php if(session('info')): ?>
        
        <div style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            <?php echo e(session('info')); ?>

        </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
        
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.875rem 1.25rem;border-radius:0.5rem;font-size:0.85rem;">
            <div style="font-weight:600;margin-bottom:0.35rem;">Periksa kembali isian berikut:</div>
            <ul style="margin:0;padding-left:1.1rem;">
                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pesan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($pesan); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if(isset($produk) && $produk->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama Produk</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Akad Syariah</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Margin / Admin</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status Kepatuhan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi DPS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $produk; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;"><?php echo e(($produk->currentPage() - 1) * $produk->perPage() + $loop->iteration); ?></td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;"><?php echo e($p->nama_produk); ?></td>
                        <td style="padding:0.75rem 1rem;">
                            <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:0.25rem;font-size:0.75rem;font-weight:600;background:#dbeafe;color:#1e40af;">
                                <?php echo e($p->akad_syariah ?? \App\Models\Pembiayaan::akadDefault()); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;"><?php echo e($p->margin_persen); ?>% / Rp <?php echo e(number_format($p->biaya_admin, 0, ',', '.')); ?></td>
                        <td style="padding:0.75rem 1rem;text-align:center;">Rp <?php echo e(number_format($p->min_plafon, 0, ',', '.')); ?> - Rp <?php echo e(number_format($p->max_plafon, 0, ',', '.')); ?></td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <?php if($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_VALID): ?>
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#dcfce7;color:#166534;"
                                      title="Disertifikasi <?php echo e(optional($p->tanggal_disahkan)->format('d/m/Y H:i')); ?> oleh <?php echo e($p->disahkanOleh?->name ?? '-'); ?>">
                                    ✅ Sertifikasi Syariah Valid
                                </span>
                                <?php if($p->tanggal_disahkan): ?>
                                    <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.25rem;">
                                        <?php echo e(optional($p->tanggal_disahkan)->format('d/m/Y')); ?> — <?php echo e($p->disahkanOleh?->name ?? '-'); ?>

                                    </div>
                                <?php endif; ?>
                            <?php elseif($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_DITOLAK): ?>
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#fee2e2;color:#991b1b;">
                                    ✖ Tidak Disetujui DPS
                                </span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#fef3c7;color:#92400e;">
                                    ⏳ Belum Disertifikasi
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <?php if($p->status_sertifikasi_dps === \App\Models\MasterProdukPembiayaan::SERTIFIKASI_VALID): ?>
                                <span style="font-size:0.75rem;color:#059669;font-weight:600;">&#10003; Tersertifikasi</span>
                            <?php else: ?>
                            <form method="POST" action="<?php echo e(route('dps.review-produk.approve', $p->id)); ?>" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <button type="submit" style="padding:0.35rem 0.75rem;background:#7c3aed;color:#fff;border:none;border-radius:0.375rem;font-size:0.78rem;font-weight:600;cursor:pointer;">
                                    Sertifikasi Akad
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <div style="padding:1rem;border-top:1px solid #e2e8f0;">
            <?php echo e($produk->links()); ?>

        </div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;color:#64748b;">
            Belum ada produk yang memerlukan review DPS.
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dps\review-produk.blade.php ENDPATH**/ ?>