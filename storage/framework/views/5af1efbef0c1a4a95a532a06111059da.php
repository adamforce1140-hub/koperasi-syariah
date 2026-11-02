<?php
    /**
     * Berkas ekspor Excel — HTML standalone (tanpa @extends) agar dapat dibuka
     * langsung oleh Excel/WPS. Format angka memakai mso-number-format supaya
     * Excel memperlakukannya sebagai bilangan, bukan teks.
     */
    $fmtAngka = fn ($nilai) => number_format((float) $nilai, 2, ',', '');

    $p    = $komponen['posisi'];
    $lr   = $komponen['labaRugi'];
    $pe   = $komponen['perubahanEkuitas'];
    $ak   = $komponen['arusKas'];
    $dit  = $komponen['dit'];
    $calk = $komponen['kepatuhan'];
    $keb  = $komponen['kebijakan'];
    $cat  = $komponen['catatan'];
    $zkt  = $komponen['zakat'];
?>
<table border="0" cellspacing="0" cellpadding="4">
    <tr>
        <td colspan="6" style="font-size:14pt;"><b>LAPORAN KEUANGAN SYARIAH</b></td>
    </tr>
    <tr>
        <td colspan="6">
            Periode <?php echo e($periode['dari']->format('d/m/Y')); ?> &ndash; <?php echo e($periode['sampai']->format('d/m/Y')); ?>

            &mdash; Standar PSAK 101
        </td>
    </tr>
    <tr><td colspan="6"></td></tr>
</table>


<table border="1" cellspacing="0" cellpadding="4">
    <tr>
        <td colspan="3" style="background:#e2e8f0;"><b>1. LAPORAN POSISI KEUANGAN</b></td>
    </tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Nama Akun</b></td>
        <td><b>Saldo</b></td>
    </tr>

    <?php $__currentLoopData = $p['asetGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr><td colspan="3" style="background:#f8fafc;"><b><?php echo e(strtoupper($grup['label'])); ?></b></td></tr>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($a->kode_akun); ?></td>
            <td><?php echo e($a->nama_akun); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($a->saldoBerjalan($periode['sampaiStr']))); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL ASET</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($p['totalAset'])); ?></b></td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>KEWAJIBAN</b></td></tr>
    <?php $__currentLoopData = $p['kewajibanGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($k->kode_akun); ?></td>
            <td><?php echo e($k->nama_akun); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($k->saldoBerjalan($periode['sampaiStr']))); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Kewajiban</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($p['totalKewajiban'])); ?></td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>DANA SYIRKAH TEMPORER</b></td></tr>
    <?php $__currentLoopData = $p['dstGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $d): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($d->kode_akun); ?></td>
            <td><?php echo e($d->nama_akun); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($d->saldoBerjalan($periode['sampaiStr']))); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Dana Syirkah Temporer</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($p['totalDanaSyirkahTemporer'])); ?></td>
    </tr>

    <tr><td colspan="3"></td></tr>
    <tr><td colspan="3" style="background:#f8fafc;"><b>EKUITAS</b></td></tr>
    <?php $__currentLoopData = $p['ekuitasGroups']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $grup): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php $__currentLoopData = $grup['akun']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($e->kode_akun); ?></td>
            <td><?php echo e($e->nama_akun); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($e->saldoBerjalanLaporan($periode['sampaiStr']))); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td>&mdash;</td>
        <td>Laba (Rugi) Berjalan</td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($p['labaBersih'])); ?></td>
    </tr>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Total Ekuitas</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($p['totalEkuitas'])); ?></td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL PASIVA</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($p['totalPasiva'])); ?></b></td>
    </tr>
    <tr>
        <td colspan="2">
            Selisih (Aset &minus; Pasiva) &mdash;
            <?php echo e($p['seimbang'] ? 'SEIMBANG' : 'TIDAK SEIMBANG'); ?>

        </td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($p['selisihNeraca'])); ?></td>
    </tr>
</table>

<br>


