<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip Transaksi <?php echo e($transaksi->no_transaksi); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background: #f1f5f9; display: flex;
            justify-content: center; padding: 2rem; }
        .slip { background: white; width: 380px; padding: 1.5rem; border-radius: 0.5rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .slip-header { text-align: center; border-bottom: 2px dashed #e2e8f0; padding-bottom: 1rem; margin-bottom: 1rem; }
        .kop-name { font-size: 1.1rem; font-weight: 700; letter-spacing: 0.05em; color: #065f46; }
        .kop-sub { font-size: 0.72rem; color: #64748b; margin-top: 0.2rem; }
        .slip-title { font-size: 0.8rem; font-weight: 700; letter-spacing: 0.1em;
            text-transform: uppercase; margin-top: 0.75rem; color: #1e293b; }
        .row { display: flex; justify-content: space-between; margin-bottom: 0.4rem;
            font-size: 0.8rem; }
        .row .label { color: #64748b; }
        .row .value { font-weight: 600; text-align: right; max-width: 55%; }
        .divider { border: none; border-top: 1px dashed #e2e8f0; margin: 0.75rem 0; }
        .nominal-box { background: #f0fdf4; border: 2px solid #059669; border-radius: 0.5rem;
            padding: 0.75rem 1rem; text-align: center; margin: 1rem 0; }
        .nominal-label { font-size: 0.7rem; color: #166534; font-weight: 600; text-transform: uppercase; }
        .nominal-value { font-size: 1.4rem; font-weight: 800; color: #065f46; letter-spacing: 0.02em; }
        .akad-box { background: #fefce8; border: 1px solid #fde047; border-radius: 0.375rem;
            padding: 0.5rem 0.75rem; font-size: 0.75rem; color: #713f12; margin-bottom: 0.75rem; }
        .akad-box strong { display: block; font-size: 0.72rem; text-transform: uppercase;
            color: #a16207; margin-bottom: 0.2rem; }
        .split-table { width: 100%; font-size: 0.78rem; border-collapse: collapse; margin-top: 0.5rem; }
        .split-table td { padding: 0.25rem 0; }
        .split-table td:last-child { text-align: right; font-weight: 600; }
        .split-total td { border-top: 1px solid #e2e8f0; padding-top: 0.35rem; font-weight: 700; }
        .status-badge { display: inline-block; padding: 0.2rem 0.7rem; border-radius: 9999px;
            font-size: 0.72rem; font-weight: 700; }
        .status-sukses { background: #dcfce7; color: #166534; }
        .status-void { background: #fee2e2; color: #991b1b; }
        .status-pending-void { background: #fef3c7; color: #92400e; }
        .slip-footer { text-align: center; border-top: 2px dashed #e2e8f0; padding-top: 1rem;
            margin-top: 1rem; font-size: 0.72rem; color: #94a3b8; line-height: 1.6; }
        .teller-sign { margin-top: 2rem; text-align: center; font-size: 0.78rem; }
        .print-btn { display: block; width: 100%; margin-top: 1.5rem; padding: 0.65rem;
            background: #059669; color: white; border: none; border-radius: 0.5rem;
            font-size: 0.875rem; font-weight: 600; cursor: pointer; }
        .print-btn:hover { background: #047857; }
        @media print {
            body { background: white; padding: 0; }
            .slip { box-shadow: none; border-radius: 0; }
            .print-btn { display: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<div class="slip">
    
    <div class="slip-header">
        <div class="kop-name"><?php echo e(config('app.name', 'Koperasi Syariah')); ?></div>
        <div class="kop-sub">Koperasi Simpan Pinjam & Pembiayaan Syariah</div>
        <div class="slip-title">Bukti Transaksi / Resi</div>
    </div>

    
    <div class="row">
        <span class="label">No. Transaksi</span>
        <span class="value" style="font-size:0.72rem;"><?php echo e($transaksi->no_transaksi); ?></span>
    </div>
    <div class="row">
        <span class="label">Tanggal</span>
        <span class="value"><?php echo e($transaksi->waktu_transaksi->format('d/m/Y H:i:s')); ?></span>
    </div>
    <div class="row">
        <span class="label">Teller</span>
        <span class="value"><?php echo e($transaksi->dibuatOleh?->name ?? '-'); ?></span>
    </div>

    <hr class="divider">

    
    <div class="row">
        <span class="label">No. Anggota</span>
        <span class="value"><?php echo e($transaksi->anggota?->no_anggota ?? '-'); ?></span>
    </div>
    <div class="row">
        <span class="label">Nama Nasabah</span>
        <span class="value"><?php echo e($transaksi->anggota?->nama_lengkap ?? '-'); ?></span>
    </div>
    <div class="row">
        <span class="label">No. Rekening</span>
        <span class="value"><?php echo e($transaksi->rekening?->no_rekening ?? '-'); ?></span>
    </div>

    <hr class="divider">

    
    <div class="row">
        <span class="label">Jenis Transaksi</span>
        <span class="value"><?php echo e($transaksi->jenis_transaksi); ?></span>
    </div>

    <?php
        $akad = match(true) {
            str_contains($transaksi->jenis_transaksi, 'Murabahah')  => 'Murabahah',
            str_contains($transaksi->jenis_transaksi, 'Mudharabah') => 'Mudharabah',
            str_contains($transaksi->jenis_transaksi, 'Musyarakah') => 'Musyarakah',
            str_contains($transaksi->jenis_transaksi, 'Qardh')      => 'Qardh (Pinjaman Kebajikan)',
            str_contains($transaksi->jenis_transaksi, 'Setoran'),
            str_contains($transaksi->jenis_transaksi, 'Penarikan')  => 'Wadi\'ah Yad Dhamanah',
            str_contains($transaksi->jenis_transaksi, 'Bagi Hasil') => 'Mudharabah (Bagi Hasil)',
            default => null,
        };
        $angsuran = $transaksi->angsuran;
    ?>

    <?php if($akad): ?>
    <div class="akad-box">
        <strong>Akad Syariah</strong>
        <?php echo e($akad); ?>

    </div>
    <?php endif; ?>

    
    <div class="nominal-box">
        <div class="nominal-label">
            <?php echo e($transaksi->isMasuk() ? 'Jumlah Setoran / Pembayaran' : 'Jumlah Penarikan'); ?>

        </div>
        <div class="nominal-value">Rp <?php echo e(number_format($transaksi->jumlah, 0, ',', '.')); ?></div>
    </div>

    
    <?php if($angsuran): ?>
    <div style="font-size:0.75rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Rincian Angsuran:</div>
    <table class="split-table">
        
        <tr><td>Pokok</td><td>Rp <?php echo e(number_format($angsuran->pokok_angsuran ?? 0, 0, ',', '.')); ?></td></tr>
        <?php if(($angsuran->margin_ujroh ?? 0) > 0): ?>
        <tr><td>Margin (Murabahah)</td><td>Rp <?php echo e(number_format($angsuran->margin_ujroh, 0, ',', '.')); ?></td></tr>
        <?php endif; ?>
        <?php if(($angsuran->denda ?? 0) > 0): ?>
        <tr><td>Denda Keterlambatan</td><td>Rp <?php echo e(number_format($angsuran->denda, 0, ',', '.')); ?></td></tr>
        <?php endif; ?>
        <tr class="split-total">
            <td>Total Bayar</td>
            <td>Rp <?php echo e(number_format($transaksi->jumlah, 0, ',', '.')); ?></td>
        </tr>
    </table>
    <hr class="divider">
    <?php endif; ?>

    
    <div class="row">
        <span class="label">Saldo Akhir</span>
        <span class="value" style="color:#065f46;">Rp <?php echo e(number_format($transaksi->saldo_setelah ?? 0, 0, ',', '.')); ?></span>
    </div>
    <div class="row">
        <span class="label">Status</span>
        <span class="value">
            <?php if($transaksi->status_transaksi === 'Sukses'): ?>
                <span class="status-badge status-sukses">Sukses</span>
            <?php elseif($transaksi->status_transaksi === 'Voided'): ?>
                <span class="status-badge status-void">VOIDED</span>
            <?php elseif($transaksi->status_transaksi === 'Pending Void'): ?>
                <span class="status-badge status-pending-void">Pending Void</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if($transaksi->catatan): ?>
    <hr class="divider">
    <div class="row">
        <span class="label">Catatan</span>
        <span class="value"><?php echo e($transaksi->catatan); ?></span>
    </div>
    <?php endif; ?>

    
    <div class="teller-sign">
        <div style="margin-bottom: 2.5rem;"></div>
        <div style="border-top: 1px solid #e2e8f0; display: inline-block; min-width: 140px; padding-top: 0.3rem;">
            <?php echo e($transaksi->dibuatOleh?->name ?? 'Teller'); ?>

        </div>
        <div style="font-size:0.7rem;color:#94a3b8;">Teller / Petugas</div>
    </div>

    
    <div class="slip-footer">
        Simpan bukti transaksi ini sebagai referensi.<br>
        Terima kasih telah menjadi anggota setia kami.<br>
        <strong>Barakallah fiikum</strong>
    </div>

    <button class="print-btn no-print" onclick="window.print()">🖨 Cetak Slip</button>
</div>

<script>
    // Auto print jika dibuka dari tab baru
    window.onload = function() {
        // Beri sedikit jeda agar halaman render sempurna
        setTimeout(() => {
            if (window.opener) window.print();
        }, 500);
    };
</script>
</body>
</html>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/teller/slip.blade.php ENDPATH**/ ?>