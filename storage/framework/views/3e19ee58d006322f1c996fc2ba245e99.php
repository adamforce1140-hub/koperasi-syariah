<?php $__env->startSection('title', 'Master Jenis Simpanan — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Master Parameter Jenis Simpanan'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Master Parameter Jenis Simpanan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;max-width:52rem;">
                Kelola jenis simpanan beserta akun COA-nya secara dinamis. Setiap perubahan
                <strong>langsung berlaku</strong> di seluruh modul terkait — pemetaan jurnal otomatis,
                dropdown pembukaan rekening, dan modul klasifikasi simpanan lainnya — tanpa mengubah kode program.
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;padding:0.5rem 0.75rem;">
            <span style="font-size:0.72rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.04em;">Parameter Dinamis</span>
            <span style="font-size:0.72rem;color:#3b82f6;">tanpa ubah kode inti</span>
        </div>
    </div>

    <?php if(session('success')): ?>
        <div style="background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            <?php echo e(session('success')); ?>

        </div>
    <?php endif; ?>

    <?php if(session('error')): ?>
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;font-weight:600;">
            <?php echo e(session('error')); ?>

        </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
        <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:0.5rem;font-size:0.85rem;">
            <strong>Validasi gagal:</strong>
            <ul style="margin:0.35rem 0 0 1rem;padding:0;">
                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1.25rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                Daftar Jenis Simpanan
            </h3>
            <button type="button" onclick="bukaModalTambah()"
                style="padding:0.5rem 1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                + Tambah Jenis Simpanan
            </button>
        </div>

        <?php if($jenis->isEmpty()): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada parameter jenis simpanan. Tambahkan minimal satu jenis agar alur simpanan dapat berjalan.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">ID</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Kode</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Nama Jenis Simpanan</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Tipe</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Kode Akun COA</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Min. Setoran</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Auto-Debit</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Status</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Rekening</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Timestamp Perubahan</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $jenis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $coa = $item->kode_akun ? $coas->firstWhere('kode_akun', $item->kode_akun) : null;
                                $jumlahRekening = (int) ($pemakaiRekening[$item->nama] ?? 0);
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;<?php echo e($item->is_active ? '' : 'background:#fafafa;'); ?>">
                                <td style="padding:0.6rem 0.75rem;color:#94a3b8;font-family:monospace;">#<?php echo e($item->id); ?></td>
                                <td style="padding:0.6rem 0.75rem;">
                                    <?php if($item->code): ?>
                                        <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;background:#eff6ff;color:#1d4ed8;font-family:monospace;"><?php echo e($item->code); ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.6rem 0.75rem;font-weight:600;color:#1e293b;">
                                    <?php echo e($item->nama); ?>

                                    <?php if($item->keterangan): ?>
                                        <span style="display:block;font-size:0.72rem;font-weight:400;color:#94a3b8;margin-top:0.15rem;"><?php echo e($item->keterangan); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;">
                                    <?php echo e($labelTipe[$item->tipe] ?? $item->tipe); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;font-family:monospace;color:#334155;">
                                    <?php if($item->kode_akun): ?>
                                        <strong><?php echo e($item->kode_akun); ?></strong>
                                        <?php if($coa): ?>
                                            <span style="display:block;font-size:0.72rem;color:#64748b;font-family:inherit;"><?php echo e($coa->nama_akun); ?></span>
                                        <?php else: ?>
                                            <span style="display:block;font-size:0.72rem;color:#b91c1c;font-family:inherit;">⚠ tidak ada di CoA aktif</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#b91c1c;">— belum dipetakan</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#334155;font-family:monospace;white-space:nowrap;">
                                    <?php echo e($item->min_deposit_amount > 0 ? 'Rp ' . number_format((int) $item->min_deposit_amount, 0, ',', '.') : '—'); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;">
                                    <?php if($item->allow_auto_debit): ?>
                                        <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;background:#fef3c7;color:#92400e;">DIIZINKAN</span>
                                    <?php else: ?>
                                        <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;background:#e2e8f0;color:#475569;">MANUAL</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:0.6rem 0.75rem;">
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;<?php echo e($item->is_active ? 'background:#d1fae5;color:#065f46;' : 'background:#e2e8f0;color:#475569;'); ?>">
                                        <?php echo e($item->is_active ? 'AKTIF' : 'NONAKTIF'); ?>

                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;">
                                    <?php echo e(number_format($jumlahRekening, 0, ',', '.')); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#64748b;white-space:nowrap;font-size:0.75rem;">
                                    <?php echo e($item->updated_at?->locale('id')->translatedFormat('d M Y, H:i') ?? '—'); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;text-align:right;white-space:nowrap;">
                                    <?php
                                        $payloadEdit = [
                                            'id'                 => $item->id,
                                            'nama'               => $item->nama,
                                            'kode_akun'          => $item->kode_akun,
                                            'tipe'               => $item->tipe,
                                            'min_deposit_amount' => (int) $item->min_deposit_amount,
                                            'allow_auto_debit'   => (bool) $item->allow_auto_debit,
                                            'account_coa_id'     => $item->account_coa_id,
                                            'is_active'          => (bool) $item->is_active,
                                            'keterangan'         => $item->keterangan,
                                        ];
                                    ?>
                                    <button type="button"
                                        onclick='bukaModalEdit(<?php echo json_encode($payloadEdit, 15, 512) ?>)'
                                        style="padding:0.3rem 0.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                        Edit
                                    </button>

                                    <form action="<?php echo e(route('pengurus.jenis-simpanan.toggle', $item->id)); ?>" method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit"
                                            style="padding:0.3rem 0.65rem;background:<?php echo e($item->is_active ? '#fef3c7' : '#d1fae5'); ?>;color:<?php echo e($item->is_active ? '#92400e' : '#065f46'); ?>;border:1px solid <?php echo e($item->is_active ? '#fde68a' : '#a7f3d0'); ?>;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                            <?php echo e($item->is_active ? 'Nonaktifkan' : 'Aktifkan'); ?>

                                        </button>
                                    </form>

                                    <?php if($jumlahRekening === 0): ?>
                                        <form action="<?php echo e(route('pengurus.jenis-simpanan.destroy', $item->id)); ?>" method="POST"
                                            style="display:inline;" onsubmit="return confirm('Hapus permanen jenis simpanan <?php echo e($item->nama); ?>?');">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit"
                                                style="padding:0.3rem 0.65rem;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                                Hapus
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p style="font-size:0.75rem;color:#64748b;margin:0.9rem 0 0;">
            <strong>Catatan:</strong> menonaktifkan jenis simpanan bersifat <em>soft disable</em> — riwayat rekening &amp;
            jurnal tetap utuh, namun jenis tersebut tidak lagi tersedia pada pembukaan rekening baru dan jurnal barunya tidak diposting.
        </p>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin-top:0;margin-bottom:0.35rem;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;">
            Riwayat Perubahan Parameter
        </h3>
        <p style="font-size:0.78rem;color:#64748b;margin:0 0 1.25rem;">
            Jejak audit setiap perubahan jenis simpanan — siapa mengubah, kapan, jenis simpanan mana, dan nilai sebelum/sesudah.
        </p>

        <?php if(($riwayat ?? collect())->isEmpty()): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada riwayat perubahan. Setiap penambahan, pengeditan, atau penonaktifan akan tercatat otomatis di sini.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Waktu</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Jenis Simpanan</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Kolom</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Nilai Lama</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Nilai Baru</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Diubah Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $riwayat; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $warna = match ($log->aksi) {
                                    'CREATE' => 'background:#dbeafe;color:#1d4ed8;',
                                    'DELETE' => 'background:#fee2e2;color:#991b1b;',
                                    default  => 'background:#fef3c7;color:#92400e;',
                                };
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.6rem 0.75rem;color:#64748b;white-space:nowrap;">
                                    <?php echo e(\Carbon\Carbon::parse($log->created_at)->locale('id')->translatedFormat('d M Y, H:i')); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;">
                                    <strong><?php echo e($log->nama_jenis ?? '—'); ?></strong>
                                    <span style="display:inline-block;font-size:0.68rem;font-weight:700;padding:0.1rem 0.35rem;border-radius:0.25rem;margin-left:0.35rem;<?php echo e($warna); ?>">
                                        <?php echo e($log->aksi); ?>

                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;font-weight:600;color:#475569;">
                                    <?php echo e(\App\Models\JenisSimpanan::LABEL_KOLOM[$log->kolom] ?? $log->kolom); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#94a3b8;font-family:monospace;">
                                    <?php echo e($log->nilai_lama ?? '—'); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#065f46;font-weight:600;font-family:monospace;">
                                    <?php echo e($log->nilai_baru ?? '—'); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;">
                                    <?php echo e($log->nama_user ?? 'Sistem'); ?>

                                    <?php if($log->role_user): ?>
                                        <span style="font-size:0.72rem;color:#94a3b8;">(<?php echo e(strtoupper($log->role_user)); ?>)</span>
                                    <?php endif; ?>
                                    <?php if($log->ip_address): ?>
                                        <span style="display:block;font-size:0.68rem;color:#cbd5e1;font-family:monospace;"><?php echo e($log->ip_address); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>


<div id="modal-tambah" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:34rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tambah Jenis Simpanan</h3>
        <form id="form-tambah" action="<?php echo e(route('pengurus.jenis-simpanan.store')); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo $__env->make('pengurus.partials._form-jenis-simpanan', ['prefix' => 'form-tambah', 'isEdit' => false], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModal('modal-tambah')"
                    style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Simpan Jenis Simpanan
                </button>
            </div>
        </form>
    </div>
</div>


<div id="modal-edit" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:34rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Edit Jenis Simpanan</h3>
        <form id="form-edit" action="" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PUT'); ?>
            <?php echo $__env->make('pengurus.partials._form-jenis-simpanan', ['prefix' => 'form-edit', 'isEdit' => true], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModal('modal-edit')"
                    style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const ROUTE_EDIT = <?php echo json_encode(route('pengurus.jenis-simpanan.update', ['jenis_simpanan' => '__ID__']), 512) ?>;

function bukaModal(id) {
    document.getElementById(id).style.display = 'block';
}

function tutupModal(id) {
    document.getElementById(id).style.display = 'none';
}

function bukaModalTambah() {
    document.getElementById('form-tambah-nama').value = '';
    document.getElementById('form-tambah-kode_akun').value = '';
    document.getElementById('form-tambah-tipe').value = 'lain';
    document.getElementById('form-tambah-keterangan').value = '';
    document.getElementById('form-tambah-is_active').checked = true;

    var minEl = document.getElementById('form-tambah-min_deposit_amount');
    if (minEl) { minEl.value = 0; }

    var coaEl = document.getElementById('form-tambah-account_coa_id');
    if (coaEl) { coaEl.value = ''; }

    var autoEl = document.getElementById('form-tambah-allow_auto_debit');
    if (autoEl) { autoEl.checked = false; }

    bukaModal('modal-tambah');
}

function bukaModalEdit(data) {
    document.getElementById('form-edit').action = ROUTE_EDIT.replace('__ID__', data.id);
    document.getElementById('form-edit-nama').value         = data.nama ?? '';
    document.getElementById('form-edit-kode_akun').value    = data.kode_akun ?? '';
    document.getElementById('form-edit-tipe').value         = data.tipe ?? 'lain';
    document.getElementById('form-edit-keterangan').value   = data.keterangan ?? '';
    document.getElementById('form-edit-is_active').checked  = !! data.is_active;

    var minEl = document.getElementById('form-edit-min_deposit_amount');
    if (minEl) { minEl.value = data.min_deposit_amount ?? 0; }

    var coaEl = document.getElementById('form-edit-account_coa_id');
    if (coaEl) { coaEl.value = data.account_coa_id ?? ''; }

    var autoEl = document.getElementById('form-edit-allow_auto_debit');
    if (autoEl) { autoEl.checked = !! data.allow_auto_debit; }

    bukaModal('modal-edit');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        tutupModal('modal-tambah');
        tutupModal('modal-edit');
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengurus/jenis-simpanan.blade.php ENDPATH**/ ?>