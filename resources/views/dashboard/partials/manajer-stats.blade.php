{{-- Partial: Kartu statistik Dashboard Manajer (dipakai halaman penuh & endpoint ringkas) --}}
<div class="stats-grid">
    <div class="stat-card" style="{{ $totalApprovalPending > 0 ? 'border-left:3px solid #d97706;' : '' }}">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $totalApprovalPending > 0 ? 'color:#d97706;' : '' }}">{{ $totalApprovalPending }}</div>
            <div class="stat-label">Approval Menunggu</div>
        </div>
    </div>
    <div class="stat-card" style="{{ $totalPengajuanPembiayaan > 0 ? 'border-left:3px solid #d97706;' : '' }}">
        <div class="stat-icon amber">📄</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $totalPengajuanPembiayaan > 0 ? 'color:#d97706;' : '' }}">{{ $totalPengajuanPembiayaan }}</div>
            <div class="stat-label">Pengajuan Pembiayaan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🔢</div>
        <div class="stat-info">
            <div class="stat-value">{{ $transaksiHariIni }}</div>
            <div class="stat-label">Transaksi Hari Ini</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalAnggota }}</div>
            <div class="stat-label">Total Anggota</div>
        </div>
    </div>
</div>
