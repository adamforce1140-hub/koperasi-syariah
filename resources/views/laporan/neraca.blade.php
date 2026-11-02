@extends('layouts.app')
@section('page-title', "Laporan Neraca")

@section('title', 'Laporan Neraca')

@section('styles')
<style>
    /* Cetak / simpan PDF: sembunyikan kerangka aplikasi & kontrol filter. */
    @media print {
        .sidebar, .topbar, .no-print { display: none !important; }
        .main-wrapper { margin-left: 0 !important; }
        .content { padding: 0 !important; }
        body { background: #fff !important; }
        .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
        .row { display: block !important; }
        .col-md-6 { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
        a[href]:after { content: ""; }
    }
</style>
@endsection

@section('content')
<div class="container-fluid px-4 py-3">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1">Laporan Neraca (Balance Sheet)</h4>
            <p class="text-muted mb-0 small">
                Per {{ $tanggalFilter->translatedFormat('d F Y') }} &mdash; Standar PSAK 101
                @if($mode === 'akhir_bulan')
                    <span class="badge bg-secondary ms-1" style="font-size:.68rem;">Akhir Bulan</span>
                @endif
            </p>
        </div>
        @if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengurus'))
        <a href="{{ route('pengurus.cadangan.index') }}" class="btn btn-outline-primary btn-sm no-print">
            Kelola Dana Cadangan
        </a>
        @endif
    </div>

    {{-- ── FILTER PERIODE ─────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('laporan.neraca') }}"
                  class="d-flex align-items-end flex-wrap gap-2">
                <div>
                    <label for="mode" class="form-label mb-1 small fw-semibold text-muted">Mode Periode</label>
                    <select name="mode" id="mode" class="form-select form-select-sm" style="min-width:190px;">
                        <option value="tanggal"     {{ $mode === 'tanggal' ? 'selected' : '' }}>Per Tanggal Spesifik</option>
                        <option value="akhir_bulan" {{ $mode === 'akhir_bulan' ? 'selected' : '' }}>Per Akhir Bulan</option>
                    </select>
                </div>
                <div>
                    <label for="tanggal" class="form-label mb-1 small fw-semibold text-muted">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal"
                           class="form-control form-control-sm" style="min-width:170px;"
                           value="{{ $tanggalInput }}" max="{{ now()->toDateString() }}">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">
                    Tampilkan Laporan
                </button>
                @php
                    // Tombol mengikuti matriks RBAC: peran yang tidak punya hak
                    // cetak/ekspor tidak ditawari tombol yang pasti ditolak server.
                    $peranSaya = auth()->user()->role;
                    $bolehUnduh  = \App\Support\LaporanKeuanganPermission::boleh($peranSaya, \App\Support\LaporanKeuanganPermission::MODUL_NERACA, \App\Support\LaporanKeuanganPermission::UNDUH);
                    $bolehEkspor = \App\Support\LaporanKeuanganPermission::boleh($peranSaya, \App\Support\LaporanKeuanganPermission::MODUL_NERACA, \App\Support\LaporanKeuanganPermission::EKSPOR);
                @endphp
                @if($bolehUnduh)
                <button type="button" class="btn btn-danger btn-sm" onclick="window.print()">
                    Cetak PDF
                </button>
                @endif
                @if($bolehEkspor)
                <a href="{{ route('laporan.neraca', ['mode' => $mode, 'tanggal' => $tanggalInput, 'export' => 'excel']) }}"
                   class="btn btn-success btn-sm">
                    Export Excel
                </a>
                @endif
                <div class="ms-auto small text-muted">
                    Saldo diakumulasi dari jurnal <strong>Posted</strong> s/d
                    <strong>{{ $tanggalFilter->translatedFormat('d F Y') }}</strong>.
                </div>
            </form>
        </div>
    </div>

    @php
        /* Total Pasiva & selisih dihitung di controller (DanaCadanganController::neraca)
         | agar angka pada rincian, badge, dan peringatan selalu identik.
         | $balance = Aktiva − Pasiva: positif berarti sisi Aktiva lebih besar. */
        $balance = $selisihNeraca;

        $badgeLaba = [
            'untung' => ['label' => 'Untung', 'bg' => '#dcfce7', 'fg' => '#166534', 'border' => '#86efac'],
            'rugi'   => ['label' => 'Rugi',   'bg' => '#fee2e2', 'fg' => '#991b1b', 'border' => '#fca5a5'],
            'impas'  => ['label' => 'Impas',  'bg' => '#f1f5f9', 'fg' => '#475569', 'border' => '#cbd5e1'],
        ][$statusLaba];
    @endphp
    @if(abs($balance) > 0.01)
    <div class="alert alert-warning">
        <strong>Perhatian:</strong> Neraca belum seimbang. Selisih Aktiva &minus; Pasiva:
        Rp {{ number_format(abs($balance), 2, ',', '.') }}
        ({{ $balance > 0 ? 'Aktiva lebih besar' : 'Pasiva lebih besar' }}).
        <div class="small mt-1 mb-0">
            Total Aset Rp {{ number_format($totalAset, 2, ',', '.') }} vs
            Total Pasiva Rp {{ number_format($totalPasiva, 2, ',', '.') }}.
            Periksa akun Ekuitas yang bersaldo minus atau jurnal yang tidak balance
            melalui tombol <em>Perbaiki Akun 3-9000</em> pada halaman Bagan Akun.
        </div>
    </div>
    @else
    <div class="alert alert-success py-2 small">
        <strong>Neraca seimbang.</strong> Total Aset = Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan
        (Rp {{ number_format($totalAset, 2, ',', '.') }}).
        <span class="text-muted ms-1">Klasifikasi penyajian mengikuti PSAK 101.</span>
    </div>
    @endif

    <div class="row g-4">
        {{-- ASET --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold" style="background:#dbeafe;color:#1e40af;">ASET</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            @foreach($asetGroups as $grup)
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#1e40af;">
                                    {{ $grup['label'] }}
                                </td>
                            </tr>
                            @foreach($grup['akun'] as $a)
                            <tr>
                                <td><code>{{ $a->kode_akun }}</code></td>
                                <td>{{ $a->nama_akun }}</td>
                                <td class="text-end">Rp {{ number_format($a->saldoBerjalan($sampai), 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                            @if(count($asetGroups) > 1)
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal {{ $grup['label'] }}</td>
                                <td class="text-end fw-semibold">Rp {{ number_format($grup['subtotal'], 0, ',', '.') }}</td>
                            </tr>
                            @endif
                            @endforeach
                        </tbody>
                        <tfoot class="table-primary fw-bold">
                            <tr><td colspan="2">Total Aset</td><td class="text-end">Rp {{ number_format($totalAset, 0, ',', '.') }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- KEWAJIBAN + EKUITAS --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header fw-bold" style="background:#fef3c7;color:#92400e;">KEWAJIBAN</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            @foreach($kewajibanGroups as $grup)
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#92400e;">
                                    {{ $grup['label'] }}
                                </td>
                            </tr>
                            @foreach($grup['akun'] as $k)
                            <tr>
                                <td><code>{{ $k->kode_akun }}</code></td>
                                <td>{{ $k->nama_akun }}</td>
                                <td class="text-end">
                                    Rp {{ number_format($k->saldoBerjalan($sampai), 0, ',', '.') }}
                                    @if($k->kode_akun === '2-1003')
                                        <a href="{{ route('laporan.buku-besar', ['kode_akun' => '2-1003']) }}"
                                           class="ms-1 badge bg-success text-white text-decoration-none no-print" style="font-size:.7rem;">Buku Besar</a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            @if(count($kewajibanGroups) > 1)
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal {{ $grup['label'] }}</td>
                                <td class="text-end fw-semibold">Rp {{ number_format($grup['subtotal'], 0, ',', '.') }}</td>
                            </tr>
                            @endif
                            @endforeach
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr><td colspan="2">Total Kewajiban</td><td class="text-end">Rp {{ number_format($totalKewajiban, 0, ',', '.') }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- DANA SYIRKAH TEMPORER (disajikan terpisah dari Kewajiban — PSAK 101) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header fw-bold" style="background:#ccfbf1;color:#0f766e;">DANA SYIRKAH TEMPORER</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            @foreach($dstGroups as $grup)
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#0f766e;">
                                    {{ $grup['label'] }}
                                </td>
                            </tr>
                            @foreach($grup['akun'] as $d)
                            <tr>
                                <td><code>{{ $d->kode_akun }}</code></td>
                                <td>{{ $d->nama_akun }}</td>
                                <td class="text-end">
                                    Rp {{ number_format($d->saldoBerjalan($sampai), 0, ',', '.') }}
                                    @if($d->kode_akun === '2-1003')
                                        <a href="{{ route('laporan.buku-besar', ['kode_akun' => '2-1003']) }}"
                                           class="ms-1 badge bg-success text-white text-decoration-none no-print" style="font-size:.7rem;">Buku Besar</a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            @endforeach
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr><td colspan="2">Total Dana Syirkah Temporer</td><td class="text-end">Rp {{ number_format($totalDanaSyirkahTemporer, 0, ',', '.') }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold" style="background:#e0e7ff;color:#3730a3;">EKUITAS / MODAL SENDIRI</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Kode</th><th>Nama Akun</th><th class="text-end">Saldo</th></tr></thead>
                        <tbody>
                            @foreach($ekuitasGroups as $grup)
                            <tr class="table-light">
                                <td colspan="3" class="fw-semibold text-uppercase small" style="letter-spacing:.03em;color:#3730a3;">
                                    {{ $grup['label'] }}
                                </td>
                            </tr>
                            @foreach($grup['akun'] as $e)
                            @php $saldoEkuitas = round($e->saldoBerjalanLaporan($sampai), 2); @endphp
                            <tr>
                                <td><code>{{ $e->kode_akun }}</code></td>
                                <td>
                                    {{ $e->nama_akun }}
                                    @if($e->kode_akun === '3-2001')
                                        <span class="badge ms-1" style="background:#6366f1!important;">Dana Cadangan</span>
                                    @endif
                                    @if($saldoEkuitas < -0.01)
                                        <span class="badge ms-1" style="background:#fee2e2!important;color:#991b1b!important;" title="Akun Ekuitas bersaldo minus: ada mutasi Debet yang belum ditutup jurnal penutup lawan.">
                                            Saldo Minus
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end {{ $e->kode_akun === '3-2001' ? 'fw-bold' : '' }} {{ $saldoEkuitas < -0.01 ? 'text-danger fw-semibold' : '' }}">
                                    {{ $saldoEkuitas < 0 ? '(' : '' }}Rp {{ number_format(abs($saldoEkuitas), 0, ',', '.') }}{{ $saldoEkuitas < 0 ? ')' : '' }}
                                </td>
                            </tr>
                            @endforeach
                            @if(count($ekuitasGroups) > 1)
                            <tr>
                                <td colspan="2" class="text-muted fw-semibold small ps-4">Subtotal {{ $grup['label'] }}</td>
                                <td class="text-end fw-semibold">Rp {{ number_format($grup['subtotal'], 0, ',', '.') }}</td>
                            </tr>
                            @endif
                            @endforeach

                            {{-- Laba/Rugi Berjalan masuk RINCIAN Pasiva (bukan card terpisah)
                              | agar tidak ada angka yang berpengaruh pada keseimbangan Neraca
                              | tetapi tidak terlihat sumbernya. */
                            --}}
                            <tr style="background:#f8fafc;">
                                <td><code>&mdash;</code></td>
                                <td>
                                    Laba/Rugi Berjalan
                                    <span class="badge ms-1" style="background:{{ $badgeLaba['bg'] }}!important;color:{{ $badgeLaba['fg'] }}!important;border:1px solid {{ $badgeLaba['border'] }}!important;font-size:.7rem;">
                                        {{ $badgeLaba['label'] }}
                                    </span>
                                    <div class="small text-muted" style="font-size:.7rem;">
                                        Pendapatan Rp {{ number_format($totalPendapatan, 0, ',', '.') }}
                                        &minus; Beban Rp {{ number_format($totalBeban, 0, ',', '.') }}
                                    </div>
                                </td>
                                <td class="text-end fw-semibold {{ $labaBersih < -0.01 ? 'text-danger' : ($labaBersih > 0.01 ? 'text-success' : 'text-muted') }}">
                                    {{ $labaBersih < 0 ? '(' : '' }}Rp {{ number_format(abs($labaBersih), 0, ',', '.') }}{{ $labaBersih < 0 ? ')' : '' }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="fw-bold">
                            <tr>
                                <td colspan="2">Total Ekuitas</td>
                                <td class="text-end">Rp {{ number_format($totalEkuitas, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td colspan="2">Laba/Rugi Berjalan
                                    <span class="badge bg-light text-dark border ms-1" style="font-size:.7rem;">Pendapatan &minus; Beban</span>
                                </td>
                                <td class="text-end {{ $labaBersih < -0.01 ? 'text-danger' : 'text-success' }}">
                                    {{ $labaBersih < 0 ? '(' : '' }}Rp {{ number_format(abs($labaBersih), 0, ',', '.') }}{{ $labaBersih < 0 ? ')' : '' }}
                                </td>
                            </tr>
                            <tr style="background:#eff6ff;">
                                <td colspan="2">TOTAL PASIVA
                                    <div class="small text-muted fw-normal" style="font-size:.7rem;">
                                        Kewajiban + Dana Syirkah Temporer + Ekuitas + Laba/Rugi Berjalan
                                    </div>
                                </td>
                                <td class="text-end" style="color:#1d4ed8;">Rp {{ number_format($totalPasiva, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── REKONSILIASI KESEIMBANGAN ─────────────────────── --}}
    @php
        /* Selisih harus benar-benar nol (bukan "dianggap nol" karena pembulatan).
         | Bila tidak nol, penyebabnya ditampilkan agar bisa ditindaklanjuti, bukan
         | disembunyikan dengan menyerap selisih ke salah satu sisi. */
        $akunEkuitasMinus = collect($ekuitasGroups)
            ->flatMap(fn ($g) => $g['akun'])
            ->filter(fn ($e) => round($e->saldoBerjalanLaporan($sampai), 2) < -0.01)
            ->values();
    @endphp
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-body py-3">
            <div class="row g-3 align-items-center">
                <div class="col-md-4">
                    <div class="small text-muted">Total Aset (Aktiva)</div>
                    <div class="fw-bold fs-5">Rp {{ number_format($totalAset, 0, ',', '.') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Total Pasiva</div>
                    <div class="fw-bold fs-5" style="color:#1d4ed8;">Rp {{ number_format($totalPasiva, 0, ',', '.') }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted">Selisih (Aset &minus; Pasiva)</div>
                    <div class="fw-bold fs-5 {{ abs($balance) > 0.01 ? 'text-danger' : 'text-success' }}">
                        Rp {{ number_format(abs($balance), 2, ',', '.') }}
                        @if(abs($balance) <= 0.01)
                            <span class="badge bg-success ms-1" style="font-size:.7rem;">BALANCE</span>
                        @else
                            <span class="badge bg-danger ms-1" style="font-size:.7rem;">TIDAK BALANCE</span>
                        @endif
                    </div>
                </div>
            </div>

            @if($akunEkuitasMinus->isNotEmpty())
            <div class="alert alert-warning mt-3 mb-0 py-2 small">
                <strong>Temuan:</strong> {{ $akunEkuitasMinus->count() }} akun Ekuitas bersaldo minus
                ({{ $akunEkuitasMinus->pluck('kode_akun')->implode(', ') }}).
                Saldo minus pada akun Ekuitas berarti ada mutasi Debet tanpa jurnal penutup lawan —
                biasanya sisa distribusi SHU sebelum Tutup Buku dijalankan.
                Bersihkan melalui tombol <em>Perbaiki Akun 3-9000</em> pada halaman
                {{-- `pengurus.coa.index` kini dijaga `role:super_admin` (mapping
                     akun = konfigurasi sistem, bukan kewenangan Pengurus).
                     Tanpa penjaga peran di bawah ini, pembaca selain Super Admin
                     melihat tautan yang pasti berakhir 403. --}}

                @if(\App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'pengelola_coa'))
                <a href="{{ route('pengurus.coa.index') }}">Bagan Akun</a>.
                @else
                Bagan Akun (wewenang Super Admin).
                @endif
            </div>
            @endif
        </div>
    </div>

</div>
@endsection
