@extends('layouts.app')
@section('page-title', "Detail Nasabah")

@section('title', 'Detail Nasabah')

@section('content')
<div class="page-header">
    <h1 class="page-title">{{ $anggota->nama_lengkap }}</h1>
    <p class="page-subtitle">{{ $anggota->no_anggota }}</p>
</div>

<div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;">

    {{-- Profil --}}
    <div>
        <div class="data-card" style="margin-bottom:1rem;">
            <div class="card-header"><span class="card-title">Profil Anggota</span></div>
            <div style="padding:1.5rem;">
                <table style="width:100%;border-collapse:collapse;">
                    @php
                        $rows = [
                            'NIK'           => $anggota->nik ?? '-',
                            'Jenis Kelamin' => $anggota->jenis_kelamin ?? '-',
                            'Pekerjaan'     => $anggota->pekerjaan ?? '-',
                            'No. HP'        => $anggota->no_hp ?? '-',
                            'Email'         => $anggota->email ?? '-',
                            'Alamat'        => $anggota->alamat ?? '-',
                            'Tanggal Gabung'=> $anggota->tanggal_gabung?->format('d/m/Y') ?? '-',
                            'Status'        => $anggota->status_label,
                        ];
                    @endphp
                    @foreach($rows as $label => $val)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:.5rem 0;font-size:.8rem;color:#6b7280;width:120px;">{{ $label }}</td>
                        <td style="padding:.5rem 0;font-size:.85rem;">{{ $val }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>

        <div class="data-card">
            <div class="card-header"><span class="card-title">Ringkasan Pembiayaan</span></div>
            <div style="padding:1.5rem;">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;text-align:center;">
                    <div style="padding:.8rem;background:#f0fdf4;border-radius:8px;">
                        <div style="font-size:1.2rem;font-weight:700;color:#166534;">Rp {{ number_format($totalPlafonAktif, 0, ',', '.') }}</div>
                        <div style="font-size:.75rem;color:#6b7280;">Total Plafon Aktif</div>
                    </div>
                    <div style="padding:.8rem;background:#fff7ed;border-radius:8px;">
                        <div style="font-size:1.2rem;font-weight:700;color:#c2410c;">Rp {{ number_format($totalSisaPembiayaan, 0, ',', '.') }}</div>
                        <div style="font-size:.75rem;color:#6b7280;">Sisa Pembiayaan</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Riwayat --}}
    <div>
        <div class="data-card">
            <div class="card-header"><span class="card-title">Riwayat Pembiayaan</span></div>
            <table>
                <thead>
                    <tr>
                        <th>No. Pembiayaan</th>
                        <th>Akad</th>
                        <th>Jumlah</th>
                        <th>Tenor</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Tanggal Cair</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($riwayatPembiayaan as $p)
                    <tr>
                        <td><strong>{{ $p->no_pembiayaan }}</strong></td>
                        <td>{{ $p->akad_pembiayaan ?? '-' }}</td>
                        <td>Rp {{ number_format($p->jumlah_pembiayaan, 0, ',', '.') }}</td>
                        <td>{{ $p->tenor }} bln</td>
                        <td>Rp {{ number_format($p->sisa_pembiayaan ?? 0, 0, ',', '.') }}</td>
                        <td>
                            <span class="badge {{ $p->isStatus([\App\Models\Pembiayaan::STATUS_AKTIF, \App\Models\Pembiayaan::STATUS_DICAIRKAN]) ? 'badge-green' : 'badge-gray' }}">
                                {{ $p->status_pembiayaan }}
                            </span>
                        </td>
                        <td>{{ $p->tanggal_cair?->format('d/m/Y') ?? '-' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat pembiayaan</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Riwayat Transaksi --}}
<div class="data-card" style="margin-top:1.5rem;">
    <div class="card-header"><span class="card-title">Riwayat Transaksi (20 Terakhir)</span></div>
    <table>
        <thead>
            <tr>
                <th>No. Transaksi</th>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Posisi</th>
                <th>Jumlah</th>
                <th>Saldo Setelah</th>
                <th>Status</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($riwayatTransaksi as $trx)
            @php $isMasuk = $trx->isMasuk(); @endphp
            <tr>
                <td style="font-size:.8rem;font-family:monospace;">{{ $trx->no_transaksi }}</td>
                <td style="font-size:.8rem;">{{ $trx->waktu_transaksi?->format('d/m/Y H:i') }}</td>
                <td>{{ $trx->jenis_transaksi }}</td>
                <td>
                    <span class="badge {{ $isMasuk ? 'badge-green' : 'badge-red' }}">
                        {{ $trx->posisi }}
                    </span>
                </td>
                <td style="text-align:right;font-weight:600;color:{{ $isMasuk ? '#059669' : '#dc2626' }};">
                    {{-- Arah mutasi mengikuti Transaksi::isMasuk(). --}}
                    {{ $isMasuk ? '+' : '-' }} Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                </td>
                <td style="text-align:right;">Rp {{ number_format($trx->saldo_setelah ?? 0, 0, ',', '.') }}</td>
                <td>
                    <span class="badge {{ $trx->status_transaksi === 'Sukses' ? 'badge-green' : ($trx->status_transaksi === 'Voided' ? 'badge-red' : 'badge-yellow') }}">
                        {{ $trx->status_transaksi }}
                    </span>
                </td>
                <td style="font-size:.8rem;color:#6b7280;">{{ $trx->catatan ?? '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Belum ada riwayat transaksi</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:1rem;">
    <a href="{{ route('ao.nasabah.index') }}" class="btn btn-secondary">Kembali</a>
</div>
@endsection