<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="3" style="background:#e2e8f0;"><b>2. LAPORAN LABA RUGI</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Uraian</b></td>
        <td><b>Jumlah</b></td>
    </tr>

    <tr><td colspan="3" style="background:#f8fafc;"><b>PENDAPATAN USAHA</b></td></tr>
    <?php $__currentLoopData = $lr['pendapatanUsaha']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td><?php echo e($r['kode']); ?></td>
        <td><?php echo e($r['nama']); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Jumlah Pendapatan Usaha</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($lr['totalPendapatanUsaha'])); ?></td>
    </tr>

    <?php if($lr['pendapatanLain']->isNotEmpty()): ?>
    <tr><td colspan="3" style="background:#f8fafc;"><b>PENDAPATAN LAIN-LAIN</b></td></tr>
        <?php $__currentLoopData = $lr['pendapatanLain']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($r['kode']); ?></td>
            <td><?php echo e($r['nama']); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endif; ?>

    <?php if($lr['pendapatanTakKlasifikasi']->isNotEmpty()): ?>
    <tr><td colspan="3" style="background:#fef3c7;"><b>PENDAPATAN &mdash; BELUM DIKLASIFIKASI</b></td></tr>
        <?php $__currentLoopData = $lr['pendapatanTakKlasifikasi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($r['kode']); ?></td>
            <td><?php echo e($r['nama']); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endif; ?>

    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL PENDAPATAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($lr['totalPendapatan'])); ?></b></td>
    </tr>

    <tr><td colspan="3" style="background:#f8fafc;"><b>BEBAN USAHA</b></td></tr>
    <?php $__currentLoopData = $lr['bebanUsaha']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td><?php echo e($r['kode']); ?></td>
        <td><?php echo e($r['nama']); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Jumlah Beban Usaha</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($lr['totalBebanUsaha'])); ?></td>
    </tr>

    <?php if($lr['bebanLain']->isNotEmpty()): ?>
    <tr><td colspan="3" style="background:#f8fafc;"><b>BEBAN LAIN-LAIN</b></td></tr>
        <?php $__currentLoopData = $lr['bebanLain']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($r['kode']); ?></td>
            <td><?php echo e($r['nama']); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endif; ?>

    <?php if($lr['bebanTakKlasifikasi']->isNotEmpty()): ?>
    <tr><td colspan="3" style="background:#fef3c7;"><b>BEBAN &mdash; BELUM DIKLASIFIKASI</b></td></tr>
        <?php $__currentLoopData = $lr['bebanTakKlasifikasi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($r['kode']); ?></td>
            <td><?php echo e($r['nama']); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php endif; ?>

    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL BEBAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($lr['totalBeban'])); ?></b></td>
    </tr>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Laba Kotor</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($lr['labaKotor'])); ?></td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>LABA (RUGI) BERSIH &mdash; <?php echo e(strtoupper($lr['statusLaba'])); ?></b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($lr['labaBersih'])); ?></b></td>
    </tr>
</table>

<br>


<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>3. LAPORAN PERUBAHAN EKUITAS</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Kode</b></td>
        <td><b>Komponen Ekuitas</b></td>
        <td><b>Saldo Awal</b></td>
        <td><b>Penambah</b></td>
        <td><b>Pengurang</b></td>
        <td><b>Saldo Akhir</b></td>
    </tr>
    <?php $__currentLoopData = $pe['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td><?php echo e($r['kode']); ?></td>
        <td><?php echo e($r['nama']); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldoAwal'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['penambah'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['pengurang'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldoAkhir'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="2"><b>Subtotal</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['totalAwal'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['totalPenambah'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['totalPengurang'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['totalAkhir'])); ?></td>
    </tr>
    <tr>
        <td colspan="2">Laba (Rugi) Bersih Periode Berjalan</td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['labaBersih'])); ?></td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['labaBersih'])); ?></td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL EKUITAS (termasuk laba berjalan)</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($pe['totalAwalTermasukLaba'])); ?></td>
        <td></td>
        <td></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($pe['totalAkhirTermasukLaba'])); ?></b></td>
    </tr>
</table>

<br>


