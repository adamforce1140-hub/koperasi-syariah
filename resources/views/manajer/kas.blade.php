@extends('layouts.app')

@section('title', 'Kas & Arus Likuiditas — Manajer')
@section('page-title', 'Kas & Arus Likuiditas')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ─────────────────────────────────────── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Kas & Arus Likuiditas
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Monitoring ketersediaan kas dan posisi rekening koperasi secara real-time.
        </p>
    </div>

    {{-- ── POSISI KAS RIIL ─────────────────────────────── --}}
    <div style="background:linear-gradient(135deg,#1d4ed8,#2563eb);border-radius:0.875rem;
                padding:1.5rem 1.75rem;color:#fff;box-shadow:0 4px 12px rgba(37,99,235,.25);">
        <div style="font-size:0.825rem;opacity:0.85;margin-bottom:0.5rem;font-weight:500;">
            Total Kas & Bank Koperasi
        </div>
        <div style="font-size:2rem;font-weight:700;line-height:1.2;">
            Rp {{ number_format($totalKas, 0, ',', '.') }}
        </div>
        <div style="font-size:0.775rem;opacity:0.8;margin-top:0.5rem;">
            @forelse($posisiKas as $k)
                {{ $k->nama_akun }}: Rp {{ number_format($k->saldo, 0, ',', '.') }}@if(! $loop->last) &nbsp;·&nbsp; @endif
            @empty
                Belum ada jurnal kas yang diposting.
            @endforelse
        </div>
        <div style="font-size:0.72rem;opacity:0.7;margin-top:0.375rem;">
            Sumber: jurnal Posted (COA 1-1001 Kas Teller &amp; 1-1002 Bank) — {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>

    {{-- ── TOTAL SIMPANAN ANGGOTA (kewajiban, bukan kas) ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid #f59e0b;
                border-radius:0.75rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.04);">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div>
                <div style="font-size:0.8rem;font-weight:600;color:#92400e;">
                    Total Simpanan Anggota
                </div>
                <div style="font-size:1.35rem;font-weight:700;color:#1e293b;margin-top:0.25rem;">
                    Rp {{ number_format($totalSimpanan, 0, ',', '.') }}
                </div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:0.25rem;">
                    Dari {{ $rekening->count() }} rekening aktif. Ini adalah
                    <strong>kewajiban koperasi kepada anggota</strong>, bukan kas yang bisa dibelanjakan.
                </div>
            </div>
        </div>
    </div>

    {{-- ── REKENING CARDS ───────────────────────────────── --}}
    @if($rekening->count())
    <div>
        <div style="font-size:0.8rem;font-weight:600;color:#64748b;text-transform:uppercase;
                    letter-spacing:0.05em;margin-bottom:0.75rem;">
            Posisi Rekening
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:0.875rem;">
            @foreach($rekening as $r)
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;
                        padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.04);">
                <div style="font-size:0.775rem;color:#64748b;margin-bottom:0.375rem;">
                    {{ $r->jenis_rekening }}
                </div>
                <div style="font-size:1.15rem;font-weight:700;color:#1e293b;">
                    Rp {{ number_format($r->saldo ?? 0, 0, ',', '.') }}
                </div>
                <div style="font-size:0.72rem;color:#94a3b8;margin-top:0.25rem;">
                    {{ $r->anggota?->nama_lengkap ?? 'Tanpa Anggota' }}
                    &nbsp;·&nbsp;
                    <span style="font-family:monospace;">{{ $r->no_rekening }}</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── TRANSAKSI TERBARU ────────────────────────────── --}}
    <div>
        <div style="font-size:0.8rem;font-weight:600;color:#64748b;text-transform:uppercase;
                    letter-spacing:0.05em;margin-bottom:0.75rem;">
            Transaksi Terbaru
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;
                    overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            @if($transaksiTerbaru->count())
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Rekening</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Jenis</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Posisi</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Nominal</th>
                            <th style="padding:0.75rem 1rem;text-align:right;font-weight:600;color:#475569;white-space:nowrap;">Saldo Setelah</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Keterangan</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-weight:600;color:#475569;white-space:nowrap;">Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transaksiTerbaru as $t)
                        @php
                            /* Arah mutasi mengikuti satu sumber kebenaran:
                               Transaksi::isMasuk() (Debet = menambah saldo). */
                            $debet = $t->isMasuk();
                        @endphp
                        <tr style="border-bottom:1px solid #f1f5f9;{{ $loop->even ? 'background:#fafafa;' : '' }}">
                            <td style="padding:0.75rem 1rem;color:#475569;">
                                <div style="font-weight:600;color:#1e293b;">
                                    {{ $t->anggota?->nama_lengkap 
                                       ?? $t->pembiayaan?->anggota?->nama_lengkap 
                                       ?? $t->rekening?->anggota?->nama_lengkap 
                                       ?? '—' }}
                                </div>
                                <div style="font-size:0.72rem;color:#94a3b8;font-family:monospace;">
                                    {{ $t->rekening?->no_rekening ?? ($t->pembiayaan?->no_pembiayaan ?? '—') }}
                                </div>
                            </td>
                            <td style="padding:0.75rem 1rem;">
                                <span style="font-size:0.72rem;font-weight:600;padding:0.15rem 0.5rem;
                                             border-radius:9999px;background:#f1f5f9;color:#475569;">
                                    {{ $t->jenis_transaksi ?? '—' }}
                                </span>
                            </td>
                            <td style="padding:0.75rem 1rem;">
                                <span style="font-size:0.72rem;font-weight:600;padding:0.15rem 0.5rem;
                                             border-radius:9999px;
                                             background:{{ $debet ? '#dbeafe' : '#fef3c7' }};
                                             color:{{ $debet ? '#1e40af' : '#92400e' }};">
                                    {{ $t->posisi ?? '—' }}
                                </span>
                            </td>
                            <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;">
                                {{-- Konvensi: Debet = uang masuk (+), Kredit = uang keluar (−). --}}
                                {{ $debet ? '+' : '−' }} Rp {{ number_format($t->jumlah ?? 0, 0, ',', '.') }}
                            </td>
                            <td style="padding:0.75rem 1rem;text-align:right;color:#475569;white-space:nowrap;">
                                {{ $t->saldo_setelah !== null ? 'Rp ' . number_format($t->saldo_setelah, 0, ',', '.') : '—' }}
                            </td>
                            <td style="padding:0.75rem 1rem;color:#64748b;max-width:200px;">
                                <span style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    {{ $t->catatan ?? '—' }}
                                </span>
                            </td>
                            <td style="padding:0.75rem 1rem;color:#94a3b8;white-space:nowrap;font-size:0.775rem;">
                                {{ $t->waktu_transaksi?->format('d/m H:i') ?? '—' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="padding:0.75rem 1rem;background:#f8fafc;border-top:1px solid #e2e8f0;
                        font-size:0.72rem;color:#64748b;">
                Menampilkan 15 transaksi berstatus <strong>Sukses</strong> terakhir. Transaksi
                Voided/Gagal/Dibatalkan tidak ditampilkan karena tidak memindahkan dana.
                Kolom <strong>Posisi</strong> adalah nilai apa adanya dari data transaksi
                (Debet/Kredit) dan tidak menunjukkan arah kas masuk/keluar.
            </div>
            @else
            <div style="padding:2.5rem 1.5rem;text-align:center;color:#94a3b8;font-size:0.825rem;">
                Belum ada transaksi berstatus Sukses.
            </div>
            @endif
        </div>
    </div>

</div>
@endsection
