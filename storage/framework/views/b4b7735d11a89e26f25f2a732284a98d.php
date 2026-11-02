<?php $__env->startSection('title', 'Manajemen User & Hak Akses — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Manajemen User & Hak Akses'); ?>

<?php
    // Format nominal batas operasional. Semantik yang dipakai mesin batas:
    // NULL = tanpa batas (unlimited), 0 = dilarang sepenuhnya.
    $formatLimit = function ($nilai) {
        if (is_null($nilai) || $nilai === '') {
            return '<span style="color:#059669;font-weight:600;">&#8734; Unlimited</span>';
        }

        if ((float) $nilai === 0.0) {
            return '<span style="color:#ef4444;font-weight:600;">&#128683; Dilarang</span>';
        }

        return '<strong>Rp ' . number_format((float) $nilai, 0, ',', '.') . '</strong>';
    };
?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Manajemen User & Hak Akses Staf
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Kelola akun pengguna, tinjau matriks wewenang per peran, dan atur batas operasional transaksi.
            </p>
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

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <?php if(isset($users) && $users->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">#</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama User</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Email</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Role / Peran</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;color:#94a3b8;"><?php echo e(($users->currentPage() - 1) * $users->perPage() + $loop->iteration); ?></td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#1e293b;"><?php echo e($u->name); ?></td>
                        <td style="padding:0.75rem 1rem;color:#475569;"><?php echo e($u->email); ?></td>
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:0.375rem;font-size:0.75rem;font-weight:600;background:#e0f2fe;color:#0369a1;text-transform:uppercase;">
                                <?php echo e($u->role); ?>

                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;text-align:center;color:#64748b;"><?php echo e($u->created_at ? $u->created_at->format('d M Y') : '-'); ?></td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <div style="padding:1rem;border-top:1px solid #e2e8f0;">
            <?php echo e($users->links()); ?>

        </div>
        <?php else: ?>
        <div style="padding:2rem;text-align:center;color:#64748b;">
            Belum ada data user.
        </div>
        <?php endif; ?>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                Matriks Hak Akses per Peran
            </h3>
            <p style="margin:0.3rem 0 0;font-size:0.78rem;color:#64748b;">
                Sumber tunggal: <code style="background:#f1f5f9;padding:0.05rem 0.3rem;border-radius:0.25rem;">config/izin.php</code>.
                Hanya-baca &mdash; wewenang tidak dapat diubah dari antarmuka agar pemisahan tugas tetap dapat diaudit dari kode.
            </p>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:0.6rem 0.75rem;text-align:left;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Izin</th>
                        <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <th style="padding:0.6rem 0.5rem;text-align:center;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">
                                <?php echo e(\App\Support\HierarkiRole::label($peran)); ?>

                            </th>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $matriksIzin; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $modul => $daftarIzin): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr style="background:#f1f5f9;">
                            <td colspan="<?php echo e(count($peranTersedia) + 1); ?>" style="padding:0.4rem 0.75rem;font-size:0.7rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;">
                                <?php echo e($modul); ?>

                            </td>
                        </tr>
                        <?php $__currentLoopData = $daftarIzin; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $izin => $peranIzin): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.45rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    <code style="font-size:0.75rem;"><?php echo e($izin); ?></code>
                                </td>
                                <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <td style="padding:0.45rem 0.5rem;text-align:center;">
                                        <?php if(in_array($peran, $peranIzin, true)): ?>
                                            <span title="Diizinkan" style="display:inline-block;width:1.15rem;height:1.15rem;line-height:1.15rem;border-radius:999px;background:#d1fae5;color:#065f46;font-size:0.72rem;font-weight:700;">&#10003;</span>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1;">&middot;</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>

        <p style="font-size:0.74rem;color:#64748b;margin:0.9rem 0 0;">
            Beberapa izin pada satu rute bersifat <strong>DAN</strong> (semua harus lolos). Satu izin mewakili satu
            wewenang fungsional &mdash; bukan satu tombol.
        </p>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1.25rem;">
            <div>
                <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                    Batas Operasional per Peran
                </h3>
                <p style="margin:0.3rem 0 0;font-size:0.78rem;color:#64748b;">
                    Override dari tabel <code style="background:#f1f5f9;padding:0.05rem 0.3rem;border-radius:0.25rem;">limit_transaksi_role</code>.
                    Pasangan peran &amp; jenis yang tidak tercantum memakai default konfigurasi.
                </p>
            </div>
            <button type="button" onclick="bukaModalLimitTambah()"
                style="padding:0.5rem 1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                + Tambah Override
            </button>
        </div>

        <?php if($limitTransaksi->isEmpty()): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada override. Seluruh peran memakai batas default dari
                <code>config/role_hierarki.php</code>.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Peran</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Jenis Transaksi</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Limit / Transaksi</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Limit Harian</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:center;white-space:nowrap;">Butuh Approval</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Approval Oleh</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Berlaku</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:right;white-space:nowrap;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $limitTransaksi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $l): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $berlaku = $batasEfektif[$l->role][$l->jenis_transaksi]['limit_per_transaksi'] ?? null;
                                $payloadLimit = [
                                    'id'                  => $l->id,
                                    'role'                => $l->role,
                                    'jenis_transaksi'     => $l->jenis_transaksi,
                                    'limit_per_transaksi' => is_null($l->limit_per_transaksi) ? '' : (float) $l->limit_per_transaksi,
                                    'limit_harian'        => is_null($l->limit_harian) ? '' : (float) $l->limit_harian,
                                    'butuh_approval'      => (bool) $l->butuh_approval,
                                    'approval_role'       => $l->approval_role,
                                ];
                            ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;font-weight:600;white-space:nowrap;">
                                    <?php echo e(\App\Support\HierarkiRole::label($l->role)); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    <?php echo e(\App\Support\HierarkiRole::labelJenis($l->jenis_transaksi)); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    <?php echo $formatLimit($l->limit_per_transaksi); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;white-space:nowrap;">
                                    <?php echo $formatLimit($l->limit_harian); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;text-align:center;">
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;<?php echo e($l->butuh_approval ? 'background:#fef3c7;color:#92400e;' : 'background:#d1fae5;color:#065f46;'); ?>">
                                        <?php echo e($l->butuh_approval ? 'YA' : 'TIDAK'); ?>

                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    <?php echo e($l->approval_role ? \App\Support\HierarkiRole::label($l->approval_role) : '—'); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    <?php echo $formatLimit($berlaku); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;text-align:right;white-space:nowrap;">
                                    <button type="button" onclick='bukaModalLimitEdit(<?php echo json_encode($payloadLimit, 15, 512) ?>)'
                                        style="padding:0.3rem 0.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                        Edit
                                    </button>

                                    <form action="<?php echo e(route('pengurus.limit-role.destroy', $l->id)); ?>" method="POST"
                                        style="display:inline;" onsubmit="return confirm('Hapus override limit <?php echo e(\App\Support\HierarkiRole::label($l->role)); ?> — <?php echo e(\App\Support\HierarkiRole::labelJenis($l->jenis_transaksi)); ?>? Batas akan kembali ke default konfigurasi.');">
                                        <?php echo csrf_field(); ?>
                                        <?php echo method_field('DELETE'); ?>
                                        <button type="submit"
                                            style="padding:0.3rem 0.65rem;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p style="font-size:0.74rem;color:#64748b;margin:0.9rem 0 0;">
            <strong>Semantik nilai:</strong> kosongkan nominal untuk <em>Unlimited</em>; isi <code>0</code> untuk
            <em>melarang</em> transaksi. Kolom <strong>Berlaku</strong> menunjukkan nilai efektif setelah lapisan
            config &rarr; override &rarr; kebijakan koperasi (mis. limit teller dapat diatur dari menu Kebijakan).
        </p>
    </div>

