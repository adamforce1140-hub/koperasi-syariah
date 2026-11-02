@extends('layouts.app')

@section('title', 'Dashboard Manajer — ' . config('app.name'))
@section('page-title', 'Dashboard Manajer')

{{-- Catatan (audit RBAC): berkas ini pernah memuat `@section('sidebar')`.
     Section itu tidak pernah dirender — `layouts.app` memuat sidebar lewat
     `@include('layouts.sidebar')` dan TIDAK punya `@yield('sidebar')`. Sidebar Manajer yang tampil dibangun dari NavigationService::menuManajer()
     melalui ShareNavigationMenu, sehingga menu tetap utuh tanpa blok tersebut.
     Blok mati seperti itu berbahaya karena isinya mirip menu asli: ia bisa
     berbeda tanpa membuat test gagal. --}}

@section('content')
{{-- Salam --}}
@include('dashboard.partials.sapaan', ['peran' => 'Manajer'])

{{-- Stats (real-time: domain approval + transaksi) --}}
<div id="manajer-stats"
     data-denyut-domain="approval transaksi anggota"
     data-denyut-url="{{ route('dashboard.manajer.ringkas') }}"
     data-denyut-sasaran="stats">
    @include('dashboard.partials.manajer-stats')
</div>

{{-- Approval Transaksi Pending --}}
<div class="card" id="manajer-approval"
     style="margin-bottom:1.5rem;"
     data-denyut-domain="approval transaksi"
     data-denyut-url="{{ route('dashboard.manajer.ringkas') }}"
     data-denyut-sasaran="approval">
    @include('dashboard.partials.manajer-approval')
</div>

{{-- Antrean Approval Pembiayaan --}}
<div class="card" id="manajer-pembiayaan"
     data-denyut-domain="pembiayaan approval"
     data-denyut-url="{{ route('dashboard.manajer.ringkas') }}"
     data-denyut-sasaran="pembiayaan">
    @include('dashboard.partials.manajer-pembiayaan')
</div>

{{-- Keputusan Approval Terakhir --}}
<div class="card" id="manajer-keputusan"
     data-denyut-domain="pembiayaan"
     data-denyut-url="{{ route('dashboard.manajer.ringkas') }}"
     data-denyut-sasaran="keputusan">
    @include('dashboard.partials.manajer-keputusan')
</div>

{{-- Keputusan Approval Beban --}}
<div class="card" id="manajer-beban"
     data-denyut-domain="jurnal"
     data-denyut-url="{{ route('dashboard.manajer.ringkas') }}"
     data-denyut-sasaran="beban">
    @include('dashboard.partials.manajer-beban')
</div>
@endsection
