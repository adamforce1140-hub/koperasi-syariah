<?php $__env->startSection('title', 'Jurnal Umum (Read-Only) — DPS'); ?>
<?php $__env->startSection('page-title', 'Jurnal Umum & Verifikasi Dana Kebajikan'); ?>

<?php $__env->startSection('content'); ?>

<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:0.875rem 1.25rem;margin-bottom:1rem;font-size:0.875rem;color:#92400e;">
    <strong>Landasan:</strong> Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — Potongan Ta'zir (denda keterlambatan) wajib dibukukan ke akun <strong>Dana Kebajikan / Social Fund (2-3001)</strong>, BUKAN pendapatan koperasi. Gunakan halaman ini untuk memverifikasi kepatuhan pembukuan.
</div>
<div style="background:#e0f2fe;border:1px solid #bae6fd;border-radius:0.5rem;padding:0.75rem 1.25rem;margin-bottom:1.5rem;font-size:0.8rem;color:#075985;">
    Mode <strong>Read-Only</strong> — Halaman ini untuk audit kepatuhan. Perubahan master akun dilakukan oleh Pengurus melalui modul Bagan Akun (CoA).
</div>


<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#92400e;text-transform:uppercase;margin-bottom:0.4rem;">Saldo Dana Kebajikan (2-3001)</div>
        <div data-field="saldo-dana-kebajikan" style="font-size:1.5rem;font-weight:700;color:#78350f;">Rp <?php echo e(number_format($saldoDanaKebajikan, 2, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#92400e;margin-top:0.25rem;">Akun Kewajiban — saldo akhir running balance</div>
    </div>
    <div style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#166534;text-transform:uppercase;margin-bottom:0.4rem;">Total Masuk (Kredit)</div>
        <div style="font-size:1.5rem;font-weight:700;color:#14532d;">Rp <?php echo e(number_format($totalMutasiKredit, 2, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#166534;margin-top:0.25rem;">Potongan Ta'zir & setoran dana sosial</div>
    </div>
    <div style="background:linear-gradient(135deg,#fee2e2,#fecaca);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#991b1b;text-transform:uppercase;margin-bottom:0.4rem;">Total Penyaluran (Debet)</div>
        <div style="font-size:1.5rem;font-weight:700;color:#7f1d1d;">Rp <?php echo e(number_format($totalMutasiDebet, 2, ',', '.')); ?></div>
        <div style="font-size:0.75rem;color:#991b1b;margin-top:0.25rem;">Penyaluran dana kebajikan</div>
    </div>
    <div style="background:linear-gradient(135deg,#e0e7ff,#c7d2fe);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#3730a3;text-transform:uppercase;margin-bottom:0.4rem;">Jumlah Mutasi</div>
        <div style="font-size:1.5rem;font-weight:700;color:#312e81;"><?php echo e($jumlahMutasi); ?></div>
        <div style="font-size:0.75rem;color:#3730a3;margin-top:0.25rem;">Baris jurnal pada akun 2-3001</div>
    </div>
</div>


<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.06);margin-bottom:1.5rem;overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
        <div>
            <h3 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">Mutasi Dana Kebajikan — Akun 2-3001</h3>
            <p style="font-size:0.75rem;color:#64748b;margin:0.25rem 0 0;">Verifikasi setiap potongan Ta'zir tercatat sebagai KREDIT pada akun sosial.</p>
        </div>
        <span style="font-size:0.7rem;font-weight:600;background:#fef3c7;color:#92400e;padding:0.25rem 0.6rem;border-radius:9999px;">Read-Only</span>
    </div>
    <?php if($mutasiDanaKebajikan->isNotEmpty()): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">No. Jurnal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Tanggal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Sumber</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Keterangan</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Debet</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Kredit</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Saldo</th>
                </tr>
            </thead>
            <tbody>
                
                <?php $__currentLoopData = $mutasiDanaKebajikan; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mutasi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $isTazir = ($mutasi->jurnal?->sumber ?? '') === "Ta'zir";
                    ?>
                    <tr style="border-top:1px solid #f1f5f9;<?php echo e($isTazir ? 'background:#fffbeb;' : ''); ?>">
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;font-weight:600;color:#334155;"><?php echo e($mutasi->jurnal?->no_jurnal ?? '-'); ?></td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;color:#64748b;"><?php echo e(optional($mutasi->jurnal?->tanggal_jurnal)->format('d M Y') ?? '-'); ?></td>
                        <td style="padding:0.55rem 1rem;">
                            <?php if($isTazir): ?>
                                <span style="font-size:0.68rem;font-weight:600;background:#fde68a;color:#92400e;padding:0.2rem 0.55rem;border-radius:9999px;">Ta'zir</span>
                            <?php else: ?>
                                <span style="font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#475569;padding:0.2rem 0.55rem;border-radius:9999px;"><?php echo e($mutasi->jurnal?->sumber ?? '-'); ?></span>
                            <?php endif; ?>
                        </td>
                        
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;color:#334155;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($mutasi->keterangan_tampil); ?>"><?php echo e($mutasi->keterangan_tampil ?: '—'); ?></td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;color:#b91c1c;"><?php echo e($mutasi->debet_valid > 0 ? 'Rp ' . number_format($mutasi->debet_valid, 2, ',', '.') : '—'); ?></td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;color:#15803d;font-weight:600;"><?php echo e($mutasi->kredit_valid > 0 ? 'Rp ' . number_format($mutasi->kredit_valid, 2, ',', '.') : '—'); ?></td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;font-weight:700;color:#1e293b;<?php echo e($loop->last ? 'background:#f0fdf4;' : ''); ?>" title="<?php echo e($loop->last ? 'Saldo akhir — sama dengan kartu Saldo Dana Kebajikan' : 'Saldo berjalan'); ?>">Rp <?php echo e(number_format($mutasi->saldo_berjalan, 2, ',', '.')); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div style="font-weight:600;">Belum ada mutasi pada Dana Kebajikan (2-3001)</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">Mutasi akan muncul otomatis saat terdapat pemotongan Ta'zir atau penyaluran dana sosial.</div>
    </div>
    <?php endif; ?>
</div>


<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.06);overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
        <h3 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">Laporan Jurnal Umum (Status: Posted)</h3>
        <p style="font-size:0.75rem;color:#64748b;margin:0.25rem 0 0;">Seluruh jurnal berpasangan Debet & Kredit hasil auto-posting transaksi operasional.</p>
    </div>

    
    <form method="GET" action="<?php echo e(route('dps.jurnal-umum')); ?>" style="padding:1rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">No. Anggota / CIF</label>
            <input type="text" name="no_anggota" value="<?php echo e(request('no_anggota')); ?>" placeholder="ANG-2026-0001 atau nama…"
                   style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;min-width:200px;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Sumber Jurnal</label>
            <select name="sumber" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;background:#ffffff;min-width:170px;">
                <option value="">— Semua Sumber —</option>
                <?php $__currentLoopData = $sumberList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sumber): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <option value="<?php echo e($sumber); ?>" <?php echo e(request('sumber') === $sumber ? 'selected' : ''); ?>><?php echo e($sumber); ?></option>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="<?php echo e(request('dari')); ?>" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="<?php echo e(request('sampai')); ?>" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;">
        </div>
        <button type="submit" style="padding:0.5rem 1.1rem;background:#0f766e;color:#ffffff;border:none;border-radius:0.375rem;font-size:0.8rem;font-weight:600;cursor:pointer;">Terapkan Filter</button>
        <a href="<?php echo e(route('dps.jurnal-umum')); ?>" style="padding:0.5rem 1.1rem;background:#e2e8f0;color:#475569;border-radius:0.375rem;font-size:0.8rem;font-weight:600;text-decoration:none;">Reset</a>
    </form>

    <?php if($jurnals->isNotEmpty()): ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">No. Jurnal / Tanggal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Sumber</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Keterangan</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Total Debet</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Total Kredit</th>
                    <th style="padding:0.6rem 1rem;text-align:center;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $jurnals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $jurnal): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $isTazir = ($jurnal->sumber ?? '') === "Ta'zir"; ?>
                    <tr style="border-top:1px solid #f1f5f9;<?php echo e($isTazir ? 'background:#fffbeb;' : ''); ?>">
                        <td style="padding:0.65rem 1rem;">
                            <div style="font-size:0.8rem;font-weight:700;color:#1e293b;"><?php echo e($jurnal->no_jurnal); ?></div>
                            <div style="font-size:0.7rem;color:#64748b;"><?php echo e(optional($jurnal->tanggal_jurnal)->format('d M Y')); ?></div>
                        </td>
                        <td style="padding:0.65rem 1rem;">
                            <?php if($isTazir): ?>
                                <span style="font-size:0.68rem;font-weight:600;background:#fde68a;color:#92400e;padding:0.2rem 0.55rem;border-radius:9999px;">Ta'zir</span>
                            <?php else: ?>
                                <span style="font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#475569;padding:0.2rem 0.55rem;border-radius:9999px;"><?php echo e($jurnal->sumber ?? '-'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;color:#334155;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($jurnal->keterangan); ?>"><?php echo e($jurnal->keterangan ?? '—'); ?></td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;text-align:right;font-weight:600;color:#1e293b;">Rp <?php echo e(number_format($jurnal->total_debet, 2, ',', '.')); ?></td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;text-align:right;font-weight:600;color:#1e293b;">Rp <?php echo e(number_format($jurnal->total_kredit, 2, ',', '.')); ?></td>
                        <td style="padding:0.65rem 1rem;text-align:center;">
                            <span style="font-size:0.68rem;font-weight:600;<?php echo e($jurnal->is_balance ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;'); ?>padding:0.2rem 0.55rem;border-radius:9999px;">
                                <?php echo e($jurnal->is_balance ? 'Balanced' : 'Unbalanced'); ?>

                            </span>
                        </td>
                    </tr>
                    
                    <tr style="border-top:1px dashed #e2e8f0;<?php echo e($isTazir ? 'background:#fffbeb;' : ''); ?>">
                        <td colspan="6" style="padding:0 1rem 0.75rem;">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.6rem 0.9rem;margin-top:0.35rem;">
                                <?php $__empty_1 = true; $__currentLoopData = $jurnal->detail; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $detail): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.3rem 0;font-size:0.76rem;border-bottom:1px dotted #e2e8f0;<?php echo e($loop->last ? 'border-bottom:none;' : ''); ?>">
                                        <div style="display:flex;align-items:center;gap:0.6rem;">
                                            <?php if($detail->debet > 0): ?>
                                                <span style="font-size:0.62rem;font-weight:700;background:#fee2e2;color:#991b1b;padding:0.15rem 0.5rem;border-radius:0.25rem;min-width:46px;text-align:center;">DEBET</span>
                                            <?php else: ?>
                                                <span style="font-size:0.62rem;font-weight:700;background:#dcfce7;color:#166534;padding:0.15rem 0.5rem;border-radius:0.25rem;min-width:46px;text-align:center;">KREDIT</span>
                                            <?php endif; ?>
                                            <span style="font-weight:700;color:#475569;"><?php echo e($detail->coa?->kode_akun ?? '?'); ?></span>
                                            <span style="color:#334155;"><?php echo e($detail->coa?->nama_akun ?? '(Akun tidak ditemukan)'); ?></span>
                                        </div>
                                        <div style="font-weight:600;color:#1e293b;white-space:nowrap;">
                                            Rp <?php echo e(number_format($detail->debet > 0 ? $detail->debet : $detail->kredit, 2, ',', '.')); ?>

                                        </div>
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                    <div style="font-size:0.75rem;color:#94a3b8;padding:0.25rem 0;">Tidak ada detail baris.</div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>

    <?php if($jurnals->hasPages()): ?>
    <div style="padding:1rem 1.25rem;border-top:1px solid #e2e8f0;">
        <?php echo e($jurnals->links()); ?>

    </div>
    <?php endif; ?>

    <?php else: ?>
    <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
        <div style="font-weight:600;">Belum ada jurnal terposting</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">Jurnal akan dibuat otomatis oleh sistem setiap ada transaksi Setoran, Penarikan, Ta'zir, dan Pencairan Pembiayaan.</div>
    </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dps/jurnal-umum.blade.php ENDPATH**/ ?>