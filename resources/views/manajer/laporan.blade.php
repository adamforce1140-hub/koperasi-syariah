@extends('layouts.app')

@section('title', 'Laporan Keuangan — Manajer')
@section('page-title', 'Laporan Keuangan')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Laporan Keuangan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Ringkasan neraca, laba/rugi, dan arus bagi hasil koperasi.
            </p>
        </div>

        {{-- Filter Bulan/Tahun --}}
        <form method="GET" action="{{ route('manajer.laporan.index') }}"
              style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <select name="bulan"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                @foreach(range(1,12) as $m)
                <option value="{{ $m }}" {{ $bulan == $m ? 'selected' : '' }}>
                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                </option>
                @endforeach
            </select>
            <select name="tahun"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;">
                @foreach(range(now()->year - 2, now()->year) as $y)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
            <button type="submit"
                    style="padding:0.45rem 1rem;background:#047857;color:#fff;font-size:0.825rem;
                           font-weight:600;border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#065f46'" onmouseout="this.style.background='#047857'">
                Tampilkan
            </button>
        </form>
    </div>

    {{-- ── PERIOD LABEL ─────────────────────────────────── --}}
    <div style="font-size:0.875rem;color:#64748b;font-weight:500;">
        Periode:
        <strong style="color:#1e293b;">
            {{ \Carbon\Carbon::create()->month($bulan)->translatedFormat('F') }} {{ $tahun }}
        </strong>
    </div>

    {{-- ── STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        @php
        $cards = [
            ['label' => 'Total Transaksi',        'value' => number_format($totalTransaksi),                              'sub' => 'transaksi sukses',  'color' => '#2563eb', 'bg' => '#dbeafe', 'icon' => '🔄'],
            ['label' => 'Total Nominal Transaksi','value' => 'Rp ' . number_format($totalNominal, 0, ',', '.'),          'sub' => 'periode ini',       'color' => '#059669', 'bg' => '#d1fae5', 'icon' => '💰'],
            ['label' => 'Pembiayaan Cair',        'value' => 'Rp ' . number_format($totalPembiayaanCair, 0, ',', '.'),   'sub' => 'akad baru',         'color' => '#7c3aed', 'bg' => '#ede9fe', 'icon' => '📤'],
            ['label' => 'Angsuran Masuk',         'value' => 'Rp ' . number_format($totalAngsuranMasuk, 0, ',', '.'),    'sub' => 'angsuran lunas',    'color' => '#0891b2', 'bg' => '#cffafe', 'icon' => '📥'],
        ];
        @endphp
        @foreach($cards as $c)
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.375rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                <div style="width:38px;height:38px;border-radius:0.5rem;background:{{ $c['bg'] }};
                            display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;">
                    {{ $c['icon'] }}
                </div>
                <div style="font-size:0.775rem;color:#64748b;font-weight:500;">{{ $c['label'] }}</div>
            </div>
            <div style="font-size:1.3rem;font-weight:700;color:{{ $c['color'] }};line-height:1.2;">
                {{ $c['value'] }}
            </div>
            <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">{{ $c['sub'] }}</div>
        </div>
        @endforeach
    </div>

    {{-- ── RINCIAN PER JENIS TRANSAKSI ─────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Rincian per Jenis Transaksi</div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem;">
                Seluruh jenis transaksi pada periode ini, termasuk yang nominalnya kecil — agar tidak ada pencatatan yang terlewat.
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.835rem;">
                <thead>
                    <tr style="background:#f1f5f9;text-align:left;">
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Jenis Transaksi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Posisi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Jumlah</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rincianTransaksi as $r)
                    @php $debet = strtolower($r->posisi ?? '') === 'debet'; @endphp
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:0.7rem 1rem;color:#1e293b;font-weight:500;">{{ $r->jenis_transaksi }}</td>
                        <td style="padding:0.7rem 1rem;">
                            <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;font-size:0.7rem;font-weight:600;
                                         background:{{ $debet ? '#dbeafe' : '#fef3c7' }};color:{{ $debet ? '#1e40af' : '#92400e' }};">
                                {{ $r->posisi }}
                            </span>
                        </td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#475569;">{{ number_format($r->jumlah_transaksi) }} trx</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:600;color:{{ $debet ? '#2563eb' : '#b45309' }};">
                            {{-- Konvensi: Debet = uang masuk (+), Kredit = uang keluar (−). --}}
                            {{ $debet ? '+' : '−' }} Rp {{ number_format($r->total_nominal, 0, ',', '.') }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" style="text-align:center;padding:1.5rem;color:#94a3b8;">Tidak ada transaksi sukses pada periode ini.</td>
                    </tr>
                    @endforelse
                </tbody>
                @if($rincianTransaksi->isNotEmpty())
                <tfoot>
                    <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
                        <td colspan="2" style="padding:0.7rem 1rem;font-weight:700;color:#475569;">Total</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#475569;">{{ number_format($totalTransaksi) }} trx</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp {{ number_format($totalNominal, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
        @if($jumlahTransaksiBermasalah > 0)
        <div style="padding:0.85rem 1.5rem;background:#fffbeb;border-top:1px solid #fde68a;font-size:0.8rem;color:#92400e;">
            <strong>{{ $jumlahTransaksiBermasalah }} transaksi</strong> berstatus tidak sukses
            (Voided/Gagal/Dibatalkan) senilai Rp {{ number_format($nominalTransaksiBermasalah, 0, ',', '.') }}
            <strong>tidak dihitung</strong> dalam nominal laporan periode ini.
        </div>
        @endif
    </div>

    {{-- ── REKAP 12 BULAN ──────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Rekap Bulanan {{ $tahun }}</div>
            <div style="font-size:0.78rem;color:#64748b;margin-top:0.2rem;">
                Pencatatan seluruh bulan dalam satu tahun — bulan tanpa transaksi tetap ditampilkan sebagai 0.
            </div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.835rem;">
                <thead>
                    <tr style="background:#f1f5f9;text-align:left;">
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;">Bulan</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Transaksi</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Nominal</th>
                        <th style="padding:0.7rem 1rem;font-weight:600;color:#475569;font-size:0.78rem;text-transform:uppercase;letter-spacing:0.04em;text-align:right;">Angsuran Masuk</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rekapBulanan as $rb)
                    @php
                        $aktif   = $rb->bulan == $bulan;
                        $kosong  = $rb->jumlah === 0 && $rb->angsuran == 0;
                    @endphp
                    <tr style="border-top:1px solid #f1f5f9;
                               {{ $aktif ? 'background:#eff6ff;' : '' }}
                               {{ $kosong ? 'opacity:0.5;' : '' }}">
                        <td style="padding:0.7rem 1rem;font-weight:{{ $aktif ? '700' : '500' }};color:{{ $aktif ? '#1d4ed8' : '#1e293b' }};">
                            {{ $rb->label }}
                            @if($aktif)
                                <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                             font-size:0.7rem;font-weight:600;background:#dbeafe;color:#1d4ed8;">Periode ini</span>
                            @endif
                        </td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#475569;">{{ number_format($rb->jumlah) }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#059669;font-weight:600;">Rp {{ number_format($rb->nominal, 0, ',', '.') }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;color:#0891b2;font-weight:600;">Rp {{ number_format($rb->angsuran, 0, ',', '.') }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
                        <td style="padding:0.7rem 1rem;font-weight:700;color:#475569;">Total {{ $tahun }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#475569;">{{ number_format($rekapBulanan->sum('jumlah')) }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#059669;">Rp {{ number_format($rekapBulanan->sum('nominal'), 0, ',', '.') }}</td>
                        <td style="padding:0.7rem 1rem;text-align:right;font-weight:700;color:#0891b2;">Rp {{ number_format($rekapBulanan->sum('angsuran'), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ── INFO TAMBAHAN ────────────────────────────────── --}}
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem 1.5rem;">
        <div style="font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.75rem;
                    text-transform:uppercase;letter-spacing:0.05em;">
            Catatan Laporan
        </div>
        <ul style="margin:0;padding-left:1.25rem;color:#64748b;font-size:0.825rem;line-height:1.8;">
            <li>Angka dikelompokkan berdasarkan <strong>tanggal transaksi</strong>, bukan tanggal input data.</li>
            <li>Hanya transaksi berstatus <strong>Sukses</strong> yang dihitung; Voided/Gagal/Dibatalkan dikecualikan namun tetap ditampilkan jumlahnya.</li>
            <li>Angsuran masuk dihitung dari angsuran berstatus <strong>Sudah Dibayar</strong> sesuai tanggal bayar.</li>
            <li>Laporan neraca lengkap dapat diakses melalui modul akuntansi.</li>
            <li>Untuk ekspor laporan, hubungi administrator sistem.</li>
        </ul>
    </div>

</div>
@endsection
