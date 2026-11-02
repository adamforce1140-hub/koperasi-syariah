{{-- Grid memakai definisi tunggal dari layouts/app.blade.php (audit D1);
     inline style minmax(180px) yang menimpa nilai global telah dihapus. --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalAnggota }}</div>
            <div class="stat-label">Total Anggota</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalPembiayaanAktif }}</div>
            <div class="stat-label">Pembiayaan Aktif</div>
        </div>
    </div>
    <div class="stat-card" style="{{ $totalPengajuan > 0 ? 'border-left:3px solid #d97706;' : '' }}">
        <div class="stat-icon amber">⏳</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $totalPengajuan > 0 ? 'color:#d97706;' : '' }}">{{ $totalPengajuan }}</div>
            <div class="stat-label">Pengajuan Pending</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🔢</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalTransaksiBulanIni }}</div>
            <div class="stat-label">Transaksi Bulan Ini</div>
        </div>
    </div>
</div>
