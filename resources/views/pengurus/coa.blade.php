@extends('layouts.app')

@section('title', 'Bagan Akun (CoA) — Pengurus')
@section('page-title', 'Bagan Akun (CoA)')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
                Bagan Akun (Chart of Accounts)
            </h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
                Master data akun standar akuntansi syariah (PSAK 101-111). Setiap transaksi teller otomatis diposting berpasangan (Debet &amp; Kredit).
            </p>
        </div>
        <div style="display:flex;gap:0.625rem;flex-wrap:wrap;align-items:center;">
            {{-- Tombol Verifikasi Jurnal --}}
            <form action="{{ route('pengurus.coa.verify') }}" method="POST" style="display:inline;">
                @csrf
                <button type="submit"
                        style="background:#7c3aed;color:#fff;border:none;padding:0.625rem 1.1rem;border-radius:0.5rem;font-weight:600;font-size:0.82rem;cursor:pointer;">
                    &#9654; Jalankan Verifikasi Jurnal
                </button>
            </form>
            {{-- Tombol Perbaikan Akun 3-9000 (Ikhtisar SHU) --}}
            <form action="{{ route('pengurus.coa.ikhtisar-shu.preview') }}" method="POST" style="display:inline;">
                @csrf
                <button type="submit"
                        style="background:#d97706;color:#fff;border:none;padding:0.625rem 1.1rem;border-radius:0.5rem;font-weight:600;font-size:0.82rem;cursor:pointer;">
                    &#128295; Perbaiki Akun 3-9000
                </button>
            </form>
            {{-- Tombol Tutup Buku --}}
            <button onclick="document.getElementById('modalTutupBuku').style.display='flex'"
                    style="background:#dc2626;color:#fff;border:none;padding:0.625rem 1.1rem;border-radius:0.5rem;font-weight:600;font-size:0.82rem;cursor:pointer;">
                &#128274; Tutup Buku Tahun
            </button>
            {{-- Tombol Tambah Akun --}}
            <button onclick="document.getElementById('modalTambah').style.display='flex'"
                    style="background:#16a34a;color:#fff;border:none;padding:0.625rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">
                + Tambah Akun
            </button>
        </div>
    </div>

    {{-- ── NOTIFIKASI ──────────────────────────────────── --}}
    @if(session('success'))
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;">
        &#9989; {{ session('success') }}
    </div>
    @endif
    @if(session('error'))
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        &#10060; {{ session('error') }}
    </div>
    @endif

    {{-- ── HASIL VERIFIKASI GANDA JURNAL & NERACA SALDO ── --}}
    @if(session('verify_result'))
    @php $vr = session('verify_result'); $vrLulus = $vr['neraca_seimbang'] && $vr['mutasi_seimbang'] && $vr['anomali'] === []; @endphp
    <div style="background:{{ $vrLulus ? '#ecfdf5' : '#fffbeb' }};border:1px solid {{ $vrLulus ? '#a7f3d0' : '#fcd34d' }};border-radius:0.75rem;padding:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;font-size:1rem;font-weight:700;color:{{ $vrLulus ? '#065f46' : '#92400e' }};">
                    {{ $vrLulus ? '&#9989;' : '&#9888;&#65039;' }} Hasil Verifikasi Ganda Jurnal &amp; Neraca Saldo
                </h2>
                <p style="margin:0.25rem 0 0;font-size:0.78rem;color:{{ $vrLulus ? '#047857' : '#a16207' }};">
                    Diperiksa {{ $vr['waktu'] }} &middot; {{ $vr['jumlah_akun'] }} akun &middot;
                    Lapis 1: hitung ulang saldo dari jurnal vs jalur model &middot;
                    Lapis 2: uji keseimbangan neraca saldo.
                </p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;margin-top:1rem;">
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem;">
                <div style="font-size:0.7rem;color:#64748b;font-weight:600;">Total Saldo Pos Debet</div>
                <div style="font-size:0.95rem;font-weight:700;color:#1e40af;font-family:monospace;">
                    Rp {{ number_format($vr['total_saldo_debet'], 2, ',', '.') }}
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem;">
                <div style="font-size:0.7rem;color:#64748b;font-weight:600;">Total Saldo Pos Kredit</div>
                <div style="font-size:0.95rem;font-weight:700;color:#166534;font-family:monospace;">
                    Rp {{ number_format($vr['total_saldo_kredit'], 2, ',', '.') }}
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem;">
                <div style="font-size:0.7rem;color:#64748b;font-weight:600;">Selisih Neraca Saldo</div>
                <div style="font-size:0.95rem;font-weight:700;color:{{ $vr['neraca_seimbang'] ? '#047857' : '#b91c1c' }};font-family:monospace;">
                    Rp {{ number_format($vr['selisih_neraca'], 2, ',', '.') }}
                    {{ $vr['neraca_seimbang'] ? '(seimbang)' : '(tidak seimbang)' }}
                </div>
            </div>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem;">
                <div style="font-size:0.7rem;color:#64748b;font-weight:600;">Selisih Mutasi (Debet − Kredit)</div>
                <div style="font-size:0.95rem;font-weight:700;color:{{ $vr['mutasi_seimbang'] ? '#047857' : '#b91c1c' }};font-family:monospace;">
                    Rp {{ number_format($vr['selisih_mutasi'], 2, ',', '.') }}
                    {{ $vr['mutasi_seimbang'] ? '(seimbang)' : '(tidak seimbang)' }}
                </div>
            </div>
        </div>

        @if(!empty($vr['anomali']))
        <div style="margin-top:1rem;background:#fff;border:1px solid #fca5a5;border-radius:0.5rem;padding:0.75rem;">
            <div style="font-size:0.78rem;font-weight:700;color:#991b1b;margin-bottom:0.4rem;">
                Hal yang perlu ditindaklanjuti ({{ count($vr['anomali']) }}):
            </div>
            <ul style="margin:0;padding-left:1.1rem;font-size:0.75rem;color:#7f1d1d;line-height:1.6;">
                @foreach($vr['anomali'] as $pesanAnomali)
                <li>{{ $pesanAnomali }}</li>
                @endforeach
            </ul>
        </div>
        @else
        <p style="margin:1rem 0 0;font-size:0.78rem;color:#047857;">
            Tidak ada selisih hitung ulang maupun akun yang bersaldo berlawanan posisi.
            Seluruh saldo CoA konsisten dengan jurnal yang diposting.
        </p>
        @endif

        <details style="margin-top:0.75rem;">
            <summary style="cursor:pointer;font-size:0.8rem;font-weight:600;color:#334155;">
                Rincian per akun ({{ $vr['jumlah_akun'] }} baris)
            </summary>
            <div style="overflow-x:auto;margin-top:0.5rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.72rem;background:#fff;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#334155;">
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Kode</th>
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Nama Akun</th>
                            <th style="padding:0.4rem 0.6rem;text-align:center;">Posisi</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Debet</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Kredit</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Saldo</th>
                            <th style="padding:0.4rem 0.6rem;text-align:center;">Uji Hitung Ulang</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vr['baris'] as $barisVerifikasi)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:0.4rem 0.6rem;font-family:monospace;font-weight:700;">{{ $barisVerifikasi['kode'] }}</td>
                            <td style="padding:0.4rem 0.6rem;">
                                {{ $barisVerifikasi['nama'] }}
                                @if($barisVerifikasi['kontra'])
                                <span style="font-size:0.7rem;background:#fef3c7;color:#92400e;padding:0.05rem 0.4rem;border-radius:999px;font-weight:700;margin-left:0.25rem;">Kontra</span>
                                @endif
                            </td>
                            <td style="padding:0.4rem 0.6rem;text-align:center;">{{ $barisVerifikasi['posisi'] }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;">{{ number_format($barisVerifikasi['debet'], 2, ',', '.') }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;">{{ number_format($barisVerifikasi['kredit'], 2, ',', '.') }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;color:{{ $barisVerifikasi['saldo'] < 0 ? '#b91c1c' : '#0f172a' }};">
                                {{ number_format($barisVerifikasi['saldo'], 2, ',', '.') }}
                            </td>
                            <td style="padding:0.4rem 0.6rem;text-align:center;">
                                {{ $barisVerifikasi['cocok'] ? '✓ cocok' : '✗ selisih' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;font-weight:700;">
                            <td colspan="3" style="padding:0.4rem 0.6rem;text-align:right;">Total</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;">{{ number_format($vr['total_mutasi_debet'], 2, ',', '.') }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;">{{ number_format($vr['total_mutasi_kredit'], 2, ',', '.') }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;font-family:monospace;">{{ number_format($vr['total_saldo_debet'] + $vr['total_saldo_kredit'], 2, ',', '.') }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </details>
    </div>
    @endif

    {{-- ── PREVIEW PERBAIKAN AKUN 3-9000 (IKHTISAR SHU) ── --}}
    @if(session('ikhtisar_preview'))
    @php $pv = session('ikhtisar_preview'); $pb = $pv['bersih']; $pt = $pv['tutupbuku']; @endphp
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:0.75rem;padding:1.25rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0;font-size:1rem;font-weight:700;color:#92400e;">
                    &#128295; Preview Perbaikan Akun 3-9000 (Ikhtisar SHU)
                </h2>
                <p style="margin:0.25rem 0 0;font-size:0.78rem;color:#a16207;">
                    Per tanggal {{ $pb['tanggal'] }}. Belum ada perubahan yang disimpan — tinjau dampaknya lebih dulu.
                </p>
            </div>
            @if($pb['perlu_dibersihkan'])
            <form action="{{ route('pengurus.coa.ikhtisar-shu.reset') }}" method="POST" style="display:inline;">
                @csrf
                <input type="hidden" name="sampai" value="{{ $pb['tanggal'] }}">
                <button type="submit"
                        onclick="return confirm('Bersihkan saldo minus akun 3-9000 sebesar Rp {{ number_format(abs($pb['saldo']), 2, ',', '.') }} dengan jurnal penyesuaian (Debet 3-2001 / Kredit 3-9000)?')"
                        style="background:#d97706;color:#fff;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.82rem;cursor:pointer;">
                    Jalankan Pembersihan
                </button>
            </form>
            @endif
        </div>

        {{-- Status akun 3-9000 --}}
        <div style="margin-top:0.875rem;padding:0.75rem 1rem;border-radius:0.5rem;
                    background:{{ $pb['perlu_dibersihkan'] ? '#fef2f2' : '#ecfdf5' }};
                    border:1px solid {{ $pb['perlu_dibersihkan'] ? '#fca5a5' : '#a7f3d0' }};
                    color:{{ $pb['perlu_dibersihkan'] ? '#991b1b' : '#065f46' }};font-size:0.82rem;">
            <strong>Saldo 3-9000: Rp {{ number_format($pb['saldo'], 2, ',', '.') }}</strong>
            (saldo awal Rp {{ number_format($pb['saldo_awal'], 2, ',', '.') }}
            + Debet Rp {{ number_format($pb['total_debet'], 2, ',', '.') }}
            &minus; Kredit Rp {{ number_format($pb['total_kredit'], 2, ',', '.') }}).
            {{ $pb['keterangan'] }}
        </div>

        {{-- Rincian mutasi penyebab --}}
        @if($pb['rincian']->isNotEmpty())
        <details style="margin-top:0.75rem;">
            <summary style="cursor:pointer;font-size:0.8rem;font-weight:600;color:#92400e;">
                Rincian {{ $pb['rincian']->count() }} mutasi akun 3-9000 (sumber saldo minus)
            </summary>
            <div style="overflow-x:auto;margin-top:0.5rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;background:#fff;">
                    <thead>
                        <tr style="background:#fef3c7;color:#92400e;">
                            <th style="padding:0.4rem 0.6rem;text-align:left;">No. Jurnal</th>
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Tanggal</th>
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Sumber</th>
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Keterangan</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Debet</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pb['rincian'] as $r)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:0.4rem 0.6rem;font-family:monospace;">{{ $r['no_jurnal'] }}</td>
                            <td style="padding:0.4rem 0.6rem;">{{ $r['tanggal'] }}</td>
                            <td style="padding:0.4rem 0.6rem;">{{ $r['sumber'] }}</td>
                            <td style="padding:0.4rem 0.6rem;">{{ $r['keterangan'] }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;color:#b91c1c;">{{ number_format($r['debet'], 2, ',', '.') }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;color:#047857;">{{ number_format($r['kredit'], 2, ',', '.') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
        @endif

        {{-- Rencana jurnal penutup Tutup Buku --}}
        <details style="margin-top:0.75rem;">
            <summary style="cursor:pointer;font-size:0.8rem;font-weight:600;color:#92400e;">
                Rencana jurnal penutup Tutup Buku
                ({{ $pt['sudah_ditutup'] ? 'tidak ada saldo untuk ditutup' : $pt['status'] . ' Rp ' . number_format(abs($pt['selisih_shu']), 2, ',', '.') }})
            </summary>
            @if($pt['sudah_ditutup'])
            <p style="margin:0.5rem 0 0;font-size:0.78rem;color:#a16207;">
                Seluruh akun Pendapatan &amp; Beban sudah bersaldo Rp 0 — Tutup Buku tidak akan membuat jurnal baru.
            </p>
            @else
            <div style="overflow-x:auto;margin-top:0.5rem;">
                <table style="width:100%;border-collapse:collapse;font-size:0.75rem;background:#fff;">
                    <thead>
                        <tr style="background:#fef3c7;color:#92400e;">
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Kode</th>
                            <th style="padding:0.4rem 0.6rem;text-align:left;">Nama Akun</th>
                            <th style="padding:0.4rem 0.6rem;text-align:center;">Posisi</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Debet</th>
                            <th style="padding:0.4rem 0.6rem;text-align:right;">Kredit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pt['baris'] as $b)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:0.4rem 0.6rem;font-family:monospace;">{{ $b['kode'] }}</td>
                            <td style="padding:0.4rem 0.6rem;">{{ $b['nama'] }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:center;">{{ $b['posisi'] }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;">{{ $b['debet'] > 0 ? number_format($b['debet'], 2, ',', '.') : '—' }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;">{{ $b['kredit'] > 0 ? number_format($b['kredit'], 2, ',', '.') : '—' }}</td>
                        </tr>
                        @endforeach
                        <tr style="background:#fef3c7;font-weight:700;color:#92400e;">
                            <td colspan="3" style="padding:0.4rem 0.6rem;">
                                Selisih SHU &rarr; {{ $pt['selisih_shu'] >= 0 ? 'Kredit' : 'Debet' }} 3-9000
                            </td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;">{{ $pt['selisih_shu'] < 0 ? number_format(abs($pt['selisih_shu']), 2, ',', '.') : '—' }}</td>
                            <td style="padding:0.4rem 0.6rem;text-align:right;">{{ $pt['selisih_shu'] > 0 ? number_format($pt['selisih_shu'], 2, ',', '.') : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p style="margin:0.5rem 0 0;font-size:0.78rem;color:#a16207;">
                Total Debet Rp {{ number_format($pt['total_debet'], 2, ',', '.') }} &middot;
                Total Kredit Rp {{ number_format($pt['total_kredit'], 2, ',', '.') }} &middot;
                Saldo 3-9000 setelah Tutup Buku: <strong>Rp {{ number_format($pt['saldo_akhir_ikhtisar'], 2, ',', '.') }}</strong>.
            </p>
            @endif
        </details>
    </div>
    @endif

    {{-- ── VALIDASI KLASIFIKASI STANDAR (PSAK 101) ─────── --}}
    @php
        $semuaAkun             = $grouped->flatten(1);
        $akunTanpaKlasifikasi  = $semuaAkun->filter(fn ($c) => blank($c->klasifikasi_neraca));
    @endphp
    @if($akunTanpaKlasifikasi->isEmpty())
    <div style="padding:0.875rem 1.25rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.625rem;color:#1e40af;font-size:0.82rem;">
        &#128203; <strong>Validasi klasifikasi PSAK 101:</strong> seluruh {{ $semuaAkun->count() }} akun telah dipetakan ke sub-kelompok penyajian standar (Aset Lancar, Kewajiban Jangka Pendek, Dana Syirkah Temporer, Modal Anggota, Dana Cadangan, Pendapatan Usaha, Beban Usaha, dst.).
    </div>
    @else
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:0.625rem;color:#991b1b;font-size:0.82rem;">
        &#9888; <strong>Validasi klasifikasi PSAK 101:</strong> {{ $akunTanpaKlasifikasi->count() }} akun belum diklasifikasikan
        ({{ $akunTanpaKlasifikasi->pluck('kode_akun')->implode(', ') }}). Perbaiki melalui tombol <em>Edit</em> pada akun terkait.
    </div>
    @endif

    {{-- ── RINGKASAN TOTAL SALDO + BADGE BALANCE/UNBALANCED ── --}}
    @php $selisih = $totalDebet - $totalKredit; @endphp
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">

        {{-- Total Debet --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.1rem 1.25rem;">
            <div style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Total Saldo Pos Debet (Aset + Beban)</div>
            <div style="font-size:1.25rem;font-weight:700;color:#1e40af;margin-top:0.35rem;">
                Rp {{ number_format($totalDebet, 2, ',', '.') }}
            </div>
        </div>

        {{-- Total Kredit --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1.1rem 1.25rem;">
            <div style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Total Saldo Pos Kredit (Kewajiban + Ekuitas + Pendapatan)</div>
            <div style="font-size:1.25rem;font-weight:700;color:#065f46;margin-top:0.35rem;">
                Rp {{ number_format($totalKredit, 2, ',', '.') }}
            </div>
        </div>

        {{-- Selisih + badge BALANCED / UNBALANCED --}}
        <div style="background:#fff;border:1px solid {{ abs($selisih) < 0.01 ? '#a7f3d0' : '#fca5a5' }};border-radius:0.75rem;padding:1.1rem 1.25rem;">
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <span style="font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;">Selisih</span>
                @if(abs($selisih) < 0.01)
                    <span style="background:#d1fae5;color:#065f46;font-size:0.7rem;font-weight:800;padding:0.15rem 0.55rem;border-radius:999px;letter-spacing:0.05em;">BALANCED &#10003;</span>
                @else
                    <span style="background:#dc2626;color:#fff;font-size:0.7rem;font-weight:800;padding:0.15rem 0.55rem;border-radius:999px;letter-spacing:0.05em;">UNBALANCED &#9888;</span>
                @endif
            </div>
            <div style="font-size:1.25rem;font-weight:700;margin-top:0.35rem;color:{{ abs($selisih) < 0.01 ? '#065f46' : '#b91c1c' }};">
                @if(abs($selisih) < 0.01)
                    Rp 0,00
                @else
                    (Rp {{ number_format(abs($selisih), 2, ',', '.') }})
                @endif
            </div>
            @if(abs($selisih) >= 0.01)
            <div style="font-size:0.7rem;color:#b91c1c;margin-top:0.25rem;">
                Selisih Rp {{ number_format(abs($selisih), 2, ',', '.') }} — jalankan Verifikasi Jurnal.
            </div>
            @endif
        </div>
    </div>

    {{-- ── TREE VIEW PER KATEGORI ──────────────────────── --}}
    @foreach(['Aset', 'Kewajiban', 'Ekuitas', 'Pendapatan', 'Beban'] as $kategori)
    @php
        $items = $grouped->get($kategori, collect());
    @endphp

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:0.875rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:0.9rem;color:#1e293b;">
                {{ $kategori }} <span style="color:#94a3b8;font-weight:500;">({{ $items->count() }} akun)</span>
            </span>
            @if(in_array($kategori, ['Pendapatan', 'Beban']))
            <span style="font-size:0.7rem;color:#92400e;background:#fef3c7;padding:0.15rem 0.55rem;border-radius:999px;font-weight:700;">Reset saat Tutup Buku</span>
            @endif
        </div>

        @if($items->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;color:#475569;">Kode</th>
                        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;color:#475569;">Nama Akun</th>
                        <th style="padding:0.6rem 1rem;text-align:center;font-weight:600;color:#475569;">Saldo Normal</th>
                        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;color:#475569;">Klasifikasi Penyajian</th>
                        <th style="padding:0.6rem 1rem;text-align:left;font-weight:600;color:#475569;">Parameter Persentase</th>
                        <th style="padding:0.6rem 1rem;text-align:right;font-weight:600;color:#475569;">Saldo Awal</th>
                        <th style="padding:0.6rem 1rem;text-align:right;font-weight:600;color:#475569;">Saldo Berjalan</th>
                        <th style="padding:0.6rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:0.6rem 1rem;text-align:center;font-weight:600;color:#475569;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $coa)
                    @php
                        $saldoBerjalan = $coa->saldoBerjalan();
                        $isNegatif     = $saldoBerjalan < 0;
                        $saldoDisplay  = $isNegatif
                            ? '(Rp ' . number_format(abs($saldoBerjalan), 2, ',', '.') . ')'
                            : 'Rp ' . number_format($saldoBerjalan, 2, ',', '.');
                        $saldoWarna    = $isNegatif ? '#b91c1c' : '#0f172a';
                    @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                        <td style="padding:0.65rem 1rem;font-family:monospace;font-weight:700;color:#0f172a;">{{ $coa->kode_akun }}</td>
                        <td style="padding:0.65rem 1rem;color:#334155;">
                            {{ $coa->nama_akun }}
                            @if($coa->jurnal_detail_count > 0)
                                <span style="font-size:0.7rem;color:#94a3b8;margin-left:0.35rem;">({{ $coa->jurnal_detail_count }} mutasi)</span>
                            @endif
                            @if($coa->kode_akun === '3-9000')
                                <span style="font-size:0.7rem;color:#7c3aed;background:#ede9fe;padding:0.1rem 0.45rem;border-radius:999px;margin-left:0.35rem;font-weight:700;">Laba Ditahan</span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 1rem;text-align:center;">
                            @php
                                // Posisi EFEKTIF diturunkan dari Kepala kode akun, bukan
                                // kolom mentah tipe_saldo_normal (beda untuk akun kontra).
                                $posisiAkun = $coa->posisiSaldo();
                                $akunKontra = $coa->isKontra();
                            @endphp
                            <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;
                                background:{{ $posisiAkun === 'Debet' ? '#dbeafe' : '#dcfce7' }};
                                color:{{ $posisiAkun === 'Debet' ? '#1e40af' : '#166534' }};">
                                {{ $posisiAkun }}
                            </span>
                            @if($akunKontra)
                            <span title="Akun kontra: berposisi {{ $posisiAkun }} (Kepala {{ substr($coa->kode_akun, 0, 1) }}) namun disajikan sebagai pengurang kelompoknya, sehingga bersaldo negatif dan mengurangi total {{ $coa->kategori }}."
                                  style="display:inline-block;margin-left:0.25rem;padding:0.15rem 0.5rem;border-radius:999px;font-size:0.7rem;font-weight:700;background:#fef3c7;color:#92400e;">
                                Kontra
                            </span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 1rem;">
                            @if($coa->klasifikasi_neraca)
                                <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;background:#e0f2fe;color:#075985;">
                                    {{ $coa->klasifikasi_neraca }}
                                </span>
                            @else
                                <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;background:#fee2e2;color:#991b1b;"
                                      title="Akun belum dipetakan ke klasifikasi PSAK 101">
                                    Belum Diklasifikasi
                                </span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 1rem;">
                            @if($coa->menerapkanPersentase())
                                <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;background:#ede9fe;color:#6d28d9;">
                                    {{ $coa->percentage_label }}
                                </span>
                                <div style="font-size:0.7rem;color:#475569;margin-top:0.25rem;white-space:nowrap;">
                                    @if($coa->percentage_type === 'margin')
                                        Rate {{ number_format($coa->default_percentage_member, 2, ',', '.') }}%/tahun
                                    @elseif($coa->percentage_type === 'nisbah')
                                        Anggota {{ number_format($coa->default_percentage_member, 2, ',', '.') }}%
                                        &middot; Koperasi {{ number_format($coa->default_percentage_institution, 2, ',', '.') }}%
                                    @else
                                        {{ number_format($coa->default_percentage_member, 2, ',', '.') }}%
                                        &middot; Koperasi {{ number_format($coa->default_percentage_institution, 2, ',', '.') }}%
                                    @endif
                                </div>
                            @elseif($coa->percentage_type === 'shu')
                                <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;background:#f1f5f9;color:#475569;">
                                    {{ $coa->percentage_label }}
                                </span>
                                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.25rem;">Via SHU Tahunan</div>
                            @else
                                <span style="font-size:0.72rem;color:#cbd5e1;">&mdash;</span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 1rem;text-align:right;color:#64748b;">
                            Rp {{ number_format($coa->saldo_awal, 2, ',', '.') }}
                        </td>
                        <td style="padding:0.65rem 1rem;text-align:right;font-weight:700;color:{{ $saldoWarna }};"
                            @if($isNegatif) title="Nilai minus — akibat jurnal penutup / mutasi kredit melebihi saldo awal + debet" @endif>
                            {{ $saldoDisplay }}
                        </td>
                        <td style="padding:0.65rem 1rem;text-align:center;">
                            <span style="padding:0.15rem 0.6rem;border-radius:999px;font-size:0.7rem;font-weight:700;
                                background:{{ $coa->is_active ? '#d1fae5' : '#fee2e2' }};
                                color:{{ $coa->is_active ? '#065f46' : '#991b1b' }};">
                                {{ $coa->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td style="padding:0.65rem 1rem;text-align:center;white-space:nowrap;">
                            <button onclick='openEdit({{ json_encode([
                                "id"               => $coa->id,
                                "kode_akun"        => $coa->kode_akun,
                                "nama_akun"        => $coa->nama_akun,
                                "kategori"         => $coa->kategori,
                                "klasifikasi_neraca" => $coa->klasifikasi_neraca,
                                "tipe_saldo_normal"=> $coa->tipe_saldo_normal,
                                "saldo_awal"       => (float) $coa->saldo_awal,
                                "has_percentage"   => (bool) $coa->has_percentage,
                                "percentage_type"  => $coa->percentage_type,
                                "default_percentage_member"      => (float) $coa->default_percentage_member,
                                "default_percentage_institution" => (float) $coa->default_percentage_institution,
                            ]) }})'
                                    style="background:#e0f2fe;color:#0369a1;border:none;padding:0.35rem 0.75rem;border-radius:0.375rem;font-size:0.72rem;font-weight:600;cursor:pointer;">Edit</button>
                            <form action="{{ route('pengurus.coa.toggle', $coa->id) }}" method="POST" style="display:inline;">
                                @csrf
                                <button type="submit" onclick="return confirm('{{ $coa->is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }} akun ini?')"
                                        style="background:#fef3c7;color:#92400e;border:none;padding:0.35rem 0.75rem;border-radius:0.375rem;font-size:0.72rem;font-weight:600;cursor:pointer;">
                                    {{ $coa->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:0.85rem;">Belum ada akun pada kategori ini.</div>
        @endif
    </div>
    @endforeach
</div>

{{-- ── MODAL TAMBAH ───────────────────────────────────── --}}
<div id="modalTambah" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:1rem;max-width:520px;width:100%;padding:1.5rem;max-height:85vh;overflow-y:auto;">
        <h2 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;color:#1e293b;">Tambah Akun CoA</h2>
        <form action="{{ route('pengurus.coa.store') }}" method="POST" style="display:flex;flex-direction:column;gap:0.875rem;">
            @csrf
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="kode_akun">Kode Akun *</label>
                <input id="kode_akun" type="text" name="kode_akun" required placeholder="cth: 1-1003" maxlength="20"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;font-family:monospace;box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="nama_akun">Nama Akun *</label>
                <input id="nama_akun" type="text" name="nama_akun" required placeholder="cth: Kas Kecil Operasional" maxlength="150"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Kategori *</label>
                    <select name="kategori" id="tambahKategori" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                        <option value="Aset">Aset</option>
                        <option value="Kewajiban">Kewajiban</option>
                        <option value="Ekuitas">Ekuitas</option>
                        <option value="Pendapatan">Pendapatan</option>
                        <option value="Beban">Beban</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Saldo Normal *</label>
                    <select name="tipe_saldo_normal" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                        <option value="Debet">Debet</option>
                        <option value="Kredit">Kredit</option>
                    </select>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Klasifikasi Penyajian (PSAK 101) *</label>
                <select name="klasifikasi_neraca" id="tambahKlasifikasi" required
                        data-kategori-source="tambahKategori"
                        style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                    @foreach(\App\Models\Coa::KLASIFIKASI as $kat => $opsiKlasifikasi)
                        @foreach($opsiKlasifikasi as $opsi)
                            <option value="{{ $opsi }}" data-kategori="{{ $kat }}">{{ $opsi }}</option>
                        @endforeach
                    @endforeach
                </select>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">Menentukan sub-kelompok penyajian pada Laporan Neraca. Pilihan mengikuti Kategori.</div>
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="saldo_awal">Saldo Awal (Rp)</label>
                <input id="saldo_awal" type="number" name="saldo_awal" min="0" step="0.01" value="0"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">Isi sesuai saldo neraca pembukaan. Saldo Berjalan = Saldo Awal ± Mutasi Jurnal.</div>
            </div>
            @include('pengurus.partials.coa-percentage-fields', ['prefix' => 'tambah', 'coa' => null])
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:0.5rem;">
                <button type="button" onclick="document.getElementById('modalTambah').style.display='none'"
                        style="background:#f1f5f9;color:#475569;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">Batal</button>
                <button type="submit" style="background:#16a34a;color:#fff;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

{{-- ── MODAL EDIT ─────────────────────────────────────── --}}
<div id="modalEdit" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div class="max-h-[85vh] overflow-y-auto" style="background:#fff;border-radius:1rem;max-width:520px;width:100%;padding:1.5rem;max-height:85vh;overflow-y:auto;">
        <h2 style="margin:0 0 1rem;font-size:1.1rem;font-weight:700;color:#1e293b;">Edit Akun CoA</h2>
        <form id="formEdit" method="POST" style="display:flex;flex-direction:column;gap:0.875rem;">
            @csrf
            @method('PUT')
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="editKode">Kode Akun *</label>
                <input type="text" name="kode_akun" id="editKode" required maxlength="20"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;font-family:monospace;box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="editNama">Nama Akun *</label>
                <input type="text" name="nama_akun" id="editNama" required maxlength="150"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Kategori *</label>
                    <select name="kategori" id="editKategori" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                        <option value="Aset">Aset</option>
                        <option value="Kewajiban">Kewajiban</option>
                        <option value="Ekuitas">Ekuitas</option>
                        <option value="Pendapatan">Pendapatan</option>
                        <option value="Beban">Beban</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Saldo Normal *</label>
                    <select name="tipe_saldo_normal" id="editSaldoNormal" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                        <option value="Debet">Debet</option>
                        <option value="Kredit">Kredit</option>
                    </select>
                </div>
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;">Klasifikasi Penyajian (PSAK 101) *</label>
                <select name="klasifikasi_neraca" id="editKlasifikasi" required
                        data-kategori-source="editKategori"
                        style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                    @foreach(\App\Models\Coa::KLASIFIKASI as $kat => $opsiKlasifikasi)
                        @foreach($opsiKlasifikasi as $opsi)
                            <option value="{{ $opsi }}" data-kategori="{{ $kat }}">{{ $opsi }}</option>
                        @endforeach
                    @endforeach
                </select>
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">Sub-kelompok penyajian pada Laporan Neraca. Pilihan mengikuti Kategori.</div>
            </div>
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="editSaldoAwal">Saldo Awal (Rp)</label>
                <input type="number" name="saldo_awal" id="editSaldoAwal" min="0" step="0.01"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
                <div style="font-size:0.7rem;color:#94a3b8;margin-top:0.2rem;">Ubah saldo awal → Saldo Berjalan otomatis terhitung ulang.</div>
            </div>
            @include('pengurus.partials.coa-percentage-fields', ['prefix' => 'edit', 'coa' => null])
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:0.5rem;">
                <button type="button" onclick="document.getElementById('modalEdit').style.display='none'"
                        style="background:#f1f5f9;color:#475569;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">Batal</button>
                <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

{{-- ── MODAL TUTUP BUKU ───────────────────────────────── --}}
<div id="modalTutupBuku" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.7);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
    <div style="background:#fff;border-radius:1rem;max-width:460px;width:100%;padding:1.5rem;">
        <h2 style="margin:0 0 0.5rem;font-size:1.1rem;font-weight:700;color:#991b1b;">&#128274; Tutup Buku Akhir Tahun</h2>
        <p style="font-size:0.82rem;color:#64748b;margin:0 0 1rem;">
            Proses ini akan membuat <strong>Jurnal Penutup</strong> untuk semua akun Pendapatan &amp; Beban,
            memindahkan selisih SHU ke akun <strong>3-9000 (Ikhtisar SHU / Laba Ditahan)</strong>,
            dan mereset saldo awal Pendapatan &amp; Beban ke Rp 0.
            <br><br>
            <span style="color:#dc2626;font-weight:700;">Tindakan ini tidak dapat diurungkan. Pastikan semua jurnal sudah diverifikasi.</span>
        </p>
        <form action="{{ route('pengurus.coa.close-fiscal-year') }}" method="POST" style="display:flex;flex-direction:column;gap:0.875rem;">
            @csrf
            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.3rem;" for="tahun_buku">Tahun Buku yang Ditutup *</label>
                <input id="tahun_buku" type="number" name="tahun_buku" required min="2000" max="2100"
                       value="{{ date('Y') - 1 }}"
                       style="width:100%;padding:0.6rem 0.75rem;border:1px solid #fca5a5;border-radius:0.5rem;font-size:0.85rem;box-sizing:border-box;">
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:0.5rem;">
                <button type="button" onclick="document.getElementById('modalTutupBuku').style.display='none'"
                        style="background:#f1f5f9;color:#475569;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">Batal</button>
                <button type="submit"
                        onclick="return confirm('KONFIRMASI: Tutup Buku akan membuat jurnal penutup dan mereset akun Pendapatan & Beban. Lanjutkan?')"
                        style="background:#dc2626;color:#fff;border:none;padding:0.6rem 1.25rem;border-radius:0.5rem;font-weight:600;font-size:0.85rem;cursor:pointer;">
                    Jalankan Tutup Buku
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function openEdit(data) {
    const form = document.getElementById('formEdit');
    form.action = '{{ url('/pengurus/coa') }}/' + data.id;
    document.getElementById('editKode').value        = data.kode_akun;
    document.getElementById('editNama').value        = data.nama_akun;
    document.getElementById('editKategori').value    = data.kategori;
    document.getElementById('editSaldoNormal').value = data.tipe_saldo_normal;
    document.getElementById('editSaldoAwal').value   = data.saldo_awal;
    syncKlasifikasi('editKategori', 'editKlasifikasi');
    document.getElementById('editKlasifikasi').value = data.klasifikasi_neraca || '';
    document.getElementById('editHasPercentage').checked = !!data.has_percentage;
    document.getElementById('editPercentageType').value  = data.percentage_type || '';
    document.getElementById('editPctMember').value       = data.default_percentage_member || 0;
    document.getElementById('editPctInstitution').value  = data.default_percentage_institution || 0;
    terapkanPersentaseFields('edit');
    document.getElementById('modalEdit').style.display = 'flex';
}

/**
 * Tampilkan/sembunyikan blok parameter persentase beserta penyesuaian label
 * per tipe: nisbah (dua sisi, wajib 100%), margin (rate tahunan tunggal),
 * dan shu (tanpa angka — imbalan lewat SHU Tahunan).
 */
function terapkanPersentaseFields(prefix) {
    const aktif       = document.getElementById(prefix + 'HasPercentage');
    const blok        = document.getElementById(prefix + 'PersentaseFields');
    const tipe        = document.getElementById(prefix + 'PercentageType');
    if (!aktif || !blok || !tipe) return;

    blok.style.display = aktif.checked ? 'block' : 'none';

    const nilai       = tipe.value;
    const wrapInstitusi = document.getElementById(prefix + 'WrapInstitusi');
    const labelAnggota  = document.getElementById(prefix + 'LabelAnggota');
    const hint          = document.getElementById(prefix + 'PctHint');

    if (nilai === 'margin') {
        wrapInstitusi.style.display = 'none';
        labelAnggota.textContent = 'Rate Margin Pembiayaan (% / tahun)';
        hint.textContent = 'Dipakai sebagai rate margin flat per tahun pada pengajuan pembiayaan Murabahah.';
    } else if (nilai === 'shu') {
        labelAnggota.textContent = 'Anggota (%)';
        wrapInstitusi.style.display = 'none';
        hint.textContent = 'Tanpa nisbah bulanan — imbalan akun ini diperhitungkan melalui SHU Tahunan.';
    } else if (nilai === 'nisbah') {
        wrapInstitusi.style.display = 'block';
        labelAnggota.textContent = 'Nisbah Anggota';
        hint.textContent = 'Total nisbah anggota + koperasi harus 100%.';
    } else {
        wrapInstitusi.style.display = 'block';
        labelAnggota.textContent = 'Persentase Anggota';
        hint.textContent = 'Pilih tipe persentase agar modul transaksi dapat membaca nilainya.';
    }
}

['tambah', 'edit'].forEach(function (prefix) {
    const aktif = document.getElementById(prefix + 'HasPercentage');
    const tipe  = document.getElementById(prefix + 'PercentageType');
    if (aktif) aktif.addEventListener('change', function () { terapkanPersentaseFields(prefix); });
    if (tipe)  tipe.addEventListener('change', function () { terapkanPersentaseFields(prefix); });
    terapkanPersentaseFields(prefix);
});

/**
 * Batasi pilihan Klasifikasi Penyajian agar hanya berisi klasifikasi yang sah
 * untuk Kategori terpilih (taksonomi PSAK 101 — Coa::KLASIFIKASI).
 */
function syncKlasifikasi(kategoriId, klasifikasiId) {
    const kategori = document.getElementById(kategoriId);
    const klasifikasi = document.getElementById(klasifikasiId);
    if (!kategori || !klasifikasi) return;

    const terpilih = klasifikasi.value;
    let sisaOpsiValid = false;

    Array.from(klasifikasi.options).forEach(function (opt) {
        const cocok = opt.dataset.kategori === kategori.value;
        opt.hidden = !cocok;
        opt.disabled = !cocok;
        if (cocok) sisaOpsiValid = true;
    });

    if (!sisaOpsiValid) return;

    // Pertahankan pilihan lama bila masih sah, jika tidak pilih opsi pertama.
    const opsiTerpilih = Array.from(klasifikasi.options).find(function (o) {
        return o.value === terpilih && !o.disabled;
    });
    if (!opsiTerpilih) {
        const pertama = Array.from(klasifikasi.options).find(function (o) { return !o.disabled; });
        if (pertama) klasifikasi.value = pertama.value;
    }
}

// Sinkronkan saat kategori diubah pada kedua modal.
['tambahKategori', 'editKategori'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', function () {
        syncKlasifikasi(id, id === 'tambahKategori' ? 'tambahKlasifikasi' : 'editKlasifikasi');
    });
});
syncKlasifikasi('tambahKategori', 'tambahKlasifikasi');

// Tutup modal jika klik area gelap
['modalTambah', 'modalEdit', 'modalTutupBuku'].forEach(function (id) {
    document.getElementById(id).addEventListener('click', function (e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>
@endpush
@endsection
