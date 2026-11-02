<div class="stats-grid">
    <div class="stat-card" style="{{ $totalMenunggu > 0 ? 'border-left:3px solid #d97706;' : '' }}">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $totalMenunggu > 0 ? 'color:#d97706;' : '' }}">{{ $totalMenunggu }}</div>
            <div class="stat-label">Pengajuan Menunggu</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalDisetujui }}</div>
            <div class="stat-label">Disetujui</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">❌</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalDitolak }}</div>
            <div class="stat-label">Ditolak</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalPembiayaanAktif }}</div>
            <div class="stat-label">Pembiayaan Berjalan</div>
        </div>
    </div>
</div>
