@extends('layouts.app')
@section('title', 'Dashboard Saya')
@section('page-title', 'Dashboard Saya')
@section('content')

{{-- Greeting --}}
@include('dashboard.partials.sapaan', ['peran' => 'Anggota', 'salam' => 'Selamat datang kembali,', 'nama' => $anggota->nama_lengkap])

{{-- ====== Mulai area real-time (mesin denyut) ======
     Potongan dimuat ulang via route('nasabah.dashboard.ringkas'). --}}

{{-- Alert angsuran mendatang (real-time) --}}
<div id="nasabah-angsuran"
     data-denyut-domain="pembiayaan"
     data-denyut-url="{{ route('nasabah.dashboard.ringkas') }}"
     data-denyut-sasaran="angsuran"
     style="margin-bottom:1.75rem;">
    @include('nasabah.partials.dashboard-angsuran')
</div>

{{-- 4 Metric Cards (real-time) --}}
<div id="nasabah-metrik"
     data-denyut-domain="rekening transaksi pembiayaan"
     data-denyut-url="{{ route('nasabah.dashboard.ringkas') }}"
     data-denyut-sasaran="metrik"
     style="margin-bottom:1.75rem;">
    @include('nasabah.partials.dashboard-metrik')
</div>


{{-- Quick Action Buttons --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Aksi Cepat</span>
    </div>
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <a href="{{ route('nasabah.pengajuan.create') }}" class="btn btn-primary">
            <span>➕</span> Ajukan Pembiayaan
        </a>
        <a href="{{ route('nasabah.mutasi.index') }}" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;">
            <span>📊</span> Lihat Mutasi
        </a>
        <a href="{{ route('nasabah.simpanan.index') }}" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all 0.15s; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;">
            <span>💳</span> Info Saldo
        </a>
    </div>
</div>

{{-- Tabel Riwayat Transaksi Terbaru (real-time) --}}
<div id="nasabah-transaksi"
     data-denyut-domain="transaksi"
     data-denyut-url="{{ route('nasabah.dashboard.ringkas') }}"
     data-denyut-sasaran="transaksi">
    @include('nasabah.partials.dashboard-transaksi')
</div>

@endsection
