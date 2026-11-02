
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
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
    <div class="stat-card" style="<?php echo e($totalPengajuan > 0 ? 'border-left:3px solid #d97706;' : ''); ?>">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="<?php echo e($totalPengajuan > 0 ? 'color:#d97706;' : ''); ?>"><?php echo e($totalPengajuan); ?></div>
            <div class="stat-label">Pengajuan Pending</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🔢</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalTransaksiBulanIni); ?></div>
            <div class="stat-label">Transaksi Bulan Ini</div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/pengurus-stats.blade.php ENDPATH**/ ?>