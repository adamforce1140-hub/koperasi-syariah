<?php $__env->startSection('title', 'Detail Pengajuan — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Tinjau Pengajuan Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>

<?php
    $statusLabel = match($item->status_pembiayaan ?? '') {
        'pending_ao'       => 'Menunggu AO',
        'pending_manajer'  => 'Menunggu Manajer',
        'pending_pengurus' => 'Menunggu Pengurus',
        'pending_dps'      => 'Menunggu DPS',
        'disetujui'        => 'Disetujui',
        'dicairkan'        => 'Dicairkan',
        'ditolak'          => 'Ditolak',
        default            => ucfirst($item->status_pembiayaan ?? '-'),
    };
    $statusStyle = match($item->status_pembiayaan ?? '') {
        'pending_pengurus' => 'background:#ede9fe;color:#6d28d9;',
        'pending_dps'      => 'background:#e0e7ff;color:#3730a3;',
        'disetujui'        => 'background:#d1fae5;color:#065f46;',
        'dicairkan'        => 'background:#e0f2fe;color:#0369a1;',
        'ditolak'          => 'background:#fee2e2;color:#991b1b;',
        default            => 'background:#fef3c7;color:#92400e;',
    };
    $isPendingPengurus = $item->status_pembiayaan === 'pending_pengurus';
?>

<?php if(session('success')): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:0.625rem;padding:0.875rem 1.125rem;
            margin-bottom:1rem;color:#065f46;font-size:0.875rem;font-weight:500;">
    ✅ <?php echo e(session('success')); ?>

</div>
<?php endif; ?>
<?php if(session('error')): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:0.625rem;padding:0.875rem 1.125rem;
            margin-bottom:1rem;color:#991b1b;font-size:0.875rem;font-weight:500;">
    ❌ <?php echo e(session('error')); ?>

</div>
<?php endif; ?>


<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
    <div>
        <a href="<?php echo e(route('pengurus.pembiayaan.index')); ?>"
           style="color:#7c3aed;text-decoration:none;font-size:0.85rem;">
            ← Kembali ke Daftar
        </a>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin-top:0.3rem;">
            Detail Pengajuan Pembiayaan
        </h1>
        <p style="color:#94a3b8;font-size:0.85rem;margin:0.15rem 0 0;">
            <?php echo e($item->no_pembiayaan ?? ('PMB-' . str_pad($item->id, 6, '0', STR_PAD_LEFT))); ?>

            &middot; <?php echo e($item->created_at?->format('d/m/Y H:i') ?? '-'); ?>

        </p>
    </div>
    <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;<?php echo e($statusStyle); ?>">
        <?php echo e($statusLabel); ?>

    </span>
</div>


<?php
    $steps = ['pending_ao' => 'AO', 'pending_manajer' => 'Manajer', 'pending_pengurus' => 'Pengurus', 'pending_dps' => 'DPS', 'disetujui' => 'Setujui', 'dicairkan' => 'Cair'];
    $stepKeys = array_keys($steps);
    $currentIdx = array_search($item->status_pembiayaan, $stepKeys);
    if ($currentIdx === false) $currentIdx = -1;
?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;overflow-x:auto;gap:0.5rem;">
    <?php $__currentLoopData = $steps; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php $idx = array_search($key, $stepKeys); ?>
    <div style="display:flex;flex-direction:column;align-items:center;gap:0.35rem;min-width:60px;">
        <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                    font-size:0.75rem;font-weight:700;
                    <?php echo e($idx < $currentIdx ? 'background:#10b981;color:#fff;' : ($idx === $currentIdx ? 'background:#7c3aed;color:#fff;' : 'background:#e2e8f0;color:#94a3b8;')); ?>">
            <?php echo e($idx < $currentIdx ? '✓' : ($idx + 1)); ?>

        </div>
        <span style="font-size:0.68rem;color:<?php echo e($idx <= $currentIdx ? '#1e293b' : '#94a3b8'); ?>;font-weight:<?php echo e($idx === $currentIdx ? '700' : '500'); ?>;">
            <?php echo e($label); ?>

        </span>
    </div>
    <?php if(!$loop->last): ?>
    <div style="flex:1;height:2px;background:<?php echo e($idx < $currentIdx ? '#10b981' : '#e2e8f0'); ?>;min-width:20px;margin-bottom:1rem;"></div>
    <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Data Anggota
        </h3>
        <?php $anggota = $item->anggota; ?>
        <?php $__currentLoopData = [
            'Nama Lengkap' => $anggota?->nama_lengkap,
            'No. Anggota'  => $anggota?->no_anggota,
            'NIK'          => $anggota?->nik,
            'No. HP'       => $anggota?->no_hp,
            'Alamat'       => $anggota?->alamat,
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;"><?php echo e($label); ?></span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;"><?php echo e($value ?? '—'); ?></span>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Detail Pembiayaan
        </h3>
        <?php $__currentLoopData = [
            'Akad'         => $item->akad_pembiayaan,
            'Jumlah'       => 'Rp ' . number_format($item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
            'Tenor'        => ($item->tenor ?? '—') . ' bulan',
            'Margin/Nisbah'=> ($item->margin_nisbah ?? '—') . '%',
            'Total'        => 'Rp ' . number_format($item->total_pembiayaan ?? 0, 0, ',', '.'),
            'Angsuran/Bln' => 'Rp ' . number_format($item->jumlah_angsuran ?? 0, 0, ',', '.'),
            'Tujuan'       => $item->tujuan_pembiayaan,
            'Agunan'       => $item->agunan,
        ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $label => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;"><?php echo e($label); ?></span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;"><?php echo e($value ?? '—'); ?></span>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

</div>


<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Persetujuan
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Direview AO</div>
            <div style="color:#1e293b;font-weight:600;"><?php echo e($item->tanggal_direview ? \Carbon\Carbon::parse($item->tanggal_direview)->format('d/m/Y') : '—'); ?></div>
        </div>
        <div>
            <div style="color:#64748b;">Disetujui Manajer</div>
            <div style="color:#1e293b;font-weight:600;"><?php echo e($item->tanggal_disetujui ? \Carbon\Carbon::parse($item->tanggal_disetujui)->format('d/m/Y') : '—'); ?></div>
        </div>
        <div>
            <div style="color:#64748b;">Disahkan DPS</div>
            <div style="color:#1e293b;font-weight:600;"><?php echo e($item->tanggal_disahkan ? \Carbon\Carbon::parse($item->tanggal_disahkan)->format('d/m/Y') : '—'); ?></div>
        </div>
        <div>
            <div style="color:#64748b;">Rencana Cair</div>
            <div style="color:#1e293b;font-weight:600;"><?php echo e($item->tgl_pencairan ? \Carbon\Carbon::parse($item->tgl_pencairan)->format('d/m/Y') : '—'); ?></div>
        </div>
    </div>
    <?php if($item->keterangan): ?>
    <div style="margin-top:1rem;padding:0.75rem;background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;color:#475569;">
        <strong>Catatan:</strong> <?php echo e($item->keterangan); ?>

    </div>
    <?php endif; ?>
</div>


<?php if($item->dokumen && $item->dokumen->count()): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Dokumen Pendukung
    </h3>
    <?php $__currentLoopData = $item->dokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.625rem 0.875rem;
                background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;margin-bottom:0.5rem;">
        <div>
            <span style="font-weight:600;color:#1e293b;"><?php echo e($dok->nama_file); ?></span>
            <span style="color:#94a3b8;margin-left:0.5rem;"><?php echo e($dok->jenis_dokumen); ?></span>
        </div>
        <a href="<?php echo e(route('berkas.unduh', ['path' => $dok->path_file])); ?>" target="_blank"
           style="color:#7c3aed;font-size:0.75rem;font-weight:600;text-decoration:none;">Lihat →</a>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>
<?php endif; ?>


<?php if($isPendingPengurus): ?>
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;
            display:flex;justify-content:flex-end;gap:0.75rem;align-items:flex-start;flex-wrap:wrap;">
    <button type="button" onclick="document.getElementById('modalReject').style.display='flex'"
        style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;
               background:#dc2626;color:#fff;font-size:0.875rem;font-weight:600;
               border:none;border-radius:0.5rem;cursor:pointer;">
        ✕ Tolak Pengajuan
    </button>
    <form action="<?php echo e(route('pengurus.pembiayaan.approve', $item->id)); ?>" method="POST"
          onsubmit="return confirm('Setujui pengajuan ini dan teruskan ke DPS?')">
        <?php echo csrf_field(); ?>
        <div style="margin-bottom:0.5rem;">
            <input type="text" name="catatan" placeholder="Catatan (opsional)"
                style="padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.45rem;font-size:0.825rem;width:220px;">
        </div>
        <button type="submit"
            style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;
                   background:#059669;color:#fff;font-size:0.875rem;font-weight:600;
                   border:none;border-radius:0.5rem;cursor:pointer;">
            ✓ Setujui & Teruskan ke DPS
        </button>
    </form>
</div>
<?php else: ?>
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;margin-top:1.25rem;
            font-size:0.85rem;color:#64748b;">
    Status saat ini: <strong style="color:#1e293b;"><?php echo e($statusLabel); ?></strong> — tidak ada aksi yang tersedia untuk Pengurus.
</div>
<?php endif; ?>


<div id="modalReject" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0.875rem;padding:1.5rem;max-width:450px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tolak Pengajuan Pembiayaan</h3>
        <form action="<?php echo e(route('pengurus.pembiayaan.reject', $item->id)); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.825rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Alasan Penolakan <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="catatan_tolak" rows="4" required placeholder="Tuliskan alasan penolakan..."
                    style="width:100%;padding:0.65rem 0.85rem;border:1px solid #d1d5db;border-radius:0.5rem;
                           font-size:0.875rem;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalReject').style.display='none'"
                    style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;border-radius:0.5rem;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.5rem 1.25rem;background:#dc2626;color:#fff;border:none;border-radius:0.5rem;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Ya, Tolak
                </button>
            </div>
        </form>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\pembiayaan-show.blade.php ENDPATH**/ ?>