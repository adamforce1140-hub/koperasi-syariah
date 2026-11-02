
<?php $__env->startSection('page-title', "Pengeluaran Beban Operasional"); ?>

<?php $__env->startSection('title', 'Pengeluaran Beban Operasional'); ?>

<?php $__env->startSection('content'); ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">
            Pengeluaran Beban Operasional
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Kas keluar operasional — otomatis terposting ke CoA: <strong>DEBET Akun Beban (5-XXXX)</strong> | <strong>KREDIT Kas/Bank (1-XXXX)</strong>.
        </p>
    </div>

    <?php if(session('success')): ?>
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;">
        ✅ <?php echo e(session('success')); ?>

    </div>
    <?php endif; ?>
    <?php if(session('warning')): ?>
    <div style="padding:0.875rem 1.25rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:0.625rem;color:#92400e;font-size:0.875rem;">
        ⏳ <?php echo e(session('warning')); ?>

    </div>
    <?php endif; ?>
    <?php if(session('error')): ?>
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        ⚠️ <?php echo e(session('error')); ?>

    </div>
    <?php endif; ?>

    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Total Beban Disetujui Bulan Ini</div>
            <div style="font-size:1.35rem;font-weight:700;color:#991b1b;margin-top:.25rem;">Rp <?php echo e(number_format($totalBulanIni, 0, ',', '.')); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Batas Kewenangan (Auto-Approve)</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;margin-top:.25rem;">Rp <?php echo e(number_format($limitApproval, 0, ',', '.')); ?></div>
            <div style="font-size:0.72rem;color:#64748b;margin-top:.25rem;">Di atas limit: butuh approval Manajer.</div>
        </div>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);max-width:760px;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h2 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">🧾 Form Pengeluaran Kas Operasional</h2>
        </div>
        <form method="POST" action="<?php echo e(route('pengeluaran-beban.store')); ?>" style="padding:1.5rem;">
            <?php echo csrf_field(); ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Tanggal Transaksi <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="tanggal" value="<?php echo e(old('tanggal', now()->toDateString())); ?>" max="<?php echo e(now()->toDateString()); ?>" required
                        style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                    <?php $__errorArgs = ['tanggal'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Nominal Pengeluaran <span style="color:#dc2626;">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.875rem;color:#64748b;">Rp</span>
                        <input type="number" name="nominal" value="<?php echo e(old('nominal')); ?>" min="1000" step="any" placeholder="0" required
                            style="width:100%;padding:.6rem .75rem .6rem 2.25rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                    </div>
                    <?php $__errorArgs = ['nominal'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Jenis Beban Operasional <span style="color:#dc2626;">*</span></label>
                    <select name="jenis_beban" id="jenis_beban" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#fff;">
                        <option value="">-- Pilih Jenis Beban --</option>
                        <?php $__currentLoopData = $jenisBebanList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $jenis): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($kode); ?>" <?php echo e(old('jenis_beban') === $kode ? 'selected' : ''); ?>>
                                <?php echo e($jenis['label']); ?> (<?php echo e($jenis['kode_akun']); ?>)
                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <?php $__errorArgs = ['jenis_beban'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                    <p style="font-size:.7rem;color:#64748b;margin:.3rem 0 0;">Akun CoA beban terisi otomatis sesuai jenis yang dipilih.</p>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Akun Beban (CoA 5-XXXX) <span style="color:#dc2626;">*</span></label>
                    <select name="coa_beban" id="coa_beban" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#f1f5f9;">
                        <option value="">-- Pilih Jenis Beban Dulu --</option>
                        <?php $__currentLoopData = $akunBebanList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akun): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($akun->id); ?>"
                                data-kode="<?php echo e($akun->kode_akun); ?>"
                                data-jenis="<?php echo e($jenisBebanByKodeAkun[$akun->kode_akun] ?? ''); ?>"
                                <?php echo e((string) old('coa_beban') === (string) $akun->id ? 'selected' : ''); ?>>
                                <?php echo e($akun->kode_akun); ?> — <?php echo e($akun->nama_akun); ?>

                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <?php $__errorArgs = ['coa_beban'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Sumber Uang / Kas <span style="color:#dc2626;">*</span></label>
                    <select name="coa_kas" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#fff;">
                        <option value="">-- Pilih Sumber Uang --</option>
                        <?php $__currentLoopData = $akunKasList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $akun): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($akun->id); ?>" <?php echo e(old('coa_kas') == $akun->id ? 'selected' : ''); ?>>
                                <?php echo e($akun->kode_akun); ?> — <?php echo e($akun->nama_akun); ?>

                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <?php $__errorArgs = ['coa_kas'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Keterangan Transaksi <span style="color:#dc2626;">*</span></label>
                <input type="text" name="keterangan" value="<?php echo e(old('keterangan')); ?>" placeholder="Contoh: Bayar Listrik & ATK Kantor" required
                    style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                <?php $__errorArgs = ['keterangan'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;"><?php echo e($message); ?></p><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>

            
            <script>
                (function () {
                    const jenis = document.getElementById('jenis_beban');
                    const akunBeban = document.getElementById('coa_beban');
                    if (!jenis || !akunBeban) return;

                    function sinkronkan() {
                        const pilihan = jenis.options[jenis.selectedIndex];
                        if (!pilihan) return;

                        const kodeTarget = (pilihan.textContent.match(/\(([^)]+)\)\s*$/) || [])[1];
                        if (!kodeTarget) return;

                        let cocok = null;
                        let general = null;
                        for (const opt of akunBeban.options) {
                            if (opt.dataset.kode === kodeTarget) cocok = opt;
                            if (opt.dataset.kode === '5-1001') general = opt;
                        }

                        // Kode spesifik tidak ada di master CoA -> jatuh ke akun general
                        const tujuan = cocok || general;
                        if (tujuan) akunBeban.value = tujuan.value;
                    }

                    jenis.addEventListener('change', sinkronkan);
                    if (jenis.value) sinkronkan();
                })();
            </script>

            <button type="submit"
                style="padding:.65rem 1.5rem;background:#059669;color:#fff;border:none;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;">
                Simpan Pengeluaran
            </button>
            <span style="font-size:.75rem;color:#64748b;margin-left:.75rem;">
                ≤ Rp <?php echo e(number_format($limitApproval, 0, ',', '.')); ?>: langsung terposting | di atasnya: menunggu Manajer.
            </span>
        </form>
    </div>

    
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h2 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">📋 Riwayat Pengeluaran Beban</h2>
        </div>
        <?php if($pengeluarans->count()): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No / Tanggal</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Keterangan</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">DEBET</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">KREDIT</th>
                        <th style="padding:.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Nominal</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $__currentLoopData = $pengeluarans; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;"><?php echo e($item->no_pengeluaran); ?></div>
                            <div style="font-size:.72rem;color:#64748b;"><?php echo e($item->tanggal->format('d M Y')); ?> — <?php echo e($item->penginput?->name); ?></div>
                        </td>
                        <td style="padding:.75rem 1rem;color:#334155;"><?php echo e($item->keterangan); ?></td>
                        <td style="padding:.75rem 1rem;color:#991b1b;font-weight:600;"><?php echo e($item->coaBeban?->kode_akun); ?> <?php echo e($item->coaBeban?->nama_akun); ?></td>
                        <td style="padding:.75rem 1rem;color:#065f46;font-weight:600;"><?php echo e($item->coaKas?->kode_akun); ?> <?php echo e($item->coaKas?->nama_akun); ?></td>
                        <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp <?php echo e(number_format($item->nominal, 0, ',', '.')); ?></td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            <?php
                                $badge = ['Approved' => ['#d1fae5', '#065f46', '✅ Approved'], 'Pending' => ['#fef3c7', '#92400e', '⏳ Pending'], 'Ditolak' => ['#fee2e2', '#991b1b', '❌ Ditolak']][$item->status];
                            ?>
                            <span style="padding:.25rem .75rem;border-radius:9999px;font-size:.7rem;font-weight:700;background:<?php echo e($badge[0]); ?>;color:<?php echo e($badge[1]); ?>;"><?php echo e($badge[2]); ?></span>
                            <?php if($item->jurnal): ?>
                                <div style="font-size:.68rem;color:#64748b;margin-top:.25rem;"><?php echo e($item->jurnal->no_jurnal); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>
        <div style="padding:.75rem 1rem;border-top:1px solid #e2e8f0;">
            <?php echo e($pengeluarans->links()); ?>

        </div>
        <?php else: ?>
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:.875rem;">Belum ada pengeluaran beban.</div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/pengeluaran-beban/input.blade.php ENDPATH**/ ?>