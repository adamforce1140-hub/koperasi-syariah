{{-- Partial: Pembiayaan Menunggu Approval (Dashboard Manajer) --}}
<div class="card-header">
    <span class="card-title">Pembiayaan Menunggu Approval</span>
    <a href="{{ route('pengelola.pengajuan-pinjaman.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
        Lihat Semua →
    </a>
</div>
@if($pengajuanPembiayaan->count() > 0)
<table>
    <thead>
        <tr>
            <th>No. Kontrak</th>
            <th>Anggota</th>
            <th>Produk</th>
            <th>Jumlah</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($pengajuanPembiayaan as $p)
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;">{{ $p->no_kontrak ?? '-' }}</td>
            <td><strong>{{ $p->anggota?->nama_lengkap ?? '-' }}</strong></td>
            <td style="font-size:0.85rem;">{{ $p->produk?->nama_produk ?? '-' }}</td>
            <td style="font-weight:600;">Rp {{ number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.') }}</td>
            <td>
                @if($p->status_pembiayaan === 'pending_manajer')
                    <span class="badge badge-amber">Menunggu Manajer</span>
                @else
                    <span class="badge badge-amber">{{ $p->status_pembiayaan }}</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<div style="padding:2.5rem;text-align:center;color:#94a3b8;">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
    <div style="font-weight:600;color:#475569;">Tidak ada pembiayaan menunggu approval</div>
</div>
@endif
