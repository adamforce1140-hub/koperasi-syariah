{{-- 4 Metric Cards — memakai kelas bersama .stats-grid/.stat-card/.stat-info
     dari layouts/app.blade.php agar konsisten dengan dashboard role lain
     (audit T2/T3/T5). Inline style grid & kartu lama telah dihapus. --}}
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon green">💰</div>
        <div class="stat-info">
            <div class="stat-value">Rp {{ number_format($totalSaldo, 0, ',', '.') }}</div>
            <div class="stat-label">Total Saldo Simpanan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue">📋</div>
        <div class="stat-info">
            <div class="stat-value">{{ count($pembiayaanAktif) }}</div>
            <div class="stat-label">Pembiayaan Aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber">📅</div>
        <div class="stat-info">
            <div class="stat-value">Rp {{ number_format($sisaAngsuranTotal, 0, ',', '.') }}</div>
            <div class="stat-label">Sisa Angsuran</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🔄</div>
        <div class="stat-info">
            <div class="stat-value">{{ $totalTrxBulanIni }}</div>
            <div class="stat-label">Transaksi Bulan Ini</div>
        </div>
    </div>
</div>
