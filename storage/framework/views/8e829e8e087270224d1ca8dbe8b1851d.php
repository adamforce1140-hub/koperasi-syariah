<?php $__env->startSection('title', 'Edit Produk & Margin Akad — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Kelola Margin & Parameter Produk'); ?>

<?php $__env->startSection('content'); ?>
<div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:1.25rem;">

    <div>
        <a href="<?php echo e(route('pengurus.produk.index')); ?>"
           style="color:#059669;text-decoration:none;font-size:0.83rem;display:inline-flex;align-items:center;gap:0.35rem;font-weight:600;">
            ← Kembali ke Daftar Produk & Akad
        </a>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0.35rem 0 0;">
            Edit Parameter: <?php echo e($produk->nama_produk); ?>

        </h1>
        <p style="margin:0.2rem 0 0;font-size:0.83rem;color:#64748b;">
            Akad Syariah: <strong style="color:#059669;"><?php echo e($produk->akad_syariah); ?></strong>
        </p>
    </div>

    <?php if($errors->any()): ?>
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.85rem;">
        <ul style="margin:0;padding-left:1.25rem;">
            <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <form action="<?php echo e(route('pengurus.produk.update', $produk->id)); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PUT'); ?>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
                
                <div style="grid-column:1 / -1;background:#f0fdf4;border:1px solid #bbf7d0;padding:1rem;border-radius:0.625rem;">
                    <label style="display:block;font-size:0.85rem;font-weight:700;color:#166534;margin-bottom:0.35rem;">
                        Margin / Nisbah Bagi Hasil (%) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" step="0.01" min="0" max="100" name="margin_persen" 
                           value="<?php echo e(old('margin_persen', $produk->margin_persen)); ?>" required
                           style="width:100%;max-width:260px;padding:0.55rem 0.75rem;border:1px solid #86efac;border-radius:0.5rem;font-size:1.05rem;font-weight:700;color:#14532d;outline:none;background:#fff;">
                    <span style="display:block;font-size:0.75rem;color:#15803d;margin-top:0.3rem;">
                        Nilai ini akan otomatis terkunci di form pengajuan AO dan digunakan untuk kalkulasi pembiayaan.
                    </span>
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Minimum Plafon (Rp) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" step="1000" min="0" name="min_plafon" 
                           value="<?php echo e(old('min_plafon', $produk->min_plafon)); ?>" required
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Maksimum Plafon (Rp) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" step="1000" min="0" name="max_plafon" 
                           value="<?php echo e(old('max_plafon', $produk->max_plafon)); ?>" required
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Min Tenor (Bulan) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" min="1" max="360" name="min_tenor_bulan" 
                           value="<?php echo e(old('min_tenor_bulan', $produk->min_tenor_bulan)); ?>" required
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Maks Tenor (Bulan) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" min="1" max="360" name="max_tenor_bulan" 
                           value="<?php echo e(old('max_tenor_bulan', $produk->max_tenor_bulan)); ?>" required
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Biaya Administrasi (Rp)
                    </label>
                    <input type="number" step="1000" min="0" name="biaya_admin" 
                           value="<?php echo e(old('biaya_admin', $produk->biaya_admin)); ?>"
                           style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;">
                </div>

                
                <div>
                    <label style="display:block;font-size:0.82rem;font-weight:600;color:#334155;margin-bottom:0.35rem;">
                        Status Produk <span style="color:#ef4444;">*</span>
                    </label>
                    <select name="status_aktif" style="width:100%;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.875rem;outline:none;background:#fff;">
                        <option value="1" <?php echo e(old('status_aktif', $produk->status_aktif) ? 'selected' : ''); ?>>Aktif</option>
                        <option value="0" <?php echo e(!old('status_aktif', $produk->status_aktif) ? 'selected' : ''); ?>>Nonaktif</option>
                    </select>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;border-top:1px solid #e2e8f0;padding-top:1.25rem;">
                <a href="<?php echo e(route('pengurus.produk.index')); ?>"
                   style="padding:0.55rem 1.25rem;background:#f1f5f9;color:#475569;font-size:0.85rem;font-weight:600;border-radius:0.5rem;text-decoration:none;">
                    Batal
                </a>
                <button type="submit"
                        style="padding:0.55rem 1.5rem;background:#059669;color:#fff;font-size:0.85rem;font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;">
                    💾 Simpan Perubahan Margin
                </button>
            </div>
        </form>
    </div>

</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\produk-edit.blade.php ENDPATH**/ ?>