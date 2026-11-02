<div class="stats-grid">
    <div class="stat-card" style="<?php echo e($totalMenunggu > 0 ? 'border-left:3px solid #d97706;' : ''); ?>">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="<?php echo e($totalMenunggu > 0 ? 'color:#d97706;' : ''); ?>"><?php echo e($totalMenunggu); ?></div>
            <div class="stat-label">Pengajuan Menunggu</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalDisetujui); ?></div>
            <div class="stat-label">Disetujui</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">❌</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalDitolak); ?></div>
            <div class="stat-label">Ditolak</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalPembiayaanAktif); ?></div>
            <div class="stat-label">Pembiayaan Berjalan</div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/ao-stats.blade.php ENDPATH**/ ?>