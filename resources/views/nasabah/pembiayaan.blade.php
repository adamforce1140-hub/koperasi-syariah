@extends('layouts.app')
@section('title', 'Pembiayaan Saya')
@section('page-title', 'Pembiayaan Saya')
@section('content')
<div class="card">
    <div class="card-header"><span class="card-title">Pembiayaan Saya</span></div>
    <table>
        <thead><tr><th>#</th><th>Akad</th><th>Pokok</th><th>Margin</th><th>Tenor</th><th>Sisa Kewajiban</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($items as $i => $p)
            @php
                // Kolom yang benar pada tabel `pembiayaan`: akad_pembiayaan,
                // jumlah_pembiayaan (pokok), margin_nisbah (NOMINAL rupiah,
                // bukan persen), dan sisa_pembiayaan (sisa kewajiban).
                $rupiah = fn ($n) => number_format((float) $n, 0, ',', '.');
                $meta = \App\Models\Pembiayaan::statusMeta()[$p->status_pembiayaan] ?? null;
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><strong>{{ $p->akad_pembiayaan }}</strong></td>
                <td>Rp {{ $rupiah($p->jumlah_pembiayaan) }}</td>
                <td>Rp {{ $rupiah($p->margin_nisbah) }}</td>
                <td>{{ $p->tenor }} bln</td>
                <td>Rp {{ $rupiah($p->sisa_pembiayaan) }}</td>
                <td><span class="badge" style="background:{{ $meta['color'] ?? '#94a3b8' }}22;color:{{ $meta['color'] ?? '#94a3b8' }};">{{ $meta['label'] ?? ucfirst((string) $p->status_pembiayaan) }}</span></td>
            </tr>
            @empty
            <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada pembiayaan</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
