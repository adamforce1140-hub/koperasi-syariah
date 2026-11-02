{{--
    Kartu statistik dashboard teller (potongan real-time).
    Dipakai oleh:
      • resources/views/dashboard/teller.blade.php      → render awal
      • TellerDashboardController::ringkas()            → penyegaran via Denyut
    Variabel yang dibutuhkan: $totalSetorHariIni, $totalTarikHariIni,
    $jumlahTransaksiHariIni, $pendingApproval.
--}}
<div class="stat-card">
    <div class="stat-icon green">📥</div>
    <div class="stat-info">
        <div class="stat-value">Rp {{ number_format($totalSetorHariIni, 0, ',', '.') }}</div>
        <div class="stat-label">Total Setoran Hari Ini</div>
    </div>
</div>
<div class="stat-card">
    <div class="stat-icon amber">📤</div>
    <div class="stat-info">
        <div class="stat-value">Rp {{ number_format($totalTarikHariIni, 0, ',', '.') }}</div>
        <div class="stat-label">Total Penarikan Hari Ini</div>
    </div>
</div>
<div class="stat-card">
    <div class="stat-icon green">🔢</div>
    <div class="stat-info">
        <div class="stat-value">{{ $jumlahTransaksiHariIni }}</div>
        <div class="stat-label">Transaksi Hari Ini</div>
    </div>
</div>
@if($pendingApproval > 0)
<div class="stat-card" style="border-left:3px solid #d97706;">
    <div class="stat-icon amber">⏳</div>
    <div class="stat-info">
        <div class="stat-value" style="color:#d97706;">{{ $pendingApproval }}</div>
        <div class="stat-label">Menunggu Approval</div>
    </div>
</div>
@endif
