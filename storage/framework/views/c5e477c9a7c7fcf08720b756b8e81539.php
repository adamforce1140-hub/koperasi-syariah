<?php $__env->startSection('title', 'Laporan Sumber & Penggunaan Dana Zakat'); ?>
<?php $__env->startSection('page-title', 'Laporan Sumber & Penggunaan Dana Zakat, Infak & Sedekah'); ?>

<?php $__env->startSection('content'); ?>

<?php
    // DPS mengakses laporan ini lewat jalur pengawasan (read-only).
    $routeBukuBesar = auth()->user()->isDPS() ? 'dps.zakat.index' : 'zakat.index';
?>

<div class="card" style="background:#fffbeb;border:1px solid #fde68a;">
    <div style="font-size:.85rem;color:#92400e;line-height:1.6;">
        Disusun sesuai <strong>PSAK 109</strong> (komponen laporan sumber dan penggunaan dana zakat serta
        sumber dan penggunaan dana infak/sedekah pada PSAK 101). Dana ini adalah
        <strong>dana titipan (amanah)</strong> — disajikan terpisah dari laporan laba rugi koperasi.
        Penyaluran zakat dibatasi pada <strong>8 asnaf</strong> (QS At-Taubah 60).
    </div>
</div>

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="<?php echo e($dari); ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="<?php echo e($sampai); ?>" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <a href="<?php echo e(route($routeBukuBesar)); ?>" class="btn" style="background:#f1f5f9;color:#334155;">Kembali ke Buku Besar</a>
    </form>
</div>

<?php if(! $validasi['valid']): ?>
    <div class="card" style="background:#fef2f2;border:1px solid #fca5a5;">
        <div class="card-title" style="color:#991b1b;">⚠️ Catatan Validasi</div>
        <ul style="margin:.6rem 0 0 1.2rem;font-size:.85rem;color:#991b1b;line-height:1.7;">
            <?php $__currentLoopData = $validasi['catatan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $catatan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><li><?php echo e($catatan); ?></li><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </ul>
    </div>
<?php endif; ?>

<?php
    $labels = [
        'zakat'         => 'DANA ZAKAT',
        'infak_sedekah' => 'DANA INFAK & SEDEKAH',
    ];
?>

<?php $__currentLoopData = $labels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kunci => $judul): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <?php $r = $ringkasan[$kunci] ?? []; ?>
    <div class="card">
        <div class="card-header">
            <div class="card-title"><?php echo e($judul); ?></div>
            <span style="font-size:.75rem;color:#64748b;">Periode <?php echo e($dari); ?> s/d <?php echo e($sampai); ?></span>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <tbody>
                    <tr>
                        <td style="font-weight:600;">Saldo awal dana</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">
                            Rp <?php echo e(number_format($r['saldoAwal'] ?? 0, 2, ',', '.')); ?>

                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;">Penerimaan dana (sumber)</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#065f46;">
                            Rp <?php echo e(number_format($r['penerimaan'] ?? 0, 2, ',', '.')); ?>

                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;">Penyaluran dana (penggunaan)</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#991b1b;">
                            (Rp <?php echo e(number_format($r['penyaluran'] ?? 0, 2, ',', '.')); ?>)
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:700;">Saldo akhir dana</td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">
                            Rp <?php echo e(number_format($r['saldoAkhir'] ?? 0, 2, ',', '.')); ?>

                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        
        <?php if($kunci === 'zakat'): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;margin-top:1.25rem;">
                <div>
                    <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                        Penerimaan per Jenis Zakat
                    </div>
                    <table>
                        <tbody>
                            <?php $__empty_1 = true; $__currentLoopData = $perKategori; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $total): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                <tr>
                                    <td><?php echo e($kategoriLabel[$kode] ?? $kode); ?></td>
                                    <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $total, 2, ',', '.')); ?></td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                <tr><td colspan="2" style="color:#94a3b8;">Tidak ada penerimaan zakat pada periode ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                        Penyaluran per Asnaf (QS At-Taubah 60)
                    </div>
                    <table>
                        <tbody>
                            <?php $__empty_1 = true; $__currentLoopData = $perAsnaf; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $total): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                <tr>
                                    <td><?php echo e($asnaf[$kode] ?? $kode); ?></td>
                                    <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $total, 2, ',', '.')); ?></td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                <tr><td colspan="2" style="color:#94a3b8;">Tidak ada penyaluran zakat pada periode ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            
            <div style="margin-top:1.25rem;">
                <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                    Rekap Hak Amil (Fatwa DSN-MUI 08)
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Uraian</th>
                            <th style="text-align:right;">Dasar (Rp)</th>
                            <th style="text-align:right;">Hak Amil (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Porsi hak amil <?php echo e(number_format((float) $porsiAmil, 2, ',', '.')); ?>% dari penerimaan dana zakat</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $dasarHakAmil, 2, ',', '.')); ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">Rp <?php echo e(number_format((float) $hakAmil, 2, ',', '.')); ?></td>
                        </tr>
                        <tr>
                            <td>Realisasi penyaluran kepada amil (asnaf)</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">—</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $realisasiAmil, 2, ',', '.')); ?></td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Selisih (hak amil − realisasi)</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">—</td>
                            
                            <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;color:<?php echo e($hakAmilTerlampaui ? '#991b1b' : '#065f46'); ?>;">
                                Rp <?php echo e(number_format((float) ($hakAmil - $realisasiAmil), 2, ',', '.')); ?>

                                <?php if($hakAmilTerlampaui): ?>
                                    <span class="badge badge-red" title="Realisasi penyaluran kepada amil melampaui hak amil">Terlampaui</span>
                                <?php else: ?>
                                    <span class="badge badge-green">Aman</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                
                <?php if($hakAmilTerlampaui): ?>
                    <div role="alert" style="margin-top:.75rem;padding:.75rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:.5rem;font-size:.85rem;color:#991b1b;line-height:1.6;">
                        <strong>⚠️ Realisasi hak amil terlampaui.</strong>
                        Penyaluran kepada amil sebesar Rp <?php echo e(number_format((float) $realisasiAmil, 2, ',', '.')); ?>

                        melampaui hak amil Rp <?php echo e(number_format((float) $hakAmil, 2, ',', '.')); ?>

                        (kelebihan Rp <?php echo e(number_format((float) ($realisasiAmil - $hakAmil), 2, ',', '.')); ?>).
                        Fatwa DSN-MUI No. 08 menetapkan porsi amil umumnya maksimum 1/8 (12,5%) dari penerimaan dana zakat.
                    </div>
                <?php endif; ?>

                <div style="font-size:.72rem;color:#94a3b8;margin-top:.4rem;line-height:1.5;">
                    Hak amil bersifat <strong>entitlement</strong> (perhitungan informatif dari parameter porsi amil);
                    penyaluran kepada amil tetap dicatat manual sebagai penyaluran ber-asnaf <em>Amil</em>.
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

