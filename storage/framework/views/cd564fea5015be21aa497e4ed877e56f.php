
<div class="card-header">
    <span class="card-title">Pembiayaan Menunggu Approval</span>
    <a href="<?php echo e(route('pengelola.pengajuan-pinjaman.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
        Lihat Semua →
    </a>
</div>
<?php if($pengajuanPembiayaan->count() > 0): ?>
<table>
    <thead>
        <tr>
            <th>No. Kontrak</th>
            <th>Anggota</th>
            <th>Produk</th>
            <th>Jumlah</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php $__currentLoopData = $pengajuanPembiayaan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;"><?php echo e($p->no_kontrak ?? '-'); ?></td>
            <td><strong><?php echo e($p->anggota?->nama_lengkap ?? '-'); ?></strong></td>
            <td style="font-size:0.85rem;"><?php echo e($p->produk?->nama_produk ?? '-'); ?></td>
            <td style="font-weight:600;">Rp <?php echo e(number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.')); ?></td>
            <td>
                <?php if($p->status_pembiayaan === 'pending_manajer'): ?>
                    <span class="badge badge-amber">Menunggu Manajer</span>
                <?php else: ?>
                    <span class="badge badge-amber"><?php echo e($p->status_pembiayaan); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </tbody>
</table>
<?php else: ?>
<div style="padding:2.5rem;text-align:center;color:#94a3b8;">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
    <div style="font-weight:600;color:#475569;">Tidak ada pembiayaan menunggu approval</div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/manajer-pembiayaan.blade.php ENDPATH**/ ?>