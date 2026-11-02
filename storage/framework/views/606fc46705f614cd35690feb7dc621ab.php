
<div class="card">
    <div class="card-header">
        <span class="card-title">Pengajuan Pembiayaan Terbaru</span>
        <a href="<?php echo e(route('pengurus.pembiayaan.index')); ?>" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua →
        </a>
    </div>
    <?php if($pengajuanTerbaru->count() > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Anggota</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Diproses Oleh</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $pengajuanTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td style="font-size:0.8rem;color:#64748b;"><?php echo e($p->created_at->format('d/m/Y')); ?></td>
                <td><strong><?php echo e($p->anggota?->nama_lengkap ?? '-'); ?></strong></td>
                <td><?php echo e($p->akad_pembiayaan ?? '-'); ?></td>
                <td style="font-weight:600;">Rp <?php echo e(number_format($p->jumlah_pembiayaan ?? $p->jumlah_pengajuan ?? 0, 0, ',', '.')); ?></td>
                <td>
                    <?php if($p->approvedFinalBy || $p->approvedBy): ?>
                        <?php echo e($p->approvedFinalBy?->name ?? $p->approvedBy?->name); ?>

                    <?php else: ?>
                        <span style="color:#94a3b8;">Belum diproses</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $st = strtolower($p->status_pembiayaan ?? $p->status ?? ''); ?>
                    <?php if(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan'], true)): ?>
                        <span class="badge badge-green">Disetujui</span>
                    <?php elseif($st === \App\Models\Pembiayaan::STATUS_RESTRUKTURISASI): ?>
                        <span class="badge badge-blue">Restrukturisasi</span>
                    <?php elseif($st === \App\Models\Pembiayaan::STATUS_PENDING_RESTRUKTURISASI): ?>
                        <span class="badge badge-amber">Restrukturisasi</span>
                    <?php elseif(in_array($st, ['ditolak', 'dibatalkan'], true)): ?>
                        <span class="badge" style="background:#fee2e2;color:#dc2626;">Ditolak</span>
                    <?php elseif(in_array($st, ['direview', 'dikaji'], true)): ?>
                        <span class="badge badge-blue">Direview</span>
                    <?php else: ?>
                        <span class="badge badge-amber">Menunggu</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada pengajuan</div>
    </div>
    <?php endif; ?>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\dashboard\partials\pengurus-pengajuan.blade.php ENDPATH**/ ?>