<div class="card">
    <div class="card-header">
        <div class="card-title">Rincian Mutasi Periode</div>
        <span class="badge badge-gray"><?php echo e($mutasi->count()); ?> baris</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No. Referensi</th>
                    <th>Dana</th>
                    <th>Jenis</th>
                    <th>Kategori</th>
                    <th>Pihak</th>
                    <th style="text-align:right;">Jumlah</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $mutasi; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $t): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr <?php if($t->sudah_void): ?> style="opacity:.6;" <?php endif; ?>>
                        <td><?php echo e($t->tanggal?->format('d/m/Y')); ?></td>
                        <td style="font-family:monospace;font-size:.8rem;"><?php echo e($t->no_referensi); ?></td>
                        <td><?php echo e($t->dana_label); ?></td>
                        <td><?php echo e($t->jenis_label); ?></td>
                        <td><?php echo e($t->kategori_label); ?></td>
                        <td>
                            <?php echo e($t->pihak_tampil); ?>

                            <?php if($t->tertaut_anggota): ?>
                                <span class="badge badge-blue" title="Tertaut ke master anggota">anggota</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp <?php echo e(number_format((float) $t->jumlah, 2, ',', '.')); ?></td>
                        <td>
                            <?php if($t->sudah_void): ?>
                                <span class="badge badge-red" title="<?php echo e($t->void_reason ?: 'Dibatalkan'); ?>">VOID</span>
                            <?php else: ?>
                                <span class="badge badge-green">Berlaku</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada mutasi pada periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/zakat/laporan.blade.php ENDPATH**/ ?>