<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="4" style="background:#e2e8f0;"><b>4. LAPORAN ARUS KAS (METODE LANGSUNG)</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Uraian</b></td>
        <td><b>Masuk</b></td>
        <td><b>Keluar</b></td>
        <td><b>Neto</b></td>
    </tr>
    <?php $__currentLoopData = ['operasi' => $ak['operasi'], 'investasi' => $ak['investasi'], 'pendanaan' => $ak['pendanaan'], 'lainnya' => $ak['lainnya']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kunci => $bag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <?php if($bag['baris']->isEmpty() && $kunci === 'lainnya'): ?>
            <?php continue; ?>
        <?php endif; ?>
        <tr><td colspan="4" style="background:#f8fafc;"><b><?php echo e(strtoupper($bag['label'])); ?></b></td></tr>
        <?php $__currentLoopData = $bag['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <tr>
            <td><?php echo e($b['label']); ?> (<?php echo e($b['akun']); ?>)</td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($b['masuk'] > 0 ? $fmtAngka($b['masuk']) : ''); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($b['keluar'] > 0 ? $fmtAngka($b['keluar']) : ''); ?></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($b['neto'])); ?></td>
        </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        <tr style="background:#f8fafc;">
            <td colspan="3"><b>Jumlah <?php echo e($bag['label']); ?></b></td>
            <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($bag['total'])); ?></td>
        </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#f8fafc;">
        <td colspan="3"><b>Kenaikan (Penurunan) Kas Bersih</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($ak['kenaikanKas'])); ?></td>
    </tr>
    <tr>
        <td colspan="3">Saldo Kas &amp; Bank Awal Periode</td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($ak['saldoAwalKas'])); ?></td>
    </tr>
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>SALDO KAS &amp; BANK AKHIR PERIODE</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($ak['saldoAkhirKas'])); ?></b></td>
    </tr>
    <tr>
        <td colspan="3">
            Verifikasi rekonsiliasi &mdash; <?php echo e($ak['rekonsiliasiCocok'] ? 'COCOK' : 'SELISIH'); ?>

        </td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($ak['selisihRekonsiliasi'])); ?></td>
    </tr>
</table>

<br>


<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>5. LAPORAN PERUBAHAN DANA INVESTASI TERIKAT (PSAK 109)</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Jenis Dana</b></td>
        <td><b>Kode</b></td>
        <td><b>Saldo Awal</b></td>
        <td><b>Penerimaan</b></td>
        <td><b>Penyaluran</b></td>
        <td><b>Saldo Akhir</b></td>
    </tr>
    <?php $__currentLoopData = $dit['baris']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td><?php echo e($r['label']); ?><?php echo e($r['akunTersedia'] ? '' : ' (akun tidak ditemukan)'); ?></td>
        <td><?php echo e($r['kode']); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldoAwal'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['penerimaan'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['penyaluran'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldoAkhir'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#dbeafe;">
        <td colspan="2"><b>TOTAL</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($dit['totalAwal'])); ?></b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($dit['totalPenerimaan'])); ?></b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($dit['totalPenyaluran'])); ?></b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($dit['totalAkhir'])); ?></b></td>
    </tr>
    <tr>
        <td colspan="6">
            Mutasi <?php echo e($dit['mutasiSeimbang'] ? 'SEIMBANG' : 'TIDAK SEIMBANG'); ?> &mdash;
            dana titipan (amanah), bukan pendapatan koperasi dan bukan bagian ekuitas.
        </td>
    </tr>
</table>

<br>


