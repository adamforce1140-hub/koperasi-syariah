@extends('layouts.app')
@section('title', 'Informasi Saldo Simpanan')
@section('page-title', 'Informasi Saldo')
@section('content')

{{-- Summary Card Total Saldo --}}
<div style="background: linear-gradient(135deg, #065f46 0%, #059669 100%); border-radius: 1rem; padding: 2rem; margin-bottom: 1.75rem; color: white; box-shadow: 0 4px 16px rgba(5,150,105,0.25);">
    <div style="font-size: 0.85rem; opacity: 0.85; margin-bottom: 0.4rem;">Total Saldo Seluruh Rekening</div>
    <div style="font-size: 2.25rem; font-weight: 700; letter-spacing: -0.5px;">Rp {{ number_format($totalSaldo, 0, ',', '.') }}</div>
    <div style="font-size: 0.78rem; opacity: 0.75; margin-top: 0.5rem;">{{ count($rekening) }} rekening terdaftar</div>
</div>

{{-- Daftar Rekening --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Rekening Simpanan</span>
    </div>

    @if($rekening->isEmpty())
        <div style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">🏦</div>
            <div style="font-size: 1rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem;">Belum ada rekening simpanan</div>
            <div style="font-size: 0.85rem;">Hubungi petugas untuk membuka rekening simpanan</div>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem;">
            @foreach($rekening as $rek)
            @php
                $jenis = strtolower($rek->jenis_rekening ?? $rek->jenis_simpanan ?? '');
                if (str_contains($jenis, 'pokok')) {
                    $badgeClass = 'badge-blue';
                    $iconBg    = '#dbeafe';
                    $icon      = '🔵';
                } elseif (str_contains($jenis, 'wajib')) {
                    $badgeClass = 'badge-green';
                    $iconBg    = '#dcfce7';
                    $icon      = '🟢';
                } elseif (str_contains($jenis, 'sukarela')) {
                    $badgeClass = 'badge-purple';
                    $iconBg    = '#f3e8ff';
                    $icon      = '🟣';
                } elseif (str_contains($jenis, 'mudharabah')) {
                    $badgeClass = 'badge-amber';
                    $iconBg    = '#fef3c7';
                    $icon      = '🟠';
                } else {
                    $badgeClass = 'badge-gray';
                    $iconBg    = '#f1f5f9';
                    $icon      = '⚪';
                }
                $isAktif = ($rek->status_rekening ?? 'Aktif') === 'Aktif';
            @endphp
            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 1px 6px rgba(0,0,0,0.05); transition: box-shadow 0.15s;">
                {{-- Header rekening --}}
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.25rem;">
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div style="width: 46px; height: 46px; border-radius: 0.75rem; background: {{ $iconBg }}; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0;">
                            {{ $icon }}
                        </div>
                        <div>
                            <span class="badge {{ $badgeClass }}" style="margin-bottom: 0.25rem; display: inline-block;">
                                {{ ucfirst($rek->jenis_rekening ?? $rek->jenis_simpanan ?? 'Simpanan') }}
                            </span>
                            <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.15rem;">{{ $rek->no_rekening }}</div>
                        </div>
                    </div>
                    <span style="display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.6rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; {{ $isAktif ? 'background: #dcfce7; color: #166534;' : 'background: #f1f5f9; color: #64748b;' }}">
                        <span style="width: 6px; height: 6px; border-radius: 50%; {{ $isAktif ? 'background: #16a34a;' : 'background: #94a3b8;' }}; display: inline-block;"></span>
                        {{ $isAktif ? 'Aktif' : 'Tidak Aktif' }}
                    </span>
                </div>

                {{-- Saldo --}}
                <div style="border-top: 1px solid #f1f5f9; padding-top: 1rem;">
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.3rem; text-transform: uppercase; letter-spacing: 0.05em;">Saldo</div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #1e293b;">Rp {{ number_format($rek->saldo, 0, ',', '.') }}</div>
                </div>

                {{-- Buku tabungan rekening ini --}}
                <a href="{{ route('nasabah.buku-tabungan.index', $rek) }}"
                   style="display: flex; align-items: center; justify-content: center; gap: 0.4rem; margin-top: 1rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.82rem; font-weight: 600; text-decoration: none; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;">
                    📖 Buku Tabungan
                </a>
            </div>
            @endforeach
        </div>
    @endif
</div>

@endsection
