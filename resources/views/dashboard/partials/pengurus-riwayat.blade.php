{{-- Riwayat Approval — siapa yang menyetujui setiap transaksi --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Approval Transaksi</span>
        <a href="{{ route('pengurus.approval.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua →
        </a>
    </div>
    @if($riwayatApproval->count() > 0)
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Diajukan Oleh</th>
                <th>Disetujui/Ditolak Oleh</th>
                <th>Jumlah</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($riwayatApproval as $ap)
            <tr>
                <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;">
                    {{ $ap->updated_at->format('d/m/Y H:i') }}
                </td>
                <td>{{ $ap->pengaju?->name ?? '-' }}</td>
                <td>
                    @if($ap->approver)
                        <strong>{{ $ap->approver->name }}</strong>
                        <div style="font-size:0.75rem;color:#64748b;">{{ ucfirst($ap->approver->role) }}</div>
                    @else
                        <span style="color:#94a3b8;">—</span>
                    @endif
                </td>
                <td style="font-weight:600;">Rp {{ number_format($ap->nominal ?? 0, 0, ',', '.') }}</td>
                <td>
                    @if($ap->status === 'APPROVED')
                        <span class="badge badge-green">Disetujui</span>
                    @elseif($ap->status === 'REJECTED')
                        <span class="badge" style="background:#fee2e2;color:#dc2626;">Ditolak</span>
                    @else
                        <span class="badge badge-amber">Pending</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div style="font-size:2rem;margin-bottom:0.5rem;">📋</div>
        <div>Belum ada riwayat approval</div>
    </div>
    @endif
</div>
