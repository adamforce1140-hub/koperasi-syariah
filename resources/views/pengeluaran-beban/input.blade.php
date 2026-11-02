@extends('layouts.app')
@section('page-title', "Pengeluaran Beban Operasional")

@section('title', 'Pengeluaran Beban Operasional')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── HEADER ── --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">
            Pengeluaran Beban Operasional
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Kas keluar operasional — otomatis terposting ke CoA: <strong>DEBET Akun Beban (5-XXXX)</strong> | <strong>KREDIT Kas/Bank (1-XXXX)</strong>.
        </p>
    </div>

    @if(session('success'))
    <div style="padding:0.875rem 1.25rem;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:0.625rem;color:#065f46;font-size:0.875rem;">
        ✅ {{ session('success') }}
    </div>
    @endif
    @if(session('warning'))
    <div style="padding:0.875rem 1.25rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:0.625rem;color:#92400e;font-size:0.875rem;">
        ⏳ {{ session('warning') }}
    </div>
    @endif
    @if(session('error'))
    <div style="padding:0.875rem 1.25rem;background:#fef2f2;border:1px solid #fecaca;border-radius:0.625rem;color:#991b1b;font-size:0.875rem;">
        ⚠️ {{ session('error') }}
    </div>
    @endif

    {{-- ── STAT ── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Total Beban Disetujui Bulan Ini</div>
            <div style="font-size:1.35rem;font-weight:700;color:#991b1b;margin-top:.25rem;">Rp {{ number_format($totalBulanIni, 0, ',', '.') }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.125rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <div style="font-size:0.72rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;">Batas Kewenangan (Auto-Approve)</div>
            <div style="font-size:1.35rem;font-weight:700;color:#059669;margin-top:.25rem;">Rp {{ number_format($limitApproval, 0, ',', '.') }}</div>
            <div style="font-size:0.72rem;color:#64748b;margin-top:.25rem;">Di atas limit: butuh approval Manajer.</div>
        </div>
    </div>

    {{-- ── FORM INPUT ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;box-shadow:0 1px 3px rgba(0,0,0,.05);max-width:760px;">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h2 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">🧾 Form Pengeluaran Kas Operasional</h2>
        </div>
        <form method="POST" action="{{ route('pengeluaran-beban.store') }}" style="padding:1.5rem;">
            @csrf

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Tanggal Transaksi <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required
                        style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                    @error('tanggal')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Nominal Pengeluaran <span style="color:#dc2626;">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.875rem;color:#64748b;">Rp</span>
                        <input type="number" name="nominal" value="{{ old('nominal') }}" min="1000" step="any" placeholder="0" required
                            style="width:100%;padding:.6rem .75rem .6rem 2.25rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                    </div>
                    @error('nominal')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Jenis Beban Operasional <span style="color:#dc2626;">*</span></label>
                    <select name="jenis_beban" id="jenis_beban" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#fff;">
                        <option value="">-- Pilih Jenis Beban --</option>
                        @foreach($jenisBebanList as $kode => $jenis)
                            <option value="{{ $kode }}" {{ old('jenis_beban') === $kode ? 'selected' : '' }}>
                                {{ $jenis['label'] }} ({{ $jenis['kode_akun'] }})
                            </option>
                        @endforeach
                    </select>
                    @error('jenis_beban')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
                    <p style="font-size:.7rem;color:#64748b;margin:.3rem 0 0;">Akun CoA beban terisi otomatis sesuai jenis yang dipilih.</p>
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Akun Beban (CoA 5-XXXX) <span style="color:#dc2626;">*</span></label>
                    <select name="coa_beban" id="coa_beban" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#f1f5f9;">
                        <option value="">-- Pilih Jenis Beban Dulu --</option>
                        @foreach($akunBebanList as $akun)
                            <option value="{{ $akun->id }}"
                                data-kode="{{ $akun->kode_akun }}"
                                data-jenis="{{ $jenisBebanByKodeAkun[$akun->kode_akun] ?? '' }}"
                                {{ (string) old('coa_beban') === (string) $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    @error('coa_beban')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Sumber Uang / Kas <span style="color:#dc2626;">*</span></label>
                    <select name="coa_kas" required style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;background:#fff;">
                        <option value="">-- Pilih Sumber Uang --</option>
                        @foreach($akunKasList as $akun)
                            <option value="{{ $akun->id }}" {{ old('coa_kas') == $akun->id ? 'selected' : '' }}>
                                {{ $akun->kode_akun }} — {{ $akun->nama_akun }}
                            </option>
                        @endforeach
                    </select>
                    @error('coa_kas')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;">Keterangan Transaksi <span style="color:#dc2626;">*</span></label>
                <input type="text" name="keterangan" value="{{ old('keterangan') }}" placeholder="Contoh: Bayar Listrik & ATK Kantor" required
                    style="width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:.5rem;font-size:.875rem;">
                @error('keterangan')<p style="color:#dc2626;font-size:.75rem;margin:.25rem 0 0;">{{ $message }}</p>@enderror
            </div>

            {{-- Isi otomatis akun CoA beban mengikuti jenis beban terpilih --}}
            <script>
                (function () {
                    const jenis = document.getElementById('jenis_beban');
                    const akunBeban = document.getElementById('coa_beban');
                    if (!jenis || !akunBeban) return;

                    function sinkronkan() {
                        const pilihan = jenis.options[jenis.selectedIndex];
                        if (!pilihan) return;

                        const kodeTarget = (pilihan.textContent.match(/\(([^)]+)\)\s*$/) || [])[1];
                        if (!kodeTarget) return;

                        let cocok = null;
                        let general = null;
                        for (const opt of akunBeban.options) {
                            if (opt.dataset.kode === kodeTarget) cocok = opt;
                            if (opt.dataset.kode === '5-1001') general = opt;
                        }

                        // Kode spesifik tidak ada di master CoA -> jatuh ke akun general
                        const tujuan = cocok || general;
                        if (tujuan) akunBeban.value = tujuan.value;
                    }

                    jenis.addEventListener('change', sinkronkan);
                    if (jenis.value) sinkronkan();
                })();
            </script>

            <button type="submit"
                style="padding:.65rem 1.5rem;background:#059669;color:#fff;border:none;border-radius:.5rem;font-size:.875rem;font-weight:600;cursor:pointer;">
                Simpan Pengeluaran
            </button>
            <span style="font-size:.75rem;color:#64748b;margin-left:.75rem;">
                ≤ Rp {{ number_format($limitApproval, 0, ',', '.') }}: langsung terposting | di atasnya: menunggu Manajer.
            </span>
        </form>
    </div>

    {{-- ── RIWAYAT ── --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="padding:1rem 1.5rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h2 style="font-size:0.95rem;font-weight:700;color:#1e293b;margin:0;">📋 Riwayat Pengeluaran Beban</h2>
        </div>
        @if($pengeluarans->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">No / Tanggal</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">Keterangan</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">DEBET</th>
                        <th style="padding:.75rem 1rem;text-align:left;font-weight:600;color:#475569;">KREDIT</th>
                        <th style="padding:.75rem 1rem;text-align:right;font-weight:600;color:#475569;">Nominal</th>
                        <th style="padding:.75rem 1rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pengeluarans as $item)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:.75rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $item->no_pengeluaran }}</div>
                            <div style="font-size:.72rem;color:#64748b;">{{ $item->tanggal->format('d M Y') }} — {{ $item->penginput?->name }}</div>
                        </td>
                        <td style="padding:.75rem 1rem;color:#334155;">{{ $item->keterangan }}</td>
                        <td style="padding:.75rem 1rem;color:#991b1b;font-weight:600;">{{ $item->coaBeban?->kode_akun }} {{ $item->coaBeban?->nama_akun }}</td>
                        <td style="padding:.75rem 1rem;color:#065f46;font-weight:600;">{{ $item->coaKas?->kode_akun }} {{ $item->coaKas?->nama_akun }}</td>
                        <td style="padding:.75rem 1rem;text-align:right;font-weight:700;color:#1e293b;">Rp {{ number_format($item->nominal, 0, ',', '.') }}</td>
                        <td style="padding:.75rem 1rem;text-align:center;">
                            @php
                                $badge = ['Approved' => ['#d1fae5', '#065f46', '✅ Approved'], 'Pending' => ['#fef3c7', '#92400e', '⏳ Pending'], 'Ditolak' => ['#fee2e2', '#991b1b', '❌ Ditolak']][$item->status];
                            @endphp
                            <span style="padding:.25rem .75rem;border-radius:9999px;font-size:.7rem;font-weight:700;background:{{ $badge[0] }};color:{{ $badge[1] }};">{{ $badge[2] }}</span>
                            @if($item->jurnal)
                                <div style="font-size:.68rem;color:#64748b;margin-top:.25rem;">{{ $item->jurnal->no_jurnal }}</div>
                            @endif
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
        <div style="padding:2.5rem;text-align:center;color:#94a3b8;font-size:.875rem;">Belum ada pengeluaran beban.</div>
        @endif
    </div>
</div>
@endsection
