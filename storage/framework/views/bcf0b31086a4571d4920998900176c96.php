
<?php $__env->startSection('page-title', "Detail Keringanan Ta'zir"); ?>

<?php $__env->startSection('title', 'Detail Keringanan Ta\'zir'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Detail Pengajuan Keringanan Ta'zir</h1>
    <p class="page-subtitle">Pengajuan #<?php echo e($relief->id); ?> — <?php echo e($relief->status_label); ?></p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-danger"><?php echo e(session('error')); ?></div>
<?php endif; ?>
<?php if($errors->any()): ?>
    <div class="alert alert-danger">
        <ul style="margin:0;padding-left:1.1rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $err): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <li><?php echo e($err); ?></li> <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start;">

    
    <div class="data-card">
        <div class="card-header"><span class="card-title">Data Anggota &amp; Tagihan</span></div>
        <table>
            <tbody>
                <tr><th style="width:45%;">Nama Anggota</th><td><?php echo e($relief->anggota?->nama_lengkap ?? '-'); ?></td></tr>
                <tr><th>No. Anggota</th><td><?php echo e($relief->anggota?->no_anggota ?? '-'); ?></td></tr>
                <tr><th>No. Pembiayaan</th><td><?php echo e($relief->pembiayaan?->no_pembiayaan ?? '-'); ?></td></tr>
                <tr><th>Angsuran Ke</th><td><?php echo e($relief->angsuran?->angsuran_ke ?? '-'); ?></td></tr>
                <tr><th>Jatuh Tempo</th><td><?php echo e($relief->angsuran?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-'); ?></td></tr>
                <tr><th>Jumlah Angsuran</th><td>Rp <?php echo e(number_format((float) ($relief->angsuran?->jumlah_angsuran ?? 0), 0, ',', '.')); ?></td></tr>
                <tr><th>Ta'zir Awal (kalkulasi)</th><td><strong style="color:#b45309;">Rp <?php echo e(number_format((float) $relief->nominal_tazir_awal, 0, ',', '.')); ?></strong></td></tr>
                <tr><th>Diajukan Oleh</th><td><?php echo e($relief->teller?->name ?? '-'); ?></td></tr>
                <tr><th>Waktu Pengajuan</th><td><?php echo e($relief->created_at?->format('d/m/Y H:i')); ?></td></tr>
            </tbody>
        </table>

        <div style="padding:1rem;">
            <div style="font-size:.8rem;color:#64748b;margin-bottom:.3rem;">Alasan dari Teller</div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.75rem;font-size:.9rem;color:#334155;">
                <?php echo e($relief->alasan_teller); ?>

            </div>
        </div>
    </div>

    
    <div class="data-card">
        <div class="card-header"><span class="card-title">Rekomendasi Analisis Lapangan (AO)</span></div>

        <?php if($relief->isPendingAo()): ?>
        <form method="POST" action="<?php echo e(route('ao.keringanan-tazir.rekomendasi', $relief->id)); ?>" style="padding:1rem;">
            <?php echo csrf_field(); ?>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.9rem;color:#374151;">
                    Hasil Analisis Lapangan <span style="color:#dc2626;">*</span>
                </label>
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;">
                        <input type="radio" name="rekomendasi_ao" value="SETUJU" required
                            style="accent-color:#16a34a;">
                        Rekomendasikan Bebas (Rp 0)
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;">
                        <input type="radio" name="rekomendasi_ao" value="TOLAK" required
                            style="accent-color:#dc2626;">
                        Tolak (Tetap Bayar Denda)
                    </label>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;font-size:.9rem;color:#374151;">
                    Catatan Analisis AO <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="catatan_ao" rows="4" required
                    placeholder="Hasil kunjungan/verifikasi lapangan, kondisi ekonomi anggota, dsb."
                    style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"><?php echo e(old('catatan_ao')); ?></textarea>
            </div>

            <div style="text-align:right;display:flex;gap:.5rem;justify-content:flex-end;">
                <a href="<?php echo e(route('ao.keringanan-tazir.index')); ?>" class="btn btn-secondary">Batal</a>
                <button type="submit" class="btn btn-primary">Kirim ke Manajer</button>
            </div>
        </form>
        <?php else: ?>
        <table>
            <tbody>
                <tr><th style="width:45%;">Rekomendasi AO</th>
                    <td>
                        <?php if($relief->rekomendasi_ao === 'SETUJU'): ?>
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#d1fae5;color:#065f46;">SETUJU (Bebas)</span>
                        <?php elseif($relief->rekomendasi_ao === 'TOLAK'): ?>
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#fee2e2;color:#991b1b;">TOLAK</span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Catatan AO</th><td><?php echo e($relief->catatan_ao ?? '-'); ?></td></tr>
                <tr><th>Diverifikasi Oleh</th><td><?php echo e($relief->ao?->name ?? '-'); ?></td></tr>
                <tr><th>Keputusan Manajer</th><td><?php echo e($relief->status_label); ?></td></tr>
                <tr><th>Catatan Manajer</th><td><?php echo e($relief->catatan_manajer ?? '-'); ?></td></tr>
                <tr><th>Diputuskan Oleh</th><td><?php echo e($relief->manajer?->name ?? '-'); ?></td></tr>
                <tr><th>Nominal Disetujui</th>
                    <td>Rp <?php echo e(number_format((float) $relief->nominal_tazir_disetujui, 0, ',', '.')); ?></td>
                </tr>
            </tbody>
        </table>
        <div style="padding:1rem;">
            <a href="<?php echo e(route('ao.keringanan-tazir.index')); ?>" class="btn btn-secondary">Kembali</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/ao/keringanan_tazir/show.blade.php ENDPATH**/ ?>