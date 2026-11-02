@extends('layouts.app')
@section('page-title', "Audit Beban Operasional")

@section('title', 'Audit Beban Operasional — DPS')

@section('content')
@php
    $adaFilter   = ($filter['bulan'] ?? '') !== '' || ($filter['dari'] ?? '') !== '' || ($filter['sampai'] ?? '') !== '';
    $labelPeriode = 'Seluruh Periode';

    if (($filter['bulan'] ?? '') !== '') {
        $labelPeriode = 'Bulan ' . \Carbon\Carbon::createFromFormat('Y-m', $filter['bulan'])->translatedFormat('F Y');
    } elseif (($filter['dari'] ?? '') !== '' || ($filter['sampai'] ?? '') !== '') {
        $dariTxt   = ($filter['dari'] ?? '') !== '' ? \Carbon\Carbon::parse($filter['dari'])->format('d M Y') : 'Awal';
        $sampaiTxt = ($filter['sampai'] ?? '') !== '' ? \Carbon\Carbon::parse($filter['sampai'])->format('d M Y') : 'Sekarang';
        $labelPeriode = $dariTxt . ' — ' . $sampaiTxt;
    }

    // Total baris Approved pada halaman tabel ini (untuk uji konsistensi tampilan)
    $totalBarisApproved = $pengeluarans->getCollection()
        ->where('status', \App\Models\PengeluaranBeban::STATUS_APPROVED)
        ->sum('nominal');
@endphp

