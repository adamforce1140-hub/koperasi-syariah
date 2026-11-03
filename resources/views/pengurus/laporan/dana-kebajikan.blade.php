@extends('layouts.app')
@section('page-title', "Dana Kebajikan")

@section('title', "Laporan Dana Kebajikan (PSAK 109)")

@section('sidebar')
<div class="nav-section">Laporan Keuangan</div>
<a href="{{ route('pengurus.laporan.index') }}" class="nav-item {{ request()->routeIs('pengurus.laporan.index') ? 'active' : '' }}">
    &#128202; Laporan Keuangan
</a>
<a href="{{ route('pengurus.laporan.dana-kebajikan') }}" class="nav-item active">
    &#127981; Dana Kebajikan (PSAK 109)
</a>
<div class="nav-section">Syariah</div>
<a href="{{ route('pengurus.laporan-syariah.index') }}" class="nav-item {{ request()->routeIs('pengurus.laporan-syariah.index') ? 'active' : '' }}">
    &#128220; Opini DPS
</a>
@endsection

@section('styles')
<style>
.stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:1.2rem 1.5rem; }
.stat-label { font-size:.8rem; color:#64748b; font-weight:500; text-transform:uppercase; letter-spacing:.05em; }
.stat-value { font-size:1.5rem; font-weight:700; margin-top:.25rem; }
.tazir-badge { background:#fef3c7; color:#92400e; padding:.2rem .7rem; border-radius:12px; font-size:.78rem; font-weight:600; }
</style>
@endsection

@section('content')
<div style="max-width:1100px;margin:0 auto;">

    {{-- Header --}}
    <div style="margin-bottom:1.5rem;">
        <h2 style="margin:0 0 .25rem;color:#1e3a5f;font-size:1.3rem;">&#127981; Laporan Sumber & Penyaluran Dana Kebajikan</h2>
        <p style="margin:0;color:#64748b;font-size:.9rem;">Sesuai PSAK 109 — Ta'zir dialokasikan ke Dana Kebajikan, tidak mempengaruhi SHU Koperasi.</p>
    </div>

    {{-- Filter Periode --}}
    <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end;margin-bottom:1.5rem;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;color:#374151;" for="dari">Dari Tanggal</label>
            <input id="dari" type="date" name="dari" value="{{ $dari }}"
                style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
        </div>
        <div>
            <label style="display:block;font-size:.85rem;font-weight:600;margin-bottom:.3rem;color:#374151;" for="sampai">Sampai Tanggal</label>
            <input id="sampai" type="date" name="sampai" value="{{ $sampai }}"
                style="padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;">
        </div>
        <button type="submit"
            style="padding:.5rem 1.2rem;background:#1d4ed8;color:#fff;border:none;border-radius:6px;font-size:.9rem;cursor:pointer;">
            &#128269; Filter
        </button>
        <a href="{{ route('pengurus.laporan.dana-kebajikan') }}"
            style="padding:.5rem 1rem;background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;border-radius:6px;font-size:.9rem;text-decoration:none;">
            Reset
        </a>
    </form>

    {{-- KPI Cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem;">
        <div class="stat-card">
            <div class="stat-label">Saldo Awal Periode</div>
            <div class="stat-value" style="color:#1e40af;">Rp {{ number_format($saldoAwal, 0, ',', '.') }}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Penerimaan</div>
            <div class="stat-value" style="color:#15803d;">Rp {{ number_format($totalPenerimaan, 0, ',', '.') }}</div>
            <div style="font-size:.8rem;color:#64748b;margin-top:.25rem;">Ta'zir + Infaq/Sedekah</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Penyaluran</div>
            <div class="stat-value" style="color:#dc2626;">Rp {{ number_format($totalPenyaluran, 0, ',', '.') }}</div>
        </div>
        <div class="stat-card" style="border-color:#a7f3d0;">
            <div class="stat-label">Saldo Akhir Periode</div>
            <div class="stat-value" style="color:#065f46;">Rp {{ number_format($saldoAkhir, 0, ',', '.') }}</div>
            <div style="font-size:.8rem;color:#64748b;margin-top:.25rem;">Akun 2-3001 Dana Kebajikan</div>
        </div>
    </div>

    {{-- PSAK 109 Note --}}
    <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:.85rem 1.1rem;margin-bottom:1.5rem;font-size:.87rem;color:#78350f;">
        <strong>&#9888; Catatan Syariah (PSAK 109):</strong>
        Dana kebajikan (termasuk ta'zir) adalah dana titipan sosial — <strong>bukan pendapatan operasional koperasi</strong>
        dan <strong>tidak mempengaruhi Sisa Hasil Usaha (SHU)</strong>.
        Tercatat di Neraca pada pos Kewajiban / Dana Sosial (Akun 2-3001).
    </div>

    {{-- Tabel Rincian --}}
    <div class="data-card">
        <div class="card-header">
            <span class="card-title">&#128196; Rincian Transaksi Dana Kebajikan</span>
            <span style="font-size:.85rem;color:#64748b;">
                Periode: {{ \Carbon\Carbon::parse($dari)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($sampai)->format('d/m/Y') }}
            </span>
        </div>

        @if($transaksi->isEmpty())
        <div style="padding:2rem;text-align:center;color:#94a3b8;">
            Tidak ada transaksi dana kebajikan pada periode ini.
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:left;white-space:nowrap;">Tanggal</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:left;white-space:nowrap;">No. Referensi</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:left;">Keterangan / Sumber</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:center;white-space:nowrap;">Jenis</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;white-space:nowrap;">Penerimaan (Rp)</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;white-space:nowrap;">Penyaluran (Rp)</th>
                        <th style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;white-space:nowrap;">Saldo (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    @php $saldoBerjalan = $saldoAwal; @endphp
                    <tr style="background:#f0f9ff;">
                        <td colspan="6" style="padding:.5rem .75rem;border:1px solid #e2e8f0;font-weight:600;color:#1e40af;">
                            Saldo Awal Periode
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:right;font-weight:700;color:#1e40af;">
                            {{ number_format($saldoAwal, 0, ',', '.') }}
                        </td>
                    </tr>
                    @foreach($transaksi as $row)
                    @php
                        if ($row->jenis === 'Penerimaan') {
                            $saldoBerjalan += $row->jumlah;
                        } else {
                            $saldoBerjalan -= $row->jumlah;
                        }
                        $isTazir = str_contains(strtolower($row->sumber_dana ?? ''), "ta'zir") ||
                                   str_contains(strtolower($row->keterangan ?? ''), 'denda');
                    @endphp
                    <tr style="{{ $loop->even ? 'background:#f8fafc;' : '' }}">
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;white-space:nowrap;">
                            {{ \Carbon\Carbon::parse($row->created_at)->format('d/m/Y') }}
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;font-family:monospace;font-size:.82rem;">
                            {{ $row->no_referensi ?? '-' }}
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;">
                            {{ $row->sumber_dana ?? $row->keterangan ?? '-' }}
                            @if($isTazir)
                            <span class="tazir-badge">Ta'zir</span>
                            @endif
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:center;">
                            @if($row->jenis === 'Penerimaan')
                            <span style="background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;">
                                Penerimaan
                            </span>
                            @else
                            <span style="background:#fee2e2;color:#991b1b;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;">
                                Penyaluran
                            </span>
                            @endif
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:right;color:#15803d;font-weight:{{ $row->jenis === 'Penerimaan' ? '600' : '400' }};">
                            {{ $row->jenis === 'Penerimaan' ? number_format($row->jumlah, 0, ',', '.') : '-' }}
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:right;color:#dc2626;font-weight:{{ $row->jenis !== 'Penerimaan' ? '600' : '400' }};">
                            {{ $row->jenis !== 'Penerimaan' ? number_format($row->jumlah, 0, ',', '.') : '-' }}
                        </td>
                        <td style="padding:.5rem .75rem;border:1px solid #e2e8f0;text-align:right;font-weight:600;color:{{ $saldoBerjalan >= 0 ? '#1e40af' : '#dc2626' }};">
                            {{ number_format($saldoBerjalan, 0, ',', '.') }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f0fdf4;font-weight:700;">
                        <td colspan="4" style="padding:.6rem .75rem;border:1px solid #e2e8f0;color:#065f46;">TOTAL PERIODE</td>
                        <td style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;color:#15803d;">
                            {{ number_format($totalPenerimaan, 0, ',', '.') }}
                        </td>
                        <td style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;color:#dc2626;">
                            {{ number_format($totalPenyaluran, 0, ',', '.') }}
                        </td>
                        <td style="padding:.6rem .75rem;border:1px solid #e2e8f0;text-align:right;color:#065f46;">
                            {{ number_format($saldoAkhir, 0, ',', '.') }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif
    </div>

    {{-- Ringkasan per Kategori --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.5rem;">
        <div class="data-card">
            <div class="card-header"><span class="card-title">&#8593; Sumber Penerimaan</span></div>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <tbody>
                    <tr>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;">Ta'zir (Denda Edukatif Anggota)</td>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#15803d;">
                            Rp {{ number_format($totalTazir, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;">Infaq / Sedekah Anggota</td>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#15803d;">
                            Rp {{ number_format($totalInfaq, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;">Lainnya</td>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#15803d;">
                            Rp {{ number_format(max(0, $totalPenerimaan - $totalTazir - $totalInfaq), 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr style="background:#f0fdf4;font-weight:700;">
                        <td style="padding:.5rem .75rem;">Total Penerimaan</td>
                        <td style="padding:.5rem .75rem;text-align:right;color:#15803d;">
                            Rp {{ number_format($totalPenerimaan, 0, ',', '.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="data-card">
            <div class="card-header"><span class="card-title">&#8595; Penyaluran Dana</span></div>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <tbody>
                    <tr>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;">Bantuan Musibah / Sosial</td>
                        <td style="padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:600;color:#dc2626;">
                            Rp {{ number_format($totalPenyaluran, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr style="background:#fff7f7;font-weight:700;">
                        <td style="padding:.5rem .75rem;">Total Penyaluran</td>
                        <td style="padding:.5rem .75rem;text-align:right;color:#dc2626;">
                            Rp {{ number_format($totalPenyaluran, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr style="background:#f0fdf4;font-weight:700;">
                        <td style="padding:.5rem .75rem;color:#065f46;">Saldo Akhir (Akun 2-3001)</td>
                        <td style="padding:.5rem .75rem;text-align:right;font-size:1rem;color:#065f46;">
                            Rp {{ number_format($saldoAkhir, 0, ',', '.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
