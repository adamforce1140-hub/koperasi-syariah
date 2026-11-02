@extends('layouts.app')
@section('title', 'Mutasi Transaksi')
@section('page-title', 'Riwayat Mutasi Transaksi')
@section('content')

{{-- Filter Bar --}}
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header" style="margin-bottom: 1rem;">
        <span class="card-title">🔍 Filter Transaksi</span>
    </div>
    <form method="GET" action="{{ route('nasabah.mutasi.index') }}">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto auto; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap;">

            {{-- Rekening --}}
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Rekening</label>
                <select name="rekening_id" style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; background: white; outline: none; font-family: inherit;">
                    <option value="">Semua Rekening</option>
                    @foreach($rekening as $rek)
                        <option value="{{ $rek->id }}" {{ request('rekening_id') == $rek->id ? 'selected' : '' }}>
                            {{ $rek->no_rekening }} — {{ ucfirst($rek->jenis_rekening ?? $rek->jenis_simpanan ?? '') }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Dari --}}
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Dari</label>
                <input type="date" name="dari" value="{{ request('dari') }}"
                    style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; outline: none; font-family: inherit;">
            </div>

            {{-- Sampai --}}
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Sampai</label>
                <input type="date" name="sampai" value="{{ request('sampai') }}"
                    style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; outline: none; font-family: inherit;">
            </div>

            {{-- Posisi --}}
            <div>
                <label style="display: block; font-size: 0.78rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em;">Posisi</label>
                <select name="posisi" style="width: 100%; padding: 0.5rem 0.75rem; border: 1.5px solid #e2e8f0; border-radius: 0.5rem; font-size: 0.875rem; color: #334155; background: white; outline: none; font-family: inherit;">
                    <option value="">Semua</option>
                    <option value="debet"  {{ request('posisi') === 'debet'  ? 'selected' : '' }}>Debet (Mutasi Masuk)</option>
                    <option value="kredit" {{ request('posisi') === 'kredit' ? 'selected' : '' }}>Kredit (Mutasi Keluar)</option>
                </select>
            </div>

            {{-- Tombol Filter --}}
            <div style="padding-top: 1.45rem;">
                <button type="submit" class="btn btn-primary" style="white-space: nowrap; width: 100%; justify-content: center;">
                    Filter
                </button>
            </div>

            {{-- Tombol Reset --}}
            <div style="padding-top: 1.45rem;">
                <a href="{{ route('nasabah.mutasi.index') }}" style="display: inline-flex; align-items: center; justify-content: center; width: 100%; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500; text-decoration: none; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; white-space: nowrap;">
                    Reset
                </a>
            </div>
        </div>
    </form>
</div>

{{-- Summary Pills --}}
<div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
    <div style="display: inline-flex; align-items: center; gap: 0.6rem; background: #dcfce7; border: 1px solid #bbf7d0; border-radius: 9999px; padding: 0.5rem 1.25rem;">
        <span style="width: 8px; height: 8px; background: #16a34a; border-radius: 50%; display: inline-block;"></span>
        <span style="font-size: 0.82rem; font-weight: 600; color: #166534;">Total Masuk</span>
        <span style="font-size: 0.95rem; font-weight: 700; color: #15803d;">Rp {{ number_format($totalMasuk, 0, ',', '.') }}</span>
    </div>
    <div style="display: inline-flex; align-items: center; gap: 0.6rem; background: #fee2e2; border: 1px solid #fecaca; border-radius: 9999px; padding: 0.5rem 1.25rem;">
        <span style="width: 8px; height: 8px; background: #dc2626; border-radius: 50%; display: inline-block;"></span>
        <span style="font-size: 0.82rem; font-weight: 600; color: #991b1b;">Total Keluar</span>
        <span style="font-size: 0.95rem; font-weight: 700; color: #b91c1c;">Rp {{ number_format($totalKeluar, 0, ',', '.') }}</span>
    </div>
</div>

