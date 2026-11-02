@extends('layouts.app')

@section('title', 'Laporan Keuangan — Pengurus')
@section('page-title', 'Laporan Keuangan')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── 1. HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Laporan Keuangan
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Neraca, Laba/Rugi, dan PHU koperasi — ringkasan keuangan periode berjalan.
            </p>
        </div>
        {{-- Filter Periode --}}
        <form method="GET" action="{{ route('pengurus.laporan.index') }}"
              style="display:flex;gap:0.5rem;align-items:center;flex-shrink:0;flex-wrap:wrap;">
            @php
                $bulanList = ['Januari','Februari','Maret','April','Mei','Juni',
                              'Juli','Agustus','September','Oktober','November','Desember'];
            @endphp
            <select name="bulan"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                    onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                @foreach($bulanList as $i => $nama)
                <option value="{{ $i+1 }}" {{ $bulan == $i+1 ? 'selected' : '' }}>{{ $nama }}</option>
                @endforeach
            </select>
            <select name="tahun"
                    style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                           font-size:0.825rem;color:#1e293b;background:#fff;outline:none;"
                    onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                @for($y = now()->year; $y >= now()->year - 4; $y--)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            <button type="submit"
                    style="padding:0.45rem 1rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                           border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                Tampilkan
            </button>
            <a href="{{ route('pengurus.laporan.index') }}?bulan={{ $bulan }}&tahun={{ $tahun }}&export=pdf"
               style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.45rem 1rem;
                      background:#e11d48;color:#fff;font-size:0.825rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#be123c'" onmouseout="this.style.background='#e11d48'">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak PDF
            </a>
        </form>
    </div>

    {{-- ── 2. RINGKASAN KEUANGAN (4 KPI) ─────────────────── --}}
    @php
        $selisih = $totalPemasukan - $totalPengeluaran;
        $bulanNama = $bulanList[$bulan - 1];
    @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem;">

        {{-- Pemasukan --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid #059669;
                    border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    Total Pemasukan
                </span>
                <div style="width:32px;height:32px;border-radius:0.5rem;background:#d1fae5;
                            display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/>
                    </svg>
                </div>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#059669;">
                Rp {{ number_format($totalPemasukan, 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.25rem;">
                Setoran & angsuran {{ $bulanNama }} {{ $tahun }}
            </div>
        </div>

        {{-- Pengeluaran --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid #dc2626;
                    border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    Total Pengeluaran
                </span>
                <div style="width:32px;height:32px;border-radius:0.5rem;background:#fee2e2;
                            display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/>
                    </svg>
                </div>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#dc2626;">
                Rp {{ number_format($totalPengeluaran, 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.25rem;">
                Penarikan & pencairan {{ $bulanNama }} {{ $tahun }}
            </div>
        </div>

        {{-- Selisih / Laba-Rugi --}}
        <div style="background:#fff;border:1px solid #e2e8f0;
                    border-top:3px solid {{ $selisih >= 0 ? '#059669' : '#dc2626' }};
                    border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    Selisih Bersih
                </span>
                <div style="width:32px;height:32px;border-radius:0.5rem;
                            background:{{ $selisih >= 0 ? '#d1fae5' : '#fee2e2' }};
                            display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="{{ $selisih >= 0 ? '#059669' : '#dc2626' }}"
                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>
                        <polyline points="16 7 22 7 22 13"/>
                    </svg>
                </div>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:{{ $selisih >= 0 ? '#059669' : '#dc2626' }};">
                {{ $selisih >= 0 ? '+' : '' }}Rp {{ number_format(abs($selisih), 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.25rem;">
                {{ $selisih >= 0 ? 'Surplus' : 'Defisit' }} periode {{ $bulanNama }} {{ $tahun }}
            </div>
        </div>

        {{-- Total Simpanan (Aset) --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:3px solid #7c3aed;
                    border-radius:0.875rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                    Total Aset Simpanan
                </span>
                <div style="width:32px;height:32px;border-radius:0.5rem;background:#ede9fe;
                            display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="#7c3aed" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
            </div>
            <div style="font-size:1.4rem;font-weight:700;color:#7c3aed;">
                Rp {{ number_format($totalSimpanan, 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.25rem;">
                Pokok + Wajib + Sukarela
            </div>
        </div>
    </div>

    {{-- ── 3. POSISI PORTOFOLIO PEMBIAYAAN ───────────────── --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

        {{-- Portofolio Pembiayaan --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.875rem;font-weight:700;color:#1e293b;margin-bottom:1rem;padding-bottom:0.625rem;
                        border-bottom:1px solid #f1f5f9;">
                Portofolio Pembiayaan Aktif
            </div>
            <div style="display:flex;flex-direction:column;gap:0.75rem;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:0.825rem;color:#475569;">Total Plafond Aktif</span>
                    <span style="font-size:0.925rem;font-weight:700;color:#1e293b;">
                        Rp {{ number_format($totalPembiayaan, 0, ',', '.') }}
                    </span>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:0.825rem;color:#475569;">Rasio Pembiayaan / Simpanan</span>
                    @php $rasio = $totalSimpanan > 0 ? round(($totalPembiayaan / $totalSimpanan) * 100, 1) : 0; @endphp
                    <span style="font-size:0.925rem;font-weight:700;
                                 color:{{ $rasio > 80 ? '#dc2626' : '#059669' }};">
                        {{ $rasio }}%
                    </span>
                </div>
                <div style="height:8px;background:#f1f5f9;border-radius:9999px;overflow:hidden;margin-top:0.25rem;">
                    <div style="height:100%;width:{{ min($rasio, 100) }}%;
                                background:{{ $rasio > 80 ? '#dc2626' : '#059669' }};
                                border-radius:9999px;transition:width .4s;"></div>
                </div>
                <div style="font-size:0.75rem;color:#94a3b8;">
                    FDR (Financing to Deposit Ratio) — ideal ≤ 80%
                </div>
            </div>
        </div>

        {{-- Trend 12 Bulan --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.875rem;font-weight:700;color:#1e293b;margin-bottom:1rem;padding-bottom:0.625rem;
                        border-bottom:1px solid #f1f5f9;">
                Trend Transaksi 12 Bulan Terakhir
            </div>
            @if($trendBulanan->count() > 0)
            @php $maxVal = max($trendBulanan->max('pemasukan'), $trendBulanan->max('pengeluaran'), 1); @endphp
            <div style="display:flex;align-items:flex-end;gap:0.3rem;height:100px;">
                @foreach($trendBulanan as $t)
                @php
                    $hP = round(($t->pemasukan / $maxVal) * 90);
                    $hK = round(($t->pengeluaran / $maxVal) * 90);
                    $namaBulan = \Carbon\Carbon::createFromDate($t->tahun, $t->bulan, 1)->format('M');
                @endphp
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;">
                    <div style="width:100%;display:flex;gap:1px;align-items:flex-end;height:90px;">
                        <div style="flex:1;height:{{ $hP }}px;background:#059669;border-radius:2px 2px 0 0;
                                    min-height:2px;" title="Pemasukan: Rp {{ number_format($t->pemasukan,0,',','.') }}"></div>
                        <div style="flex:1;height:{{ $hK }}px;background:#dc2626;border-radius:2px 2px 0 0;
                                    min-height:2px;" title="Pengeluaran: Rp {{ number_format($t->pengeluaran,0,',','.') }}"></div>
                    </div>
                    <span style="font-size:0.6rem;color:#94a3b8;">{{ $namaBulan }}</span>
                </div>
                @endforeach
            </div>
            <div style="display:flex;gap:1rem;margin-top:0.75rem;">
                <span style="display:flex;align-items:center;gap:0.35rem;font-size:0.72rem;color:#475569;">
                    <span style="width:10px;height:10px;background:#059669;border-radius:2px;flex-shrink:0;"></span>
                    Pemasukan
                </span>
                <span style="display:flex;align-items:center;gap:0.35rem;font-size:0.72rem;color:#475569;">
                    <span style="width:10px;height:10px;background:#dc2626;border-radius:2px;flex-shrink:0;"></span>
                    Pengeluaran
                </span>
            </div>
            @else
            <div style="text-align:center;padding:2rem 0;color:#94a3b8;font-size:0.825rem;">
                Belum ada data transaksi untuk ditampilkan.
            </div>
            @endif
        </div>
    </div>

</div>
@endsection
