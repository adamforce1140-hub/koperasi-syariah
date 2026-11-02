
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value">Rp <?php echo e(number_format($totalSaldo, 0, ',', '.')); ?></div>
            <div class="stat-label">Total Saldo Simpanan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📋</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e(count($pembiayaanAktif)); ?></div>
            <div class="stat-label">Pembiayaan Aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber">📅</div>
        <div class="stat-info">
            <div class="stat-value">Rp <?php echo e(number_format($sisaAngsuranTotal, 0, ',', '.')); ?></div>
            <div class="stat-label">Sisa Angsuran</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🔄</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalTrxBulanIni); ?></div>
            <div class="stat-label">Transaksi Bulan Ini</div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views\nasabah\partials\dashboard-metrik.blade.php ENDPATH**/ ?>