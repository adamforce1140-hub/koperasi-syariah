<?php $__env->startSection('title', 'Pembiayaan Saya'); ?>
<?php $__env->startSection('page-title', 'Pembiayaan Saya'); ?>
<?php $__env->startSection('content'); ?>
<div class="card">
    <div class="card-header"><span class="card-title">Pembiayaan Saya</span></div>
    <table>
        <thead><tr><th>#</th><th>Akad</th><th>Pokok</th><th>Margin</th><th>Tenor</th><th>Sisa Kewajiban</th><th>Status</th></tr></thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php
                // Kolom yang benar pada tabel `pembiayaan`: akad_pembiayaan,
                // jumlah_pembiayaan (pokok), margin_nisbah (NOMINAL rupiah,
                // bukan persen), dan sisa_pembiayaan (sisa kewajiban).
                $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');
                $meta = \App\Models\Pembiayaan::statusMeta()[$p->status_pembiayaan] ?? null;
            ?>
            <tr>
                <td><?php echo e($i + 1); ?></td>
                <td><strong><?php echo e($p->akad_pembiayaan); ?></strong></td>
                <td>Rp <?php echo e($rupiah($p->jumlah_pembiayaan)); ?></td>
                <td>Rp <?php echo e($rupiah($p->margin_nisbah)); ?></td>
                <td><?php echo e($p->tenor); ?> bln</td>
                <td>Rp <?php echo e($rupiah($p->sisa_pembiayaan)); ?></td>
                <td><span class="badge" style="background:<?php echo e($meta['color'] ?? '#94a3b8'); ?>22;color:<?php echo e($meta['color'] ?? '#94a3b8'); ?>;"><?php echo e($meta['label'] ?? ucfirst((string) $p->status_pembiayaan)); ?></span></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada pembiayaan</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\pembiayaan.blade.php ENDPATH**/ ?>