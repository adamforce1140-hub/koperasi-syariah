@extends('layouts.app')
@section('title', 'Buku Tabungan')
@section('page-title', 'Buku Tabungan')

@section('content')

<style>
    @media print {
        .no-print { display: none !important; }
        .card { border: 1px solid #cbd5e1 !important; }
        table { font-size: 0.78rem; }
    }
</style>

{{-- Tombol kembali --}}
<div class="no-print" style="margin-bottom: 1rem;">
    <a href="{{ route('nasabah.simpanan.index') }}"
       style="display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; color: #475569; text-decoration: none;">
        <span>←</span> Kembali ke Simpanan Saya
    </a>
</div>

{{-- Kop rekening --}}
<div style="background: linear-gradient(135deg, #065f46 0%, #059669 100%); border-radius: 1rem; padding: 1.75rem; margin-bottom: 1.5rem; color: white; box-shadow: 0 4px 16px rgba(5,150,105,0.25);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
        <div>
            <div style="font-size: 0.78rem; opacity: 0.85; margin-bottom: 0.3rem;">{{ config('app.name', 'Koperasi Syariah') }}</div>
            <div style="font-size: 1.25rem; font-weight: 700;">{{ $anggota->nama_lengkap }}</div>
            <div style="font-size: 0.82rem; opacity: 0.9; margin-top: 0.35rem;">
                {{ $anggota->no_anggota }} · {{ $rekening->no_rekening }} · {{ ucfirst($rekening->jenis_rekening ?? 'Simpanan') }}
            </div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 0.78rem; opacity: 0.85; margin-bottom: 0.3rem;">Saldo Saat Ini</div>
            <div style="font-size: 1.85rem; font-weight: 700; letter-spacing: -0.5px;">
                Rp {{ number_format($rekening->saldo, 0, ',', '.') }}
            </div>
        </div>
    </div>
</div>

{{-- Filter periode --}}
<div class="card no-print" style="margin-bottom: 1.5rem;">
    <div class="card-header" style="margin-bottom: 1rem;">
        <span class="card-title">Periode Buku Tabungan</span>
    </div>
    <form method="GET" action="{{ route('nasabah.buku-tabungan.index', $rekening) }}">
        <div style="display: grid; grid-template-columns: 1fr 1fr auto auto auto; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Dari Tanggal</label>
                <input type="date" name="dari" value="{{ request('dari') }}"
                    style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; outline: none; font-family: inherit;">
            </div>
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Sampai Tanggal</label>
                <input type="date" name="sampai" value="{{ request('sampai') }}"
                    style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; outline: none; font-family: inherit;">
            </div>
            <div style="padding-top: 1.45rem;">
                <button type="submit" class="btn btn-primary" style="white-space: nowrap; width: 100%; justify-content: center;">
                    Tampilkan
                </button>
            </div>
            <div style="padding-top: 1.45rem;">
                <a href="{{ route('nasabah.buku-tabungan.index', $rekening) }}"
                   style="display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; text-decoration: none; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; white-space: nowrap;">
                    Semua
                </a>
            </div>
            <div style="padding-top: 1.45rem;">
                <button type="button" onclick="window.print()"
                        style="display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer; background: #065f46; color: #fff; border: 1px solid #065f46; white-space: nowrap;">
                    🖨 Cetak
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Ringkasan periode --}}
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 1rem 1.25rem;">
        <div style="font-size: 0.72rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Saldo Awal</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #1e293b; margin-top: 0.25rem;">Rp {{ number_format($saldoAwal, 0, ',', '.') }}</div>
    </div>
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 0.75rem; padding: 1rem 1.25rem;">
        <div style="font-size: 0.72rem; color: #166534; text-transform: uppercase; letter-spacing: 0.05em;">Total Masuk</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #15803d; margin-top: 0.25rem;">+ Rp {{ number_format($totalMasuk, 0, ',', '.') }}</div>
    </div>
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 0.75rem; padding: 1rem 1.25rem;">
        <div style="font-size: 0.72rem; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em;">Total Keluar</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #b91c1c; margin-top: 0.25rem;">− Rp {{ number_format($totalKeluar, 0, ',', '.') }}</div>
    </div>
    <div style="background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 0.75rem; padding: 1rem 1.25rem;">
        <div style="font-size: 0.72rem; color: #065f46; text-transform: uppercase; letter-spacing: 0.05em;">Saldo Akhir</div>
        <div style="font-size: 1.2rem; font-weight: 700; color: #065f46; margin-top: 0.25rem;">Rp {{ number_format($saldoAkhir, 0, ',', '.') }}</div>
    </div>
</div>

{{-- Tabel buku tabungan --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Mutasi Rekening {{ $rekening->no_rekening }}</span>
        <span style="font-size: 0.8rem; color: #64748b;">{{ number_format(count($mutasi)) }} baris</span>
    </div>

    @if(empty($mutasi))
        <div style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">📖</div>
            <div style="font-size: 1rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem;">Belum ada mutasi</div>
            <div style="font-size: 0.85rem;">Tidak ada transaksi pada periode yang dipilih</div>
        </div>
    @else
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th style="text-align: right;">Debet (Masuk)</th>
                        <th style="text-align: right;">Kredit (Keluar)</th>
                        <th style="text-align: right;">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5" style="color: #64748b; font-size: 0.82rem; font-weight: 600;">Saldo awal periode</td>
                        <td style="text-align: right; font-weight: 700; color: #1e293b; white-space: nowrap;">
                            Rp {{ number_format($saldoAwal, 0, ',', '.') }}
                        </td>
                    </tr>
                    @foreach($mutasi as $i => $baris)
                    <tr>
                        <td style="color: #94a3b8; font-size: 0.8rem;">{{ $i + 1 }}</td>
                        <td style="white-space: nowrap; color: #64748b; font-size: 0.82rem;">
                            {{ $baris['waktu']->format('d/m/Y') }}
                            <div style="font-size: 0.72rem; color: #94a3b8;">{{ $baris['waktu']->format('H:i') }}</div>
                        </td>
                        <td style="color: #475569;">
                            <div>{{ $baris['jenis'] ?? '-' }}</div>
                            @if(filled($baris['catatan']))
                                <div style="font-size: 0.75rem; color: #94a3b8;">{{ $baris['catatan'] }}</div>
                            @endif
                        </td>
                        <td style="text-align: right; font-weight: 600; white-space: nowrap; color: {{ $baris['masuk'] ? '#16a34a' : '#cbd5e1' }};">
                            {{ $baris['masuk'] ? '+ Rp ' . number_format($baris['jumlah'], 0, ',', '.') : '—' }}
                        </td>
                        <td style="text-align: right; font-weight: 600; white-space: nowrap; color: {{ $baris['masuk'] ? '#cbd5e1' : '#dc2626' }};">
                            {{ $baris['masuk'] ? '—' : '- Rp ' . number_format($baris['jumlah'], 0, ',', '.') }}
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #1e293b; white-space: nowrap;">
                            Rp {{ number_format($baris['saldo_akhir'], 0, ',', '.') }}
                        </td>
                    </tr>
                    @endforeach
                    <tr>
                        <td colspan="5" style="color: #065f46; font-size: 0.82rem; font-weight: 700;">Saldo akhir periode</td>
                        <td style="text-align: right; font-weight: 800; color: #065f46; white-space: nowrap;">
                            Rp {{ number_format($saldoAkhir, 0, ',', '.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</div>

@endsection
