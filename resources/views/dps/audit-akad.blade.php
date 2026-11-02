@extends('layouts.app')

@section('title', 'Audit Pembiayaan & Akad — DPS')
@section('page-title', 'Audit Pembiayaan & Akad')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Flash & validasi: form Setujui/Tolak pada halaman ini mem-POST ke
         `dps.pengesahan-akad.*`. Bila validasi gagal (mis. tanggal rencana
         cair kosong), Laravel mengalihkan KEMBALI ke halaman ini; tanpa blok
         ini DPS tidak melihat alasan apa pun dan mengira tombolnya rusak. --}}
    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;
                border-radius:8px;display:flex;align-items:center;gap:0.6rem;">
        ✅ {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;
                border-radius:8px;display:flex;align-items:center;gap:0.6rem;">
        ❌ {{ session('error') }}
    </div>
    @endif
    @if($errors->any())
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;
                border-radius:8px;">
        <div style="font-weight:600;margin-bottom:0.35rem;">Periksa kembali isian berikut:</div>
        <ul style="margin:0;padding-left:1.1rem;font-size:0.85rem;">
            @foreach($errors->all() as $pesan)
                <li>{{ $pesan }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════
         1. HEADER & ACTION BAR
    ════════════════════════════════════════════════════════ --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;">

        {{-- Judul + deskripsi --}}
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;line-height:1.3;margin:0;">
                Audit Pembiayaan & Akad
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Verifikasi kepatuhan syariah seluruh portofolio pembiayaan — akses <strong>read-only</strong> DPS.
            </p>
        </div>

        {{-- Tombol Ekspor --}}
        <div style="display:flex;gap:0.625rem;flex-shrink:0;">
            {{-- Export Excel --}}
            <a href="{{ route('dps.audit-akad') }}?{{ http_build_query(array_filter(['akad'=>$akadFilter,'status'=>$statusFilter,'search'=>$search,'tgl_mulai'=>$tglMulai,'tgl_selesai'=>$tglSelesai,'export'=>'excel'])) }}"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#059669;color:#fff;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12);
                      transition:background .15s;"
               onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                    <line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/>
                </svg>
                Export Excel
            </a>

            {{-- Cetak PDF --}}
            <a href="{{ route('dps.audit-akad') }}?{{ http_build_query(array_filter(['akad'=>$akadFilter,'status'=>$statusFilter,'search'=>$search,'tgl_mulai'=>$tglMulai,'tgl_selesai'=>$tglSelesai,'export'=>'pdf'])) }}"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.5rem 1rem;
                      background:#e11d48;color:#fff;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;box-shadow:0 1px 3px rgba(0,0,0,.12);
                      transition:background .15s;"
               onmouseover="this.style.background='#be123c'" onmouseout="this.style.background='#e11d48'">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Cetak PDF
            </a>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         Banner Read-Only
    ════════════════════════════════════════════════════════ --}}
    <div style="display:flex;align-items:center;gap:0.75rem;background:#fef9c3;border:1px solid #fde047;
                border-left:4px solid #ca8a04;border-radius:0.625rem;padding:0.75rem 1.125rem;font-size:0.825rem;color:#713f12;">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
             fill="none" stroke="#ca8a04" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span>
            Halaman ini bersifat <strong>read-only</strong> untuk Dewan Pengawas Syariah (DPS).
            Data pembiayaan ditampilkan untuk keperluan <strong>audit kepatuhan syariah</strong> — tidak ada tindakan modifikasi yang tersedia.
        </span>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         2. FILTER BAR LANJUTAN
    ════════════════════════════════════════════════════════ --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                box-shadow:0 1px 4px rgba(0,0,0,.06);">

        <div style="font-size:0.78rem;font-weight:600;color:#64748b;text-transform:uppercase;
                    letter-spacing:.06em;margin-bottom:0.875rem;">
            Filter Data
        </div>

        <form method="GET" action="{{ route('dps.audit-akad') }}" id="form-filter">
            {{-- Pertahankan filter akad yang sedang aktif --}}
            @if($akadFilter)
                <input type="hidden" name="akad" value="{{ $akadFilter }}">
            @endif

            {{-- Grid 3 kolom: Search | Date Range | Status --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.875rem;align-items:end;">

                {{-- Pencarian No. Akad / Nama Nasabah --}}
                <div>
                    <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                        Cari No. Akad / Nama Nasabah
                    </label>
                    <div style="position:relative;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                             fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="position:absolute;left:0.625rem;top:50%;transform:translateY(-50%);pointer-events:none;">
                            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input type="text" name="search" value="{{ $search }}"
                               placeholder="Contoh: PBY-001 atau Ahmad..."
                               style="width:100%;padding:0.5rem 0.75rem 0.5rem 2rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;
                                      transition:border-color .15s;"
                               onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                </div>

                {{-- Date Range: Tanggal Pengajuan --}}
                <div>
                    <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                        Tanggal Pengajuan
                    </label>
                    <div style="display:flex;align-items:center;gap:0.375rem;">
                        <input type="date" name="tgl_mulai" value="{{ $tglMulai }}"
                               style="flex:1;padding:0.5rem 0.625rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.8rem;color:#1e293b;outline:none;
                                      transition:border-color .15s;"
                               onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#cbd5e1'">
                        <span style="font-size:0.75rem;color:#94a3b8;flex-shrink:0;">s/d</span>
                        <input type="date" name="tgl_selesai" value="{{ $tglSelesai }}"
                               style="flex:1;padding:0.5rem 0.625rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.8rem;color:#1e293b;outline:none;
                                      transition:border-color .15s;"
                               onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                </div>

                {{-- Dropdown Status Audit --}}
                <div>
                    <label style="display:block;font-size:0.76rem;font-weight:600;color:#475569;margin-bottom:0.375rem;">
                        Status Audit
                    </label>
                    <div style="display:flex;gap:0.5rem;">
                        <select name="status"
                                style="flex:1;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                       border-radius:0.5rem;font-size:0.825rem;color:#1e293b;
                                       background:#fff;outline:none;transition:border-color .15s;appearance:auto;"
                                onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#cbd5e1'">
                            <option value="">Semua Status</option>
                            @foreach($statusList as $st)
                                <option value="{{ $st }}" {{ $statusFilter === $st ? 'selected' : '' }}>{{ $st }}</option>
                            @endforeach
                        </select>

                        {{-- Tombol Terapkan --}}
                        <button type="submit"
                                style="padding:0.5rem 1rem;background:#7c3aed;color:#fff;font-size:0.8rem;font-weight:600;
                                       border:none;border-radius:0.5rem;cursor:pointer;white-space:nowrap;
                                       transition:background .15s;"
                                onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
                            Terapkan
                        </button>

                        {{-- Reset filter --}}
                        @if($search || $tglMulai || $tglSelesai || $statusFilter || $akadFilter)
                        <a href="{{ route('dps.audit-akad') }}"
                           style="display:inline-flex;align-items:center;padding:0.5rem 0.75rem;
                                  background:#f1f5f9;color:#64748b;font-size:0.8rem;font-weight:600;
                                  border-radius:0.5rem;text-decoration:none;white-space:nowrap;
                                  transition:background .15s;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                            Reset
                        </a>
                        @endif
                    </div>
                </div>
            </div>
        </form>
    </div>

    {{-- ═══════════════════════════════════════════════════════
         3. TAB FILTER JENIS AKAD (PILL TABS + COUNTER BADGE)
    ════════════════════════════════════════════════════════ --}}
    @php
        // Warna aksen per akad
        $akadColor = [
            ''           => ['bg'=>'#7c3aed','light'=>'#ede9fe','text'=>'#5b21b6'],
            'Murabahah'  => ['bg'=>'#2563eb','light'=>'#dbeafe','text'=>'#1e40af'],
            'Mudharabah' => ['bg'=>'#059669','light'=>'#d1fae5','text'=>'#065f46'],
            'Musyarakah' => ['bg'=>'#d97706','light'=>'#fef3c7','text'=>'#92400e'],
            'Ijarah'     => ['bg'=>'#0891b2','light'=>'#cffafe','text'=>'#155e75'],
            'Ijarah Muntahia Bittamlik' => ['bg'=>'#0d9488','light'=>'#ccfbf1','text'=>'#115e59'],
            'Istishna'   => ['bg'=>'#7c3aed','light'=>'#ede9fe','text'=>'#5b21b6'],
            'Salam'      => ['bg'=>'#be185d','light'=>'#fce7f3','text'=>'#9d174d'],
            'Qardh'      => ['bg'=>'#dc2626','light'=>'#fee2e2','text'=>'#991b1b'],
        ];
        $tabList = array_merge([''], $akadList);
    @endphp

    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
        @foreach($tabList as $tab)
            @php
                $isActive  = ($akadFilter ?? '') === $tab;
                $color     = $akadColor[$tab] ?? $akadColor[''];
                $count     = $tab === '' ? $totalSemua : ($counterAkad[$tab] ?? 0);
                $label     = $tab === '' ? 'Semua Akad' : $tab;

                // Bangun URL tab: ganti akad saja, pertahankan filter lain
                $tabParams = array_filter([
                    'akad'       => $tab ?: null,
                    'status'     => $statusFilter,
                    'search'     => $search,
                    'tgl_mulai'  => $tglMulai,
                    'tgl_selesai'=> $tglSelesai,
                ], fn($v) => $v !== null && $v !== '');
                $tabUrl = route('dps.audit-akad') . ($tabParams ? '?' . http_build_query($tabParams) : '');
            @endphp
            <a href="{{ $tabUrl }}"
               style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.875rem;
                      border-radius:9999px;font-size:0.8rem;font-weight:600;text-decoration:none;
                      border:1.5px solid {{ $isActive ? $color['bg'] : '#e2e8f0' }};
                      background:{{ $isActive ? $color['bg'] : '#fff' }};
                      color:{{ $isActive ? '#fff' : '#475569' }};
                      box-shadow:{{ $isActive ? '0 2px 6px rgba(0,0,0,.14)' : 'none' }};
                      transition:all .15s;"
               onmouseover="if(!{{ $isActive ? 'true' : 'false' }}) { this.style.background='{{ $color['light'] }}'; this.style.borderColor='{{ $color['bg'] }}'; this.style.color='{{ $color['text'] }}'; }"
               onmouseout="if(!{{ $isActive ? 'true' : 'false' }}) { this.style.background='#fff'; this.style.borderColor='#e2e8f0'; this.style.color='#475569'; }">
                {{ $label }}
                <span style="display:inline-flex;align-items:center;justify-content:center;
                             min-width:1.25rem;height:1.25rem;padding:0 0.35rem;
                             background:{{ $isActive ? 'rgba(255,255,255,.25)' : $color['light'] }};
                             color:{{ $isActive ? '#fff' : $color['text'] }};
                             font-size:0.7rem;font-weight:700;border-radius:9999px;line-height:1;">
                    {{ $count }}
                </span>
            </a>
        @endforeach
    </div>

    {{-- ═══════════════════════════════════════════════════════
         4. TABEL DATA AUDIT
    ════════════════════════════════════════════════════════ --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;">

        {{-- Sub-header: jumlah hasil + label filter aktif --}}
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;
                    padding:0.875rem 1.25rem;border-bottom:1px solid #f1f5f9;">
            <div style="font-size:0.8rem;color:#64748b;">
                Menampilkan
                <strong style="color:#1e293b;">{{ $pembiayaan->total() }}</strong> data
                @if($akadFilter)
                    akad <strong style="color:#7c3aed;">{{ $akadFilter }}</strong>
                @endif
                @if($statusFilter)
                    · status <strong style="color:#7c3aed;">{{ $statusFilter }}</strong>
                @endif
                @if($search)
                    · kata kunci "<strong style="color:#7c3aed;">{{ $search }}</strong>"
                @endif
            </div>
            <div style="font-size:0.75rem;color:#94a3b8;">
                Halaman {{ $pembiayaan->currentPage() }} / {{ $pembiayaan->lastPage() }}
            </div>
        </div>

        @if($pembiayaan->count() > 0)
        {{-- ── Tabel ──────────────────────────────────────────── --}}
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">No. Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Nama Nasabah</th>
                        <th style="padding:0.75rem 1rem;text-align:left;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Jenis Akad</th>
                        <th style="padding:0.75rem 1rem;text-align:right;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Plafond Pembiayaan</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Rincian Skema</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Tanggal Cair</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">Status Audit</th>
                        <th style="padding:0.75rem 1rem;text-align:center;font-size:0.72rem;font-weight:700;
                                   color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pembiayaan as $idx => $pb)
                    @php
                        // Warna badge status
                        $stMap = [
                            'aktif'                => ['bg'=>'#dcfce7','color'=>'#15803d','dot'=>'#16a34a'],
                            'lunas'                => ['bg'=>'#dbeafe','color'=>'#1d4ed8','dot'=>'#2563eb'],
                            'perlu_review_syariah' => ['bg'=>'#fef9c3','color'=>'#92400e','dot'=>'#ca8a04'],
                            'sesuai_syariah'       => ['bg'=>'#d1fae5','color'=>'#065f46','dot'=>'#059669'],
                        ];
                        $stStyle = $stMap[\App\Models\Pembiayaan::normalizeStatus($pb->status_pembiayaan)] ?? ['bg'=>'#f1f5f9','color'=>'#475569','dot'=>'#94a3b8'];

                        // Warna badge akad
                        $akadBadge = [
                            'Murabahah'  => ['bg'=>'#dbeafe','color'=>'#1e40af'],
                            'Mudharabah' => ['bg'=>'#d1fae5','color'=>'#065f46'],
                            'Musyarakah' => ['bg'=>'#fef3c7','color'=>'#92400e'],
                            'Ijarah'     => ['bg'=>'#cffafe','color'=>'#155e75'],
                            'Ijarah Muntahia Bittamlik' => ['bg'=>'#ccfbf1','color'=>'#115e59'],
                            'Istishna'   => ['bg'=>'#ede9fe','color'=>'#5b21b6'],
                            'Salam'      => ['bg'=>'#fce7f3','color'=>'#9d174d'],
                            'Qardh'      => ['bg'=>'#fee2e2','color'=>'#991b1b'],
                        ];
                        $ab = $akadBadge[$pb->akad_pembiayaan] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];

                        $rowBg = $idx % 2 === 1 ? '#f8fafc' : '#fff';
                    @endphp
                    <tr style="background:{{ $rowBg }};border-bottom:1px solid #f1f5f9;
                               transition:background .1s;"
                        onmouseover="this.style.background='#faf5ff'"
                        onmouseout="this.style.background='{{ $rowBg }}'">

                        {{-- No. Akad --}}
                        <td style="padding:0.75rem 1rem;white-space:nowrap;">
                            <span style="font-family:monospace;font-size:0.775rem;font-weight:600;color:#7c3aed;
                                         background:#f5f3ff;padding:0.2rem 0.45rem;border-radius:0.3rem;">
                                {{ $pb->no_pembiayaan ?? '—' }}
                            </span>
                        </td>

                        {{-- Nama Nasabah --}}
                        <td style="padding:0.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;font-size:0.825rem;">
                                {{ $pb->anggota?->nama_lengkap ?? '—' }}
                            </div>
                            @if($pb->anggota?->no_anggota)
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.1rem;">
                                No. Anggota: {{ $pb->anggota->no_anggota }}
                            </div>
                            @endif
                        </td>

                        {{-- Jenis Akad --}}
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.2rem 0.65rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:700;letter-spacing:.02em;
                                         background:{{ $ab['bg'] }};color:{{ $ab['color'] }};">
                                {{ $pb->akad_pembiayaan ?? '—' }}
                            </span>
                        </td>

                        {{-- Plafond --}}
                        <td style="padding:0.75rem 1rem;text-align:right;white-space:nowrap;">
                            <div style="font-weight:700;color:#1e293b;">
                                Rp {{ number_format($pb->jumlah_pembiayaan ?? 0, 0, ',', '.') }}
                            </div>
                            @if($pb->sisa_pembiayaan !== null)
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.1rem;">
                                Sisa: Rp {{ number_format($pb->sisa_pembiayaan, 0, ',', '.') }}
                            </div>
                            @endif
                        </td>

                        {{-- Rincian Skema (kondisional per akad) --}}
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            @if(in_array($pb->akad_pembiayaan, ['Murabahah']))
                                <div style="font-size:0.75rem;color:#1e40af;font-weight:600;">
                                    Margin {{ $pb->margin_nisbah ?? '—' }}%
                                </div>
                                <div style="font-size:0.68rem;color:#64748b;">{{ $pb->tenor ?? '—' }} bln</div>
                            @elseif(in_array($pb->akad_pembiayaan, ['Mudharabah', 'Musyarakah']))
                                <div style="font-size:0.72rem;color:#065f46;font-weight:600;line-height:1.4;">
                                    Pengelola {{ $pb->nisbah_pengelola ?? '—' }}%
                                </div>
                                <div style="font-size:0.72rem;color:#92400e;font-weight:600;">
                                    Pemilik {{ $pb->nisbah_pemilik ?? '—' }}%
                                </div>
                            @elseif($pb->akad_pembiayaan === 'Ijarah')
                                <div style="font-size:0.75rem;color:#155e75;font-weight:600;">
                                    Ujroh Rp {{ number_format($pb->total_ujroh ?? 0, 0, ',', '.') }}
                                </div>
                                <div style="font-size:0.68rem;color:#64748b;">{{ $pb->tenor ?? '—' }} bln</div>
                            @elseif($pb->akad_pembiayaan === 'Ijarah Muntahia Bittamlik')
                                <div style="font-size:0.72rem;color:#115e59;font-weight:600;">
                                    Ujroh Rp {{ number_format($pb->total_ujroh ?? 0, 0, ',', '.') }}
                                </div>
                                <div style="font-size:0.68rem;color:#64748b;">Hibah di akhir {{ $pb->tenor ?? '—' }} bln</div>
                            @elseif(in_array($pb->akad_pembiayaan, ['Istishna', 'Salam']))
                                <div style="font-size:0.75rem;color:#5b21b6;font-weight:600;">
                                    Margin {{ $pb->margin_nisbah ?? '—' }}%
                                </div>
                                <div style="font-size:0.68rem;color:#64748b;">{{ $pb->tenor ?? '—' }} bln</div>
                            @elseif($pb->akad_pembiayaan === 'Qardh')
                                <div style="font-size:0.75rem;color:#991b1b;font-weight:600;">
                                    Tanpa Imbal Hasil
                                </div>
                                <div style="font-size:0.68rem;color:#64748b;">{{ $pb->tenor ?? '—' }} bln</div>
                            @else
                                <span style="color:#94a3b8;font-size:0.75rem;">—</span>
                            @endif
                        </td>

                        {{-- Tanggal Cair --}}
                        <td style="padding:0.75rem 1rem;text-align:center;white-space:nowrap;">
                            @if($pb->tanggal_cair)
                                <div style="font-size:0.8rem;font-weight:600;color:#1e293b;">
                                    {{ $pb->tanggal_cair->format('d/m/Y') }}
                                </div>
                                <div style="font-size:0.68rem;color:#94a3b8;">
                                    {{ $pb->tanggal_cair->locale('id')->diffForHumans() }}
                                </div>
                            @else
                                <span style="color:#94a3b8;">—</span>
                            @endif
                        </td>

                        {{-- Status Audit --}}
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <span style="display:inline-flex;align-items:center;gap:0.3rem;
                                         padding:0.25rem 0.65rem;border-radius:9999px;
                                         font-size:0.72rem;font-weight:700;
                                         background:{{ $stStyle['bg'] }};color:{{ $stStyle['color'] }};">
                                <span style="width:0.45rem;height:0.45rem;border-radius:50%;
                                             background:{{ $stStyle['dot'] }};flex-shrink:0;"></span>
                                {{ $pb->status_pembiayaan ?? '—' }}
                            </span>
                        </td>

                        {{-- Aksi: Tombol Detail --}}
                        <td style="padding:0.75rem 1rem;text-align:center;">
                            <button type="button"
                                    onclick="showDetail({{ $pb->id }})"
                                    style="display:inline-flex;align-items:center;gap:0.3rem;
                                           padding:0.35rem 0.7rem;background:#f5f3ff;color:#7c3aed;
                                           font-size:0.75rem;font-weight:600;border:1px solid #ddd6fe;
                                           border-radius:0.4rem;cursor:pointer;transition:all .15s;"
                                    onmouseover="this.style.background='#7c3aed';this.style.color='#fff'"
                                    onmouseout="this.style.background='#f5f3ff';this.style.color='#7c3aed'">
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                                Detail
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($pembiayaan->hasPages())
        <div style="padding:1rem 1.25rem;border-top:1px solid #f1f5f9;">
            {{ $pembiayaan->links() }}
        </div>
        @endif

        @else
        {{-- ── EMPTY STATE ─────────────────────────────────────── --}}
        <div style="padding:4rem 2rem;text-align:center;">
            <div style="display:inline-flex;align-items:center;justify-content:center;
                        width:72px;height:72px;background:#f5f3ff;border-radius:50%;margin-bottom:1.25rem;">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24"
                     fill="none" stroke="#a78bfa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
            </div>
            <div style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:0.375rem;">
                Belum ada data pembiayaan
            </div>
            <div style="font-size:0.825rem;color:#94a3b8;max-width:320px;margin:0 auto 1.25rem;">
                @if($akadFilter || $statusFilter || $search || $tglMulai || $tglSelesai)
                    Tidak ada data yang cocok dengan filter yang diterapkan. Coba ubah atau reset filter.
                @else
                    Data pembiayaan akan muncul di sini setelah ada pembiayaan yang disetujui.
                @endif
            </div>
            @if($akadFilter || $statusFilter || $search || $tglMulai || $tglSelesai)
            <a href="{{ route('dps.audit-akad') }}"
               style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.5rem 1.125rem;
                      background:#7c3aed;color:#fff;font-size:0.8rem;font-weight:600;
                      border-radius:0.5rem;text-decoration:none;transition:background .15s;"
               onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'">
                Reset semua filter
            </a>
            @endif
        </div>
        @endif
    </div>

</div>{{-- end wrapper --}}

{{-- ═══════════════════════════════════════════════════════════
     MODAL DETAIL PEMBIAYAAN
     (read-only sheet, data diambil via AJAX jika ada)
════════════════════════════════════════════════════════════ --}}
<div id="modal-detail"
     style="display:none;position:fixed;inset:0;z-index:500;align-items:center;justify-content:center;
            background:rgba(15,23,42,.45);backdrop-filter:blur(2px);"
     onclick="if(event.target===this)closeModal()">
    <div class="max-h-[85vh] overflow-y-auto"
         style="background:#fff;border-radius:1rem;box-shadow:0 20px 60px rgba(0,0,0,.2);
                width:100%;max-width:540px;max-height:90vh;overflow-y:auto;margin:1rem;"
         onclick="event.stopPropagation()">

        {{-- Modal header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:1.125rem 1.375rem;border-bottom:1px solid #f1f5f9;">
            <div>
                <div style="font-weight:700;font-size:1rem;color:#1e293b;">Detail Pembiayaan</div>
                <div style="font-size:0.75rem;color:#94a3b8;margin-top:0.1rem;">Informasi rinci untuk keperluan audit</div>
            </div>
            <button onclick="closeModal()"
                    style="width:2rem;height:2rem;display:flex;align-items:center;justify-content:center;
                           background:#f1f5f9;border:none;border-radius:0.4rem;cursor:pointer;
                           color:#64748b;font-size:1.1rem;transition:background .15s;"
                    onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                ×
            </button>
        </div>

        {{-- Modal body — diisi oleh JS --}}
        <div id="modal-body" style="padding:1.375rem;">
            @foreach($pembiayaan as $pb)
            @php
                $ab = $akadBadge[$pb->akad_pembiayaan] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
                $todayDate = now()->format('Y-m-d');
            @endphp
            <div id="detail-{{ $pb->id }}" style="display:none;">

                {{-- Badge akad + no. akad --}}
                <div style="display:flex;align-items:center;gap:0.625rem;margin-bottom:1.25rem;">
                    <span style="padding:0.25rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:700;
                                 background:{{ $ab['bg'] }};color:{{ $ab['color'] }};">
                        {{ $pb->akad_pembiayaan ?? '—' }}
                    </span>
                    <span style="font-family:monospace;font-size:0.825rem;font-weight:600;color:#7c3aed;
                                 background:#f5f3ff;padding:0.2rem 0.5rem;border-radius:0.35rem;">
                        {{ $pb->no_pembiayaan ?? '—' }}
                    </span>
                </div>

                {{-- Grid info --}}
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.875rem;">
                    @php
                        $fields = [
                            ['label'=>'Nama Nasabah',    'val'=> $pb->anggota?->nama_lengkap ?? '—'],
                            ['label'=>'No. Anggota',     'val'=> $pb->anggota?->no_anggota ?? '—'],
                            ['label'=>'Plafond',         'val'=> 'Rp '.number_format($pb->jumlah_pembiayaan??0,0,',','.')],
                            ['label'=>'Total Pembiayaan','val'=> 'Rp '.number_format($pb->total_pembiayaan??0,0,',','.')],
                            ['label'=>'Sisa Pembiayaan', 'val'=> 'Rp '.number_format($pb->sisa_pembiayaan??0,0,',','.')],
                            ['label'=>'Tenor',           'val'=> ($pb->tenor ?? '—').' bulan'],
                            ['label'=>'Tgl. Akad',       'val'=> $pb->tanggal_akad?->format('d/m/Y') ?? '—'],
                            ['label'=>'Tgl. Cair',       'val'=> $pb->tanggal_cair?->format('d/m/Y') ?? '—'],
                            ['label'=>'Jatuh Tempo',     'val'=> $pb->tanggal_jatuh_tempo?->format('d/m/Y') ?? '—'],
                            ['label'=>'Disetujui Oleh',  'val'=> $pb->approvedBy?->name ?? '—'],
                        ];
                        // Tambah field skema sesuai akad
                        if (in_array($pb->akad_pembiayaan, ['Murabahah'])) {
                            $fields[] = ['label'=>'Margin', 'val'=>($pb->margin_nisbah??'—').'%'];
                        } elseif (in_array($pb->akad_pembiayaan, ['Mudharabah','Musyarakah'])) {
                            $fields[] = ['label'=>'Nisbah Pengelola', 'val'=>($pb->nisbah_pengelola??'—').'%'];
                            $fields[] = ['label'=>'Nisbah Pemilik',   'val'=>($pb->nisbah_pemilik??'—').'%'];
                        } elseif ($pb->akad_pembiayaan === 'Ijarah') {
                            $fields[] = ['label'=>'Total Ujroh', 'val'=>'Rp '.number_format($pb->total_ujroh??0,0,',','.')];
                        } elseif ($pb->akad_pembiayaan === 'Ijarah Muntahia Bittamlik') {
                            $fields[] = ['label'=>'Total Ujroh', 'val'=>'Rp '.number_format($pb->total_ujroh??0,0,',','.')];
                            $fields[] = ['label'=>'Kepemilikan Akhir', 'val'=>'Hibah (IMBT)'];
                        } elseif (in_array($pb->akad_pembiayaan, ['Istishna', 'Salam'])) {
                            $fields[] = ['label'=>'Margin', 'val'=>($pb->margin_nisbah??'—').'%'];
                        }
                        if ($pb->tujuan_pembiayaan) {
                            $fields[] = ['label'=>'Tujuan', 'val'=> $pb->tujuan_pembiayaan];
                        }
                        if ($pb->agunan) {
                            $fields[] = ['label'=>'Agunan', 'val'=> $pb->agunan];
                        }
                    @endphp

                    @foreach($fields as $f)
                    <div style="background:#f8fafc;border-radius:0.5rem;padding:0.625rem 0.875rem;">
                        <div style="font-size:0.68rem;font-weight:600;color:#94a3b8;text-transform:uppercase;
                                    letter-spacing:.05em;margin-bottom:0.2rem;">{{ $f['label'] }}</div>
                        <div style="font-size:0.825rem;font-weight:600;color:#1e293b;">{{ $f['val'] }}</div>
                    </div>
                    @endforeach

                    {{-- Status --}}
                    @php $stStyle = $stMap[\App\Models\Pembiayaan::normalizeStatus($pb->status_pembiayaan)] ?? ['bg'=>'#f1f5f9','color'=>'#475569','dot'=>'#94a3b8']; @endphp
                    <div style="background:#f8fafc;border-radius:0.5rem;padding:0.625rem 0.875rem;">
                        <div style="font-size:0.68rem;font-weight:600;color:#94a3b8;text-transform:uppercase;
                                    letter-spacing:.05em;margin-bottom:0.35rem;">Status Audit</div>
                        <span style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.2rem 0.6rem;
                                     border-radius:9999px;font-size:0.75rem;font-weight:700;
                                     background:{{ $stStyle['bg'] }};color:{{ $stStyle['color'] }};">
                            <span style="width:0.4rem;height:0.4rem;border-radius:50%;
                                         background:{{ $stStyle['dot'] }};"></span>
                            {{ $pb->status_pembiayaan ?? '—' }}
                        </span>
                    </div>
                </div>

                @if($pb->keterangan)
                <div style="margin-top:0.875rem;background:#fef9c3;border-radius:0.5rem;padding:0.75rem 0.875rem;">
                    <div style="font-size:0.7rem;font-weight:600;color:#92400e;margin-bottom:0.25rem;">Keterangan</div>
                    <div style="font-size:0.8rem;color:#78350f;">{{ $pb->keterangan }}</div>
                </div>
                @endif

                {{-- Form Aksi DPS — hanya tampil jika status pending_dps --}}
                @if($pb->status_pembiayaan === 'pending_dps')
                <div style="margin-top:1.125rem;border-top:1px solid #f1f5f9;padding-top:1.125rem;">
                    <div style="font-size:0.7rem;font-weight:700;color:#475569;text-transform:uppercase;
                                letter-spacing:.05em;margin-bottom:0.75rem;">Keputusan DPS</div>

                    <form id="form-approve-{{ $pb->id }}"
                          action="{{ route('dps.pengesahan-akad.approve', $pb->id) }}"
                          method="POST">
                        @csrf
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
                            <div>
                                <label style="display:block;font-size:0.72rem;font-weight:600;color:#374151;margin-bottom:0.3rem;">
                                    Tgl. Rencana Cair <span style="color:#dc2626;">*</span>
                                </label>
                                <input type="date" name="tgl_pencairan"
                                       value="{{ $todayDate }}" required
                                       style="width:100%;padding:0.45rem 0.6rem;border:1px solid #d1d5db;
                                              border-radius:0.4rem;font-size:0.8rem;box-sizing:border-box;">
                            </div>
                            <div>
                                <label style="display:block;font-size:0.72rem;font-weight:600;color:#374151;margin-bottom:0.3rem;">
                                    Opini Syariah
                                </label>
                                <input type="text" name="catatan_dps"
                                       placeholder="Misal: Rukun terpenuhi..."
                                       style="width:100%;padding:0.45rem 0.6rem;border:1px solid #d1d5db;
                                              border-radius:0.4rem;font-size:0.8rem;box-sizing:border-box;">
                            </div>
                        </div>
                        <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                            <button type="button" class="dps-btn-tolak"
                                    onclick="document.getElementById('reject-area-{{ $pb->id }}').style.display='block';this.style.display='none'">
                                ✕ Tolak Syariah
                            </button>
                            <button type="submit" class="dps-btn-approve">
                                ✓ Setujui &amp; Sahkan Akad
                            </button>
                        </div>
                    </form>

                    {{-- Area tolak (tersembunyi sampai tombol Tolak diklik) --}}
                    <div id="reject-area-{{ $pb->id }}" style="display:none;margin-top:0.75rem;">
                        <form action="{{ route('dps.pengesahan-akad.reject', $pb->id) }}" method="POST">
                            @csrf
                            <div style="margin-bottom:0.5rem;">
                                <label style="display:block;font-size:0.72rem;font-weight:600;color:#374151;margin-bottom:0.3rem;">
                                    Alasan Penolakan <span style="color:#dc2626;">*</span>
                                </label>
                                <textarea name="catatan_dps" rows="3" required
                                          placeholder="Tuliskan alasan penolakan secara syariah..."
                                          style="width:100%;padding:0.5rem 0.65rem;border:1px solid #fca5a5;
                                                 border-radius:0.4rem;font-size:0.8rem;resize:vertical;box-sizing:border-box;"></textarea>
                            </div>
                            <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
                                <button type="button"
                                        onclick="document.getElementById('reject-area-{{ $pb->id }}').style.display='none'"
                                        style="padding:0.4rem 0.875rem;background:#f1f5f9;color:#475569;border:none;
                                               border-radius:0.4rem;font-size:0.775rem;cursor:pointer;">
                                    Batal
                                </button>
                                <button type="submit"
                                        style="padding:0.4rem 0.875rem;background:#dc2626;color:#fff;border:none;
                                               border-radius:0.4rem;font-size:0.775rem;font-weight:600;cursor:pointer;">
                                    Konfirmasi Tolak
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                @else
                <div style="margin-top:0.875rem;padding:0.625rem 0.875rem;background:#f0fdf4;
                            border-radius:0.5rem;font-size:0.775rem;color:#065f46;">
                    Status: <strong>{{ $pb->status_pembiayaan }}</strong> — tidak ada aksi DPS yang tersedia.
                </div>
                @endif

            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

@section('styles')
<style>
.dps-btn-approve {
    padding: 0.45rem 1rem;
    background: #059669;
    color: #fff;
    border: none;
    border-radius: 0.4rem;
    font-size: 0.775rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.dps-btn-approve:hover { background: #047857; }
.dps-btn-tolak {
    padding: 0.45rem 1rem;
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fca5a5;
    border-radius: 0.4rem;
    font-size: 0.775rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.dps-btn-tolak:hover { background: #fecaca; }
</style>
@endsection

{{-- ═══════════════════════════════════════════════════════════
     SCRIPTS
════════════════════════════════════════════════════════════ --}}
@section('scripts')
<script>
// Tampilkan modal detail berdasarkan ID pembiayaan
function showDetail(id) {
    // Sembunyikan semua panel detail
    document.querySelectorAll('[id^="detail-"]').forEach(el => el.style.display = 'none');
    const panel = document.getElementById('detail-' + id);
    if (panel) {
        panel.style.display = 'block';
        const modal = document.getElementById('modal-detail');
        modal.style.display = 'flex';
        // Cegah scroll body
        document.body.style.overflow = 'hidden';
        // Set action form approve/reject berdasarkan id
        setFormAction(id);
    }
}

function setFormAction(id) {
    const formApprove = document.getElementById('form-approve-' + id);
    if (formApprove) {
        // form action sudah di-set via blade, tidak perlu ubah
    }
}

function submitRejectDPS() {
    const activePanel = document.querySelector('[id^="detail-"]:not([style*="display: none"]):not([style*="display:none"])');
    if (!activePanel) return;
    const id = activePanel.id.replace('detail-', '');
    const rejectArea = document.getElementById('reject-area-' + id);
    if (rejectArea) {
        rejectArea.style.display = 'block';
    }
}

function submitApproveDPS() {
    const activePanel = document.querySelector('[id^="detail-"]:not([style*="display: none"]):not([style*="display:none"])');
    if (!activePanel) return;
    const id = activePanel.id.replace('detail-', '');
    const form = document.getElementById('form-approve-' + id);
    if (form) form.submit();
}

function closeModal() {
    document.getElementById('modal-detail').style.display = 'none';
    document.body.style.overflow = '';
}

// Tutup modal dengan Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// Auto-submit filter saat pilih status berubah
document.querySelector('select[name="status"]')?.addEventListener('change', function() {
    document.getElementById('form-filter').submit();
});
</script>
@endsection
