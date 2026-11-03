@extends('layouts.app')

@section('title', 'Setoran Angsuran — Pengurus')
@section('page-title', 'Setoran Angsuran')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── 1. HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Setoran Angsuran
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Pemantauan tagihan & detail cicilan seluruh pembiayaan aktif.
            </p>
        </div>
        <a href="{{ route('pengurus.angsuran.index') }}?export=excel"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                  background:#059669;color:#fff;font-size:0.8rem;font-weight:600;
                  border-radius:0.5rem;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12);"
           onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
            </svg>
            Export Excel
        </a>
    </div>

    {{-- ── 2. STAT CARDS ──────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">

        {{-- Pembiayaan Aktif --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#1e293b;line-height:1.1;">{{ number_format($totalAktif) }}</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Pembiayaan Aktif</div>
            </div>
        </div>

        {{-- Total Sisa Tagihan --}}
        <div style="background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;border-radius:0.875rem;
                    padding:1.125rem 1.25rem;display:flex;align-items:center;gap:1rem;
                    box-shadow:0 2px 8px rgba(220,38,38,.3);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:rgba(255,255,255,.2);
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"/>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.1rem;font-weight:700;line-height:1.1;">
                    Rp {{ number_format($totalSisaTagihan, 0, ',', '.') }}
                </div>
                <div style="font-size:0.75rem;opacity:0.85;margin-top:0.125rem;">Total Sisa Tagihan</div>
            </div>
        </div>

        {{-- Cicilan Belum Dibayar --}}
        <div style="background:#fff;border:1px solid #fde68a;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#fef3c7;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#d97706;line-height:1.1;">{{ number_format($totalAngsuranBelum) }}</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Cicilan Belum Bayar</div>
            </div>
        </div>

        {{-- Cicilan Terlambat --}}
        <div style="background:#fff;border:1px solid #fca5a5;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#fee2e2;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#dc2626;line-height:1.1;">{{ number_format($totalAngsuranTerlambat) }}</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Cicilan Terlambat</div>
            </div>
        </div>

        {{-- Cicilan Sudah Lunas --}}
        <div style="background:#fff;border:1px solid #a7f3d0;border-radius:0.875rem;padding:1.125rem 1.25rem;
                    display:flex;align-items:center;gap:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="width:42px;height:42px;border-radius:0.625rem;background:#d1fae5;
                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                     fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div>
                <div style="font-size:1.5rem;font-weight:700;color:#059669;line-height:1.1;">{{ number_format($totalAngsuranLunas) }}</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.125rem;">Cicilan Lunas</div>
            </div>
        </div>
    </div>

    {{-- ── 3. TAB AKAD ─────────────────────────────────────── --}}
    @php
        $akadColors = [
            ''           => ['color' => '#7c3aed', 'bg' => '#ede9fe'],
            'Murabahah'  => ['color' => '#2563eb', 'bg' => '#dbeafe'],
            'Mudharabah' => ['color' => '#059669', 'bg' => '#d1fae5'],
            'Musyarakah' => ['color' => '#d97706', 'bg' => '#fef3c7'],
            'Ijarah'     => ['color' => '#0891b2', 'bg' => '#cffafe'],
            'Ijarah Muntahia Bittamlik' => ['color' => '#0d9488', 'bg' => '#ccfbf1'],
            'Istishna'   => ['color' => '#7c3aed', 'bg' => '#ede9fe'],
            'Salam'      => ['color' => '#be185d', 'bg' => '#fce7f3'],
            'Qardh'      => ['color' => '#dc2626', 'bg' => '#fee2e2'],
        ];
    @endphp
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
        @php
            $akadTabs = array_merge(['' => 'Semua Akad'], array_combine($akadList, $akadList));
        @endphp
        @foreach($akadTabs as $key => $label)
        @php
            $isActive = $akadFilter === $key || ($key === '' && !$akadFilter);
            $c = $akadColors[$key] ?? $akadColors[''];
            $params = array_filter(['search' => $search, 'akad' => $key ?: null, 'status' => $statusFilter ?: null]);
            $url = route('pengurus.angsuran.index') . ($params ? '?' . http_build_query($params) : '');
        @endphp
        <a href="{{ $url }}"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.875rem;
                  font-size:0.8rem;font-weight:600;border-radius:9999px;text-decoration:none;
                  border:1.5px solid {{ $isActive ? $c['color'] : '#e2e8f0' }};
                  background:{{ $isActive ? $c['color'] : '#fff' }};
                  color:{{ $isActive ? '#fff' : '#475569' }};">
            {{ $label }}
        </a>
        @endforeach
    </div>

    {{-- ── 4. FILTER BAR ──────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <form method="GET" action="{{ route('pengurus.angsuran.index') }}"
              style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="akad" value="{{ $akadFilter }}">
            <div style="flex:1;min-width:220px;">
                <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Cari Pembiayaan
                </label>
                <div style="position:relative;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                         fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" name="search" value="{{ $search }}" aria-label="Cari no. pembiayaan atau nama nasabah" placeholder="No. Pembiayaan atau nama nasabah…"
                           style="width:100%;padding:0.5rem 0.75rem 0.5rem 2rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div style="min-width:160px;">
                <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                    Status Cicilan
                </label>
                <select name="status"
                        style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;
                               font-size:0.825rem;color:#1e293b;outline:none;background:#fff;"
                        onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    <option value="">Semua Status</option>
                    <option value="belum" {{ $statusFilter === 'belum' ? 'selected' : '' }}>Belum Dibayar</option>
                    <option value="terlambat" {{ $statusFilter === 'terlambat' ? 'selected' : '' }}>Terlambat</option>
                    <option value="lunas" {{ $statusFilter === 'lunas' ? 'selected' : '' }}>Lunas</option>
                </select>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <button type="submit"
                        style="padding:0.5rem 1.125rem;background:#059669;color:#fff;font-size:0.825rem;font-weight:600;
                               border:none;border-radius:0.5rem;cursor:pointer;"
                        onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                    Cari
                </button>
                @if($search || $akadFilter || $statusFilter)
                <a href="{{ route('pengurus.angsuran.index') }}"
                   style="display:inline-flex;align-items:center;padding:0.5rem 0.875rem;background:#f1f5f9;
                          color:#64748b;font-size:0.825rem;font-weight:600;border-radius:0.5rem;text-decoration:none;">
                    Reset
                </a>
                @endif
            </div>
        </form>
    </div>

    {{-- ── 5. TABEL ────────────────────────────────────────── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 3px rgba(0,0,0,.05);overflow:hidden;">
        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;
                    display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <span style="font-size:0.825rem;color:#64748b;">
                <strong style="color:#1e293b;">{{ $pembiayaan->total() }}</strong> pembiayaan ditemukan
            </span>
            <span style="font-size:0.75rem;color:#94a3b8;">Hal. {{ $pembiayaan->currentPage() }} / {{ $pembiayaan->lastPage() }}</span>
        </div>

        @if($pembiayaan->count() > 0)
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1.25rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">No. Pembiayaan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nasabah</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Plafond</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Progress Cicilan</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Sisa Tagihan</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Jatuh Tempo</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.75rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pembiayaan as $p)
                    @php
                        $ac = $akadColors[$p->akad_pembiayaan] ?? $akadColors[''];
                        $overdue = $p->tanggal_jatuh_tempo && \Carbon\Carbon::parse($p->tanggal_jatuh_tempo)->isPast();
                        $allAngsuran  = $p->angsuran;
                        $totalCicilan = $allAngsuran->count();
                        $sudahBayar   = $allAngsuran->filter(fn($a) => $a->status_dinamis === 'Sudah Dibayar')->count();
                        $terlambat    = $allAngsuran->filter(fn($a) => $a->status_dinamis === 'Terlambat')->count();
                        $belumBayar   = $allAngsuran->filter(fn($a) => in_array($a->status_dinamis, ['Belum Jatuh Tempo', 'Jatuh Tempo Hari Ini']))->count();
                        $pct = $totalCicilan > 0 ? round(($sudahBayar / $totalCicilan) * 100) : 0;
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">

                        {{-- No Pembiayaan --}}
                        <td style="padding:0.875rem 1.25rem;">
                            <span style="font-family:monospace;font-size:0.8rem;font-weight:600;
                                         color:#7c3aed;background:#ede9fe;padding:0.2rem 0.5rem;border-radius:0.375rem;">
                                {{ $p->no_pembiayaan ?? '—' }}
                            </span>
                            @if($p->isStatus(\App\Models\Pembiayaan::STATUS_LUNAS))
                            <div style="margin-top:0.25rem;">
                                <span style="font-size:0.7rem;background:#d1fae5;color:#059669;padding:0.1rem 0.4rem;border-radius:9999px;font-weight:600;">Lunas</span>
                            </div>
                            @endif
                        </td>

                        {{-- Nasabah --}}
                        <td style="padding:0.875rem 1rem;">
                            <div style="font-size:0.875rem;font-weight:600;color:#1e293b;">
                                {{ $p->anggota?->nama_lengkap ?? '—' }}
                            </div>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $p->anggota?->no_anggota ?? '' }}</div>
                        </td>

                        {{-- Akad --}}
                        <td style="padding:0.875rem 1rem;">
                            <span style="display:inline-flex;align-items:center;padding:0.2rem 0.65rem;
                                         background:{{ $ac['bg'] }};color:{{ $ac['color'] }};
                                         font-size:0.75rem;font-weight:600;border-radius:9999px;">
                                {{ $p->akad_pembiayaan ?? '—' }}
                            </span>
                        </td>

                        {{-- Plafond --}}
                        <td style="padding:0.875rem 1rem;text-align:right;font-size:0.85rem;font-weight:600;color:#1e293b;">
                            Rp {{ number_format($p->jumlah_pembiayaan ?? 0, 0, ',', '.') }}
                        </td>

                        {{-- Progress Cicilan --}}
                        <td style="padding:0.875rem 1rem;text-align:center;min-width:160px;">
                            @if($totalCicilan > 0)
                            <div style="font-size:0.78rem;color:#475569;margin-bottom:0.3rem;">
                                <strong style="color:#059669;">{{ $sudahBayar }}</strong>/{{ $totalCicilan }} cicilan
                                @if($terlambat > 0)
                                    &nbsp;<span style="color:#dc2626;font-weight:600;">• {{ $terlambat }} terlambat</span>
                                @endif
                                @if($belumBayar > 0)
                                    &nbsp;<span style="color:#d97706;font-weight:600;">• {{ $belumBayar }} belum</span>
                                @endif
                            </div>
                            <div style="background:#e2e8f0;border-radius:9999px;height:6px;overflow:hidden;">
                                <div style="height:100%;width:{{ $pct }}%;border-radius:9999px;
                                            background:{{ $terlambat > 0 ? '#dc2626' : '#059669' }};
                                            transition:width .3s;"></div>
                            </div>
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">{{ $pct }}%</div>
                            @else
                            <span style="font-size:0.78rem;color:#94a3b8;">Belum ada jadwal</span>
                            @endif
                        </td>

                        {{-- Sisa Tagihan --}}
                        <td style="padding:0.875rem 1rem;text-align:right;">
                            <span style="font-size:0.875rem;font-weight:700;color:{{ $overdue ? '#dc2626' : '#059669' }};">
                                Rp {{ number_format($p->sisa_pembiayaan ?? 0, 0, ',', '.') }}
                            </span>
                        </td>

                        {{-- Jatuh Tempo --}}
                        <td style="padding:0.875rem 1rem;">
                            @if($p->tanggal_jatuh_tempo)
                                <div style="font-size:0.825rem;color:{{ $overdue ? '#dc2626' : '#475569' }};font-weight:{{ $overdue ? '600' : '400' }};">
                                    {{ \Carbon\Carbon::parse($p->tanggal_jatuh_tempo)->format('d M Y') }}
                                </div>
                                @if($overdue && ! $p->isStatus(\App\Models\Pembiayaan::STATUS_LUNAS))
                                <div style="font-size:0.72rem;background:#fee2e2;color:#dc2626;
                                            padding:0.1rem 0.4rem;border-radius:9999px;display:inline-block;margin-top:0.2rem;">
                                    Lewat Tempo
                                </div>
                                @endif
                            @else —
                            @endif
                        </td>

                        {{-- Toggle Detail --}}
                        <td style="padding:0.875rem 1rem;text-align:center;">
                            @if($totalCicilan > 0)
                            <button onclick="toggleDetail('detail-{{ $p->id }}')"
                                    style="padding:0.35rem 0.75rem;background:#ede9fe;color:#7c3aed;font-size:0.78rem;
                                           font-weight:600;border:none;border-radius:0.375rem;cursor:pointer;">
                                Lihat
                            </button>
                            @else
                            <span style="font-size:0.78rem;color:#cbd5e1;">—</span>
                            @endif
                        </td>
                    </tr>

                    {{-- ── BARIS DETAIL CICILAN ── --}}
                    @if($totalCicilan > 0)
                    <tr id="detail-{{ $p->id }}" style="display:none;background:#fafafa;">
                        <td colspan="8" style="padding:0 1.25rem 1rem;">
                            <div style="border:1px solid #e2e8f0;border-radius:0.625rem;overflow:hidden;margin-top:0.5rem;">
                                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                                    <thead>
                                        <tr style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;">
                                            <th style="padding:0.5rem 0.875rem;text-align:center;color:#475569;font-weight:600;">Ke-</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:left;color:#475569;font-weight:600;">Jatuh Tempo</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:left;color:#475569;font-weight:600;">Tgl Bayar</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Pokok</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Margin/Ujroh</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Jml Angsuran</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Denda</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Total Bayar</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:right;color:#475569;font-weight:600;">Sisa Pokok</th>
                                            <th style="padding:0.5rem 0.875rem;text-align:center;color:#475569;font-weight:600;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($allAngsuran as $ang)
                                        @php
                                            $statusDinamis = $ang->status_dinamis;
                                            $statusColor = match($statusDinamis) {
                                                'Sudah Dibayar'       => ['bg' => '#d1fae5', 'color' => '#059669'],
                                                'Terlambat'           => ['bg' => '#fee2e2', 'color' => '#dc2626'],
                                                'Jatuh Tempo Hari Ini'=> ['bg' => '#fef3c7', 'color' => '#d97706'],
                                                default               => ['bg' => '#f1f5f9', 'color' => '#64748b'],
                                            };
                                        @endphp
                                        <tr style="border-bottom:1px solid #f1f5f9;"
                                            onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                            <td style="padding:0.5rem 0.875rem;text-align:center;font-weight:700;color:#7c3aed;">{{ $ang->angsuran_ke }}</td>
                                            <td style="padding:0.5rem 0.875rem;color:#475569;">
                                                {{ $ang->tanggal_jatuh_tempo ? \Carbon\Carbon::parse($ang->tanggal_jatuh_tempo)->format('d M Y') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;color:#475569;">
                                                {{ $ang->tanggal_bayar ? \Carbon\Carbon::parse($ang->tanggal_bayar)->format('d M Y') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;color:#1e293b;">
                                                {{ $ang->pokok_angsuran ? 'Rp '.number_format($ang->pokok_angsuran,0,',','.') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;color:#1e293b;">
                                                {{ $ang->margin_ujroh ? 'Rp '.number_format($ang->margin_ujroh,0,',','.') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;font-weight:600;color:#1e293b;">
                                                Rp {{ number_format($ang->jumlah_angsuran ?? 0, 0, ',', '.') }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;color:{{ $ang->denda > 0 ? '#dc2626' : '#94a3b8' }};">
                                                {{ $ang->denda > 0 ? 'Rp '.number_format($ang->denda,0,',','.') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;font-weight:600;color:#059669;">
                                                {{ $ang->total_bayar ? 'Rp '.number_format($ang->total_bayar,0,',','.') : '—' }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:right;color:#475569;">
                                                Rp {{ number_format($ang->sisa_pokok ?? 0, 0, ',', '.') }}
                                            </td>
                                            <td style="padding:0.5rem 0.875rem;text-align:center;">
                                                <span style="display:inline-block;padding:0.15rem 0.55rem;border-radius:9999px;
                                                             font-size:0.72rem;font-weight:600;
                                                             background:{{ $statusColor['bg'] }};color:{{ $statusColor['color'] }};">
                                                    {{ $statusDinamis }}
                                                </span>
                                                @if($statusDinamis === 'Terlambat' && $ang->tanggal_jatuh_tempo)
                                                <div style="font-size:0.7rem;color:#dc2626;margin-top:0.1rem;">
                                                    {{ \Carbon\Carbon::parse($ang->tanggal_jatuh_tempo)->diffInDays(\Carbon\Carbon::today()) }} hari
                                                </div>
                                                @endif
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($pembiayaan->hasPages())
        <div style="padding:0.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $pembiayaan->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="width:64px;height:64px;border-radius:50%;background:#dbeafe;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                     fill="none" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
            </div>
            <div style="font-size:0.95rem;font-weight:600;color:#1e293b;margin-bottom:0.375rem;">Belum ada data angsuran</div>
            <p style="font-size:0.825rem;color:#94a3b8;">Tidak ada pembiayaan yang sesuai dengan filter.</p>
        </div>
        @endif
    </div>

</div>

<script>
function toggleDetail(id) {
    const row = document.getElementById(id);
    if (!row) return;
    const isHidden = row.style.display === 'none';
    row.style.display = isHidden ? 'table-row' : 'none';
    // ubah teks tombol
    const btn = event.target;
    btn.textContent = isHidden ? 'Tutup' : 'Lihat';
    btn.style.background = isHidden ? '#fee2e2' : '#ede9fe';
    btn.style.color = isHidden ? '#dc2626' : '#7c3aed';
}
</script>
@endsection
