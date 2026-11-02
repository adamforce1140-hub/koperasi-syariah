@extends('layouts.app')

@section('title', 'Jurnal Umum (Read-Only) — DPS')
@section('page-title', 'Jurnal Umum & Verifikasi Dana Kebajikan')

@section('content')
{{-- Landasan & Mode Read-Only --}}
<div style="background:#fef3c7;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:0.5rem;padding:0.875rem 1.25rem;margin-bottom:1rem;font-size:0.875rem;color:#92400e;">
    <strong>Landasan:</strong> Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — Potongan Ta'zir (denda keterlambatan) wajib dibukukan ke akun <strong>Dana Kebajikan / Social Fund (2-3001)</strong>, BUKAN pendapatan koperasi. Gunakan halaman ini untuk memverifikasi kepatuhan pembukuan.
</div>
<div style="background:#e0f2fe;border:1px solid #bae6fd;border-radius:0.5rem;padding:0.75rem 1.25rem;margin-bottom:1.5rem;font-size:0.8rem;color:#075985;">
    Mode <strong>Read-Only</strong> — Halaman ini untuk audit kepatuhan. Perubahan master akun dilakukan oleh Pengurus melalui modul Bagan Akun (CoA).
</div>

{{-- Summary Cards — seluruh nilai berasal dari kalkulasi running balance di controller --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem;">
    <div style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#92400e;text-transform:uppercase;margin-bottom:0.4rem;">Saldo Dana Kebajikan (2-3001)</div>
        <div data-field="saldo-dana-kebajikan" style="font-size:1.5rem;font-weight:700;color:#78350f;">Rp {{ number_format($saldoDanaKebajikan, 2, ',', '.') }}</div>
        <div style="font-size:0.75rem;color:#92400e;margin-top:0.25rem;">Akun Kewajiban — saldo akhir running balance</div>
    </div>
    <div style="background:linear-gradient(135deg,#dcfce7,#bbf7d0);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#166534;text-transform:uppercase;margin-bottom:0.4rem;">Total Masuk (Kredit)</div>
        <div style="font-size:1.5rem;font-weight:700;color:#14532d;">Rp {{ number_format($totalMutasiKredit, 2, ',', '.') }}</div>
        <div style="font-size:0.75rem;color:#166534;margin-top:0.25rem;">Potongan Ta'zir & setoran dana sosial</div>
    </div>
    <div style="background:linear-gradient(135deg,#fee2e2,#fecaca);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#991b1b;text-transform:uppercase;margin-bottom:0.4rem;">Total Penyaluran (Debet)</div>
        <div style="font-size:1.5rem;font-weight:700;color:#7f1d1d;">Rp {{ number_format($totalMutasiDebet, 2, ',', '.') }}</div>
        <div style="font-size:0.75rem;color:#991b1b;margin-top:0.25rem;">Penyaluran dana kebajikan</div>
    </div>
    <div style="background:linear-gradient(135deg,#e0e7ff,#c7d2fe);border-radius:0.75rem;padding:1.25rem;">
        <div style="font-size:0.75rem;font-weight:600;color:#3730a3;text-transform:uppercase;margin-bottom:0.4rem;">Jumlah Mutasi</div>
        <div style="font-size:1.5rem;font-weight:700;color:#312e81;">{{ $jumlahMutasi }}</div>
        <div style="font-size:0.75rem;color:#3730a3;margin-top:0.25rem;">Baris jurnal pada akun 2-3001</div>
    </div>
</div>

{{-- Mutasi Dana Kebajikan (2-3001) --}}
<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.06);margin-bottom:1.5rem;overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;">
        <div>
            <h3 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">Mutasi Dana Kebajikan — Akun 2-3001</h3>
            <p style="font-size:0.75rem;color:#64748b;margin:0.25rem 0 0;">Verifikasi setiap potongan Ta'zir tercatat sebagai KREDIT pada akun sosial.</p>
        </div>
        <span style="font-size:0.7rem;font-weight:600;background:#fef3c7;color:#92400e;padding:0.25rem 0.6rem;border-radius:9999px;">Read-Only</span>
    </div>
    @if($mutasiDanaKebajikan->isNotEmpty())
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">No. Jurnal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Tanggal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Sumber</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Keterangan</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Debet</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Kredit</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Saldo</th>
                </tr>
            </thead>
            <tbody>
                {{-- Saldo berjalan dihitung di controller (Saldo Baru = Saldo Sebelumnya + KREDIT − DEBET) --}}
                @foreach($mutasiDanaKebajikan as $mutasi)
                    @php
                        $isTazir = ($mutasi->jurnal?->sumber ?? '') === "Ta'zir";
                    @endphp
                    <tr style="border-top:1px solid #f1f5f9;{{ $isTazir ? 'background:#fffbeb;' : '' }}">
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;font-weight:600;color:#334155;">{{ $mutasi->jurnal?->no_jurnal ?? '-' }}</td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;color:#64748b;">{{ optional($mutasi->jurnal?->tanggal_jurnal)->format('d M Y') ?? '-' }}</td>
                        <td style="padding:0.55rem 1rem;">
                            @if($isTazir)
                                <span style="font-size:0.68rem;font-weight:600;background:#fde68a;color:#92400e;padding:0.2rem 0.55rem;border-radius:9999px;">Ta'zir</span>
                            @else
                                <span style="font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#475569;padding:0.2rem 0.55rem;border-radius:9999px;">{{ $mutasi->jurnal?->sumber ?? '-' }}</span>
                            @endif
                        </td>
                        {{-- Narasi transaksi sebenarnya (bukan nama akun statis), dipotong
                             sesuai lebar kolom dengan tooltip berisi teks lengkap. --}}
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;color:#334155;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $mutasi->keterangan_tampil }}">{{ $mutasi->keterangan_tampil ?: '—' }}</td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;color:#b91c1c;">{{ $mutasi->debet_valid > 0 ? 'Rp ' . number_format($mutasi->debet_valid, 2, ',', '.') : '—' }}</td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;color:#15803d;font-weight:600;">{{ $mutasi->kredit_valid > 0 ? 'Rp ' . number_format($mutasi->kredit_valid, 2, ',', '.') : '—' }}</td>
                        <td style="padding:0.55rem 1rem;font-size:0.78rem;text-align:right;font-weight:700;color:#1e293b;{{ $loop->last ? 'background:#f0fdf4;' : '' }}" title="{{ $loop->last ? 'Saldo akhir — sama dengan kartu Saldo Dana Kebajikan' : 'Saldo berjalan' }}">Rp {{ number_format($mutasi->saldo_berjalan, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div style="padding:2rem;text-align:center;color:#94a3b8;">
        <div style="font-weight:600;">Belum ada mutasi pada Dana Kebajikan (2-3001)</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">Mutasi akan muncul otomatis saat terdapat pemotongan Ta'zir atau penyaluran dana sosial.</div>
    </div>
    @endif
</div>

{{-- Jurnal Umum (Posted) --}}
<div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.06);overflow:hidden;">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid #e2e8f0;">
        <h3 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">Laporan Jurnal Umum (Status: Posted)</h3>
        <p style="font-size:0.75rem;color:#64748b;margin:0.25rem 0 0;">Seluruh jurnal berpasangan Debet & Kredit hasil auto-posting transaksi operasional.</p>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('dps.jurnal-umum') }}" style="padding:1rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:flex-end;">
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">No. Anggota / CIF</label>
            <input type="text" name="no_anggota" value="{{ request('no_anggota') }}" placeholder="ANG-2026-0001 atau nama…"
                   style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;min-width:200px;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Sumber Jurnal</label>
            <select name="sumber" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;background:#ffffff;min-width:170px;">
                <option value="">— Semua Sumber —</option>
                @foreach($sumberList as $sumber)
                    <option value="{{ $sumber }}" {{ request('sumber') === $sumber ? 'selected' : '' }}>{{ $sumber }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Dari Tanggal</label>
            <input type="date" name="dari" value="{{ request('dari') }}" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;">
        </div>
        <div>
            <label style="display:block;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;margin-bottom:0.3rem;">Sampai Tanggal</label>
            <input type="date" name="sampai" value="{{ request('sampai') }}" style="padding:0.45rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.375rem;font-size:0.8rem;">
        </div>
        <button type="submit" style="padding:0.5rem 1.1rem;background:#0f766e;color:#ffffff;border:none;border-radius:0.375rem;font-size:0.8rem;font-weight:600;cursor:pointer;">Terapkan Filter</button>
        <a href="{{ route('dps.jurnal-umum') }}" style="padding:0.5rem 1.1rem;background:#e2e8f0;color:#475569;border-radius:0.375rem;font-size:0.8rem;font-weight:600;text-decoration:none;">Reset</a>
    </form>

    @if($jurnals->isNotEmpty())
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f8fafc;">
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">No. Jurnal / Tanggal</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Sumber</th>
                    <th style="padding:0.6rem 1rem;text-align:left;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Keterangan</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Total Debet</th>
                    <th style="padding:0.6rem 1rem;text-align:right;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Total Kredit</th>
                    <th style="padding:0.6rem 1rem;text-align:center;font-size:0.7rem;font-weight:600;color:#475569;text-transform:uppercase;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($jurnals as $jurnal)
                    @php $isTazir = ($jurnal->sumber ?? '') === "Ta'zir"; @endphp
                    <tr style="border-top:1px solid #f1f5f9;{{ $isTazir ? 'background:#fffbeb;' : '' }}">
                        <td style="padding:0.65rem 1rem;">
                            <div style="font-size:0.8rem;font-weight:700;color:#1e293b;">{{ $jurnal->no_jurnal }}</div>
                            <div style="font-size:0.7rem;color:#64748b;">{{ optional($jurnal->tanggal_jurnal)->format('d M Y') }}</div>
                        </td>
                        <td style="padding:0.65rem 1rem;">
                            @if($isTazir)
                                <span style="font-size:0.68rem;font-weight:600;background:#fde68a;color:#92400e;padding:0.2rem 0.55rem;border-radius:9999px;">Ta'zir</span>
                            @else
                                <span style="font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#475569;padding:0.2rem 0.55rem;border-radius:9999px;">{{ $jurnal->sumber ?? '-' }}</span>
                            @endif
                        </td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;color:#334155;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $jurnal->keterangan }}">{{ $jurnal->keterangan ?? '—' }}</td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;text-align:right;font-weight:600;color:#1e293b;">Rp {{ number_format($jurnal->total_debet, 2, ',', '.') }}</td>
                        <td style="padding:0.65rem 1rem;font-size:0.78rem;text-align:right;font-weight:600;color:#1e293b;">Rp {{ number_format($jurnal->total_kredit, 2, ',', '.') }}</td>
                        <td style="padding:0.65rem 1rem;text-align:center;">
                            <span style="font-size:0.68rem;font-weight:600;{{ $jurnal->is_balance ? 'background:#dcfce7;color:#166534;' : 'background:#fee2e2;color:#991b1b;' }}padding:0.2rem 0.55rem;border-radius:9999px;">
                                {{ $jurnal->is_balance ? 'Balanced' : 'Unbalanced' }}
                            </span>
                        </td>
                    </tr>
                    {{-- Detail baris Debet/Kredit per CoA --}}
                    <tr style="border-top:1px dashed #e2e8f0;{{ $isTazir ? 'background:#fffbeb;' : '' }}">
                        <td colspan="6" style="padding:0 1rem 0.75rem;">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.6rem 0.9rem;margin-top:0.35rem;">
                                @forelse($jurnal->detail as $detail)
                                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.3rem 0;font-size:0.76rem;border-bottom:1px dotted #e2e8f0;{{ $loop->last ? 'border-bottom:none;' : '' }}">
                                        <div style="display:flex;align-items:center;gap:0.6rem;">
                                            @if($detail->debet > 0)
                                                <span style="font-size:0.62rem;font-weight:700;background:#fee2e2;color:#991b1b;padding:0.15rem 0.5rem;border-radius:0.25rem;min-width:46px;text-align:center;">DEBET</span>
                                            @else
                                                <span style="font-size:0.62rem;font-weight:700;background:#dcfce7;color:#166534;padding:0.15rem 0.5rem;border-radius:0.25rem;min-width:46px;text-align:center;">KREDIT</span>
                                            @endif
                                            <span style="font-weight:700;color:#475569;">{{ $detail->coa?->kode_akun ?? '?' }}</span>
                                            <span style="color:#334155;">{{ $detail->coa?->nama_akun ?? '(Akun tidak ditemukan)' }}</span>
                                        </div>
                                        <div style="font-weight:600;color:#1e293b;white-space:nowrap;">
                                            Rp {{ number_format($detail->debet > 0 ? $detail->debet : $detail->kredit, 2, ',', '.') }}
                                        </div>
                                    </div>
                                @empty
                                    <div style="font-size:0.75rem;color:#94a3b8;padding:0.25rem 0;">Tidak ada detail baris.</div>
                                @endforelse
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($jurnals->hasPages())
    <div style="padding:1rem 1.25rem;border-top:1px solid #e2e8f0;">
        {{ $jurnals->links() }}
    </div>
    @endif

    @else
    <div style="padding:2.5rem;text-align:center;color:#94a3b8;">
        <div style="font-weight:600;">Belum ada jurnal terposting</div>
        <div style="font-size:0.8rem;margin-top:0.25rem;">Jurnal akan dibuat otomatis oleh sistem setiap ada transaksi Setoran, Penarikan, Ta'zir, dan Pencairan Pembiayaan.</div>
    </div>
    @endif
</div>
@endsection
