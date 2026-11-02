{{-- Pengajuan Pembiayaan Terbaru --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Pengajuan Pembiayaan Terbaru</span>
        <a href="{{ route('pengurus.pembiayaan.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua →
        </a>
    </div>
    @if($pengajuanTerbaru->count() > 0)
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Anggota</th>
                <th>Produk</th>
                <th>Jumlah</th>
                <th>Diproses Oleh</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($pengajuanTerbaru as $p)
            <tr>
                <td style="font-size:0.8rem;color:#64748b;">{{ $p->created_at->format('d/m/Y') }}</td>
                <td><strong>{{ $p->anggota?->nama_lengkap ?? '-' }}</strong></td>
                <td>{{ $p->akad_pembiayaan ?? '-' }}</td>
                <td style="font-weight:600;">Rp {{ number_format($p->jumlah_pembiayaan ?? $p->jumlah_pengajuan ?? 0, 0, ',', '.') }}</td>
                <td>
                    @if($p->approvedFinalBy || $p->approvedBy)
                        {{ $p->approvedFinalBy?->name ?? $p->approvedBy?->name }}
                    @else
                        <span style="color:#94a3b8;">Belum diproses</span>
                    @endif
                </td>
                <td>
                    @php $st = strtolower($p->status_pembiayaan ?? $p->status ?? ''); @endphp
                    @if(in_array($st, ['aktif', 'disetujui', 'disetujui_pengurus', 'disetujui_manajer', 'dicairkan'], true))
                        <span class="badge badge-green">Disetujui</span>
                    @elseif($st === \App\Models\Pembiayaan::STATUS_RESTRUKTURISASI)
                        <span class="badge badge-blue">Restrukturisasi</span>
                    @elseif($st === \App\Models\Pembiayaan::STATUS_PENDING_RESTRUKTURISASI)
                        <span class="badge badge-amber">Restrukturisasi</span>
                    @elseif(in_array($st, ['ditolak', 'dibatalkan'], true))
                        <span class="badge" style="background:#fee2e2;color:#dc2626;">Ditolak</span>
                    @elseif(in_array($st, ['direview', 'dikaji'], true))
                        <span class="badge badge-blue">Direview</span>
                    @else
                        <span class="badge badge-amber">Menunggu</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div>Belum ada pengajuan</div>
    </div>
    @endif
</div>