<table border="1" cellspacing="0" cellpadding="4">
    <tr><td colspan="6" style="background:#e2e8f0;"><b>6. CATATAN ATAS LAPORAN KEUANGAN (CaLK)</b></td></tr>
    <tr><td colspan="6"><b>6.a Kebijakan Akuntansi &amp; Asumsi</b></td></tr>
    <tr><td colspan="2">Dasar penyusunan</td><td colspan="4"><?php echo e($keb['dasarPenyusunan']); ?></td></tr>
    <tr><td colspan="2">Standar acuan</td><td colspan="4"><?php echo e($keb['standar']); ?></td></tr>
    <tr><td colspan="2">Mata uang</td><td colspan="4"><?php echo e($keb['mataUang']); ?></td></tr>
    <tr><td colspan="2">Pembulatan</td><td colspan="4"><?php echo e($keb['pembulatan']); ?></td></tr>
    <tr><td colspan="2">Sumber kebenaran nilai</td><td colspan="4"><?php echo e($keb['sumberKebenaran']); ?></td></tr>
    <tr><td colspan="2">Kolom kanonik</td><td colspan="4"><?php echo e($keb['kolomKanonik']); ?></td></tr>
    <tr><td colspan="2">Dana titipan (PSAK 109)</td><td colspan="4"><?php echo e($keb['danaTitipan']); ?></td></tr>
    <tr><td colspan="2">Dana Syirkah Temporer</td><td colspan="4"><?php echo e($keb['danaSyirkahTemporer']); ?></td></tr>
    <tr><td colspan="2">Batas penyaluran zakat</td><td colspan="4"><?php echo e($keb['batasZakat']); ?></td></tr>
    <tr>
        <td colspan="2">Toleransi selisih jurnal</td>
        <td colspan="4" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($keb['toleransiSelisih'])); ?></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.b Rincian Piutang Pembiayaan per Akad</b></td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Akad</b></td><td><b>Kode</b></td><td><b>Nama Akun</b></td>
        <td><b>Saldo</b></td><td><b>Mutasi Debet</b></td><td><b>Mutasi Kredit</b></td>
    </tr>
    <?php $__currentLoopData = $cat['piutang']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td><?php echo e($r['akad']); ?></td>
        <td><?php echo e($r['kode']); ?></td>
        <td><?php echo e($r['nama']); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['mutasi']['debet'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['mutasi']['kredit'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>TOTAL PIUTANG PEMBIAYAAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($cat['totalPiutang'])); ?></b></td>
        <td colspan="2"></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.c CKPN &amp; Persentase per Kolektibilitas</b></td></tr>
    <tr>
        <td colspan="2"><?php echo e($cat['ckpn']['kode']); ?> <?php echo e($cat['ckpn']['nama']); ?></td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($cat['ckpn']['saldo'])); ?></td>
    </tr>
    <?php $__currentLoopData = $cat['ckpn']['persen']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $kode => $persen): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td colspan="2"><?php echo e(strtoupper($kode)); ?></td>
        <td colspan="4" align="right" style="mso-number-format:'0.00';"><?php echo e(number_format((float) $persen, 2, ',', '')); ?>%</td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.d Margin Murabahah Ditangguhkan</b></td></tr>
    <tr>
        <td colspan="2"><?php echo e($cat['marginDitangguhkan']['kode']); ?> <?php echo e($cat['marginDitangguhkan']['nama']); ?></td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($cat['marginDitangguhkan']['saldo'])); ?></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.e Rincian Kas &amp; Bank</b></td></tr>
    <?php $__currentLoopData = $cat['kas']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td colspan="2"><?php echo e($r['kode']); ?> <?php echo e($r['nama']); ?></td>
        <td colspan="2">Saldo</td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['saldo'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.f Uji Kepatuhan Penyajian</b></td></tr>
    <tr>
        <td colspan="3">Kelompok penyajian terisi / total kelompok</td>
        <td colspan="3"><?php echo e(collect($calk['kelompokDisajikan'])->filter()->count()); ?> / <?php echo e(count($calk['kelompokDisajikan'])); ?></td>
    </tr>
    <tr>
        <td colspan="3">Jumlah jurnal periode</td>
        <td colspan="3"><?php echo e((int) $calk['mutasi']['jumlah_jurnal']); ?></td>
    </tr>
    <tr>
        <td colspan="3">Keseimbangan debet &minus; kredit</td>
        <td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($calk['mutasi']['selisih'])); ?></td>
    </tr>
    <tr>
        <td colspan="3">Status kepatuhan</td>
        <td colspan="3">
            <?php echo e($calk['patuh'] ? 'PATUH' : count($calk['temuan']) . ' TEMUAN'); ?>

        </td>
    </tr>
    <?php $__currentLoopData = $calk['temuan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $temuan): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr><td colspan="6">Temuan: <?php echo e($temuan); ?></td></tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6" style="background:#e2e8f0;"><b>6.g Laporan Sumber &amp; Penggunaan Dana Zakat (PSAK 109)</b></td></tr>
    <tr><td colspan="6">Dana zakat adalah dana titipan (amanah), bukan pendapatan koperasi; disajikan terpisah dari Laba Rugi dan tidak memengaruhi SHU.</td></tr>
    <tr style="background:#f1f5f9;">
        <td><b>Uraian</b></td><td><b>Saldo Awal</b></td><td><b>Penerimaan</b></td>
        <td><b>Penyaluran</b></td><td><b>Saldo Akhir</b></td><td></td>
    </tr>
    <tr>
        <td>Dana Zakat (<?php echo e($zkt['akun']); ?>)</td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['saldoAwal'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['penerimaan'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['penyaluran'])); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['saldoAkhir'])); ?></td>
        <td></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.1 Sumber Dana &mdash; per Jenis Zakat</b></td></tr>
    <tr style="background:#f1f5f9;"><td colspan="4"><b>Jenis Zakat</b></td><td colspan="2"><b>Jumlah</b></td></tr>
    <?php $__currentLoopData = $zkt['rincianPenerimaan']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td colspan="4"><?php echo e($r['label']); ?><?php echo e($r['kode'] === '__lainnya' ? ' (di luar daftar baku)' : ''); ?></td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['jumlah'])); ?></td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#dbeafe;">
        <td colspan="4"><b>TOTAL PENERIMAAN</b></td>
        <td colspan="2" align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($zkt['totalRincianPenerimaan'])); ?></b></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.2 Penggunaan Dana &mdash; per Asnaf</b></td></tr>
    <tr style="background:#f1f5f9;"><td colspan="3"><b>Asnaf</b></td><td><b>Jumlah</b></td><td colspan="2"><b>Persentase</b></td></tr>
    <?php $__currentLoopData = $zkt['rincianPenyaluran']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <tr>
        <td colspan="3"><?php echo e($r['label']); ?><?php echo e($r['kode'] === '__lainnya' ? ' (di luar 8 asnaf)' : ''); ?></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($r['jumlah'])); ?></td>
        <td colspan="2" align="right" style="mso-number-format:'0.00';"><?php echo e(number_format((float) $r['persen'], 2, ',', '')); ?>%</td>
    </tr>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <tr style="background:#dbeafe;">
        <td colspan="3"><b>TOTAL PENYALURAN</b></td>
        <td align="right" style="mso-number-format:'#,##0.00';"><b><?php echo e($fmtAngka($zkt['totalRincianPenyaluran'])); ?></b></td>
        <td colspan="2" align="right"><b><?php echo e($zkt['totalRincianPenyaluran'] > 0 ? '100.00%' : '-'); ?></b></td>
    </tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.3 Rekonsiliasi Hak Amil (<?php echo e(number_format((float) $zkt['amil']['persen'], 2, ',', '')); ?>%)</b></td></tr>
    <tr><td colspan="3">Dasar hak (penerimaan)</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['amil']['dasar'])); ?></td></tr>
    <tr><td colspan="3">Hak amil</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['amil']['hak'])); ?></td></tr>
    <tr><td colspan="3">Realisasi (asnaf amil)</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['amil']['realisasi'])); ?></td></tr>
    <tr><td colspan="3">Sisa hak belum direalisasikan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['amil']['sisa'])); ?></td></tr>

    <tr><td colspan="6"></td></tr>
    <tr><td colspan="6"><b>6.g.4 Rekonsiliasi Jurnal vs Buku Besar Modul</b></td></tr>
    <tr><td colspan="3">Selisih penerimaan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['kontrol']['selisihPenerimaan'])); ?></td></tr>
    <tr><td colspan="3">Selisih dana tersalurkan</td><td colspan="3" align="right" style="mso-number-format:'#,##0.00';"><?php echo e($fmtAngka($zkt['kontrol']['selisihPenyaluran'])); ?></td></tr>
    <tr><td colspan="3">Baris tanpa jurnal</td><td colspan="3"><?php echo e((int) $zkt['kontrol']['tanpaJurnal']); ?></td></tr>
    <tr><td colspan="3">Penyaluran di luar 8 asnaf</td><td colspan="3"><?php echo e((int) $zkt['kontrol']['asnafDiLuar']); ?></td></tr>
    <tr>
        <td colspan="3">Status rekonsiliasi</td>
        <td colspan="3">
            <?php echo e($zkt['kontrol']['selaras'] ? 'SELARAS' : 'SELISIH'); ?>

            &mdash;
            <?php echo e($zkt['mutasiSeimbang'] ? 'MUTASI SEIMBANG' : 'MUTASI TIDAK SEIMBANG'); ?>

        </td>
    </tr>
    <?php if(! $zkt['akunTersedia']): ?>
    <tr><td colspan="6">TEMUAN: Akun DIT Dana Zakat <?php echo e($zkt['akun']); ?> belum ada pada CoA.</td></tr>
    <?php endif; ?>
</table>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\laporan\psak101-excel.blade.php ENDPATH**/ ?>