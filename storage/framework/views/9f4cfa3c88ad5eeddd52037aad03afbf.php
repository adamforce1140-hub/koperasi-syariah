
<?php $__env->startSection('page-title', "Detail Pengajuan"); ?>

<?php $__env->startSection('title', 'Detail Pengajuan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Detail Pengajuan</h1>
    <p class="page-subtitle"><?php echo e($item->no_pengajuan ?? $item->no_pembiayaan ?? '-'); ?></p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.5rem;">

    
    <div>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Informasi Pengajuan</span></div>
            <div style="padding:1.5rem;">
                <table style="width:100%;border-collapse:collapse;">
                    <?php
                        $rows = [
                            'No. Pengajuan'   => $item->no_pengajuan ?? $item->no_pembiayaan ?? '-',
                            'Produk / Akad'   => ($item->produk?->nama_produk ?? '-') . ' / ' . ($item->jenis_akad ?? $item->akad_pembiayaan ?? '-'),
                            'Jumlah'          => 'Rp ' . number_format($item->jumlah_pengajuan ?? $item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
                            'Tenor'           => ($item->tenor ?? '-') . ' bulan',
                            'Tujuan'          => $item->tujuan ?? $item->tujuan_pembiayaan ?? '-',
                            'Agunan'          => $item->agunan ?? '-',
                            'Status'          => $item->status ?? $item->status_pembiayaan ?? '-',
                            'Tanggal Input'   => $item->created_at?->format('d/m/Y H:i') ?? '-',
                        ];
                    ?>
                    <?php $__currentLoopData = $rows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $val): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:.6rem 0;font-size:.85rem;color:#6b7280;width:160px;"><?php echo e($label); ?></td>
                        <td style="padding:.6rem 0;font-size:.9rem;font-weight:500;"><?php echo e($val); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </table>
            </div>
        </div>

        
        <?php if($item->analisis_5c): ?>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Hasil Analisis 5C</span></div>
            <div style="padding:1.5rem;">
                <?php $c = is_array($item->analisis_5c) ? $item->analisis_5c : json_decode($item->analisis_5c, true); ?>
                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;text-align:center;margin-bottom:1rem;">
                    <?php $__currentLoopData = ['character'=>'Character','capacity'=>'Capacity','capital'=>'Capital','collateral'=>'Collateral','condition'=>'Condition']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div style="padding:.8rem;background:#f9fafb;border-radius:8px;">
                        <div style="font-size:1.5rem;font-weight:700;color:#0ea5e9;"><?php echo e($c[$key] ?? '-'); ?></div>
                        <div style="font-size:.7rem;color:#6b7280;"><?php echo e($label); ?></div>
                    </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
                <div style="text-align:center;font-size:1rem;font-weight:600;color:#166534;">
                    Skor Total: <?php echo e($c['skor_total'] ?? array_sum(array_intersect_key($c ?? [], array_flip(['character','capacity','capital','collateral','condition'])))); ?> / 25
                </div>
                <?php if($item->catatan_survei): ?>
                <div style="margin-top:1rem;padding:.8rem;background:#fafafa;border-radius:6px;font-size:.85rem;color:#374151;">
                    <strong>Catatan Survei:</strong> <?php echo e($item->catatan_survei); ?>

                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        
        <?php if($item->dokumen && $item->dokumen->count()): ?>
        <div class="data-card">
            <div class="card-header"><span class="card-title">Dokumen Terlampir</span></div>
            <div style="padding:1rem;">
                <?php $__currentLoopData = $item->dokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid #f3f4f6;">
                    <div style="font-size:.85rem;"><?php echo e($dok->nama_dokumen ?? $dok->nama_file ?? '-'); ?></div>
                    <?php if($dok->path_file ?? $dok->path ?? null): ?>
                    <a href="<?php echo e(route('berkas.unduh', ['path' => $dok->path_file ?? $dok->path])); ?>" target="_blank"
                        class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .6rem;">Lihat</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    
    <div>
        <div class="data-card" style="margin-bottom:1.5rem;">
            <div class="card-header"><span class="card-title">Data Anggota</span></div>
            <div style="padding:1.5rem;">
                <div style="font-weight:600;font-size:1rem;"><?php echo e($item->anggota?->nama_lengkap ?? '-'); ?></div>
                <div style="font-size:.85rem;color:#6b7280;margin-top:.3rem;"><?php echo e($item->anggota?->no_anggota); ?></div>
                <div style="font-size:.85rem;color:#6b7280;">NIK: <?php echo e($item->anggota?->nik ?? '-'); ?></div>
                <div style="font-size:.85rem;color:#6b7280;">HP: <?php echo e($item->anggota?->no_hp ?? '-'); ?></div>
                <div style="font-size:.85rem;color:#6b7280;margin-top:.3rem;"><?php echo e($item->anggota?->alamat ?? '-'); ?></div>
            </div>
        </div>

        <?php
            $status = $item->status ?? \App\Models\Pembiayaan::normalizeStatus($item->status_pembiayaan) ?? '';
            $canAct = in_array(strtolower($status), ['menunggu', 'pending', 'pending_ao', 'direview', 'menunggu_approval_manajer']);
        ?>

        <?php if($canAct): ?>
        <div class="data-card">
            <div class="card-header"><span class="card-title">Tindakan</span></div>
            <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
                <form method="POST" action="<?php echo e(route('ao.pengajuan.approve', $item->id)); ?>">
                    <?php echo csrf_field(); ?>
                    <textarea name="catatan_ao" rows="3" placeholder="Catatan (opsional)..."
                        style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:.6rem;"></textarea>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Teruskan ke Manajer</button>
                </form>

                <form method="POST" action="<?php echo e(route('ao.pengajuan.reject', $item->id)); ?>">
                    <?php echo csrf_field(); ?>
                    <textarea name="catatan_tolak" rows="3" placeholder="Alasan penolakan..." required
                        style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;margin-bottom:.6rem;"></textarea>
                    <button type="submit" class="btn btn-danger" style="width:100%;"
                        onclick="return confirm('Tolak pengajuan ini?')">Tolak Pengajuan</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div style="margin-top:1rem;">
            <a href="<?php echo e(route('ao.pengajuan.index')); ?>" class="btn btn-secondary" style="width:100%;text-align:center;">Kembali ke Daftar</a>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/ao/pengajuan/show.blade.php ENDPATH**/ ?>