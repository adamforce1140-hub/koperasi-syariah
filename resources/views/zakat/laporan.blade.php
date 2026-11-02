@extends('layouts.app')

@section('title', 'Laporan Sumber & Penggunaan Dana Zakat')
@section('page-title', 'Laporan Sumber & Penggunaan Dana Zakat, Infak & Sedekah')

@section('content')

@php
    // DPS mengakses laporan ini lewat jalur pengawasan (read-only).
    $routeBukuBesar = auth()->user()->isDPS() ? 'dps.zakat.index' : 'zakat.index';
@endphp

<div class="card" style="background:#fffbeb;border:1px solid #fde68a;">
    <div style="font-size:.85rem;color:#92400e;line-height:1.6;">
        Disusun sesuai <strong>PSAK 109</strong> (komponen laporan sumber dan penggunaan dana zakat serta
        sumber dan penggunaan dana infak/sedekah pada PSAK 101). Dana ini adalah
        <strong>dana titipan (amanah)</strong> — disajikan terpisah dari laporan laba rugi koperasi.
        Penyaluran zakat dibatasi pada <strong>8 asnaf</strong> (QS At-Taubah 60).
    </div>
</div>

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap;">
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="{{ $dari }}" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <div>
            <label style="display:block;font-size:.75rem;color:#64748b;margin-bottom:.25rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="{{ $sampai }}" style="padding:.5rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;">
        </div>
        <button type="submit" class="btn btn-primary">Terapkan</button>
        <a href="{{ route($routeBukuBesar) }}" class="btn" style="background:#f1f5f9;color:#334155;">Kembali ke Buku Besar</a>
    </form>
</div>

@if(! $validasi['valid'])
    <div class="card" style="background:#fef2f2;border:1px solid #fca5a5;">
        <div class="card-title" style="color:#991b1b;">⚠️ Catatan Validasi</div>
        <ul style="margin:.6rem 0 0 1.2rem;font-size:.85rem;color:#991b1b;line-height:1.7;">
            @foreach($validasi['catatan'] as $catatan)<li>{{ $catatan }}</li>@endforeach
        </ul>
    </div>
@endif

@php
    $labels = [
        'zakat'         => 'DANA ZAKAT',
        'infak_sedekah' => 'DANA INFAK & SEDEKAH',
    ];
@endphp

