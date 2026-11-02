
<div class="stat-card">
    <div class="stat-icon green">📥</div>
    <div class="stat-info">
        <div class="stat-value">Rp <?php echo e(number_format($totalSetorHariIni, 0, ',', '.')); ?></div>
        <div class="stat-label">Total Setoran Hari Ini</div>
    </div>
</div>
<div class="stat-card">
    <div class="stat-icon amber">📤</div>
    <div class="stat-info">
        <div class="stat-value">Rp <?php echo e(number_format($totalTarikHariIni, 0, ',', '.')); ?></div>
        <div class="stat-label">Total Penarikan Hari Ini</div>
    </div>
</div>
<div class="stat-card">
    <div class="stat-icon green">🔢</div>
    <div class="stat-info">
        <div class="stat-value"><?php echo e($jumlahTransaksiHariIni); ?></div>
        <div class="stat-label">Transaksi Hari Ini</div>
    </div>
</div>
<?php if($pendingApproval > 0): ?>
<div class="stat-card" style="border-left:3px solid #d97706;">
    <div class="stat-icon amber">⏳</div>
    <div class="stat-info">
        <div class="stat-value" style="color:#d97706;"><?php echo e($pendingApproval); ?></div>
        <div class="stat-label">Menunggu Approval</div>
    </div>
</div>
<?php endif; ?>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/teller-stats.blade.php ENDPATH**/ ?>