</div>


<div id="modal-limit-tambah" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tambah Override Batas</h3>
        <form action="<?php echo e(route('pengurus.limit-role.store')); ?>" method="POST">
            <?php echo csrf_field(); ?>
            <div style="display:flex;flex-direction:column;gap:0.9rem;">
                <div>
                    <label for="tambah-limit-role" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran <span style="color:#dc2626;">*</span></label>
                    <select id="tambah-limit-role" name="role" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($peran); ?>"><?php echo e(\App\Support\HierarkiRole::label($peran)); ?> (<?php echo e($peran); ?>)</option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label for="tambah-limit-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis Transaksi <span style="color:#dc2626;">*</span></label>
                    <select id="tambah-limit-jenis" name="jenis_transaksi" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <?php $__currentLoopData = $jenisTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kodeJenis => $labelJenis): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($kodeJenis); ?>"><?php echo e($labelJenis); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label for="tambah-limit-per-transaksi" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit per Transaksi (Rp)</label>
                    <input type="number" id="tambah-limit-per-transaksi" name="limit_per_transaksi" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited, 0 = dilarang"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <div>
                    <label for="tambah-limit-harian" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit Harian (Rp)</label>
                    <input type="number" id="tambah-limit-harian" name="limit_harian" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                    <input type="checkbox" name="butuh_approval" value="1" style="width:1rem;height:1rem;">
                    Transaksi di atas limit wajib disetujui
                </label>
                <div>
                    <label for="tambah-limit-approval" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran Pemberi Approval</label>
                    <select id="tambah-limit-approval" name="approval_role"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <option value="">— Tidak ada —</option>
                        <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($peran); ?>"><?php echo e(\App\Support\HierarkiRole::label($peran)); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModalLimit('modal-limit-tambah')"
                    style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                    Simpan Override
                </button>
            </div>
        </form>
    </div>
