<?php $__env->startSection('title', 'Kalender Hari Libur — Pengurus'); ?>
<?php $__env->startSection('page-title', 'Kalender Hari Libur'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Kalender Hari Libur
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;max-width:52rem;">
                Daftar hari libur dipakai mesin penjadwalan angsuran: tanggal jatuh tempo yang jatuh pada
                Sabtu/Minggu atau pada tanggal di bawah ini digeser ke hari kerja terdekat. Perubahan
                <strong>langsung berlaku</strong> untuk jadwal angsuran yang dihitung setelah penyimpanan.
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

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem;">

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Libur Aktif <?php echo e($tahun); ?></div>
            <div style="font-size:1.6rem;font-weight:700;color:#065f46;line-height:1.2;margin-top:0.25rem;"><?php echo e(number_format($statistik['aktif'], 0, ',', '.')); ?></div>
            <div style="font-size:0.74rem;color:#64748b;margin-top:0.15rem;">
                <?php echo e($statistik['sudah_lewat']); ?> sudah lewat &middot; <?php echo e($statistik['akan_datang']); ?> akan datang
            </div>
        </div>

        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Nonaktif <?php echo e($tahun); ?></div>
            <div style="font-size:1.6rem;font-weight:700;color:#92400e;line-height:1.2;margin-top:0.25rem;"><?php echo e(number_format($statistik['nonaktif'], 0, ',', '.')); ?></div>
            <div style="font-size:0.74rem;color:#64748b;margin-top:0.15rem;">Diabaikan penjadwalan angsuran</div>
        </div>

        <?php $__currentLoopData = $labelJenis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $jenis => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
                <div style="font-size:0.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;"><?php echo e($label); ?></div>
                <div style="font-size:1.6rem;font-weight:700;color:#1e293b;line-height:1.2;margin-top:0.25rem;"><?php echo e(number_format((int) ($statistik['per_jenis'][$jenis] ?? 0), 0, ',', '.')); ?></div>
                <div style="font-size:0.74rem;color:#64748b;margin-top:0.15rem;">Aktif pada <?php echo e($tahun); ?></div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    </div>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:0.9rem 1.1rem;font-size:0.8rem;color:#475569;">
        <strong>Pratinjau penjadwalan:</strong>
        <?php echo e($besok['tanggal']->locale('id')->translatedFormat('d F Y')); ?>

        (<?php echo e($besok['tanggal']->locale('id')->translatedFormat('l')); ?>)
        <?php if($besok['hari_kerja']): ?>
            adalah hari kerja &mdash; jatuh tempo tidak digeser.
        <?php else: ?>
            bukan hari kerja &mdash; jatuh tempo digeser ke
            <strong><?php echo e($besok['hasil_geser']->locale('id')->translatedFormat('d F Y')); ?></strong>
            (<?php echo e($besok['hasil_geser']->locale('id')->translatedFormat('l')); ?>).
        <?php endif; ?>
        <span style="display:block;margin-top:0.3rem;color:#64748b;">
            Fitur penyesuaian: <strong><?php echo e($besok['aktif'] ? 'AKTIF' : 'NONAKTIF'); ?></strong>
            &middot; Strategi: <strong><?php echo e($besok['strategi'] === 'MUNDUR' ? 'MUNDUR (hari kerja sebelumnya)' : 'MAJU (hari kerja berikutnya)'); ?></strong>
            &middot; Tanggal jatuh tempo bulanan: <strong><?php echo e(\App\Support\Setting::tanggalJatuhTempoDefault()); ?></strong>
            &mdash; ubah pada menu <em>Profil &amp; Kebijakan Koperasi</em>.
        </span>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid #f1f5f9;padding-bottom:0.75rem;margin-bottom:1.25rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0;">
                Daftar Hari Libur <?php echo e($tahun); ?>

            </h3>
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <form method="GET" action="<?php echo e(route('pengurus.hari-libur.index')); ?>" style="display:flex;align-items:center;gap:0.4rem;">
                    <label for="filter-tahun" style="font-size:0.75rem;font-weight:600;color:#475569;">Tahun</label>
                    <select id="filter-tahun" name="tahun" onchange="this.form.submit()"
                        style="padding:0.4rem 0.6rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.78rem;color:#1e293b;background:#fff;">
                        <?php $__currentLoopData = $daftarTahun; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $opsi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($opsi); ?>" <?php if($opsi === $tahun): echo 'selected'; endif; ?>><?php echo e($opsi); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </form>
                <?php if($bolehUbah): ?>
                    <button type="button" onclick="bukaModalTambah()"
                        style="padding:0.5rem 1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                        + Tambah Hari Libur
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if($libur->isEmpty()): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:0.5rem;padding:1.25rem;text-align:center;font-size:0.82rem;color:#64748b;">
                Belum ada hari libur tercatat untuk tahun <?php echo e($tahun); ?>. Tanpa data ini, penjadwalan hanya
                menghindari Sabtu dan Minggu.
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;text-align:left;">
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Tanggal</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Hari</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Keterangan</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Jenis</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">Status</th>
                            <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;white-space:nowrap;">Waktu</th>
                            <?php if($bolehUbah): ?>
                                <th style="padding:0.6rem 0.75rem;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;text-align:right;">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $__currentLoopData = $libur; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr style="border-bottom:1px solid #f1f5f9;<?php echo e($item->is_active ? '' : 'background:#fafafa;'); ?>">
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;font-weight:600;white-space:nowrap;">
                                    <?php echo e($item->tanggal->locale('id')->translatedFormat('d M Y')); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#475569;white-space:nowrap;">
                                    <?php echo e($item->tanggal->locale('id')->translatedFormat('l')); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#1e293b;">
                                    <?php echo e($item->keterangan); ?>

                                </td>
                                <td style="padding:0.6rem 0.75rem;">
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;background:#eff6ff;color:#1d4ed8;">
                                        <?php echo e($labelJenis[$item->jenis] ?? $item->jenis); ?>

                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;">
                                    <span style="display:inline-block;font-size:0.7rem;font-weight:700;padding:0.15rem 0.45rem;border-radius:0.25rem;<?php echo e($item->is_active ? 'background:#d1fae5;color:#065f46;' : 'background:#e2e8f0;color:#475569;'); ?>">
                                        <?php echo e($item->is_active ? 'AKTIF' : 'NONAKTIF'); ?>

                                    </span>
                                </td>
                                <td style="padding:0.6rem 0.75rem;color:#64748b;white-space:nowrap;font-size:0.75rem;">
                                    <?php echo e($item->updated_at?->locale('id')->translatedFormat('d M Y, H:i') ?? '—'); ?>

                                </td>
                                <?php if($bolehUbah): ?>
                                    <td style="padding:0.6rem 0.75rem;text-align:right;white-space:nowrap;">
                                        <?php
                                            $payloadEdit = [
                                                'id'         => $item->id,
                                                'tanggal'    => $item->tanggal->format('Y-m-d'),
                                                'keterangan' => $item->keterangan,
                                                'jenis'      => $item->jenis,
                                                'is_active'  => (bool) $item->is_active,
                                            ];
                                        ?>
                                        <button type="button"
                                            onclick='bukaModalEdit(<?php echo json_encode($payloadEdit, 15, 512) ?>)'
                                            style="padding:0.3rem 0.65rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                            Edit
                                        </button>

                                        <form action="<?php echo e(route('pengurus.hari-libur.toggle', $item->id)); ?>" method="POST" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <button type="submit"
                                                style="padding:0.3rem 0.65rem;background:<?php echo e($item->is_active ? '#fef3c7' : '#d1fae5'); ?>;color:<?php echo e($item->is_active ? '#92400e' : '#065f46'); ?>;border:1px solid <?php echo e($item->is_active ? '#fde68a' : '#a7f3d0'); ?>;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                                <?php echo e($item->is_active ? 'Nonaktifkan' : 'Aktifkan'); ?>

                                            </button>
                                        </form>

                                        <form action="<?php echo e(route('pengurus.hari-libur.destroy', $item->id)); ?>" method="POST"
                                            style="display:inline;" onsubmit="return confirm('Hapus hari libur <?php echo e($item->tanggal->format('d/m/Y')); ?> (<?php echo e($item->keterangan); ?>)?');">
                                            <?php echo csrf_field(); ?>
                                            <?php echo method_field('DELETE'); ?>
                                            <button type="submit"
                                                style="padding:0.3rem 0.65rem;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;border-radius:0.35rem;font-size:0.74rem;font-weight:600;cursor:pointer;">
                                                Hapus
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p style="font-size:0.75rem;color:#64748b;margin:0.9rem 0 0;">
            <strong>Catatan:</strong> menonaktifkan hari libur bersifat <em>soft disable</em> — baris tetap tersimpan
            sebagai riwayat, namun tanggal tersebut kembali dianggap hari kerja oleh penjadwalan.
            Perubahan hanya memengaruhi jadwal angsuran yang dihitung setelah perubahan; jadwal yang sudah
            tersimpan pada pembiayaan berjalan tidak diubah otomatis.
        </p>
    </div>

</div>

<?php if($bolehUbah): ?>
    
    <div id="modal-tambah" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
        <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tambah Hari Libur</h3>
            <form id="form-tambah" action="<?php echo e(route('pengurus.hari-libur.store')); ?>" method="POST">
                <?php echo csrf_field(); ?>
                <div style="display:flex;flex-direction:column;gap:0.9rem;">
                    <div>
                        <label for="form-tambah-tanggal" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Tanggal <span style="color:#dc2626;">*</span></label>
                        <input type="date" id="form-tambah-tanggal" name="tanggal" required
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                        <span style="display:block;font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">Satu tanggal hanya boleh terdaftar sekali.</span>
                    </div>
                    <div>
                        <label for="form-tambah-keterangan" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Keterangan <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="form-tambah-keterangan" name="keterangan" maxlength="150" required
                            placeholder="Contoh: Hari Raya Idul Fitri 1448 H"
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                    </div>
                    <div>
                        <label for="form-tambah-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis</label>
                        <select id="form-tambah-jenis" name="jenis"
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                            <?php $__currentLoopData = $labelJenis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $jenis => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($jenis); ?>" <?php if($jenis === \App\Models\HariLibur::JENIS_NASIONAL): echo 'selected'; endif; ?>><?php echo e($label); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </select>
                    </div>
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                        <input type="checkbox" id="form-tambah-is_active" name="is_active" value="1" checked style="width:1rem;height:1rem;">
                        Langsung dipakai penjadwalan angsuran
                    </label>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:1.25rem;">
                    <button type="button" onclick="tutupModal('modal-tambah')"
                        style="padding:0.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                        Batal
                    </button>
                    <button type="submit"
                        style="padding:0.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:0.5rem;font-size:0.8rem;font-weight:600;cursor:pointer;">
                        Simpan Hari Libur
                    </button>
                </div>
            </form>
        </div>
    </div>

    
    <div id="modal-edit" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:60;padding:1.5rem;overflow-y:auto;">
        <div style="max-width:32rem;margin:3rem auto;background:#fff;border-radius:0.875rem;padding:1.5rem;">
            <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Edit Hari Libur</h3>
            <form id="form-edit" action="" method="POST">
                <?php echo csrf_field(); ?>
                <?php echo method_field('PUT'); ?>
                <div style="display:flex;flex-direction:column;gap:0.9rem;">
                    <div>
                        <label for="form-edit-tanggal" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Tanggal <span style="color:#dc2626;">*</span></label>
                        <input type="date" id="form-edit-tanggal" name="tanggal" required
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                    </div>
                    <div>
                        <label for="form-edit-keterangan" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Keterangan <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="form-edit-keterangan" name="keterangan" maxlength="150" required
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;">
                    </div>
                    <div>
                        <label for="form-edit-jenis" style="display:block;font-size:0.75rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">Jenis</label>
                        <select id="form-edit-jenis" name="jenis"
                            style="width:100%;padding:0.5rem 0.65rem;border:1px solid #cbd5e1;border-radius:0.4rem;font-size:0.82rem;color:#1e293b;background:#fff;">
                            <?php $__currentLoopData = $labelJenis; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $jenis => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($jenis); ?>"><?php echo e($label); ?></option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </select>
                    </div>
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#334155;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.4rem;padding:0.55rem 0.7rem;cursor:pointer;">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="form-edit-is_active" name="is_active" value="1" style="width:1rem;height:1rem;">
                        Dipakai penjadwalan angsuran
                    </label>
                </div>
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
    const ROUTE_EDIT = <?php echo json_encode(route('pengurus.hari-libur.update', ['hari_libur' => '__ID__']), 512) ?>;

    function bukaModal(id) {
        document.getElementById(id).style.display = 'block';
    }

    function tutupModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    function bukaModalTambah() {
        document.getElementById('form-tambah-tanggal').value    = '';
        document.getElementById('form-tambah-keterangan').value = '';
        document.getElementById('form-tambah-jenis').value      = <?php echo json_encode(\App\Models\HariLibur::JENIS_NASIONAL, 15, 512) ?>;
        document.getElementById('form-tambah-is_active').checked = true;

        bukaModal('modal-tambah');
    }

    function bukaModalEdit(data) {
        document.getElementById('form-edit').action = ROUTE_EDIT.replace('__ID__', data.id);
        document.getElementById('form-edit-tanggal').value     = data.tanggal ?? '';
        document.getElementById('form-edit-keterangan').value  = data.keterangan ?? '';
        document.getElementById('form-edit-jenis').value       = data.jenis ?? <?php echo json_encode(\App\Models\HariLibur::JENIS_NASIONAL, 15, 512) ?>;
        document.getElementById('form-edit-is_active').checked = !! data.is_active;

        bukaModal('modal-edit');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            tutupModal('modal-tambah');
            tutupModal('modal-edit');
        }
    });
    </script>
<?php endif; ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\pengurus\hari-libur.blade.php ENDPATH**/ ?>