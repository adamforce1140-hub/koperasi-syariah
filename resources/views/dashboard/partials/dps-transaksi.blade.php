{{-- Monitoring Transaksi Terbaru --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Monitoring Transaksi Terbaru</span>
        <span class="badge" style="background:#ede9fe;color:#7c3aed;font-size:0.7rem;">READ-ONLY</span>
    </div>
    @if($transaksiTerbaru->count() > 0)
    <table>
        <thead>
            <tr>
                <th>Waktu</th>
                <th>No. Transaksi</th>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Jumlah</th>
                <th>Diinput Oleh</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transaksiTerbaru as $trx)
            <tr>
                <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;">
                    {{ $trx->waktu_transaksi ? $trx->waktu_transaksi->format('d/m/Y H:i') : '-' }}
                </td>
                <td style="font-family:monospace;font-size:0.78rem;">{{ $trx->no_transaksi }}</td>
                <td>{{ $trx->rekening?->anggota?->nama_lengkap ?? '-' }}</td>
                <td>
                    <span class="badge {{ str_contains($trx->jenis_transaksi ?? '', 'Setor') ? 'badge-green' : (str_contains($trx->jenis_transaksi ?? '', 'Tarik') ? 'badge-amber' : 'badge-blue') }}">
                        {{ $trx->jenis_transaksi ?? '-' }}
                    </span>
                </td>
                <td style="font-weight:600;">Rp {{ number_format($trx->jumlah, 0, ',', '.') }}</td>
                <td style="font-size:0.85rem;">{{ $trx->dibuatOleh?->name ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada transaksi</div>
    </div>
    @endif
</div>
