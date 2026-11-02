{{-- Tabel: Jurnal terbaru --}}
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#128203; Jurnal Terbaru</span>
        <a href="{{ route('laporan.buku-besar') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua &rarr;
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>No. Ref</th>
                <th>Tanggal</th>
                <th>Keterangan</th>
                <th>Status</th>
                <th>Dibuat Oleh</th>
            </tr>
        </thead>
        <tbody>
            @forelse($jurnalTerbaru as $j)
            <tr>
                <td><strong style="color:#059669;">{{ $j->no_ref }}</strong></td>
                <td>{{ optional($j->tanggal_jurnal)->format('d/m/Y') }}</td>
                <td style="color:#64748b;">{{ Str::limit($j->keterangan, 45) }}</td>
                <td>
                    <span class="badge
                        @if($j->isPosted()) badge-green
                        @elseif($j->isVoid()) badge-red
                        @else badge-amber
                        @endif">
                        {{ $j->status }}
                    </span>
                </td>
                <td>{{ $j->createdBy->name ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada jurnal
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
