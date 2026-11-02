
<?php $__env->startSection('page-title', "Pengajuan Pembiayaan"); ?>

<?php $__env->startSection('title', 'Pengajuan Pembiayaan'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Pengajuan Pembiayaan Nasabah</h1>
    <p class="page-subtitle">Review dan proses pengajuan pembiayaan</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('info')): ?>
    <div class="alert alert-info" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.8rem 1rem;border-radius:6px;margin-bottom:1rem;"><?php echo e(session('info')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
    <div class="alert alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>


<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div class="stat-card" style="background:#fef3c7;">
        <div style="font-size:.8rem;color:#92400e;">Menunggu Review</div>
        <div style="font-size:2rem;font-weight:700;color:#d97706;"><?php echo e($stats['menunggu']); ?></div>
    </div>
    <div class="stat-card" style="background:#dbeafe;">
        <div style="font-size:.8rem;color:#1e40af;">Sedang Diproses</div>
        <div style="font-size:2rem;font-weight:700;color:#2563eb;"><?php echo e($stats['diproses']); ?></div>
    </div>
    <div class="stat-card" style="background:#d1fae5;">
        <div style="font-size:.8rem;color:#065f46;">Disetujui</div>
        <div style="font-size:2rem;font-weight:700;color:#059669;"><?php echo e($stats['disetujui']); ?></div>
    </div>
    <div class="stat-card" style="background:#fee2e2;">
        <div style="font-size:.8rem;color:#991b1b;">Ditolak</div>
        <div style="font-size:2rem;font-weight:700;color:#dc2626;"><?php echo e($stats['ditolak']); ?></div>
    </div>
</div>


<div style="margin-bottom:1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
    <?php $__currentLoopData = ['Menunggu','Diproses','Disetujui','Ditolak','semua']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <a href="<?php echo e(route('pengelola.pengajuan-pinjaman.index', ['status' => $s])); ?>"
       style="padding:.4rem .9rem;border-radius:20px;font-size:.82rem;text-decoration:none;font-weight:600;
              <?php echo e($status === $s ? 'background:#3b82f6;color:#fff;' : 'background:#f1f5f9;color:#475569;'); ?>">
        <?php echo e($s === 'semua' ? 'Semua' : $s); ?>

    </a>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>


