<?php $__env->startSection('title', 'Pembiayaan Aktif — Manajer'); ?>
<?php $__env->startSection('page-title', 'Pembiayaan Aktif'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <?php if(session('success')): ?>
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
        &#10003; <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
        &#9888; <?php echo e(session('error')); ?>

    </div>
    <?php endif; ?>
    <?php if($errors->any()): ?>
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;" role="alert">
        <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><div><?php echo e($e); ?></div><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    <?php endif; ?>

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Pembiayaan Aktif
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Daftar seluruh akad pembiayaan yang sedang berjalan.
        </p>
    </div>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;">
        <?php
        $cards = [
            ['label' => 'Pembiayaan Aktif', 'value' => $totalAktif, 'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '📂'],
            ['label' => 'Sudah Lunas',       'value' => $totalLunas, 'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '✅'],
        ];
        ?>
        <?php $__currentLoopData = $cards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:40px;height:40px;border-radius:0.625rem;background:<?php echo e($c['bg']); ?>;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem;">
                <?php echo e($c['icon']); ?>

            </div>
            <div>
                <div style="font-size:1.35rem;font-weight:700;color:<?php echo e($c['color']); ?>;line-height:1;">
                    <?php echo e(number_format($c['value'])); ?>

                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;"><?php echo e($c['label']); ?></div>
            </div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <form method="GET" action="<?php echo e(route('manajer.pembiayaan-aktif.index')); ?>"
          style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Cari nama / no. anggota…"
               style="flex:1;min-width:200px;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
               onfocus="this.style.borderColor='#047857'" onblur="this.style.borderColor='#cbd5e1'">
        <button type="submit"
                style="padding:0.5rem 1.125rem;background:#047857;color:#fff;font-size:0.825rem;
                       font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
            Cari
        </button>
        <?php if($search): ?>
        <a href="<?php echo e(route('manajer.pembiayaan-aktif.index')); ?>"
           style="padding:0.5rem 1rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Reset
        </a>
        <?php endif; ?>
    </form>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if($pembiayaan->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Produk / Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Plafon</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Sisa Pokok</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;white-space:nowrap;">Tenor</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Tgl Cair</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $pembiayaan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pb): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f1f5f9;<?php echo e($loop->even ? 'background:#fafafa;' : ''); ?>"
                        onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='<?php echo e($loop->even ? '#fafafa' : '#fff'); ?>'">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;">
                            <?php echo e(($pembiayaan->currentPage() - 1) * $pembiayaan->perPage() + $loop->iteration); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;"><?php echo e($pb->anggota?->nama_lengkap ?? '—'); ?></div>
                            <div style="font-size:0.75rem;color:#94a3b8;"><?php echo e($pb->anggota?->no_anggota ?? ''); ?></div>
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            <div style="color:#1e293b;"><?php echo e($pb->produk?->nama_produk ?? $pb->akad_pembiayaan ?? '—'); ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?php echo e($pb->akad_pembiayaan ?? $pb->produk?->akad_syariah ?? ''); ?></div>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#1e293b;">
                            Rp <?php echo e(number_format($pb->jumlah_pembiayaan ?? $pb->total_pembiayaan ?? 0, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:right;color:#dc2626;font-weight:600;">
                            Rp <?php echo e(number_format($pb->sisa_pembiayaan ?? 0, 0, ',', '.')); ?>

                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#475569;">
                            <?php echo e($pb->tenor ?? '—'); ?> bln
                        </td>
                        <td style="padding:0.75rem 1rem;color:#64748b;white-space:nowrap;">
                            
                            <?php echo e($pb->tanggal_akad?->format('d/m/Y') ?? '—'); ?>

                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <?php if($pembiayaan->hasPages()): ?>
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;"><?php echo e($pembiayaan->links()); ?></div>
        <?php endif; ?>
        <?php else: ?>
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;font-size:1.75rem;">📂</div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada pembiayaan aktif</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tidak ada akad pembiayaan yang sedang berjalan.</p>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/manajer/pembiayaan-aktif.blade.php ENDPATH**/ ?>