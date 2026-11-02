@extends('layouts.app')

@section('title', 'Dashboard Account Officer — ' . config('app.name'))
@section('page-title', 'Dashboard Account Officer')


@section('content')
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

{{-- Salam --}}
@include('dashboard.partials.sapaan', ['peran' => 'Account Officer'])

{{-- Stats --}}
<div id="ao-stats" data-denyut-domain="pembiayaan approval"
     data-denyut-url="{{ route('dashboard.ao.ringkas') }}" data-denyut-sasaran="stats">
    @include('dashboard.partials.ao-stats')
</div>

{{-- Pengajuan Perlu Review --}}
<div class="card" id="ao-menunggu" data-denyut-domain="pembiayaan"
     data-denyut-url="{{ route('dashboard.ao.ringkas') }}" data-denyut-sasaran="menunggu">
    @include('dashboard.partials.ao-menunggu')
</div>

{{-- Riwayat Pengajuan Semua Status --}}
<div class="card" id="ao-riwayat" data-denyut-domain="pembiayaan"
     data-denyut-url="{{ route('dashboard.ao.ringkas') }}" data-denyut-sasaran="riwayat">
    @include('dashboard.partials.ao-riwayat')
</div>
@endsection
