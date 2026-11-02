@extends('layouts.app')
@section('title', 'Rincian Bagi Hasil Deposito')
@section('page-title', 'Rincian Bagi Hasil Deposito')
@section('content')

@php
    $labelStatus = [
        \App\Models\Deposito::STATUS_PENDING => ['Menunggu', 'badge-amber'],
        \App\Models\Deposito::STATUS_ACTIVE  => ['Aktif', 'badge-green'],
        \App\Models\Deposito::STATUS_MATURED => ['Jatuh Tempo', 'badge-blue'],
        \App\Models\Deposito::STATUS_CLOSED  => ['Ditutup', 'badge-gray'],
    ];
    [$statusLabel, $statusBadge] = $labelStatus[$deposito->status] ?? [ucfirst((string) $deposito->status), 'badge-gray'];

    $labelSharing = [
        \App\Models\DepositoProfitSharing::STATUS_PAID      => ['Dibayarkan', 'badge-green'],
        \App\Models\DepositoProfitSharing::STATUS_FAILED    => ['Tertunda', 'badge-amber'],
        \App\Models\DepositoProfitSharing::STATUS_FORFEITED => ['Hangus', 'badge-red'],
    ];
@endphp

<div style="margin-bottom: 1.25rem;">
    <a href="{{ route('nasabah.deposito.index') }}" class="btn btn-sm" style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;">
        &larr; Kembali ke Deposito Saya
    </a>
</div>

{{-- Informasi bilyet & akad --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Bilyet {{ $deposito->deposito_number }}</span>
        <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem;">
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Pokok</div>
            <div style="font-size: 1.15rem; font-weight: 700; color: #1e293b;">{{ $deposito->principal_formatted }}</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Tenor</div>
            <div style="font-size: 1.15rem; font-weight: 700; color: #1e293b;">{{ $deposito->tenorMonths() }} bulan</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Mulai</div>
            <div style="font-size: 0.95rem; color: #334155;">{{ $deposito->start_date?->format('d/m/Y') ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Jatuh Tempo</div>
            <div style="font-size: 0.95rem; color: #334155;">{{ $deposito->maturity_date?->format('d/m/Y') ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase;">Mode Perpanjangan</div>
            <div style="font-size: 0.95rem; color: #334155;">{{ $deposito->aro_label }}</div>
        </div>
    </div>
</div>

{{-- Transparansi akad & nisbah --}}
<div class="card" style="background:#f0fdf4;border:1px solid #bbf7d0;">
    <div class="card-header" style="margin-bottom:0.75rem;">
        <span class="card-title" style="color:#065f46;">Akad Mudharabah Muthlaqah — Nisbah Bagi Hasil</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 0.75rem;">
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Nisbah Anggota (Shahibul Maal)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #065f46;">{{ rtrim(rtrim(number_format((float) $deposito->nisbah_anggota, 2, ',', '.'), '0'), ',') }}%</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Nisbah Koperasi (Mudharib)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #065f46;">{{ rtrim(rtrim(number_format((float) $deposito->nisbah_koperasi, 2, ',', '.'), '0'), ',') }}%</div>
        </div>
        <div>
            <div style="font-size: 0.7rem; color: #166534; text-transform: uppercase;">Produk</div>
            <div style="font-size: 1rem; font-weight: 600; color: #065f46;">{{ $deposito->product?->name ?? '-' }}</div>
        </div>
    </div>
    <p style="font-size: 0.82rem; color: #166534; margin: 0; line-height: 1.6;">
        Bagi hasil dihitung dari <strong>pendapatan riil koperasi</strong> atas penempatan dana pada periode berjalan,
        dikalikan nisbah anggota di atas. Besarnya bagi hasil tidak dijamin di muka dan mengikuti hasil usaha
        (bukan bunga tetap), sesuai prinsip Mudharabah.
    </p>
</div>

{{-- Ringkasan angka --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Total Pendapatan Koperasi (dasar hitung)</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#1e293b;">Rp {{ number_format($totalPendapatan, 0, ',', '.') }}</div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Bagi Hasil Diterima Anggota</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#047857;">Rp {{ number_format($totalDibayar, 0, ',', '.') }}</div>
    </div>
    <div style="background:white;border:1px solid #e2e8f0;border-radius:1rem;padding:1.25rem;">
        <div style="font-size: 0.75rem; color:#64748b;">Bagi Hasil Hangus (Pencairan Dini)</div>
        <div style="font-size: 1.35rem; font-weight:700; color:#b91c1c;">Rp {{ number_format($totalHangus, 0, ',', '.') }}</div>
    </div>
</div>

{{-- Rincian per periode --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Rincian Bagi Hasil per Periode</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Periode</th>
                    <th style="text-align:right;">Pendapatan Koperasi</th>
                    <th style="text-align:right;">Nisbah Anggota</th>
                    <th style="text-align:right;">Bagi Hasil Anggota</th>
                    <th>Tanggal Bayar</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rincian as $r)
                @php
                    [$sLabel, $sBadge] = $labelSharing[$r->status] ?? [ucfirst((string) $r->status), 'badge-gray'];
                @endphp
                <tr>
                    <td><strong>{{ $r->nama_periode }}</strong></td>
                    <td style="text-align:right;">Rp {{ number_format((float) $r->gross_koperasi_revenue, 0, ',', '.') }}</td>
                    <td style="text-align:right;">{{ rtrim(rtrim(number_format((float) $deposito->nisbah_anggota, 2, ',', '.'), '0'), ',') }}%</td>
                    <td style="text-align:right;font-weight:600;color:#047857;">{{ $r->member_profit_formatted }}</td>
                    <td>{{ $r->payout_date?->format('d/m/Y') ?? '-' }}</td>
                    <td><span class="badge {{ $sBadge }}">{{ $sLabel }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" style="text-align:center;color:#94a3b8;padding:2.5rem;">
                        Belum ada pembagian bagi hasil untuk bilyet ini.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