{{-- Tabel Mutasi --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">Detail Mutasi</span>
        @if($transaksi->total() > 0)
            <span style="font-size: 0.8rem; color: #64748b;">{{ number_format($transaksi->total()) }} transaksi ditemukan</span>
        @endif
    </div>

    @if($transaksi->isEmpty())
        <div style="text-align: center; padding: 3.5rem 1rem; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 1rem;">📭</div>
            <div style="font-size: 1rem; font-weight: 600; color: #64748b; margin-bottom: 0.35rem;">Tidak ada transaksi</div>
            <div style="font-size: 0.85rem;">Coba ubah filter atau pilih rentang tanggal yang berbeda</div>
        </div>
    @else
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">No</th>
                        <th>Tanggal</th>
                        <th>No Rekening</th>
                        <th>Jenis Transaksi</th>
                        <th>Keterangan</th>
                        <th style="text-align: right;">Debet</th>
                        <th style="text-align: right;">Kredit</th>
                        <th style="text-align: right;">Saldo Akhir</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transaksi as $i => $trx)
                    @php
                        // Arah mutasi mengikuti satu sumber kebenaran: Transaksi::isMasuk().
                        $isKredit = $trx->isKeluar();
                    @endphp
                    <tr>
                        <td style="color: #94a3b8; font-size: 0.8rem;">
                            {{ $transaksi->firstItem() + $i }}
                        </td>
                        <td style="white-space: nowrap; color: #64748b; font-size: 0.82rem;">
                            {{ \Carbon\Carbon::parse($trx->waktu_transaksi)->format('d/m/Y') }}
                            <div style="font-size: 0.72rem; color: #94a3b8;">{{ \Carbon\Carbon::parse($trx->waktu_transaksi)->format('H:i') }}</div>
                        </td>
                        <td style="font-size: 0.82rem; color: #475569; white-space: nowrap;">
                            {{ $trx->no_rekening ?? ($trx->rekening->no_rekening ?? '-') }}
                        </td>
                        <td>
                            <span class="badge {{ $isKredit ? 'badge-red' : 'badge-green' }}">
                                {{ $isKredit ? 'Kredit (Mutasi Keluar)' : 'Debet (Mutasi Masuk)' }}
                            </span>
                            @if(isset($trx->jenis_transaksi) && !in_array(strtolower($trx->jenis_transaksi), ['kredit','debet']))
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem;">{{ $trx->jenis_transaksi }}</div>
                            @endif
                        </td>
                        <td style="color: #475569; max-width: 220px;">
                            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                {{ $trx->catatan ?? '-' }}
                            </div>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: {{ $isKredit ? '#94a3b8' : '#16a34a' }}; white-space: nowrap;">
                            @if(!$isKredit)
                                + Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                            @else
                                <span style="color: #cbd5e1;">—</span>
                            @endif
                        </td>
                        <td style="text-align: right; font-weight: 600; color: {{ $isKredit ? '#dc2626' : '#94a3b8' }}; white-space: nowrap;">
                            @if($isKredit)
                                - Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                            @else
                                <span style="color: #cbd5e1;">—</span>
                            @endif
                        </td>
                        <td style="text-align: right; font-weight: 700; color: #1e293b; white-space: nowrap;">
                            {{-- `saldo_setelah` boleh NULL pada transaksi lama; jangan tampilkan "Rp 0"
                                 seolah saldo benar-benar nol. Buku tabungan per rekening menghitung
                                 saldo berjalan sendiri, lihat NasabahController::bukuTabungan(). --}}
                            @if($trx->saldo_setelah === null)
                                <span style="color: #cbd5e1;">—</span>
                            @else
                                Rp {{ number_format($trx->saldo_setelah, 0, ',', '.') }}
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($transaksi->hasPages())
        <div style="margin-top: 1.5rem; display: flex; justify-content: center;">
            {{ $transaksi->appends(request()->except('page'))->links() }}
        </div>
        @endif
    @endif
</div>

@endsection
