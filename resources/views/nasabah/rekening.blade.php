@extends('layouts.app')
@section('title', 'Rekening Saya')
@section('page-title', 'Rekening Saya')
@section('content')
<div class="card">
    <div class="card-header"><span class="card-title">Daftar Rekening</span></div>
    @forelse($items as $r)
    <div style="background:#f8fafc;border-radius:0.75rem;padding:1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-size:0.75rem;color:#64748b;">No. Rekening</div>
            <div style="font-size:1.1rem;font-weight:700;letter-spacing:0.05em;">{{ $r->no_rekening }}</div>
            <div style="font-size:0.82rem;color:#64748b;margin-top:0.25rem;">{{ $r->jenis_rekening ?? '-' }}</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:0.75rem;color:#64748b;">Saldo</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;">Rp {{ number_format($r->saldo, 0, ',', '.') }}</div>
        </div>
    </div>
    @empty
    <p style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada rekening</p>
    @endforelse
</div>
@endsection
