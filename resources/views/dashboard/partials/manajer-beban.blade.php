{{-- Partial: Approval Beban Terakhir (Dashboard Manajer) --}}
<div class="card-header">
    <span class="card-title">Approval Beban Terakhir</span>
</div>
@if($bebanKeputusan->count() > 0)
<table>
    <thead>
        <tr>
            <th>No. Pengeluaran</th>
            <th>Penginput</th>
            <th>Akun Beban</th>
            <th>Nominal</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        @foreach($bebanKeputusan as $bb)
        <tr>
            <td style="font-family:monospace;font-size:0.8rem;">{{ $bb->no_pengeluaran }}</td>
            <td>{{ $bb->penginput?->name ?? '-' }}</td>
            <td style="font-size:0.82rem;">{{ $bb->coaBeban?->nama_akun ?? '-' }}</td>
            <td style="font-weight:600;">Rp {{ number_format($bb->nominal, 0, ',', '.') }}</td>
            <td>
                <span class="badge {{ $bb->status === 'Approved' ? 'badge-green' : 'badge-red' }}">
                    {{ $bb->status }}
                </span>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<div style="padding:2rem;text-align:center;color:#94a3b8;">
    <div>Belum ada approval beban</div>
</div>
@endif
