<?php $__env->startSection('title', 'Audit Log — Manajer'); ?>
<?php $__env->startSection('page-title', 'Audit Log'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Audit Log
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Catatan aktivitas pengguna dan perubahan data di sistem.
            </p>
        </div>

        
        <form method="GET" action="<?php echo e(route('manajer.audit.index')); ?>"
              style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Cari..."
                   style="width:160px;padding:0.45rem 0.75rem;border:1px solid #cbd5e1;
                          border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
                   onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#cbd5e1'">
            <select name="bulan"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <option value="">-- Semua Bulan --</option>
                <?php $__currentLoopData = range(1,12); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($m); ?>" <?php echo e($bulan == $m ? 'selected' : ''); ?>>
                    <?php echo e(\Carbon\Carbon::create()->month($m)->translatedFormat('F')); ?>

                </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
            <select name="tahun"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                <option value="">-- Semua Tahun --</option>
                <?php $__currentLoopData = range(now()->year - 2, now()->year); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $y): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option value="<?php echo e($y); ?>" <?php echo e($tahun == $y ? 'selected' : ''); ?>><?php echo e($y); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
            <button type="submit"
                    style="padding:0.45rem 1rem;background:#6366f1;color:#fff;font-size:0.825rem;
                           font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#4f46e5'" onmouseout="this.style.background='#6366f1'">
                Filter
            </button>
            <?php if($search || $bulan || $tahun): ?>
            <a href="<?php echo e(route('manajer.audit.index')); ?>"
               style="padding:0.45rem 0.875rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                      font-weight:600;border-radius:0.5rem;text-decoration:none;">
                Reset
            </a>
            <?php endif; ?>
        </form>
    </div>

    
    <div style="font-size:0.825rem;color:#64748b;">
        Menampilkan <strong style="color:#1e293b;"><?php echo e(number_format($logs->total())); ?></strong> aktivitas
        <?php if($bulan && $tahun): ?>
            pada periode <strong style="color:#1e293b;"><?php echo e(\Carbon\Carbon::create()->month($bulan)->translatedFormat('F')); ?> <?php echo e($tahun); ?></strong>
        <?php endif; ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if($logs->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;width:50px;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Rekening & Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Jenis Transaksi</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Nominal</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Keterangan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $logs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $index => $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        // Fallback handling untuk model AuditLog maupun Transaksi legacy
                        $noRekening = $log->no_rekening 
                                    ?? $log->rekening?->no_rekening 
                                    ?? $log->pembiayaan?->no_pembiayaan 
                                    ?? '-';
                        
                        $namaAnggota = $log->rekening?->anggota?->nama_lengkap 
                                     ?? $log->anggota?->nama_lengkap 
                                     ?? $log->pembiayaan?->anggota?->nama_lengkap 
                                     ?? null;

                        $jenisTransaksi = $log->jenis_transaksi ?? '-';
                        $nominalVal     = $log->nominal ?? $log->jumlah ?? 0;
                        $keteranganVal  = $log->keterangan ?? $log->catatan ?? ("Aktivitas " . $jenisTransaksi);
                        $waktuVal       = $log->created_at ?? $log->waktu_transaksi ?? now();
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;<?php echo e($loop->even ? 'background:#fafafa;' : ''); ?>"
                        onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background='<?php echo e($loop->even ? '#fafafa' : '#fff'); ?>'">
                        <td style="padding:0.75rem 1rem;text-align:center;color:#94a3b8;">
                            <?php echo e($logs->firstItem() + $index); ?>

                        </td>

                        
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">
                                <?php echo e($noRekening); ?>

                            </div>
                            <?php if($namaAnggota): ?>
                                <div style="font-size:0.75rem;color:#64748b;"><?php echo e($namaAnggota); ?></div>
                            <?php endif; ?>
                        </td>

                        
                        <td style="padding:0.75rem 1rem;">
                            <span style="font-size:0.72rem;font-weight:600;padding:0.2rem 0.6rem;
                                         border-radius:9999px;background:#eff6ff;color:#2563eb;">
                                <?php echo e($jenisTransaksi); ?>

                            </span>
                        </td>

                        
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;font-family:monospace;color:#1e293b;">
                            Rp <?php echo e(number_format($nominalVal, 0, ',', '.')); ?>

                        </td>

                        
                        <td style="padding:0.75rem 1rem;color:#475569;max-width:280px;">
                            <span style="display:block;white-space:normal;line-height:1.4;">
                                <?php echo e($keteranganVal); ?>

                            </span>
                        </td>

                        
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;font-size:0.775rem;">
                            <?php echo e(\Carbon\Carbon::parse($waktuVal)->format('d/m/Y H:i')); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($logs->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($logs->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#ede9fe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">👁️</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada catatan aktivitas transaksi pada periode ini</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Silakan sesuaikan filter pencarian atau periode tanggal.</p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\manajer\audit.blade.php ENDPATH**/ ?>