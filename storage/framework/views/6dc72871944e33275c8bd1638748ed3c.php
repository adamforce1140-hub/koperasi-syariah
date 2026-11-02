
<?php $__env->startSection('page-title', "Approval Transaksi"); ?>

<?php $__env->startSection('title', 'Approval Transaksi'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Approval Transaksi
        <?php if($pendingCount > 0): ?>
            <span style="display:inline-block;margin-left:.5rem;background:#dc2626;color:#fff;font-size:.75rem;font-weight:700;padding:.15rem .55rem;border-radius:9999px;vertical-align:middle;">
                <?php echo e($pendingCount); ?>

            </span>
        <?php endif; ?>
    </h1>
    <p class="page-subtitle">Transaksi yang memerlukan persetujuan</p>
</div>

<?php if(session('success')): ?>
<div class="alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
<div class="alert-error"><?php echo e(session('error')); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span>Daftar Pengajuan</span>
        <form method="GET" style="display:flex;gap:0.5rem;">
            <?php
                // Opsi status mengikuti cakupan peran: pengurus memegang seluruh
                // rantai, penyetuju lain hanya tahapnya sendiri. Tanpa ini menu
                // menampilkan tahap yang memang tidak dapat ia lihat.
                $peranPengguna = auth()->user()->role;
                $statusTersedia = auth()->user()->isPengurus()
                    ? \App\Support\HierarkiRole::semuaStatusMenunggu()
                    : array_filter([\App\Support\HierarkiRole::penyetuju($peranPengguna)
                        ? \App\Support\HierarkiRole::statusMenunggu($peranPengguna) : null]);
            ?>
            <select name="status" onchange="this.form.submit()" style="padding:0.4rem 0.7rem;border-radius:6px;border:1px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.08);color:white;font-size:0.85rem;">
                <option value="">Semua Status</option>
                <?php $__currentLoopData = $statusTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $statusMenunggu): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($statusMenunggu); ?>" <?php echo e(request('status')===$statusMenunggu?'selected':''); ?>>
                        Menunggu <?php echo e(\App\Support\HierarkiRole::label(\App\Support\HierarkiRole::peranUntukStatus($statusMenunggu)[0] ?? '')); ?>

                    </option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                <option value="APPROVED" <?php echo e(request('status')==='APPROVED'?'selected':''); ?>>Disetujui</option>
                <option value="REJECTED" <?php echo e(request('status')==='REJECTED'?'selected':''); ?>>Ditolak</option>
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kode Approval</th>
                    <th>Jenis Transaksi</th>
                    <th>Nominal</th>
                    <th>Diajukan Oleh</th>
                    <th>Disetujui Oleh</th>
                    <th>Status</th>
                    <th>Tanggal</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $approvals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $apr): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><code style="font-size:0.8rem;"><?php echo e($apr->kode_approval); ?></code></td>
                    <td><?php echo e(str_replace('_',' ', ucwords($apr->jenis_transaksi))); ?></td>
                    <td>Rp <?php echo e(number_format($apr->nominal,0,',','.')); ?></td>
                    <td><?php echo e($apr->pengaju->name ?? '-'); ?> <small style="color:#64748b;">(<?php echo e($apr->role_pengaju); ?>)</small></td>
                    <td>
                        <?php if($apr->approver): ?>
                            <strong><?php echo e($apr->approver->name); ?></strong><br>
                            <small style="color:#64748b;"><?php echo e($apr->role_approver); ?></small><br>
                            <small style="color:#94a3b8;"><?php echo e($apr->approved_at?->format('d/m/Y H:i')); ?></small>
                        <?php else: ?>
                            <span style="color:#94a3b8;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $peranPemutus = \App\Support\HierarkiRole::peranUntukStatus($apr->status); ?>
                        <?php if(!empty($peranPemutus)): ?> <span class="badge badge-warning">Menunggu <?php echo e(\App\Support\HierarkiRole::label($peranPemutus[0])); ?></span>
                        <?php elseif($apr->status==='APPROVED'): ?> <span class="badge badge-green">Disetujui</span>
                        <?php else: ?> <span class="badge badge-red">Ditolak</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.8rem;"><?php echo e($apr->created_at->format('d/m/Y H:i')); ?></td>
                    <td style="white-space:nowrap;">
                        <a href="<?php echo e(route('pengelola.approval.show', $apr)); ?>" class="btn-sm btn-primary">Detail</a>
                        <?php $peranBerwenang = \App\Support\HierarkiRole::peranUntukStatus($apr->status); ?>
                        <?php if(!empty($peranBerwenang) && in_array(auth()->user()->role, $peranBerwenang, true)): ?>
                            <form method="POST" action="<?php echo e(route('pengelola.approval.approve', $apr)); ?>" style="display:inline;">
                                <?php echo csrf_field(); ?>
                                <button type="submit" class="btn-sm btn-success" onclick="return confirm('Setujui transaksi ini?')">Setujui</button>
                            </form>
                            <button onclick="openTolakModal(<?php echo e($apr->id); ?>,'<?php echo e($apr->kode_approval); ?>','<?php echo e(route('pengelola.approval.reject', $apr)); ?>')"
                                class="btn-sm" style="background:#dc2626;color:#fff;border:none;border-radius:4px;padding:.3rem .7rem;font-size:.78rem;cursor:pointer;">
                                Tolak
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr><td colspan="8" style="text-align:center;color:#64748b;padding:2rem;">Tidak ada data approval</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="padding:1rem;"><?php echo e($approvals->links()); ?></div>
</div>

<div id="modalTolak" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#1e293b;border-radius:8px;padding:1.5rem;width:420px;max-width:95vw;">
        <h3 style="margin:0 0 1rem;color:#f1f5f9;">Tolak Transaksi</h3>
        <p style="color:#94a3b8;font-size:.9rem;">Kode: <strong id="tolakKode" style="color:#f1f5f9;"></strong></p>
        <form method="POST" id="formTolak">
            <?php echo csrf_field(); ?>
            <?php echo method_field('POST'); ?>
            <div style="margin-bottom:1rem;">
                <label style="display:block;color:#94a3b8;font-size:.85rem;margin-bottom:.4rem;">Alasan Penolakan <span style="color:#ef4444;">*</span></label>
                <textarea name="catatan" rows="3" required
                    style="width:100%;padding:.6rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);border-radius:6px;color:#f1f5f9;font-size:.9rem;resize:vertical;"
                    placeholder="Masukkan alasan penolakan..."></textarea>
            </div>
            <div style="display:flex;gap:.7rem;justify-content:flex-end;">
                <button type="button" onclick="closeTolakModal()"
                    style="padding:.5rem 1rem;background:transparent;border:1px solid rgba(255,255,255,.2);border-radius:6px;color:#94a3b8;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:.5rem 1rem;background:#dc2626;border:none;border-radius:6px;color:#fff;cursor:pointer;">
                    Tolak Transaksi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTolakModal(id, kode, url) {
    document.getElementById('tolakKode').textContent = kode;
    document.getElementById('formTolak').action = url;
    document.getElementById('modalTolak').style.display = 'flex';
}
function closeTolakModal() {
    document.getElementById('modalTolak').style.display = 'none';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengelola/approval/index.blade.php ENDPATH**/ ?>