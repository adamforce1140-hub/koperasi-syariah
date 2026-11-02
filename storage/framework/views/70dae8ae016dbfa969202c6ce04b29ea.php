
<?php $__env->startSection('page-title', "Detail Pengajuan Pembiayaan"); ?>

<?php $__env->startSection('title', 'Detail Pengajuan Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<?php
    $st   = strtolower($pengajuan->status ?? '');
    $role = auth()->user()->role ?? '';
    // Daftar peran berwenang berasal dari config/role_hierarki.php.
    $peranPeninjau = \App\Support\HierarkiRole::dalamKelompok($role, 'peninjau_pembiayaan');
    $peranPemutus  = \App\Support\HierarkiRole::dalamKelompok($role, 'pemutus_pembiayaan');
?>

<div class="page-header">
    <h1 class="page-title">Detail Pengajuan Pembiayaan</h1>
    <p class="page-subtitle"><?php echo e($pengajuan->no_pengajuan ?? '-'); ?></p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>

<div style="margin-bottom:1rem;">
    <a href="<?php echo e(route('pengelola.pengajuan-pinjaman.index')); ?>"
       style="font-size:.85rem;color:#2563eb;text-decoration:none;">&larr; Kembali ke Daftar Pengajuan</a>
</div>


<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Ringkasan Pengajuan</span>
        <?php if(in_array($st, ['menunggu', 'direview', 'diproses'])): ?>
            <span class="badge badge-amber">Menunggu Review</span>
        <?php elseif(in_array($st, ['disetujui'])): ?>
            <span class="badge badge-green">Disetujui</span>
        <?php elseif(in_array($st, ['ditolak'])): ?>
            <span class="badge badge-red">Ditolak</span>
        <?php else: ?>
            <span class="badge"><?php echo e($pengajuan->status ?? '-'); ?></span>
        <?php endif; ?>
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2rem;">
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Nasabah</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e($pengajuan->anggota?->nama_lengkap ?? '-'); ?></div>
            <div style="font-size:.78rem;color:#94a3b8;">CIF: <?php echo e($pengajuan->anggota?->no_cif ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Produk</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e($pengajuan->produk?->nama_produk ?? $pengajuan->jenis_akad ?? '-'); ?></div>
            <div style="font-size:.78rem;color:#94a3b8;">Akad: <?php echo e($pengajuan->jenis_akad ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Jumlah Pengajuan</div>
            <div style="font-weight:700;color:#059669;">Rp <?php echo e(number_format((float) ($pengajuan->jumlah_pengajuan ?? 0), 0, ',', '.')); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tenor</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e((int) ($pengajuan->tenor ?? 0)); ?> bulan</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Margin</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e(rtrim(rtrim(number_format((float) ($pengajuan->margin_persen ?? 0), 2, ',', '.'), '0'), ',')); ?>%</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Angsuran / Bulan</div>
            <div style="font-weight:600;color:#1e293b;">Rp <?php echo e(number_format((float) ($pengajuan->angsuran_per_bulan ?? 0), 0, ',', '.')); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tujuan</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e($pengajuan->tujuan ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Agunan</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e($pengajuan->agunan ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tanggal Pengajuan</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e(optional($pengajuan->created_at)->format('d/m/Y H:i') ?? '-'); ?></div>
        </div>
    </div>
</div>


<?php if(!empty($pengajuan->analisis_5c)): ?>
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Analisis 5C</span>
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <?php $__currentLoopData = (array) $pengajuan->analisis_5c; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kunci => $nilai): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;padding:.9rem;">
                <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;"><?php echo e($kunci); ?></div>
                <div style="font-weight:600;color:#1e293b;"><?php echo e(is_array($nilai) ? json_encode($nilai) : $nilai); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
</div>
<?php endif; ?>


<?php if(!empty($pengajuan->catatan_survei)): ?>
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Catatan Survei</span>
    </div>
    <div style="padding:1.5rem;color:#334155;line-height:1.6;"><?php echo e($pengajuan->catatan_survei); ?></div>
</div>
<?php endif; ?>


<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Dokumen Pendukung</span>
        <span style="font-size:.78rem;color:#94a3b8;"><?php echo e($pengajuan->dokumen->count()); ?> berkas</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Jenis Dokumen</th>
                <th>Nama File</th>
                <th>Ukuran</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $pengajuan->dokumen; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dok): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><?php echo e($dok->jenis_dokumen ?? '-'); ?></td>
                <td><?php echo e($dok->nama_file ?? '-'); ?></td>
                <td><?php echo e(isset($dok->ukuran_file) ? $dok->ukuran_file . ' KB' : '-'); ?></td>
                <td>
                    <?php if(!empty($dok->path_file)): ?>
                        <a href="<?php echo e(route('berkas.unduh', ['path' => $dok->path_file])); ?>"
                           target="_blank" rel="noopener"
                           style="font-size:.8rem;color:#2563eb;text-decoration:none;">Lihat</a>
                    <?php else: ?>
                        <span style="font-size:.8rem;color:#94a3b8;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:1.5rem;">Belum ada dokumen</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>


<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Jejak Persetujuan</span>
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.2rem;">
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Diproses Oleh</div>
            <div style="font-weight:600;color:#1e293b;"><?php echo e($pengajuan->diprosesOleh?->name ?? '-'); ?></div>
            <div style="font-size:.78rem;color:#94a3b8;"><?php echo e(optional($pengajuan->tanggal_diproses)->format('d/m/Y H:i') ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Catatan Manajer</div>
            <div style="color:#334155;"><?php echo e($pengajuan->catatan_manajer ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Catatan Pengurus</div>
            <div style="color:#334155;"><?php echo e($pengajuan->catatan_pengurus ?? '-'); ?></div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Kontrak Pembiayaan</div>
            <?php if($pengajuan->pembiayaan): ?>
                <div style="font-weight:600;color:#059669;"><?php echo e($pengajuan->pembiayaan->no_pembiayaan ?? '-'); ?></div>
            <?php else: ?>
                <div style="color:#94a3b8;">Belum dibuat</div>
            <?php endif; ?>
        </div>
    </div>
</div>


<?php if($pengajuan->pembiayaan): ?>
<div style="display:flex;gap:.6rem;flex-wrap:wrap;">
    
    <?php if($peranPeninjau && in_array($st, ['menunggu'])): ?>
        <form method="POST" action="<?php echo e(route('pengelola.pengajuan-pinjaman.review', $pengajuan->pembiayaan)); ?>"
            onsubmit="return confirm('Teruskan pengajuan ini ke Pengurus untuk disetujui?');">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <input type="hidden" name="catatan_manajer" value="Direview dari halaman detail">
            <button type="submit"
                style="padding:.6rem 1.2rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
                Teruskan ke Pengurus
            </button>
        </form>
    <?php endif; ?>

    <?php if($peranPemutus && in_array($st, ['direview', 'diproses'])): ?>
        <form method="POST" action="<?php echo e(route('pengelola.pengajuan-pinjaman.approve', $pengajuan->pembiayaan)); ?>"
            onsubmit="return confirm('Setujui pengajuan ini? Pencairan dana dilakukan oleh pengguna lain.');">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <input type="hidden" name="catatan_pengelola" value="Disetujui dari halaman detail">
            <button type="submit"
                style="padding:.6rem 1.2rem;background:#059669;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
                Setujui Pengajuan
            </button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengelola/pengajuan-pinjaman-detail.blade.php ENDPATH**/ ?>