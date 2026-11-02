@extends('layouts.app')

@section('title', 'Dashboard DPS — ' . config('app.name'))
@section('page-title', 'Dashboard Dewan Pengawas Syariah')

@section('content')
{{-- Salam --}}
@include('dashboard.partials.sapaan', ['peran' => 'Dewan Pengawas Syariah', 'tema' => 'ungu', 'badge' => 'READ-ONLY', 'badgeKetika' => 'Akses Audit'])

{{-- Info DPS --}}
<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:0.875rem;color:#92400e;">
    Sebagai DPS, Anda hanya memiliki akses <strong>baca (read-only)</strong> untuk keperluan audit kepatuhan syariah. Tidak ada aksi yang dapat dilakukan dari halaman ini.
</div>

{{-- ====== Mulai area real-time (mesin denyut) ======
     Setiap container memuat potongan HTML-nya sendiri lewat
     route('dashboard.dps.ringkas') ketika sidik jari domain berubah. --}}

{{-- Stats Utama --}}
<div id="dps-stats"
     data-denyut-domain="anggota pembiayaan transaksi"
     data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
     data-denyut-sasaran="stats">
    @include('dashboard.partials.dps-stats')
</div>

<div id="dps-detail" style="display:flex;flex-direction:column;gap:1.5rem;">

    {{-- Breakdown Akad --}}
    <div id="dps-akad"
         data-denyut-domain="pembiayaan approval"
         data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
         data-denyut-sasaran="akad">
        @include('dashboard.partials.dps-akad')
    </div>

    {{-- Pendapatan (margin / bagi hasil / ujroh) --}}
    <div id="dps-pendapatan"
         data-denyut-domain="jurnal pembiayaan transaksi"
         data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
         data-denyut-sasaran="pendapatan">
        @include('dashboard.partials.dps-pendapatan')
    </div>

    {{-- Dana Kebajikan (ta'zir/denda) --}}
    <div id="dps-kebajikan"
         data-denyut-domain="jurnal"
         data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
         data-denyut-sasaran="kebajikan">
        @include('dashboard.partials.dps-kebajikan')
    </div>

    {{-- Monitoring transaksi terbaru --}}
    <div id="dps-transaksi"
         data-denyut-domain="transaksi"
         data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
         data-denyut-sasaran="transaksi">
        @include('dashboard.partials.dps-transaksi')
    </div>

    {{-- Pembiayaan aktif --}}
    <div id="dps-pembiayaan"
         data-denyut-domain="pembiayaan"
         data-denyut-url="{{ route('dashboard.dps.ringkas') }}"
         data-denyut-sasaran="pembiayaan">
        @include('dashboard.partials.dps-pembiayaan')
    </div>

</div>
@endsection
