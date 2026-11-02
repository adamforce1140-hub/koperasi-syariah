{{-- Tabel Riwayat Transaksi Terbaru --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">5 Transaksi Terbaru</span>
        <a href="{{ route('nasabah.mutasi.index') }}" style="font-size: 0.8rem; color: #059669; text-decoration: none; font-weight: 500;">Lihat semua &rarr;</a>
    </div>

    @if($transaksiTerbaru->isEmpty())
        <div style="text-align: center; padding: 3rem 1rem; color: #94a3b8;">
            <div style="font-size: 2.5rem; margin-bottom: 0.75rem;">📭</div>
            <div style="font-size: 0.95rem; font-weight: 500; color: #64748b;">Belum ada transaksi</div>
            <div style="font-size: 0.82rem; margin-top: 0.25rem;">Transaksi Anda akan muncul di sini</div>
        </div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Jenis</th>
                    <th>Keterangan</th>
                    <th style="text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transaksiTerbaru as $trx)
                @php
                    // Arah mutasi mengikuti satu sumber kebenaran: Transaksi::isMasuk().
                    $isMasuk = $trx->isMasuk();
                @endphp
                <tr>
                    <td style="white-space: nowrap; color: #64748b; font-size: 0.82rem;">
                        {{ \Carbon\Carbon::parse($trx->waktu_transaksi)->format('d/m/Y') }}
                    </td>
                    <td>
                        <span class="badge {{ $isMasuk ? 'badge-green' : 'badge-red' }}">
                            {{ $trx->jenis_transaksi }}
                        </span>
                    </td>
                    <td style="color: #475569;">{{ $trx->catatan ?? '-' }}</td>
                    <td style="text-align: right; font-weight: 600; color: {{ $isMasuk ? '#16a34a' : '#dc2626' }};">
                        {{ $isMasuk ? '+' : '-' }}Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
