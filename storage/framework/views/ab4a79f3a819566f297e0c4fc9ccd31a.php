
<div class="stats-grid">
    <div class="stat-card" style="<?php echo e($totalApprovalPending > 0 ? 'border-left:3px solid #d97706;' : ''); ?>">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="<?php echo e($totalApprovalPending > 0 ? 'color:#d97706;' : ''); ?>"><?php echo e($totalApprovalPending); ?></div>
            <div class="stat-label">Approval Menunggu</div>
        </div>
    </div>
    <div class="stat-card" style="<?php echo e($totalPengajuanPembiayaan > 0 ? 'border-left:3px solid #d97706;' : ''); ?>">
        <div class="stat-icon amber">📄</div>
        <div class="stat-info">
            <div class="stat-value" style="<?php echo e($totalPengajuanPembiayaan > 0 ? 'color:#d97706;' : ''); ?>"><?php echo e($totalPengajuanPembiayaan); ?></div>
            <div class="stat-label">Pengajuan Pembiayaan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🔢</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($transaksiHariIni); ?></div>
            <div class="stat-label">Transaksi Hari Ini</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <div class="stat-value"><?php echo e($totalAnggota); ?></div>
            <div class="stat-label">Total Anggota</div>
        </div>
    </div>
</div>
<?php /**PATH C:\laragon\www\koperasi_syariah\resources\views/dashboard/partials/manajer-stats.blade.php ENDPATH**/ ?>