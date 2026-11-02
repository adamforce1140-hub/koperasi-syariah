{{-- Sampling Pembiayaan Aktif --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Sampling Pembiayaan Aktif</span>
        <a href="{{ route('dps.audit-akad') }}" style="font-size:0.78rem;color:#7c3aed;text-decoration:none;">Lihat Semua →</a>
    </div>
    @if($pembiayaanAktif->count() > 0)
    <table>
        <thead>
            <tr>
                <th>Anggota</th>
                <th>Akad</th>
                <th>Pokok</th>
                <th>Margin / Nisbah</th>
                <th>Tenor</th>
                <th>Disetujui Oleh</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pembiayaanAktif as $pb)
            <tr>
                <td><strong>{{ $pb->anggota?->nama_lengkap ?? '-' }}</strong></td>
                <td>
                    <span class="badge badge-blue" style="font-size:0.72rem;">{{ $pb->akad_pembiayaan ?? '-' }}</span>
                </td>
                <td>Rp {{ number_format($pb->jumlah_pembiayaan ?? 0, 0, ',', '.') }}</td>
                <td>{{ $pb->margin_nisbah ?? '-' }}%</td>
                <td>{{ $pb->tenor ?? '-' }} bln</td>
                <td style="font-size:0.85rem;">
                    @if($pb->approvedBy)
                        {{ $pb->approvedBy->name }}
                        <div style="font-size:0.72rem;color:#64748b;">{{ ucfirst($pb->approvedBy->role) }}</div>
                    @else
                        <span style="color:#94a3b8;">—</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Tidak ada pembiayaan aktif</div>
    </div>
    @endif
</div>