</div>


<div id="modal-limit-edit" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Edit Override Batas</h3>
        <form id="form-limit-edit" action="" method="POST">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PUT'); ?>
            <div style="display:flex;flex-direction:column;gap:0.9rem;">
                <div>
                    <label for="edit-limit-role" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran <span style="color:#dc2626;">*</span></label>
                    <select id="edit-limit-role" name="role" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($peran); ?>"><?php echo e(\App\Support\HierarkiRole::label($peran)); ?> (<?php echo e($peran); ?>)</option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label for="edit-limit-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis Transaksi <span style="color:#dc2626;">*</span></label>
                    <select id="edit-limit-jenis" name="jenis_transaksi" required
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <?php $__currentLoopData = $jenisTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kodeJenis => $labelJenis): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($kodeJenis); ?>"><?php echo e($labelJenis); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <div>
                    <label for="edit-limit-per-transaksi" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit per Transaksi (Rp)</label>
                    <input type="number" id="edit-limit-per-transaksi" name="limit_per_transaksi" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited, 0 = dilarang"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <div>
                    <label for="edit-limit-harian" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Limit Harian (Rp)</label>
                    <input type="number" id="edit-limit-harian" name="limit_harian" min="0" step="0.01"
                        placeholder="Kosongkan = unlimited"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                </div>
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                    <input type="checkbox" id="edit-limit-butuh-approval" name="butuh_approval" value="1" style="width:1rem;height:1rem;">
                    Transaksi di atas limit wajib disetujui
                </label>
                <div>
                    <label for="edit-limit-approval" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Peran Pemberi Approval</label>
                    <select id="edit-limit-approval" name="approval_role"
                        style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                        <option value="">— Tidak ada —</option>
                        <?php $__currentLoopData = $peranTersedia; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $peran): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($peran); ?>"><?php echo e(\App\Support\HierarkiRole::label($peran)); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                <button type="button" onclick="tutupModalLimit('modal-limit-edit')"
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
const ROUTE_LIMIT_UPDATE = <?php echo json_encode(route('pengurus.limit-role.update', ['limit_role' => '__ID__']), 512) ?>;

function bukaModalLimit(id) {
    document.getElementById(id).style.display = 'block';
}

function tutupModalLimit(id) {
    document.getElementById(id).style.display = 'none';
}

function bukaModalLimitTambah() {
    document.getElementById('tambah-limit-role').selectedIndex = 0;
    document.getElementById('tambah-limit-jenis').selectedIndex = 0;
    document.getElementById('tambah-limit-per-transaksi').value = '';
    document.getElementById('tambah-limit-harian').value = '';
    document.getElementById('tambah-limit-approval').value = '';

    const centang = document.querySelector('#modal-limit-tambah input[name="butuh_approval"]');
    if (centang) {
        centang.checked = false;
    }

    bukaModalLimit('modal-limit-tambah');
}

function bukaModalLimitEdit(data) {
    document.getElementById('form-limit-edit').action = ROUTE_LIMIT_UPDATE.replace('__ID__', data.id);
    document.getElementById('edit-limit-role').value = data.role ?? '';
    document.getElementById('edit-limit-jenis').value = data.jenis_transaksi ?? '';
    document.getElementById('edit-limit-per-transaksi').value = data.limit_per_transaksi ?? '';
    document.getElementById('edit-limit-harian').value = data.limit_harian ?? '';
    document.getElementById('edit-limit-butuh-approval').checked = !! data.butuh_approval;
    document.getElementById('edit-limit-approval').value = data.approval_role ?? '';

    bukaModalLimit('modal-limit-edit');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        tutupModalLimit('modal-limit-tambah');
        tutupModalLimit('modal-limit-edit');
    }
});
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\users.blade.php ENDPATH**/ ?>