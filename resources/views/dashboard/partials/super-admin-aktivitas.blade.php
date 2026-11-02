{{-- Aktivitas transaksi terakhir (real-time) — pemantauan menyeluruh
     seluruh mutasi keuangan koperasi untuk kebutuhan audit Super Admin.
     Arah transaksi diklasifikasi lewat registri tunggal JenisTransaksi
     agar seluruh varian nama lama tetap terbaca. --}}
@php
    $masuk = \App\Support\JenisTransaksi::cashIn();
@endphp
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">🔄 Aktivitas Transaksi Terakhir</span>
        <span style="font-size:0.74rem;color:#94a3b8;">
            {{ number_format($totalTransaksiHariIni ?? 0, 0, ',', '.') }} transaksi hari ini
        </span>
    </div>

    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Anggota</th>
                    <th>Jenis</th>
                    <th>Posisi</th>
                    <th style="text-align:right;">Nominal</th>
                    <th>Petugas</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transaksiTerakhir as $t)
                    @php
                        $pertambah = in_array($t->jenis_transaksi, $masuk, true);
                        $nama = $t->anggota->nama ?? ($t->rekening->anggota->nama ?? '-');
                    @endphp
                    <tr>
                        <td style="color:#94a3b8;font-size:0.78rem;white-space:nowrap;">
                            {{ $t->waktu_transaksi ? \Carbon\Carbon::parse($t->waktu_transaksi)->format('d/m/Y H:i') : '-' }}
                        </td>
                        <td>{{ $nama }}</td>
                        <td>
                            <span class="badge {{ $pertambah ? 'badge-green' : 'badge-amber' }}">
                                {{ $t->jenis_transaksi }}
                            </span>
                        </td>
                        <td style="color:#64748b;font-size:0.78rem;">{{ $t->posisi ?? '-' }}</td>
                        <td style="text-align:right;font-weight:600;white-space:nowrap;">
                            Rp {{ number_format($t->jumlah ?? 0, 0, ',', '.') }}
                        </td>
                        <td style="color:#64748b;font-size:0.78rem;">{{ $t->user->name ?? 'Sistem' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                            Belum ada transaksi
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    </div>
</div>
