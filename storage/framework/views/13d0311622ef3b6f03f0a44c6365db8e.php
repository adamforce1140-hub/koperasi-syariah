
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
<table border="1" cellspacing="0" cellpadding="4">
    <thead>
        <tr>
            <th colspan="3" style="font-size:14pt;text-align:left;">
                LAPORAN NERACA (BALANCE SHEET)
            </th>
        </tr>
        <tr>
            <th colspan="3" style="text-align:left;">
                Per <?php echo e($tanggalFilter->translatedFormat('d F Y')); ?> &mdash; Standar PSAK 101
            </th>
        </tr>
        <tr>
            <th colspan="3" style="text-align:left;">
                <?php echo e($mode === 'akhir_bulan' ? 'Mode: Per Akhir Bulan' : 'Mode: Per Tanggal Spesifik'); ?>

            </th>
        </tr>
        <tr><td colspan="3"></td></tr>
    </thead>
    <tbody>
        
        <tr><td colspan="3" style="font-weight:bold;background:#dbeafe;">ASET</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        <?php $__currentLoopData = $asetGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#eff6ff;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($a->kode_akun); ?></td>
            <td><?php echo e($a->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $a->saldoBerjalan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php if(count($asetGroups) > 1): ?>
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal <?php echo e($grup['label']); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $grup['subtotal']); ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="font-weight:bold;background:#dbeafe;">
            <td colspan="2">Total Aset</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalAset); ?></td>
        </tr>
        <tr><td colspan="3"></td></tr>

        
        <tr><td colspan="3" style="font-weight:bold;background:#fef3c7;">KEWAJIBAN</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        <?php $__currentLoopData = $kewajibanGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#fffbeb;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($k->kode_akun); ?></td>
            <td><?php echo e($k->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $k->saldoBerjalan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php if(count($kewajibanGroups) > 1): ?>
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal <?php echo e($grup['label']); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $grup['subtotal']); ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="font-weight:bold;background:#fef3c7;">
            <td colspan="2">Total Kewajiban</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalKewajiban); ?></td>
        </tr>
        <tr><td colspan="3"></td></tr>

        
        <tr><td colspan="3" style="font-weight:bold;background:#ccfbf1;">DANA SYIRKAH TEMPORER</td></tr>
        <?php $__currentLoopData = $dstGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#f0fdfa;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($d->kode_akun); ?></td>
            <td><?php echo e($d->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $d->saldoBerjalan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="font-weight:bold;background:#ccfbf1;">
            <td colspan="2">Total Dana Syirkah Temporer</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalDanaSyirkahTemporer); ?></td>
        </tr>
        <tr><td colspan="3"></td></tr>

        
        <tr><td colspan="3" style="font-weight:bold;background:#e0e7ff;">EKUITAS / MODAL SENDIRI</td></tr>
        <tr style="font-weight:bold;background:#f1f5f9;">
            <td>Kode</td><td>Nama Akun</td><td>Saldo</td>
        </tr>
        <?php $__currentLoopData = $ekuitasGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#eef2ff;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($e->kode_akun); ?></td>
            <td><?php echo e($e->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $e->saldoBerjalanLaporan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php if(count($ekuitasGroups) > 1): ?>
        <tr style="font-weight:bold;">
            <td colspan="2">Subtotal <?php echo e($grup['label']); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $grup['subtotal']); ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        
        <tr style="background:#f8fafc;">
            <td>&mdash;</td>
            <td>Laba/Rugi Berjalan
                (Pendapatan <?php echo e(number_format($totalPendapatan, 0, ',', '.')); ?>

                &minus; Beban <?php echo e(number_format($totalBeban, 0, ',', '.')); ?>)
                &mdash; <?php echo e(ucfirst($statusLaba)); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $labaBersih); ?></td>
        </tr>
        <tr style="font-weight:bold;background:#e0e7ff;">
            <td colspan="2">Total Ekuitas</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalEkuitas); ?></td>
        </tr>
        <tr style="font-weight:bold;background:#eff6ff;">
            <td colspan="2">TOTAL PASIVA (Kewajiban + DST + Ekuitas + Laba/Rugi Berjalan)</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalPasiva); ?></td>
        </tr>
        <tr style="font-weight:bold;">
            <td colspan="2">Total Aset (Aktiva)</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalAset); ?></td>
        </tr>
        <tr style="font-weight:bold;background:<?php echo e(abs($selisihNeraca) > 0.01 ? '#fee2e2' : '#dcfce7'); ?>;">
            <td colspan="2">Selisih (Aset &minus; Pasiva)</td>
            <td style="mso-number-format:'#,##0.00';"><?php echo e((float) $selisihNeraca); ?></td>
        </tr>
        <tr><td colspan="3"></td></tr>

        
        <tr><td colspan="3" style="font-weight:bold;background:#d1fae5;">RINCIAN PENDAPATAN</td></tr>
        <?php $__currentLoopData = $pendapatanGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#ecfdf5;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($p->kode_akun); ?></td>
            <td><?php echo e($p->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $p->saldoBerjalanLaporan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="font-weight:bold;">
            <td colspan="2">Total Pendapatan</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalPendapatan); ?></td>
        </tr>
        <tr><td colspan="3"></td></tr>

        
        <tr><td colspan="3" style="font-weight:bold;background:#fee2e2;">RINCIAN BEBAN</td></tr>
        <?php $__currentLoopData = $bebanGroups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr><td colspan="3" style="font-weight:bold;background:#fef2f2;"><?php echo e($grup['label']); ?></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($b->kode_akun); ?></td>
            <td><?php echo e($b->nama_akun); ?></td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $b->saldoBerjalanLaporan($sampai)); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="font-weight:bold;">
            <td colspan="2">Total Beban</td>
            <td style="mso-number-format:'#,##0';"><?php echo e((float) $totalBeban); ?></td>
        </tr>
    </tbody>
</table>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\laporan\neraca-excel.blade.php ENDPATH**/ ?>