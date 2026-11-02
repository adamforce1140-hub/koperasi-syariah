<?php $__env->startSection('title', 'Approval Keringanan Penalti Deposito — Manajer'); ?>
<?php $__env->startSection('page-title', 'Approval Keringanan Penalti Deposito'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Approval Keringanan Penalti Deposito
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Keputusan akhir atas pengajuan keringanan penalti pencairan dini deposito yang telah direkomendasikan AO.
            Penalti adalah ta'zir (Dana Kebajikan), bukan pendapatan koperasi.
        </p>
    </div>

    
    <?php $__currentLoopData = ['success'=>['#ecfdf5','#a7f3d0','#065f46','✅'], 'error'=>['#fef2f2','#fecaca','#991b1b','❌'], 'warning'=>['#fffbeb','#fde68a','#92400e','⚠️']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type=>$s): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php if(session($type)): ?>
    <div style="padding:0.875rem 1.25rem;background:<?php echo e($s[0]); ?>;border:1px solid <?php echo e($s[1]); ?>;border-radius:0.625rem;color:<?php echo e($s[2]); ?>;font-size:0.875rem;display:flex;align-items:center;gap:0.5rem;">
        <span><?php echo e($s[3]); ?></span> <?php echo e(session($type)); ?>

    </div>
    <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php if($errors->any()): ?>
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
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
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
        <?php $__currentLoopData = $statCards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $card): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem 1.1rem;">
            <div style="font-size:.8rem;color:#64748b;margin-bottom:.35rem;"><?php echo e($card['label']); ?></div>
            <div style="font-size:1.5rem;font-weight:700;color:<?php echo e($card['color']); ?>;"><?php echo e($card['value']); ?></div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
            <span style="font-weight:600;color:#1e293b;">Daftar Pengajuan (<?php echo e($pengajuan->total()); ?>)</span>
            <form method="GET" action="<?php echo e(route('manajer.keringanan-penalti.index')); ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <select name="status" style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;">
                    <option value="PENDING_MANAJER" <?php echo e($status === 'PENDING_MANAJER' ? 'selected' : ''); ?>>Menunggu Approval Manajer</option>
                    <option value="PENDING_AO"      <?php echo e($status === 'PENDING_AO'      ? 'selected' : ''); ?>>Menunggu Verifikasi AO</option>
                    <option value="APPROVED"        <?php echo e($status === 'APPROVED'        ? 'selected' : ''); ?>>Disetujui</option>
                    <option value="REJECTED"        <?php echo e($status === 'REJECTED'        ? 'selected' : ''); ?>>Ditolak</option>
                </select>
                <input type="text" name="search" value="<?php echo e($search); ?>"
                    placeholder="Cari nama / bilyet..."
                    style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:200px;">
                <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Filter</button>
            </form>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Anggota</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No. Bilyet</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Penalti Awal</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Keringanan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Rek. AO</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
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
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.75rem 1rem;color:#64748b;"><?php echo e($item->id); ?></td>
                    <td style="padding:0.75rem 1rem;">
                        <div style="font-weight:600;color:#1e293b;"><?php echo e($item->anggota?->nama_lengkap ?? '-'); ?></div>
                        <div style="font-size:0.75rem;color:#64748b;"><?php echo e($item->anggota?->no_anggota); ?></div>
                    </td>
                    <td style="padding:0.75rem 1rem;color:#475569;"><?php echo e($item->deposito?->deposito_number ?? '-'); ?></td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#b45309;white-space:nowrap;">
                        Rp <?php echo e(number_format((float) $item->nominal_penalti_awal, 0, ',', '.')); ?>

                    </td>
                    <td style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#059669;white-space:nowrap;">
                        <?php echo e($item->isApproved() ? 'Rp ' . number_format((float) $item->nominal_penalti_disetujui, 0, ',', '.') : '—'); ?>

                        <?php if($item->isApproved() && $item->sudahDipakai()): ?>
                            <div style="font-size:0.7rem;color:#94a3b8;font-weight:400;">sudah dipakai</div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem 1rem;">
                        <?php if($item->rekomendasi_ao === 'SETUJU'): ?>
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#d1fae5;color:#065f46;">SETUJU</span>
                        <?php elseif($item->rekomendasi_ao === 'TOLAK'): ?>
                            <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:#fee2e2;color:#991b1b;">TOLAK</span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;">
                        <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;<?php echo e($badgeStyle); ?>">
                            <?php echo e($item->status_label); ?>

                        </span>
                    </td>
                    <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                        <?php if($item->isPendingManajer()): ?>
                            <button type="button" class="btn btn-primary"
                                style="font-size:.75rem;padding:.35rem .7rem;"
                                onclick="openApprove(<?php echo e($item->id); ?>, '<?php echo e(addslashes($item->anggota?->nama_lengkap ?? '-')); ?>', '<?php echo e(number_format((float) $item->nominal_penalti_awal, 0, ',', '.')); ?>')">
                                Setujui
                            </button>
                            <button type="button" class="btn btn-secondary"
                                style="font-size:.75rem;padding:.35rem .7rem;"
                                onclick="openReject(<?php echo e($item->id); ?>, '<?php echo e(addslashes($item->anggota?->nama_lengkap ?? '-')); ?>')">
                                Tolak
                            </button>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-size:.8rem;">Selesai</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada pengajuan keringanan penalti deposito</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($pengajuan->hasPages()): ?>
        <div style="padding:1rem;border-top:1px solid #f1f5f9;"><?php echo e($pengajuan->links()); ?></div>
        <?php endif; ?>
    </div>

