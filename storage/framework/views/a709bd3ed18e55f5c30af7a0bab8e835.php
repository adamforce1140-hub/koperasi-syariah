<?php $__env->startSection('page-title', "Approval Keringanan Ta'zir"); ?>

<?php $__env->startSection('title', 'Approval Keringanan Ta\'zir'); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Approval Keringanan Ta'zir</h1>
    <p class="page-subtitle">Keputusan akhir atas pengajuan keringanan denda (ta'zir) yang telah direkomendasikan AO</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('warning')): ?>
    <div class="alert alert-warning"><?php echo e(session('warning')); ?></div>
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

<?php
    $statCards = [
        ['label' => 'Menunggu Approval Manajer', 'value' => $totalPendingManajer, 'color' => '#2563eb'],
        ['label' => 'Menunggu Verifikasi AO',    'value' => $totalPendingAo,      'color' => '#f59e0b'],
        ['label' => 'Disetujui',                 'value' => $totalApproved,       'color' => '#16a34a'],
        ['label' => 'Ditolak',                   'value' => $totalRejected,       'color' => '#dc2626'],
    ];
?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;">
    <?php $__currentLoopData = $statCards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;">
        <div style="font-size:.8rem;color:#64748b;margin-bottom:.35rem;"><?php echo e($card['label']); ?></div>
        <div style="font-size:1.5rem;font-weight:700;color:<?php echo e($card['color']); ?>;"><?php echo e($card['value']); ?></div>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
</div>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <span class="card-title">Daftar Pengajuan (<?php echo e($pengajuan->total()); ?>)</span>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                <option value="PENDING_MANAJER" <?php echo e($status === 'PENDING_MANAJER' ? 'selected' : ''); ?>>Menunggu Approval Manajer</option>
                <option value="PENDING_AO"      <?php echo e($status === 'PENDING_AO' ? 'selected' : ''); ?>>Menunggu Verifikasi AO</option>
                <option value="APPROVED"        <?php echo e($status === 'APPROVED' ? 'selected' : ''); ?>>Disetujui</option>
                <option value="REJECTED"        <?php echo e($status === 'REJECTED' ? 'selected' : ''); ?>>Ditolak</option>
            </select>
            <input type="text" name="search" value="<?php echo e($search); ?>"
                placeholder="Cari nama / no. anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:220px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Anggota</th>
                <th>No. Pembiayaan</th>
                <th>Angsuran Ke</th>
                <th style="text-align:right;">Ta'zir Awal</th>
                <th>Alasan Teller</th>
                <th>Rekomendasi AO</th>
                <th>Catatan AO</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $pengajuan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php
                $badgeStyle = [
                    'yellow' => 'background:#fef3c7;color:#92400e;',
                    'green'  => 'background:#d1fae5;color:#065f46;',
                    'red'    => 'background:#fee2e2;color:#991b1b;',
                    'gray'   => 'background:#f1f5f9;color:#475569;',
                ][$item->status_badge_color] ?? 'background:#f1f5f9;color:#475569;';
            ?>
            <tr>
                <td><?php echo e($item->id); ?></td>
                <td>
                    <div><strong><?php echo e($item->anggota?->nama_lengkap ?? '-'); ?></strong></div>
                    <div style="font-size:.75rem;color:#6b7280;"><?php echo e($item->anggota?->no_anggota); ?></div>
                </td>
                <td><?php echo e($item->pembiayaan?->no_pembiayaan ?? '-'); ?></td>
                <td style="text-align:center;"><?php echo e($item->angsuran?->angsuran_ke ?? '-'); ?></td>
                <td style="text-align:right;">Rp <?php echo e(number_format((float) $item->nominal_tazir_awal, 0, ',', '.')); ?></td>
                <td style="font-size:.8rem;color:#475569;max-width:200px;"><?php echo e(\Illuminate\Support\Str::limit($item->alasan_teller, 60)); ?></td>
                <td>
                    <?php if($item->rekomendasi_ao === 'SETUJU'): ?>
                        <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#d1fae5;color:#065f46;">SETUJU</span>
                    <?php elseif($item->rekomendasi_ao === 'TOLAK'): ?>
                        <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#fee2e2;color:#991b1b;">TOLAK</span>
                    <?php else: ?>
                        <span style="color:#94a3b8;">-</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:#475569;max-width:200px;"><?php echo e(\Illuminate\Support\Str::limit($item->catatan_ao, 60) ?: '-'); ?></td>
                <td>
                    <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;<?php echo e($badgeStyle); ?>">
                        <?php echo e($item->status_label); ?>

                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <?php if($item->isPendingManajer()): ?>
                        <button type="button" class="btn btn-primary"
                            style="font-size:.75rem;padding:.35rem .7rem;"
                            onclick="openApprove(<?php echo e($item->id); ?>, '<?php echo e(addslashes($item->anggota?->nama_lengkap ?? '-')); ?>', '<?php echo e(number_format((float) $item->nominal_tazir_awal, 0, ',', '.')); ?>')">
                            Approve
                        </button>
                        <button type="button" class="btn btn-danger"
                            style="font-size:.75rem;padding:.35rem .7rem;"
                            onclick="openReject(<?php echo e($item->id); ?>, '<?php echo e(addslashes($item->anggota?->nama_lengkap ?? '-')); ?>')">
                            Reject
                        </button>
                    <?php else: ?>
                        <span style="color:#94a3b8;font-size:.8rem;">Selesai</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan keringanan ta'zir</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if($pengajuan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($pengajuan->links()); ?></div>
    <?php endif; ?>
</div>


<div id="modal-approve" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:460px;width:92%;padding:1.5rem;">
        <h3 style="margin:0 0 .5rem;color:#166534;">Setujui Keringanan Ta'zir</h3>
        <p id="approve-text" style="font-size:.9rem;color:#475569;margin-bottom:1rem;"></p>
        <form method="POST" id="form-approve">
            <?php echo csrf_field(); ?>
            <label style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">Catatan Manajer (opsional)</label>
            <textarea name="catatan_manajer" rows="3"
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"
                placeholder="Catatan persetujuan..."></textarea>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Batal</button>
                <button type="submit" class="btn btn-primary">Approve (Setujui)</button>
            </div>
        </form>
    </div>
</div>


<div id="modal-reject" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:460px;width:92%;padding:1.5rem;">
        <h3 style="margin:0 0 .5rem;color:#991b1b;">Tolak Keringanan Ta'zir</h3>
        <p id="reject-text" style="font-size:.9rem;color:#475569;margin-bottom:1rem;"></p>
        <form method="POST" id="form-reject">
            <?php echo csrf_field(); ?>
            <label style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">
                Alasan Penolakan <span style="color:#dc2626;">*</span>
            </label>
            <textarea name="catatan_manajer" rows="3" required
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"
                placeholder="Alasan penolakan..."></textarea>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Batal</button>
                <button type="submit" class="btn btn-danger">Reject (Tolak)</button>
            </div>
        </form>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_APPROVE = "<?php echo e(route('manajer.keringanan-tazir.approve', ['relief' => '__ID__'])); ?>";
const URL_REJECT  = "<?php echo e(route('manajer.keringanan-tazir.reject', ['relief' => '__ID__'])); ?>";

function openApprove(id, nama, nominal) {
    document.getElementById('approve-text').innerHTML =
        'Setujui keringanan untuk <strong>' + nama + '</strong>? Tagihan ta\'zir <strong>Rp ' + nominal +
        '</strong> akan dibebaskan menjadi <strong>Rp 0</strong>.';
    document.getElementById('form-approve').action = URL_APPROVE.replace('__ID__', encodeURIComponent(id));
    document.getElementById('modal-approve').style.display = 'flex';
}
function openReject(id, nama) {
    document.getElementById('reject-text').innerHTML =
        'Tolak keringanan untuk <strong>' + nama + '</strong>? Tagihan ta\'zir kembali ke nominal kalkulasi normal (anggota wajib membayar).';
    document.getElementById('form-reject').action = URL_REJECT.replace('__ID__', encodeURIComponent(id));
    document.getElementById('modal-reject').style.display = 'flex';
}
function closeModals() {
    document.getElementById('modal-approve').style.display = 'none';
    document.getElementById('modal-reject').style.display = 'none';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\manajer\keringanan_tazir\index.blade.php ENDPATH**/ ?>