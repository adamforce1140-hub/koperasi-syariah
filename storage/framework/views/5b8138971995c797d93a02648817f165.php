<?php $__env->startSection('title', 'Laporan Opini & Catatan Syariah DPS — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Laporan Opini & Catatan Syariah DPS'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.5rem;">

    
    <div style="background:#ede9fe;border:1px solid #ddd6fe;border-left:4px solid #7c3aed;border-radius:0.5rem;padding:0.875rem 1.25rem;font-size:0.875rem;color:#4c1d95;">
        Daftar opini kepatuhan syariah, catatan temuan audit berkala, dan rekomendasi perbaikan resmi yang diterbitkan oleh <strong>Dewan Pengawas Syariah (DPS)</strong> untuk jajaran Pengurus & Manajemen.
    </div>

    
    <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <div>
                <h3 class="card-title" style="margin:0;font-size:1.05rem;font-weight:700;color:#1e293b;">Laporan Opini & Catatan DPS</h3>
                <p style="margin:0.25rem 0 0;font-size:0.78rem;color:#64748b;">Arsip dokumentasi audit syariah berkala.</p>
            </div>
            <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.75rem;padding:0.35rem 0.75rem;">
                READ-ONLY PENGURUS
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#64748b;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.03em;">
                        <th style="padding:0.75rem 1rem;text-align:left;">Judul & Periode</th>
                        <th style="padding:0.75rem 1rem;text-align:left;">Kategori</th>
                        <th style="padding:0.75rem 1rem;text-align:center;">Status Kepatuhan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;">Isi Opini / Rekomendasi</th>
                        <th style="padding:0.75rem 1rem;text-align:center;">Lampiran</th>
                        <th style="padding:0.75rem 1rem;text-align:left;">Dibuat Oleh / Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__empty_1 = true; $__currentLoopData = $opinis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $o): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                            <td style="padding:0.875rem 1rem;vertical-align:top;">
                                <div style="font-weight:700;color:#1e293b;font-size:0.9rem;"><?php echo e($o->judul); ?></div>
                                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">
                                    Periode: <span style="color:#334155;font-weight:600;"><?php echo e($o->periode ?? '-'); ?></span>
                                </div>
                            </td>
                            <td style="padding:0.875rem 1rem;vertical-align:top;">
                                <span class="badge badge-gray" style="text-transform:capitalize;font-size:0.75rem;">
                                    <?php echo e(str_replace('_', ' ', $o->kategori)); ?>

                                </span>
                            </td>
                            <td style="padding:0.875rem 1rem;text-align:center;vertical-align:top;">
                                <?php if($o->status_kepatuhan === 'patuh'): ?>
                                    <span class="badge badge-green" style="font-size:0.75rem;">✅ Patuh Syariah</span>
                                <?php elseif($o->status_kepatuhan === 'perlu_perbaikan'): ?>
                                    <span class="badge badge-amber" style="font-size:0.75rem;">⚠️ Perlu Perbaikan</span>
                                <?php elseif($o->status_kepatuhan === 'tidak_patuh'): ?>
                                    <span class="badge badge-red" style="font-size:0.75rem;">❌ Tidak Patuh</span>
                                <?php else: ?>
                                    <span class="badge badge-blue" style="font-size:0.75rem;">🔍 Dalam Kajian</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.875rem 1rem;max-width:320px;color:#334155;line-height:1.45;vertical-align:top;">
                                <?php echo e($o->isi); ?>

                            </td>
                            <td style="padding:0.875rem 1rem;text-align:center;vertical-align:top;">
                                <?php if($o->lampiran): ?>
                                    <a href="<?php echo e(route('berkas.unduh', ['path' => $o->lampiran])); ?>" target="_blank"
                                       style="display:inline-flex;align-items:center;gap:0.25rem;color:#7c3aed;font-weight:600;text-decoration:none;font-size:0.78rem;background:#f5f3ff;padding:0.3rem 0.6rem;border-radius:0.375rem;border:1px solid #ddd6fe;">
                                        📄 Unduh
                                    </a>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:0.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:0.875rem 1rem;font-size:0.78rem;color:#64748b;vertical-align:top;">
                                <div style="font-weight:600;color:#1e293b;"><?php echo e($o->user->name ?? 'DPS Ustadz Fulan'); ?></div>
                                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.15rem;"><?php echo e($o->created_at?->format('d/m/Y H:i')); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" style="padding:3.5rem 1rem;text-align:center;color:#94a3b8;">
                                <div style="font-size:2.5rem;margin-bottom:0.5rem;">📝</div>
                                <div style="font-weight:600;font-size:0.95rem;color:#64748b;">Belum ada opini atau catatan syariah dari DPS.</div>
                                <div style="font-size:0.8rem;margin-top:0.25rem;">Opini yang diterbitkan oleh DPS di menu Opini Syariah akan muncul di sini.</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($opinis->hasPages()): ?>
        <div style="padding:1rem;border-top:1px solid #f1f5f9;">
            <?php echo e($opinis->links()); ?>

        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\laporan_syariah\index.blade.php ENDPATH**/ ?>