<div class="data-card">
    <div class="card-header">
        <span class="card-title">Daftar Pengajuan — <span style="text-transform:capitalize;"><?php echo e($status); ?></span></span>
        <span style="font-size:.78rem;color:#94a3b8;"><?php echo e($pengajuan->total()); ?> pengajuan</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Nasabah / CIF</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Tenor</th>
                <th>Tujuan</th>
                <th>Status</th>
                <th>Tanggal</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><strong><?php echo e($item->no_pembiayaan); ?></strong></td>
                <td>
                    <div style="font-weight:600;"><?php echo e($item->anggota?->nama_lengkap ?? '-'); ?></div>
                    <div style="font-size:.75rem;color:#94a3b8;"><?php echo e($item->anggota?->no_cif ?? '-'); ?></div>
                </td>
                <td><?php echo e($item->akad_pembiayaan); ?></td>
                <td><strong>Rp <?php echo e(number_format($item->jumlah_pembiayaan, 0, ',', '.')); ?></strong></td>
                <td><?php echo e($item->tenor); ?> bln</td>
                <td style="max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo e($item->tujuan_pembiayaan); ?></td>
                <td>
                    <?php $st = strtolower($item->status_pembiayaan); ?>
                    <?php if(in_array($st, ['pengajuan', 'pending_ao', 'pending_manajer', 'pending_pengurus', 'pending_dps', 'pending'])): ?>
                        <span class="badge badge-amber">Menunggu Review</span>
                    <?php elseif(in_array($st, ['direview', 'dikaji'])): ?>
                        <span class="badge badge-blue">Direview</span>
                    <?php elseif(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan'])): ?>
                        <span class="badge badge-green">Disetujui</span>
                    <?php elseif(in_array($st, ['ditolak'])): ?>
                        <span class="badge badge-red">Ditolak</span>
                    <?php else: ?>
                        <span class="badge"><?php echo e($item->status_pembiayaan); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo e($item->created_at->format('d/m/Y')); ?></td>
                <td>
                    <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    <?php
                        $role = auth()->user()->role ?? '';
                        // Daftar peran berwenang berasal dari config/role_hierarki.php.
                        $peranPeninjau = \App\Support\HierarkiRole::dalamKelompok($role, 'peninjau_pembiayaan');
                        $peranPemutus  = \App\Support\HierarkiRole::dalamKelompok($role, 'pemutus_pembiayaan');
                    ?>

                    
                    <?php if($peranPeninjau && in_array($st, ['pengajuan', 'pending_ao', 'pending_manajer'])): ?>
                        <button onclick="openReview(<?php echo e($item->id); ?>, '<?php echo e($item->no_pembiayaan); ?>')"
                            style="padding:.3rem .7rem;background:#2563eb;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Review
                        </button>
                        <button onclick="openTolak(<?php echo e($item->id); ?>, '<?php echo e($item->no_pembiayaan); ?>')"
                            style="padding:.3rem .7rem;background:#dc2626;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Tolak
                        </button>

                    
                    <?php elseif($peranPemutus && in_array($st, ['direview', 'pending_pengurus'])): ?>
                        <button onclick="openApprove(<?php echo e($item->id); ?>, '<?php echo e($item->no_pembiayaan); ?>')"
                            style="padding:.3rem .7rem;background:#059669;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Setujui
                        </button>
                        <button onclick="openTolak(<?php echo e($item->id); ?>, '<?php echo e($item->no_pembiayaan); ?>')"
                            style="padding:.3rem .7rem;background:#dc2626;color:#fff;border:none;border-radius:4px;font-size:.78rem;cursor:pointer;">
                            Tolak
                        </button>

                    
                    <?php elseif(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan'])): ?>
                        <span style="font-size:.78rem;color:#059669;">&#10003; <?php echo e($item->status_pembiayaan); ?></span>
                    <?php elseif(in_array($st, ['ditolak'])): ?>
                        <span style="font-size:.78rem;color:#dc2626;">&#10007; <?php echo e($item->keterangan ?? 'Ditolak'); ?></span>
                    <?php elseif(in_array($st, ['direview', 'dikaji']) && $peranPeninjau): ?>
                        <span style="font-size:.78rem;color:#6b7280;">Menunggu Pengurus</span>
                    <?php elseif(in_array($st, ['pengajuan', 'pending_ao', 'pending_dps']) && $peranPemutus): ?>
                        <span style="font-size:.78rem;color:#6b7280;">Menunggu DPS/Review</span>
                    <?php else: ?>
                        <span style="font-size:.78rem;color:#6b7280;">-</span>
                    <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan dengan status ini</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($pengajuan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($pengajuan->links()); ?></div>
    <?php endif; ?>
</div>


<div id="modal-review" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#2563eb;">Review Pengajuan</h3>
        <p id="review-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <p style="font-size:.85rem;color:#374151;margin-bottom:1rem;">Pengajuan akan diteruskan ke <strong>Pengurus</strong> untuk persetujuan final.</p>
        <form id="form-review" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Catatan Manajer (opsional)</label>
            <textarea name="catatan_manajer" rows="3" placeholder="Misal: Sudah dicek kelayakan, layak diproses..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;box-sizing:border-box;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Teruskan ke Pengurus</button>
                <button type="button" onclick="closeModal('modal-review')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>


<div id="modal-approve" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;">Setujui Pengajuan</h3>
        <p id="approve-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <form id="form-approve" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Catatan (opsional)</label>
            <textarea name="catatan_pengelola" rows="3" placeholder="Misal: Disetujui sesuai plafon..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#059669;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Konfirmasi Setujui</button>
                <button type="button" onclick="closeModal('modal-approve')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>


<div id="modal-tolak" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:2rem;width:440px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#dc2626;">Tolak Pengajuan</h3>
        <p id="tolak-info" style="font-size:.9rem;color:#6b7280;margin-bottom:1rem;"></p>
        <form id="form-tolak" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PATCH'); ?>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.4rem;">Alasan Penolakan <span style="color:#ef4444">*</span></label>
            <textarea name="catatan_pengelola" rows="3" required placeholder="Misal: Riwayat angsuran kurang baik..." style="width:100%;padding:.6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"></textarea>
            <div style="display:flex;gap:.8rem;margin-top:1.2rem;">
                <button type="submit" style="flex:1;padding:.7rem;background:#dc2626;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Konfirmasi Tolak</button>
                <button type="button" onclick="closeModal('modal-tolak')" style="flex:1;padding:.7rem;background:#f1f5f9;color:#374151;border:none;border-radius:6px;cursor:pointer;">Batal</button>
            </div>
        </form>
    </div>
</div>

<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_REVIEW  = "<?php echo e(route('pengelola.pengajuan-pinjaman.review', ['pengajuan' => '__ID__'])); ?>";
const URL_APPROVE = "<?php echo e(route('pengelola.pengajuan-pinjaman.approve', ['pengajuan' => '__ID__'])); ?>";
const URL_TOLAK   = "<?php echo e(route('pengelola.pengajuan-pinjaman.tolak', ['pengajuan' => '__ID__'])); ?>";

function urlAksi(template, id) {
    return template.replace('__ID__', encodeURIComponent(id));
}
function openReview(id, no) {
    document.getElementById('review-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-review').action = urlAksi(URL_REVIEW, id);
    document.getElementById('modal-review').style.display = 'flex';
}
function openApprove(id, no) {
    document.getElementById('approve-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-approve').action = urlAksi(URL_APPROVE, id);
    document.getElementById('modal-approve').style.display = 'flex';
}
function openTolak(id, no) {
    document.getElementById('tolak-info').textContent = 'Pengajuan: ' + no;
    document.getElementById('form-tolak').action = urlAksi(URL_TOLAK, id);
    document.getElementById('modal-tolak').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengelola\pengajuan-pinjaman.blade.php ENDPATH**/ ?>