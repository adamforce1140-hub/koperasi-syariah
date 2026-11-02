@php
    // Klasifikasi arah transaksi memakai registri tunggal App\Support\JenisTransaksi
    // (bukan daftar literal hardcode) agar seluruh varian nama lama tetap terbaca.
    $jenisMasuk = \App\Support\JenisTransaksi::cashIn();
@endphp
{{-- Tabel: Transaksi terakhir --}}
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">&#8644; Transaksi Terakhir</span>
        <a href="{{ route('pengurus.kas.index') }}" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.9rem;">
            Lihat Semua &rarr;
        </a>
    </div>
    <table>
        <thead>
            <tr>
                <th>Anggota</th>
                <th>Jenis</th>
                <th>Nominal</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transaksiTerakhir as $t)
            <tr>
                <td>{{ $t->namaAnggota ?? '-' }}</td>
                <td>
                    <span class="badge {{ in_array($t->jenis_transaksi, $jenisMasuk, true) ? 'badge-green' : 'badge-amber' }}">
                        {{ $t->jenis_transaksi }}
                    </span>
                </td>
                <td>Rp {{ number_format($t->jumlah ?? 0, 0, ',', '.') }}</td>
                <td style="color:#94a3b8;font-size:0.8rem;">
                    {{ \Carbon\Carbon::parse($t->waktu_transaksi)->format('d/m/Y') }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="4" style="text-align:center;color:#94a3b8;padding:1.5rem;">
                    Belum ada transaksi
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
