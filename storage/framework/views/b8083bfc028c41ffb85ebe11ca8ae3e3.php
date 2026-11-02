
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalAnggota); ?></div>
            <div class="stat-label">Total Anggota</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalPembiayaanAktif); ?></div>
            <div class="stat-label">Pembiayaan Aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">📊</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e(number_format($totalTransaksiAll)); ?></div>
            <div class="stat-label">Total Transaksi</div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/dps-stats.blade.php ENDPATH**/ ?>