</div>


<div id="modal-approve" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:520px;width:92%;padding:1.5rem;">
        <h3 style="margin:0 0 .5rem;color:#1e293b;">Setujui Keringanan Penalti</h3>
        <p id="approve-text" style="font-size:.9rem;color:#475569;margin-bottom:1rem;"></p>
        <form method="POST" id="form-approve">
            <?php echo csrf_field(); ?>
            <label style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">
                Nominal Keringanan Disetujui (opsional)
            </label>
            <input type="number" name="nominal_disetujui" step="0.01" min="0"
                placeholder="Kosongkan untuk bebas penuh"
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
            <div style="font-size:.78rem;color:#64748b;margin-top:.3rem;">
                Bila dikosongkan, keringanan = BEBAS PENUH (penalti menjadi Rp 0). Nilai tidak boleh melebihi penalti awal.
            </div>
            <label style="display:block;font-weight:600;margin:.9rem 0 .35rem;font-size:.9rem;">Catatan Manajer (opsional)</label>
            <textarea name="catatan_manajer" rows="2"
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"
                placeholder="Pertimbangan keputusan..."></textarea>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Batal</button>
                <button type="submit" class="btn btn-primary">Setujui</button>
            </div>
        </form>
    </div>
</div>


<div id="modal-reject" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;max-width:480px;width:92%;padding:1.5rem;">
        <h3 style="margin:0 0 .5rem;color:#1e293b;">Tolak Keringanan Penalti</h3>
        <p id="reject-text" style="font-size:.9rem;color:#475569;margin-bottom:1rem;"></p>
        <form method="POST" id="form-reject">
            <?php echo csrf_field(); ?>
            <label style="display:block;font-weight:600;margin-bottom:.35rem;font-size:.9rem;">
                Alasan Penolakan <span style="color:#dc2626;">*</span>
            </label>
            <textarea name="catatan_manajer" rows="3" required
                style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"
                placeholder="Alasan penolakan keringanan..."></textarea>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModals()">Batal</button>
                <button type="submit" class="btn btn-primary">Tolak</button>
            </div>
        </form>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
// URL aksi diturunkan dari nama rute (bukan hardcode) agar tetap benar
// bila aplikasi dipasang di subdirektori atau domain berbeda.
const URL_APPROVE = "<?php echo e(route('manajer.keringanan-penalti.approve', ['relief' => '__ID__'])); ?>";
const URL_REJECT  = "<?php echo e(route('manajer.keringanan-penalti.reject', ['relief' => '__ID__'])); ?>";

function openApprove(id, nama, nominal) {
    document.getElementById('approve-text').innerHTML =
        'Setujui keringanan untuk <strong>' + nama + '</strong>? Penalti awal <strong>Rp ' + nominal +
        '</strong> dapat dibebaskan menjadi <strong>Rp 0</strong> atau dikurangi sebagian.';
    document.getElementById('form-approve').action = URL_APPROVE.replace('__ID__', encodeURIComponent(id));
    document.getElementById('modal-approve').style.display = 'flex';
}
function openReject(id, nama) {
    document.getElementById('reject-text').innerHTML =
        'Tolak keringanan untuk <strong>' + nama + '</strong>? Penalti tetap ditanggung penuh oleh anggota ' +
        'dan dibukukan sebagai Dana Kebajikan (ta\'zir).';
    document.getElementById('form-reject').action = URL_REJECT.replace('__ID__', encodeURIComponent(id));
    document.getElementById('modal-reject').style.display = 'flex';
}
function closeModals() {
    document.getElementById('modal-approve').style.display = 'none';
    document.getElementById('modal-reject').style.display = 'none';
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\manajer\keringanan_penalti_deposito\index.blade.php ENDPATH**/ ?>