{{-- Kartu ringkasan utama Super Admin (real-time). Grid & kartu memakai
     definisi tunggal dari layouts/app.blade.php — tidak didefinisikan ulang. --}}
@php
    $ringkasRp = function ($angka, $desimal = 1) {
        $angka = (float) $angka;
        if (abs($angka) >= 1_000_000_000) return 'Rp ' . number_format($angka / 1_000_000_000, 2, ',', '.') . ' M';
        if (abs($angka) >= 1_000_000)     return 'Rp ' . number_format($angka / 1_000_000, $desimal, ',', '.') . ' jt';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    };
    $npf = (float) ($kolektibilitas['npf'] ?? 0);
@endphp
<div class="stats-grid stats-grid--6">
    <div class="stat-card">
        <div class="stat-icon blue">👥</div>
        <div class="stat-info">
            <div class="stat-value">{{ number_format($totalAnggota, 0, ',', '.') }}</div>
            <div class="stat-label">Total Anggota · {{ number_format($anggotaAktif, 0, ',', '.') }} aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple">🏦</div>
        <div class="stat-info">
            <div class="stat-value">{{ $ringkasRp($totalAset, 2) }}</div>
            <div class="stat-label">Total Aset Koperasi</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">💳</div>
        <div class="stat-info">
            <div class="stat-value">{{ $ringkasRp($pembiayaanDisalurkan['total'] ?? 0, 1) }}</div>
            <div class="stat-label">Pembiayaan Disalurkan · {{ number_format($pembiayaanDisalurkan['akad'] ?? 0, 0, ',', '.') }} akad</div>
        </div>
    </div>
    <div class="stat-card" style="{{ $npf >= 5 ? 'border-left:3px solid #dc2626;' : '' }}">
        <div class="stat-icon {{ $npf >= 5 ? 'red' : 'amber' }}">📉</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $npf >= 5 ? 'color:#dc2626;' : '' }}">{{ number_format($npf, 2, ',', '.') }}%</div>
            <div class="stat-label">NPF (Kol 3–5) · Kolektibilitas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">🐖</div>
        <div class="stat-info">
            <div class="stat-value">{{ $ringkasRp($totalSimpanan['total'] ?? 0, 1) }}</div>
            <div class="stat-label">Total Simpanan · {{ number_format($totalSimpanan['rekening'] ?? 0, 0, ',', '.') }} rekening</div>
        </div>
    </div>
    <div class="stat-card" style="{{ $labaRugi['labaBersih'] < 0 ? 'border-left:3px solid #dc2626;' : '' }}">
        <div class="stat-icon {{ $labaRugi['labaBersih'] < 0 ? 'red' : 'green' }}">📊</div>
        <div class="stat-info">
            <div class="stat-value" style="{{ $labaRugi['labaBersih'] < 0 ? 'color:#dc2626;' : '' }}">
                {{ $ringkasRp($labaRugi['labaBersih'] ?? 0, 1) }}
            </div>
            <div class="stat-label">Laba Bersih {{ $periode['tahun'] ?? now()->year }} (YTD)</div>
        </div>
    </div>
</div>
