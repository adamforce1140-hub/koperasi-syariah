@extends('layouts.app')

@section('title', 'Dashboard Teller — ' . config('app.name'))
@section('page-title', 'Dashboard Teller')

@section('content')
{{-- Salam --}}
@include('dashboard.partials.sapaan', ['peran' => 'Teller'])

{{-- Stats Hari Ini — ikut disegarkan real-time (domain: transaksi, approval) --}}
<div id="teller-stats" class="stats-grid"
     data-denyut-domain="transaksi approval"
     data-denyut-url="{{ route('dashboard.teller.ringkas') }}"
     data-denyut-sasaran="stats">
          @include('dashboard.partials.teller-stats')
</div>

{{-- Menu Operasional --}}
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Menu Operasional</span>
        {{-- Angka batas TIDAK boleh ditulis literal di view. Sumber kebenaran
             adalah HierarkiRole (config role_hierarki + override DB) — sama
             dengan yang dipakai TransactionLimitService saat memvalidasi.
             Ditampilkan batas `tarik_tunai`: limit tunai terendah yang dipegang
             teller, sehingga angka di layar tidak pernah menjanjikan lebih
             besar dari kewenangan sebenarnya. --}}
        <span style="font-size:0.78rem;color:#64748b;">Batas mandiri: Rp {{ number_format(\App\Support\HierarkiRole::batas('teller', 'tarik_tunai')['limit_per_transaksi'] ?? 0, 0, ',', '.') }}</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;padding:1rem 0 0.5rem;">
        <a href="{{ route('teller.setor') }}" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">📥</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Setoran Tunai</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Input setor anggota</div>
            </div>
        </a>
        <a href="{{ route('teller.tarik') }}" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">📤</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Penarikan Tunai</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Proses penarikan dana</div>
            </div>
        </a>
        <a href="{{ route('teller.angsuran') }}" style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;
            padding:1.5rem 1rem;border-radius:0.75rem;border:2px solid #e2e8f0;text-decoration:none;
            color:#1e293b;transition:all 0.15s;text-align:center;" onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='white'">
            <span style="font-size:2rem;">💳</span>
            <div>
                <div style="font-weight:600;font-size:0.9rem;">Bayar Angsuran</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.2rem;">Pembayaran angsuran</div>
            </div>
        </a>
    </div>
</div>

{{-- Riwayat Transaksi Terakhir — ikut disegarkan real-time (domain: transaksi) --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Transaksi Terakhir</span>
        <a href="{{ route('teller.riwayat') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    <div id="teller-transaksi"
         data-denyut-domain="transaksi"
         data-denyut-url="{{ route('dashboard.teller.ringkas') }}"
         data-denyut-sasaran="transaksi">
        @include('dashboard.partials.teller-transaksi')
    </div>
</div>

{{-- Pengeluaran Beban yang Diinput --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Pengeluaran Beban Saya</span>
        <a href="{{ route('pengeluaran-beban.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    @if($bebanTerakhir->count() > 0)
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>No. Bukti</th>
                <th>Akun Beban</th>
                <th>Nominal</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bebanTerakhir as $bb)
            <tr>
                <td style="white-space:nowrap;font-size:0.8rem;color:#64748b;">{{ $bb->tanggal?->format('d/m/Y') }}</td>
                <td style="font-family:monospace;font-size:0.8rem;">{{ $bb->no_pengeluaran }}</td>
                <td style="font-size:0.82rem;">{{ $bb->coaBeban?->nama_akun ?? '-' }}</td>
                <td style="font-weight:600;">Rp {{ number_format($bb->nominal, 0, ',', '.') }}</td>
                <td>
                    <span class="badge {{ $bb->status === 'Approved' ? 'badge-green' : ($bb->status === 'Ditolak' ? 'badge-red' : 'badge-amber') }}">
                        {{ $bb->status }}
                    </span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada pengeluaran beban</div>
    </div>
    @endif
</div>
@endsection
