{{-- Sebaran kolektibilitas pembiayaan berjalan (Kol 1–5) menurut sisa
     pokok, beserta ringkasan NPF (Kol 3–5). Sumber: SuperAdminController
     ::kolektibilitas() yang memakai CkpnService (POJK 12/2023). --}}
@php
    $ringkasRp = function ($angka) {
        $angka = (float) $angka;
        if (abs($angka) >= 1_000_000_000) return 'Rp ' . number_format($angka / 1_000_000_000, 2, ',', '.') . ' M';
        if (abs($angka) >= 1_000_000)     return 'Rp ' . number_format($angka / 1_000_000, 1, ',', '.') . ' jt';
        return 'Rp ' . number_format($angka, 0, ',', '.');
    };

    $kolom = [
        ['kol' => 'kol1', 'label' => 'Lancar',        'warna' => '#16a34a'],
        ['kol' => 'kol2', 'label' => 'Dalam Perhatian', 'warna' => '#65a30d'],
        ['kol' => 'kol3', 'label' => 'Kurang Lancar', 'warna' => '#d97706'],
        ['kol' => 'kol4', 'label' => 'Diragukan',     'warna' => '#ea580c'],
        ['kol' => 'kol5', 'label' => 'Macet',         'warna' => '#dc2626'],
    ];

    $npf     = (float) ($kolektibilitas['npf'] ?? 0);
    $totalPb = (float) ($kolektibilitas['total'] ?? 0);
@endphp
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">📊 Kolektibilitas Pembiayaan</span>
        <span style="font-size:0.74rem;color:#94a3b8;">Portofolio berjalan · real-time</span>
    </div>

    {{-- Baris ringkas NPF --}}
    <div class="fin-grid" style="margin-bottom:1rem;">
        <div class="fin-cell">
            <div class="fin-cap">Pembiayaan Berjalan</div>
            <div class="fin-val">{{ $ringkasRp($totalPb) }}</div>
            <div class="fin-note">Sisa pokok seluruh akad aktif</div>
        </div>
        <div class="fin-cell" style="{{ $npf >= 5 ? 'background:#fef2f2;border-color:#fecaca;' : '' }}">
            <div class="fin-cap">NPF (Kol 3–5)</div>
            <div class="fin-val" style="{{ $npf >= 5 ? 'color:#dc2626;' : 'color:#16a34a;' }}">
                {{ number_format($npf, 2, ',', '.') }}%
            </div>
            <div class="fin-note">{{ $npf >= 5 ? 'Di atas ambang aman 5%' : 'Dalam batas aman (&lt; 5%)' }}</div>
        </div>
    </div>

    {{-- Rincian per kolektibilitas --}}
    <div style="display:flex;flex-direction:column;gap:0.55rem;">
        @foreach($kolom as $k)
            @php
                $nominal = (float) ($kolektibilitas[$k['kol']] ?? 0);
                $jml     = (int) ($kolektibilitas['jumlah'][$k['kol']] ?? 0);
                $persen  = $totalPb > 0 ? round($nominal / $totalPb * 100, 1) : 0;
            @endphp
            <div style="display:flex;align-items:center;gap:0.75rem;font-size:0.8rem;">
                <span style="width:110px;color:#475569;font-weight:600;flex-shrink:0;">
                    Kol {{ substr($k['kol'], -1) }} · {{ $k['label'] }}
                </span>
                <span style="flex:1;height:8px;border-radius:999px;background:#f1f5f9;overflow:hidden;min-width:40px;">
                    <span style="display:block;height:100%;width:{{ $persen }}%;background:{{ $k['warna'] }};"></span>
                </span>
                <span style="width:56px;text-align:right;color:#64748b;font-weight:600;">{{ $persen }}%</span>
                <span style="width:130px;text-align:right;color:#0f172a;font-weight:600;">{{ $ringkasRp($nominal) }}</span>
                <span style="width:64px;text-align:right;color:#94a3b8;">{{ $jml }} akad</span>
            </div>
        @endforeach
    </div>

    <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:0.6rem;">
        {{-- Tautan CKPN hanya dirender bila rute ada DAN peran berhak — menjaga
             invariant anti-403 (NavigasiWewenangPeranTest) serta keamanan render
             bila matriks RBAC neraca berubah. --}}
        @if(\Illuminate\Support\Facades\Route::has('laporan.ckpn')
            && \App\Support\LaporanKeuanganPermission::boleh(auth()->user()->role, \App\Support\LaporanKeuanganPermission::MODUL_NERACA, \App\Support\LaporanKeuanganPermission::LIHAT))
            <a href="{{ route('laporan.ckpn') }}" class="btn btn-primary" style="font-size:0.78rem;padding:0.45rem 0.9rem;">
                Perhitungan CKPN (PSAK 71) →
            </a>
        @endif
    </div>
</div>