@foreach($labels as $kunci => $judul)
    @php $r = $ringkasan[$kunci] ?? []; @endphp
    <div class="card">
        <div class="card-header">
            <div class="card-title">{{ $judul }}</div>
            <span style="font-size:.75rem;color:#64748b;">Periode {{ $dari }} s/d {{ $sampai }}</span>
        </div>
        <div style="overflow-x:auto;">
            <table>
                <tbody>
                    <tr>
                        <td style="font-weight:600;">Saldo awal dana</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">
                            Rp {{ number_format($r['saldoAwal'] ?? 0, 2, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;">Penerimaan dana (sumber)</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#065f46;">
                            Rp {{ number_format($r['penerimaan'] ?? 0, 2, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:600;">Penyaluran dana (penggunaan)</td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;color:#991b1b;">
                            (Rp {{ number_format($r['penyaluran'] ?? 0, 2, ',', '.') }})
                        </td>
                    </tr>
                    <tr>
                        <td style="font-weight:700;">Saldo akhir dana</td>
                        <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;">
                            Rp {{ number_format($r['saldoAkhir'] ?? 0, 2, ',', '.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Rincian per kategori / asnaf (khusus dana zakat) --}}
        @if($kunci === 'zakat')
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;margin-top:1.25rem;">
                <div>
                    <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                        Penerimaan per Jenis Zakat
                    </div>
                    <table>
                        <tbody>
                            @forelse($perKategori as $kode => $total)
                                <tr>
                                    <td>{{ $kategoriLabel[$kode] ?? $kode }}</td>
                                    <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp {{ number_format((float) $total, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" style="color:#94a3b8;">Tidak ada penerimaan zakat pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div>
                    <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                        Penyaluran per Asnaf (QS At-Taubah 60)
                    </div>
                    <table>
                        <tbody>
                            @forelse($perAsnaf as $kode => $total)
                                <tr>
                                    <td>{{ $asnaf[$kode] ?? $kode }}</td>
                                    <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp {{ number_format((float) $total, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" style="color:#94a3b8;">Tidak ada penyaluran zakat pada periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Rekap hak amil (Fatwa DSN-MUI 08) — kolom "Hak Amil" terpisah --}}
            <div style="margin-top:1.25rem;">
                <div style="font-size:.75rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem;">
                    Rekap Hak Amil (Fatwa DSN-MUI 08)
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Uraian</th>
                            <th style="text-align:right;">Dasar (Rp)</th>
                            <th style="text-align:right;">Hak Amil (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Porsi hak amil {{ number_format((float) $porsiAmil, 2, ',', '.') }}% dari penerimaan dana zakat</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp {{ number_format((float) $dasarHakAmil, 2, ',', '.') }}</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">Rp {{ number_format((float) $hakAmil, 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Realisasi penyaluran kepada amil (asnaf)</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">—</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp {{ number_format((float) $realisasiAmil, 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td style="font-weight:600;">Selisih (hak amil − realisasi)</td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">—</td>
                            {{-- Z-09: logika warna DIBALIK agar sesuai makna kepatuhan.
                                 Hijau (#065f46) bila hak ≥ realisasi (AMAN);
                                 merah (#991b1b) bila realisasi MELAMPAUI hak (pelanggaran). --}}
                            <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;color:{{ $hakAmilTerlampaui ? '#991b1b' : '#065f46' }};">
                                Rp {{ number_format((float) ($hakAmil - $realisasiAmil), 2, ',', '.') }}
                                @if($hakAmilTerlampaui)
                                    <span class="badge badge-red" title="Realisasi penyaluran kepada amil melampaui hak amil">Terlampaui</span>
                                @else
                                    <span class="badge badge-green">Aman</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

                {{-- Z-07: peringatan eksplisit bila realisasi penyaluran kepada amil
                     MELAMPAUI hak amil (melampaui porsi Fatwa DSN-MUI 08). --}}
                @if($hakAmilTerlampaui)
                    <div role="alert" style="margin-top:.75rem;padding:.75rem 1rem;background:#fef2f2;border:1px solid #fca5a5;border-radius:.5rem;font-size:.85rem;color:#991b1b;line-height:1.6;">
                        <strong>⚠️ Realisasi hak amil terlampaui.</strong>
                        Penyaluran kepada amil sebesar Rp {{ number_format((float) $realisasiAmil, 2, ',', '.') }}
                        melampaui hak amil Rp {{ number_format((float) $hakAmil, 2, ',', '.') }}
                        (kelebihan Rp {{ number_format((float) ($realisasiAmil - $hakAmil), 2, ',', '.') }}).
                        Fatwa DSN-MUI No. 08 menetapkan porsi amil umumnya maksimum 1/8 (12,5%) dari penerimaan dana zakat.
                    </div>
                @endif

                <div style="font-size:.72rem;color:#94a3b8;margin-top:.4rem;line-height:1.5;">
                    Hak amil bersifat <strong>entitlement</strong> (perhitungan informatif dari parameter porsi amil);
                    penyaluran kepada amil tetap dicatat manual sebagai penyaluran ber-asnaf <em>Amil</em>.
                </div>
            </div>
        @endif
    </div>
@endforeach

<div class="card">
    <div class="card-header">
        <div class="card-title">Rincian Mutasi Periode</div>
        <span class="badge badge-gray">{{ $mutasi->count() }} baris</span>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>No. Referensi</th>
                    <th>Dana</th>
                    <th>Jenis</th>
                    <th>Kategori</th>
                    <th>Pihak</th>
                    <th style="text-align:right;">Jumlah</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($mutasi as $t)
                    <tr @if($t->sudah_void) style="opacity:.6;" @endif>
                        <td>{{ $t->tanggal?->format('d/m/Y') }}</td>
                        <td style="font-family:monospace;font-size:.8rem;">{{ $t->no_referensi }}</td>
                        <td>{{ $t->dana_label }}</td>
                        <td>{{ $t->jenis_label }}</td>
                        <td>{{ $t->kategori_label }}</td>
                        <td>
                            {{ $t->pihak_tampil }}
                            @if($t->tertaut_anggota)
                                <span class="badge badge-blue" title="Tertaut ke master anggota">anggota</span>
                            @endif
                        </td>
                        <td style="text-align:right;font-variant-numeric:tabular-nums;">Rp {{ number_format((float) $t->jumlah, 2, ',', '.') }}</td>
                        <td>
                            @if($t->sudah_void)
                                <span class="badge badge-red" title="{{ $t->void_reason ?: 'Dibatalkan' }}">VOID</span>
                            @else
                                <span class="badge badge-green">Berlaku</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:2rem;">Tidak ada mutasi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
