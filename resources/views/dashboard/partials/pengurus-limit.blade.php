{{-- Tabel: Limit transaksi per role --}}
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#128274; Limit Transaksi per Role</span>
        <span style="font-size:0.78rem;color:#94a3b8;">Sumber: tabel limit_transaksi_role (dapat diubah)</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Role</th>
                <th>Jenis Transaksi</th>
                <th>Limit per Transaksi</th>
                <th>Butuh Approval</th>
                <th>Approval Oleh</th>
            </tr>
        </thead>
        <tbody>
            @forelse($limitTransaksi as $l)
            <tr>
                <td>
                    <span class="badge {{ \App\Services\NavigationService::warnaRole($l->role) }}">
                        {{ \App\Support\HierarkiRole::label($l->role) }}
                    </span>
                </td>
                <td>{{ str_replace('_', ' ', ucfirst($l->jenis_transaksi)) }}</td>
                <td>
                    @if(is_null($l->limit_per_transaksi))
                        <span style="color:#059669;font-weight:600;">&#8734; Unlimited</span>
                    @elseif($l->limit_per_transaksi == 0)
                        <span style="color:#ef4444;font-weight:600;">&#128683; Dilarang</span>
                    @else
                        <strong>Rp {{ number_format($l->limit_per_transaksi, 0, ',', '.') }}</strong>
                    @endif
                </td>
                <td>
                    @if($l->butuh_approval)
                        <span class="badge badge-amber">Ya</span>
                    @else
                        <span class="badge badge-green">Tidak</span>
                    @endif
                </td>
                <td>{{ $l->approval_role ? \App\Support\HierarkiRole::label($l->approval_role) : '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada konfigurasi limit
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
