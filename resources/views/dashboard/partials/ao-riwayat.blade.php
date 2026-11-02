{{-- Riwayat pengajuan milik AO (semua status) --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Pengajuan Saya</span>
        <a href="{{ route('ao.pengajuan.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">Lihat Semua →</a>
    </div>
    @if($pengajuanTerakhir->count() > 0)
    <table>
        <thead>
            <tr>
                <th>No. Pengajuan</th>
                <th>Anggota</th>
                <th>Jumlah</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pengajuanTerakhir as $p)
            <tr>
                <td style="font-family:monospace;font-size:0.8rem;">{{ $p->no_pengajuan }}</td>
                <td><strong>{{ $p->anggota?->nama_lengkap ?? '-' }}</strong></td>
                <td style="font-weight:600;">Rp {{ number_format($p->jumlah_pengajuan, 0, ',', '.') }}</td>
                <td>
                    @php $st = strtolower($p->status ?? ''); @endphp
                    @if(in_array($st, ['disetujui', 'approved']))
                        <span class="badge badge-green">Disetujui</span>
                    @elseif(in_array($st, ['ditolak', 'rejected']))
                        <span class="badge badge-red">Ditolak</span>
                    @elseif(in_array($st, ['direview']))
                        <span class="badge badge-blue">Direview</span>
                    @else
                        <span class="badge badge-amber">{{ $p->status }}</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada riwayat pengajuan</div>
    </div>
    @endif
</div>
