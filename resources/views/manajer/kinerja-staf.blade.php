@extends('layouts.app')

@section('title', 'Kinerja Staf — Manajer')
@section('page-title', 'Kinerja Staf (AO & Teller)')

@section('content')

<div class="page-header" style="margin-bottom:1.5rem;">
    <div>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0;">📊 Evaluasi Kinerja Staf Operasional</h1>
        <p style="color:#64748b;font-size:0.85rem;margin:0.25rem 0 0;">Monitoring produktivitas Account Officer (AO) dan volume pelayanan Teller</p>
    </div>
</div>

{{-- Filter Periode --}}
<div class="card" style="margin-bottom:1.25rem;">
    <form method="GET" action="{{ route('manajer.kinerja-staf.index') }}" style="display:flex;gap:0.75rem;align-items:center;">
        <select name="bulan" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            @for($m=1; $m<=12; $m++)
                <option value="{{ $m }}" {{ $bulan == $m ? 'selected' : '' }}>
                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                </option>
            @endfor
        </select>
        <select name="tahun" style="padding:0.55rem 0.85rem;border:1px solid #cbd5e1;border-radius:8px;font-size:0.875rem;">
            @for($y=date('Y')-2; $y<=date('Y')+1; $y++)
                <option value="{{ $y }}" {{ $tahun == $y ? 'selected' : '' }}>{{ $y }}</option>
            @endfor
        </select>
        <button type="submit" class="btn btn-primary" style="padding:0.55rem 1.25rem;">Terapkan Filter</button>
    </form>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    {{-- Kinerja AO --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
            <span class="card-title" style="font-size:0.95rem;">👤 Kinerja Account Officer (AO)</span>
        </div>
        <table style="width:100%;">
            <thead>
                <tr>
                    <th>Nama Staf AO</th>
                    <th>Jumlah Pengajuan</th>
                    <th>Total Nominal</th>
                </tr>
            </thead>
            <tbody>
                @forelse($kinerjaAO as $ao)
                @php $kosongAO = (int) $ao->total_pengajuan === 0; @endphp
                <tr style="{{ $kosongAO ? 'opacity:0.55;' : '' }}">
                    <td style="font-weight:600;">
                        {{ $ao->nama }}
                        @if($ao->peran)
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#e0e7ff;color:#3730a3;">{{ strtoupper($ao->peran) }}</span>
                        @endif
                        @if($kosongAO)
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#64748b;">Tanpa Aktivitas</span>
                        @endif
                    </td>
                    <td>{{ $ao->total_pengajuan }} berkas</td>
                    <td style="color:#059669;font-weight:600;">Rp {{ number_format($ao->total_nominal, 0, ',', '.') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" style="text-align:center;padding:1.5rem;color:#94a3b8;">Belum ada staf ber-role AO terdaftar.</td>
                </tr>
                @endforelse
            </tbody>
            @if($kinerjaAO->isNotEmpty())
            <tfoot>
                <tr style="background:#f8fafc;border-top:1px solid #e2e8f0;">
                    <td style="font-weight:700;color:#475569;">Total</td>
                    <td style="font-weight:700;color:#475569;">{{ $totalPengajuan }} berkas</td>
                    <td style="font-weight:700;color:#059669;">Rp {{ number_format($totalNominalPengajuan, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

    {{-- Kinerja Teller --}}
    <div class="card" style="padding:0;overflow:hidden;">
        <div class="card-header" style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
            <span class="card-title" style="font-size:0.95rem;">💼 Kinerja Kasir / Teller</span>
        </div>
        <table style="width:100%;">
            <thead>
                <tr>
                    <th>Nama Teller</th>
                    <th>Jumlah Transaksi</th>
                    <th>Volume Kas</th>
                </tr>
            </thead>
            <tbody>
                @forelse($kinerjaTeller as $t)
                @php $kosongTeller = (int) $t->total_transaksi === 0; @endphp
                <tr style="{{ $kosongTeller ? 'opacity:0.55;' : '' }}">
                    <td style="font-weight:600;">
                        {{ $t->nama }}
                        @if($t->peran)
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#e0e7ff;color:#3730a3;">{{ strtoupper($t->peran) }}</span>
                        @endif
                        @if($kosongTeller)
                            <span style="display:inline-block;margin-left:0.375rem;padding:0.1rem 0.5rem;border-radius:9999px;
                                         font-size:0.68rem;font-weight:600;background:#f1f5f9;color:#64748b;">Tanpa Aktivitas</span>
                        @endif
                    </td>
                    <td>{{ $t->total_transaksi }} transaksi</td>
                    <td style="color:#2563eb;font-weight:600;">Rp {{ number_format($t->volume_transaksi, 0, ',', '.') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" style="text-align:center;padding:1.5rem;color:#94a3b8;">Belum ada staf ber-role Teller terdaftar.</td>
                </tr>
                @endforelse
            </tbody>
            @if($kinerjaTeller->isNotEmpty())
            <tfoot>
                <tr style="background:#f8fafc;border-top:1px solid #e2e8f0;">
                    <td style="font-weight:700;color:#475569;">Total</td>
                    <td style="font-weight:700;color:#475569;">{{ $totalTransaksi }} transaksi</td>
                    <td style="font-weight:700;color:#2563eb;">Rp {{ number_format($totalVolumeTransaksi, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

</div>

<p style="margin-top:1rem;font-size:0.78rem;color:#94a3b8;">
    Catatan: kolom kinerja hanya menghitung berkas dan transaksi yang berhasil.
    Transaksi berstatus Gagal/Voided/Dibatalkan serta proses otomatis sistem
    (mis. distribusi SHU massal dan autodebet denda) tidak dihitung sebagai
    kinerja pelayanan staf. Staf tanpa aktivitas tetap ditampilkan dengan nilai 0
    agar pencatatan kinerja merata.
</p>

@endsection