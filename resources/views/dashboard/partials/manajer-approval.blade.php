{{-- Partial: Approval Transaksi Menunggu (Dashboard Manajer) --}}
<div class="card-header">
    <span class="card-title">Approval Transaksi Menunggu</span>
    <a href="{{ route('pengelola.approval.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
        Lihat Semua →
    </a>
</div>
@if($approvalPending->count() > 0)
<table>
    <thead>
        <tr>
            <th>Waktu</th>
            <th>Diajukan Oleh</th>
            <th>Jenis</th>
            <th>Jumlah</th>
            <th>Aksi</th>
        </tr>
    </thead>
    <tbody>
        @foreach($approvalPending as $ap)
        <tr>
            <td style="font-size:0.8rem;color:#64748b;">{{ $ap->created_at->format('d/m/Y H:i') }}</td>
            <td><strong>{{ $ap->pengaju?->name ?? '-' }}</strong></td>
            <td>{{ $ap->jenis_transaksi ?? '-' }}</td>
            <td style="font-weight:600;">Rp {{ number_format($ap->nominal ?? 0, 0, ',', '.') }}</td>
            <td>
                <a href="{{ route('pengelola.approval.show', $ap->id) }}" class="btn btn-primary" style="padding:0.3rem 0.7rem;font-size:0.78rem;">
                    Review
                </a>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@else
<div style="padding:2.5rem;text-align:center;color:#94a3b8;">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
    <div style="font-weight:600;color:#475569;">Tidak ada transaksi menunggu approval</div>
</div>
@endif
