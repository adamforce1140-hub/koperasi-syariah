
<?php $__env->startSection('page-title', "Monitoring Tunggakan"); ?>

<?php $__env->startSection('title', 'Monitoring Tunggakan'); ?>

<?php $__env->startSection('styles'); ?>
<style>
    /* Kolom catatan pada tabel */
    .catatan-cell { font-size: .8rem; color: #475569; max-width: 230px; }
    .catatan-latest { display: block; line-height: 1.35; }
    .catatan-meta {
        display: inline-block; margin-top: .25rem; font-size: .7rem; font-weight: 600;
        color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0;
        padding: .05rem .4rem; border-radius: 9999px;
    }

    /* Tombol aksi catatan */
    .btn-catatan {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .45rem .75rem; font-size: .78rem; font-weight: 600;
        color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0;
        border-radius: 6px; cursor: pointer; white-space: nowrap;
    }
    .btn-catatan:hover { background: #d1fae5; }

    /* Modal catatan penagihan */
    .modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, .5);
        z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
    }
    .modal-overlay.open { display: flex; }
    .modal-box {
        background: #fff; border-radius: 1rem; padding: 1.5rem;
        width: 540px; max-width: 100%; max-height: 90vh; overflow-y: auto;
    }
    .modal-title { font-size: 1.05rem; font-weight: 700; color: #065f46; margin-bottom: .35rem; }
    .modal-sub { font-size: .8rem; color: #64748b; margin-bottom: 1rem; line-height: 1.4; }
    .modal-label {
        display: block; font-size: .75rem; font-weight: 700; color: #475569;
        text-transform: uppercase; letter-spacing: .03em; margin-bottom: .4rem;
    }
    .riwayat-list {
        display: flex; flex-direction: column; gap: .6rem;
        max-height: 240px; overflow-y: auto; margin-bottom: 1.25rem;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .6rem; padding: .75rem;
    }
    .riwayat-item {
        background: #fff; border: 1px solid #e2e8f0; border-left: 3px solid #059669;
        border-radius: .45rem; padding: .55rem .7rem;
    }
    .riwayat-head {
        display: flex; justify-content: space-between; gap: .5rem;
        font-size: .72rem; color: #64748b; margin-bottom: .2rem;
    }
    .riwayat-body { font-size: .82rem; color: #334155; line-height: 1.4; white-space: pre-wrap; }
    .riwayat-empty { font-size: .8rem; color: #94a3b8; text-align: center; padding: .5rem 0; }

    .modal-box textarea {
        width: 100%; border: 1.5px solid #e2e8f0; border-radius: .5rem; padding: .65rem;
        font-size: .85rem; font-family: inherit; resize: vertical; min-height: 90px; outline: none;
    }
    .modal-box textarea:focus { border-color: #059669; }
    .modal-footer {
        display: flex; align-items: center; justify-content: flex-end;
        gap: .75rem; margin-top: 1rem;
    }
    .modal-footer .btn {
        padding: .55rem 1.1rem; font-size: .85rem; font-weight: 600;
        border: none; border-radius: .5rem; cursor: pointer;
    }
</style>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="page-header">
    <h1 class="page-title">Monitoring Tunggakan</h1>
    <p class="page-subtitle">Daftar angsuran yang terlambat bayar</p>
</div>

<?php if(session('success')): ?>
    <div class="alert alert-success"><?php echo e(session('success')); ?></div>
<?php endif; ?>

<div class="data-card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Daftar Tunggakan (<?php echo e($tunggakan->total()); ?>)</span>
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="<?php echo e($search); ?>"
                placeholder="Cari nama / no. anggota..."
                style="padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;width:240px;">
            <button type="submit" class="btn btn-secondary" style="font-size:.85rem;padding:.5rem .9rem;">Cari</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>No. Pembiayaan</th>
                <th>Anggota</th>
                <th>Angsuran Ke</th>
                <th>Jatuh Tempo</th>
                <th>Hari Terlambat</th>
                <th>Status</th>
                <th>Jumlah Tagihan</th>
                <th>Catatan</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php $__empty_1 = true; $__currentLoopData = $tunggakan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <?php
                $hariTerlambat = $item->hari_terlambat_dinamis;
                $badge         = $item->status_badge;
                $badgeStyle    = [
                    'red'    => 'background:#fee2e2;color:#991b1b;',
                    'yellow' => 'background:#fef3c7;color:#92400e;',
                    'green'  => 'background:#d1fae5;color:#065f46;',
                    'gray'   => 'background:#f1f5f9;color:#475569;',
                ][$badge['color']] ?? 'background:#f1f5f9;color:#475569;';

                $jumlahRiwayat = $item->catatanPenagihan->count();
            ?>
            <tr>
                <td><strong><?php echo e($item->pembiayaan?->no_pembiayaan ?? '-'); ?></strong></td>
                <td>
                    <div><?php echo e($item->pembiayaan?->anggota?->nama_lengkap ?? '-'); ?></div>
                    <div style="font-size:.75rem;color:#6b7280;"><?php echo e($item->pembiayaan?->anggota?->no_hp); ?></div>
                </td>
                <td style="text-align:center;"><?php echo e($item->angsuran_ke); ?></td>
                <td><?php echo e($item->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-'); ?></td>
                <td style="text-align:center;">
                    <span style="font-weight:700;color:<?php echo e($hariTerlambat > 30 ? '#ef4444' : '#f59e0b'); ?>;">
                        Terlambat <?php echo e($hariTerlambat); ?> Hari
                    </span>
                </td>
                <td>
                    <span style="padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;<?php echo e($badgeStyle); ?>">
                        <?php echo e($badge['label']); ?>

                    </span>
                </td>
                <td>Rp <?php echo e(number_format($item->jumlah_angsuran ?? 0, 0, ',', '.')); ?></td>
                <td class="catatan-cell">
                    <?php if($item->catatan): ?>
                        <span class="catatan-latest"><?php echo e(\Illuminate\Support\Str::limit($item->catatan, 70)); ?></span>
                    <?php else: ?>
                        <span style="color:#cbd5e1;">Belum ada catatan</span>
                    <?php endif; ?>
                    <?php if($jumlahRiwayat > 0): ?>
                        <span class="catatan-meta">🗒 <?php echo e($jumlahRiwayat); ?> riwayat</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn-catatan" onclick="bukaModalCatatan(<?php echo e($item->id); ?>)">
                        ✎ Catatan
                    </button>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada tunggakan</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($tunggakan->hasPages()): ?>
    <div style="padding:1rem;"><?php echo e($tunggakan->links()); ?></div>
    <?php endif; ?>
</div>


<?php $__currentLoopData = $tunggakan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
<div class="modal-overlay" id="modalCatatan-<?php echo e($item->id); ?>">
    <div class="modal-box">
        <div class="modal-title">📝 Catatan Penagihan</div>
        <div class="modal-sub">
            <?php echo e($item->pembiayaan?->no_pembiayaan ?? '-'); ?> ·
            <?php echo e($item->pembiayaan?->anggota?->nama_lengkap ?? '-'); ?> ·
            Angsuran ke-<?php echo e($item->angsuran_ke); ?> ·
            Terlambat <?php echo e($item->hari_terlambat_dinamis); ?> hari
        </div>

        <span class="modal-label">Riwayat Penagihan (<?php echo e($item->catatanPenagihan->count()); ?>)</span>
        <div class="riwayat-list">
            <?php $__empty_1 = true; $__currentLoopData = $item->catatanPenagihan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <div class="riwayat-item">
                    <div class="riwayat-head">
                        <strong><?php echo e($c->user?->name ?? 'Sistem'); ?></strong>
                        <span><?php echo e($c->created_at?->format('d/m/Y H:i') ?? '-'); ?></span>
                    </div>
                    <div class="riwayat-body"><?php echo e($c->catatan); ?></div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <div class="riwayat-empty">Belum ada catatan penagihan untuk angsuran ini.</div>
            <?php endif; ?>
        </div>

        <form method="POST" action="<?php echo e(route('ao.tunggakan.catatan', $item->id)); ?>">
            <?php echo csrf_field(); ?>
            <label class="modal-label" for="catatan-<?php echo e($item->id); ?>">Catatan Baru</label>
            <textarea id="catatan-<?php echo e($item->id); ?>" name="catatan" rows="3" maxlength="1000" required
                placeholder="Tulis hasil penagihan, mis. nasabah berjanji bayar tanggal ..."></textarea>
            <div class="modal-footer">
                <button type="button" class="btn" style="background:#f1f5f9;color:#475569;"
                    onclick="tutupModalCatatan(<?php echo e($item->id); ?>)">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Catatan</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
    function bukaModalCatatan(id) {
        document.getElementById('modalCatatan-' + id).classList.add('open');
    }

    function tutupModalCatatan(id) {
        document.getElementById('modalCatatan-' + id).classList.remove('open');
    }

    // Klik di luar modal untuk menutup
    document.querySelectorAll('.modal-overlay').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (e.target === this) this.classList.remove('open');
        });
    });

    // Tutup dengan tombol Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open')
                .forEach(el => el.classList.remove('open'));
        }
    });
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/ao/tunggakan/index.blade.php ENDPATH**/ ?>