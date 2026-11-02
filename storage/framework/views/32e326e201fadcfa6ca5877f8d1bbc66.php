
<div class="card">
    <div class="card-header">
        <span class="card-title">Pengajuan Menunggu Review</span>
        <a href="<?php echo e(route('ao.pengajuan.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua →
        </a>
    </div>
    <?php if($pengajuanMenunggu->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Anggota</th>
                <th>Produk</th>
                <th>Jumlah Pengajuan</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $pengajuanMenunggu; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-size:0.8rem;color:#64748b;"><?php echo e($p->created_at->format('d/m/Y')); ?></td>
                <td><strong><?php echo e($p->anggota?->nama_lengkap ?? '-'); ?></strong></td>
                <td><?php echo e($p->produk?->nama_produk ?? '-'); ?></td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($p->jumlah_pengajuan, 0, ',', '.')); ?></td>
                <td><span class="badge badge-amber">Menunggu</span></td>
                <td>
                    <a href="<?php echo e(route('ao.pengajuan.show', $p->id)); ?>" class="btn btn-primary" style="padding:0.3rem 0.7rem;font-size:0.78rem;">
                        Review
                    </a>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
        <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
        <div style="font-weight:600;color:#475569;">Semua pengajuan sudah diproses</div>
        <div style="font-size:0.85rem;margin-top:0.4rem;">Tidak ada pengajuan yang menunggu review</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\ao-menunggu.blade.php ENDPATH**/ ?>