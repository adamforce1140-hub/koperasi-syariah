{{-- Partial: Keputusan Approval Pembiayaan Terakhir (Dashboard Manajer) --}}
<div class="card-header">
    <span class="card-title">Keputusan Approval Terakhir</span>
</div>
@if($keputusanTerakhir->count() > 0)
<table>
    <thead>
        <tr>
            <th>No. Kontrak</th>
            <th>Anggota</th>
            <th>Jumlah</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($keputusanTerakhir as $p)
        @php $st = strtolower((string) $p->status_pembiayaan); @endphp
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;">{{ $p->no_kontrak ?? '-' }}</td>
            <td><strong>{{ $p->anggota?->nama_lengkap ?? '-' }}</strong></td>
            <td style="font-weight:600;">Rp {{ number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.') }}</td>
            <td>
                @if($st === 'disetujui')
                    <span class="badge badge-green">Disetujui</span>
                @elseif($st === 'ditolak')
                    <span class="badge badge-red">Ditolak</span>
                @elseif(in_array($st, ['pending_pengurus', 'pending_dps']))
                    <span class="badge badge-blue">Diteruskan ke {{ $st === 'pending_pengurus' ? 'Pengurus' : 'DPS' }}</span>
                @else
                    <span class="badge badge-amber">{{ $p->status_pembiayaan }}</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<div style="padding:2rem;text-align:center;color:#94a3b8;">
    <div>Belum ada keputusan approval</div>
</div>
@endif