<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">
            Audit Beban Operasional <span style="font-size:.7rem;font-weight:700;background:#dbeafe;color:#1e40af;padding:.2rem .6rem;border-radius:9999px;vertical-align:middle;">READ-ONLY</span>
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Pemantauan pengeluaran beban operasional koperasi & mutasi CoA-nya (PSAK 101-111).
        </p>
    </div>

    {{-- ── FILTER PERIODE ── --}}
    <form method="GET" action="{{ route('dps.beban.index') }}"
          style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:.75rem;">
            <div style="display:flex;flex-direction:column;gap:.3rem;">
                <label for="bulan" style="font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Bulan</label>
                <input type="month" id="bulan" name="bulan" value="{{ $filter['bulan'] ?? '' }}"
                       style="padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;color:#1e293b;">
            </div>

            <div style="display:flex;flex-direction:column;gap:.3rem;">
                <label for="dari" style="font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Dari Tanggal</label>
                <input type="date" id="dari" name="dari" value="{{ $filter['dari'] ?? '' }}"
                       style="padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;color:#1e293b;">
            </div>

            <div style="display:flex;flex-direction:column;gap:.3rem;">
                <label for="sampai" style="font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.05em;">Sampai Tanggal</label>
                <input type="date" id="sampai" name="sampai" value="{{ $filter['sampai'] ?? '' }}"
                       style="padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.825rem;color:#1e293b;">
            </div>

            <button type="submit"
                    style="padding:.55rem 1.1rem;background:#1e40af;color:#fff;border:none;border-radius:.5rem;font-size:.825rem;font-weight:600;cursor:pointer;">
                Terapkan Filter
            </button>

            @if($adaFilter)
            <a href="{{ route('dps.beban.index') }}"
               style="padding:.55rem 1rem;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:.5rem;font-size:.825rem;font-weight:600;text-decoration:none;">
                Reset
            </a>
            @endif

            <div style="margin-left:auto;font-size:.75rem;color:#64748b;">
                Periode aktif: <strong style="color:#1e293b;">{{ $labelPeriode }}</strong>
            </div>
        </div>
        <div style="margin-top:.6rem;font-size:.72rem;color:#94a3b8;">
            Pilih <em>salah satu</em>: <em>Bulan</em> untuk filter bulanan, atau rentang <em>Dari/Sampai Tanggal</em>. Pilihan yang tidak dipakai otomatis dikosongkan & dinonaktifkan.
        </div>
    </form>

    <script>
    (function () {
        var bulan  = document.getElementById('bulan');
        var dari   = document.getElementById('dari');
        var sampai = document.getElementById('sampai');

        if (!bulan || !dari || !sampai) { return; }

        /** Mode bulan: kunci & kosongkan kedua input tanggal. */
        function aktifkanModeBulan() {
            bulan.disabled  = false;
            dari.disabled   = true;
            sampai.disabled = true;
            dari.value      = '';
            sampai.value    = '';
        }

        /** Mode rentang tanggal: kunci & kosongkan input bulan. */
        function aktifkanModeRentang() {
            bulan.disabled  = true;
            bulan.value     = '';
            dari.disabled   = false;
            sampai.disabled = false;
        }

        /** Tidak ada periode dipilih: kedua mode kembali dapat diisi. */
        function aktifkanKeduanya() {
            bulan.disabled  = false;
            dari.disabled   = false;
            sampai.disabled = false;
        }

        function sinkronkanDariBulan() {
            if (bulan.value !== '') { aktifkanModeBulan(); } else { aktifkanKeduanya(); }
        }

        function sinkronkanDariTanggal() {
            if (dari.value !== '' || sampai.value !== '') { aktifkanModeRentang(); } else { aktifkanKeduanya(); }
        }

        // Real-time: bereaksi saat nilai dipilih/diubah pengguna.
        ['input', 'change'].forEach(function (evt) {
            bulan.addEventListener(evt, sinkronkanDariBulan);
            dari.addEventListener(evt, sinkronkanDariTanggal);
            sampai.addEventListener(evt, sinkronkanDariTanggal);
        });

        // Selaraskan kondisi awal dengan filter yang sedang aktif dari server.
        if (bulan.value !== '') {
            dari.disabled = true;
            sampai.disabled = true;
        } else if (dari.value !== '' || sampai.value !== '') {
            bulan.disabled = true;
        }
    })();
    </script>

    {{-- ── STAT ── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Total Beban Disetujui</div>
            <div style="font-size:1.35rem;font-weight:700;color:#991b1b;margin-top:.25rem;">Rp {{ number_format($totalApproved, 0, ',', '.') }}</div>
            <div style="font-size:.72rem;color:#64748b;margin-top:.25rem;">
                Status Approved
            </div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Batas Auto-Approve Teller/AO</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;margin-top:.25rem;">Rp {{ number_format($limitApproval, 0, ',', '.') }}</div>
            <div style="font-size:.72rem;color:#64748b;margin-top:.25rem;">Di atas limit: wajib otorisasi Manajer.</div>
        </div>
    </div>

    {{-- ── BREAKDOWN AKUN BEBAN ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid #e2e8f0;font-size:.85rem;font-weight:700;color:#1e293b;">
            Breakdown Akun Beban (Approved)
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:.6rem 1.25rem;text-align:left;font-weight:600;color:#475569;">Kode</th>
                        <th style="padding:.6rem 1.25rem;text-align:left;font-weight:600;color:#475569;">Nama Akun</th>
                        <th style="padding:.6rem 1.25rem;text-align:right;font-weight:600;color:#475569;">Trx</th>
                        <th style="padding:.6rem 1.25rem;text-align:right;font-weight:600;color:#475569;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($breakdownAkun as $baris)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.6rem 1.25rem;font-weight:700;color:#1e40af;">{{ $baris->kode_akun }}</td>
                        <td style="padding:.6rem 1.25rem;color:#334155;">{{ $baris->nama_akun }}</td>
                        <td style="padding:.6rem 1.25rem;text-align:right;color:#64748b;">{{ $baris->jumlah }}</td>
                        <td style="padding:.6rem 1.25rem;text-align:right;font-weight:700;color:#991b1b;">Rp {{ number_format((float) $baris->total, 0, ',', '.') }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" style="padding:1.5rem;text-align:center;color:#94a3b8;">Belum ada beban Approved pada periode ini.</td></tr>
                    @endforelse
                </tbody>
                @if($breakdownAkun->count())
                <tfoot>
                    <tr style="background:#f8fafc;border-top:1px solid #e2e8f0;">
                        <td colspan="3" style="padding:.6rem 1.25rem;text-align:right;font-weight:700;color:#475569;">Total</td>
                        <td style="padding:.6rem 1.25rem;text-align:right;font-weight:700;color:#991b1b;">
                            Rp {{ number_format((float) $breakdownAkun->sum('total'), 0, ',', '.') }}
                        </td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- ── TABEL ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        @if($pengeluarans->count())
        <div style="padding:.85rem 1.25rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
            <span style="font-size:.85rem;font-weight:700;color:#1e293b;">Daftar Mutasi Beban Operasional</span>
            <span style="font-size:.72rem;color:#64748b;">
                Halaman ini: {{ $pengeluarans->count() }} baris ·
                Approved di halaman ini: <strong style="color:#991b1b;">Rp {{ number_format($totalBarisApproved, 0, ',', '.') }}</strong>
            </span>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No / Tanggal</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Keterangan</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Akun Beban (CoA)</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Jurnal (D / K)</th>
                        <th style="padding:.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Nominal</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Penginput / Otorisator</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pengeluarans as $item)
                    <tr style="border-bottom:1px solid #f1f5f9;{{ $item->status !== \App\Models\PengeluaranBeban::STATUS_APPROVED ? 'background:#fcfcfd;' : '' }}">
                        <td style="padding:.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $item->no_pengeluaran }}</div>
                            <div style="font-size:.72rem;color:#64748b;">{{ $item->tanggal->format('d M Y') }}</div>
                        </td>
                        <td style="padding:.75rem 1rem;color:#334155;">{{ $item->keterangan }}</td>
                        <td style="padding:.75rem 1rem;font-size:.75rem;">
                            <span style="padding:.2rem .55rem;border-radius:.375rem;font-weight:700;background:#eef2ff;color:#1e40af;">{{ $item->coaBeban?->kode_akun ?? '—' }}</span>
                            <div style="color:#475569;margin-top:.2rem;">{{ $item->coaBeban?->nama_akun ?? 'Akun beban tidak ditemukan' }}</div>
                        </td>
                        <td style="padding:.75rem 1rem;font-size:.75rem;">
                            <div style="color:#991b1b;font-weight:600;">D: {{ $item->coaBeban?->kode_akun }} {{ $item->coaBeban?->nama_akun }}</div>
                            <div style="color:#065f46;font-weight:600;">K: {{ $item->coaKas?->kode_akun }} {{ $item->coaKas?->nama_akun }}</div>
                            @if($item->jurnal)
                                <div style="font-size:.68rem;color:#64748b;margin-top:.15rem;">{{ $item->jurnal->no_jurnal }}</div>
                            @else
                                <div style="font-size:.68rem;color:#b45309;margin-top:.15rem;">Jurnal belum terposting</div>
                            @endif
                        </td>
                        <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp {{ number_format($item->nominal, 0, ',', '.') }}</td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            @php
                                $badge = [
                                    \App\Models\PengeluaranBeban::STATUS_APPROVED => ['#d1fae5', '#065f46', '✅ Approved'],
                                    \App\Models\PengeluaranBeban::STATUS_PENDING  => ['#fef3c7', '#92400e', '⏳ Pending'],
                                    \App\Models\PengeluaranBeban::STATUS_DITOLAK  => ['#fee2e2', '#991b1b', '❌ Ditolak'],
                                ][$item->status] ?? ['#f1f5f9', '#475569', $item->status];
                            @endphp
                            <span style="padding:.25rem .75rem;border-radius:9999px;font-size:.7rem;font-weight:700;background:{{ $badge[0] }};color:{{ $badge[1] }};">{{ $badge[2] }}</span>
                            @if($item->catatan_manajer)
                                <div style="font-size:.68rem;color:#64748b;margin-top:.25rem;">"{{ $item->catatan_manajer }}"</div>
                            @endif
                        </td>
                        <td style="padding:.75rem 1rem;font-size:.75rem;color:#334155;">
                            <div>Input: {{ $item->penginput?->name }}</div>
                            <div style="color:#64748b;">Otorisasi: {{ $item->approver?->name ?? '—' }}</div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:.75rem 1rem;border-top:1px solid #e2e8f0;">
            {{ $pengeluarans->links() }}
        </div>
        @else
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:.875rem;">
            Belum ada pengeluaran beban{{ $adaFilter ? ' pada periode ' . $labelPeriode : '' }}.
        </div>
        @endif
    </div>
</div>
@endsection
