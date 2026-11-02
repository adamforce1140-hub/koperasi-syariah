@extends('layouts.app')

@section('title', 'Detail Pembiayaan — Manajer')
@section('page-title', 'Detail Pembiayaan')

@section('content')

@php
    $statusLabel = match($item->status_pembiayaan ?? '') {
        'pending_ao'                 => 'Menunggu AO',
        'pending_manajer'            => 'Menunggu Manajer',
        'pending_pengurus'           => 'Menunggu Pengurus',
        'pending_dps'                => 'Menunggu DPS',
        'direview'                   => 'Direview',
        'disetujui'                  => 'Disetujui',
        'disahkan'                   => 'Disahkan DPS',
        'pending_pencairan'          => 'Menunggu Pencairan',
        'dicairkan'                  => 'Dicairkan',
        'aktif'                      => 'Aktif',
        'restrukturisasi'            => 'Restrukturisasi',
        'pending_restrukturisasi'    => 'Menunggu Approval Restrukturisasi',
        'lunas'                      => 'Lunas',
        'macet'                      => 'Macet',
        'ditolak'                    => 'Ditolak',
        'dibatalkan'                 => 'Dibatalkan',
        default                      => ucfirst($item->status_pembiayaan ?? '-'),
    };
    $statusStyle = match($item->status_pembiayaan ?? '') {
        'disetujui', 'disahkan'      => 'background:#d1fae5;color:#065f46;',
        'dicairkan', 'aktif'         => 'background:#e0f2fe;color:#0369a1;',
        'restrukturisasi'            => 'background:#fef3c7;color:#92400e;',
        'pending_restrukturisasi'    => 'background:#ede9fe;color:#6d28d9;',
        'lunas'                      => 'background:#d1fae5;color:#065f46;',
        'macet', 'ditolak',
        'dibatalkan'                 => 'background:#fee2e2;color:#991b1b;',
        default                      => 'background:#fef3c7;color:#92400e;',
    };

    $anggota = $item->anggota;
    $progress = $item->persentase_terbayar;
@endphp

{{-- ── HEADER ─────────────────────────────────────── --}}
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem;">
    <div>
        <a href="{{ route('manajer.npf.index') }}"
           style="color:#dc2626;text-decoration:none;font-size:0.85rem;">
            ← Kembali ke NPF
        </a>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0.3rem 0 0;">
            Detail Pembiayaan
        </h1>
        <p style="color:#94a3b8;font-size:0.85rem;margin:0.15rem 0 0;">
            {{ $item->no_pembiayaan ?? ('PMB-' . str_pad($item->id, 6, '0', STR_PAD_LEFT)) }}
            &middot; Akad {{ $item->tanggal_akad?->format('d/m/Y') ?? '-' }}
        </p>
    </div>
    <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        @if($kolektibilitas)
        <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;background:#fee2e2;color:#991b1b;">
            {{ $kolektibilitas }}
        </span>
        @endif
        <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;{{ $statusStyle }}">
            {{ $statusLabel }}
        </span>
    </div>
</div>

{{-- ── ALERT TUNGGAKAN ──────────────────────────────── --}}
@if($hariTunggakan > 0)
<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.75rem;padding:0.875rem 1.25rem;
            display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
    <span style="font-size:1.25rem;">⚠️</span>
    <div style="font-size:0.85rem;color:#9a3412;">
        Menunggak <strong>{{ $hariTunggakan }} hari</strong> sejak
        {{ $angsuranTunggakan->first()?->tanggal_jatuh_tempo?->format('d/m/Y') ?? '-' }}
        &middot; Total tunggakan <strong>Rp {{ number_format($totalTunggakan, 0, ',', '.') }}</strong>
        ({{ $angsuranTunggakan->count() }} angsuran).
    </div>
</div>
@endif

{{-- ── DATA ANGGOTA & DETAIL PEMBIAYAAN ─────────────── --}}
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Data Anggota
        </h3>
        @foreach([
            'Nama Lengkap' => $anggota?->nama_lengkap,
            'No. Anggota'  => $anggota?->no_anggota,
            'NIK'          => $anggota?->nik,
            'No. HP'       => $anggota?->no_hp,
            'Alamat'       => $anggota?->alamat,
        ] as $label => $value)
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;">{{ $label }}</span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;">{{ $value ?? '—' }}</span>
        </div>
        @endforeach
    </div>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Detail Pembiayaan
        </h3>
        @foreach([
            'Akad'          => $item->akad_pembiayaan,
            'Produk'        => $item->produk?->nama_produk,
            'Jumlah'        => 'Rp ' . number_format($item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
            'Margin/Nisbah' => ($item->margin_nisbah ?? '—') . '%',
            'Total'         => 'Rp ' . number_format($item->total_pembiayaan ?? 0, 0, ',', '.'),
            'Angsuran/Bln'  => 'Rp ' . number_format($item->jumlah_angsuran ?? 0, 0, ',', '.'),
            'Tenor'         => ($item->tenor ?? '—') . ' bulan',
            'Tujuan'        => $item->tujuan_pembiayaan,
            'Agunan'        => $item->agunan,
        ] as $label => $value)
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;">{{ $label }}</span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;">{{ $value ?? '—' }}</span>
        </div>
        @endforeach
    </div>

</div>

{{-- ── POSISI PIUTANG ───────────────────────────────── --}}
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Posisi Piutang
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Sisa Pembiayaan</div>
            <div style="color:#dc2626;font-weight:700;font-size:1rem;">
                Rp {{ number_format($item->sisa_pembiayaan ?? 0, 0, ',', '.') }}
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Total Tunggakan</div>
            <div style="color:#dc2626;font-weight:700;font-size:1rem;">
                Rp {{ number_format($totalTunggakan, 0, ',', '.') }}
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Angsuran Terbayar</div>
            <div style="color:#1e293b;font-weight:700;font-size:1rem;">
                {{ $item->angsuran_sudah_bayar }} / {{ $item->jadwalAngsuran->count() }} kali
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Progres</div>
            <div style="color:#1e293b;font-weight:700;font-size:1rem;">{{ $progress }}%</div>
        </div>
    </div>
    <div style="margin-top:0.875rem;height:8px;background:#f1f5f9;border-radius:9999px;overflow:hidden;">
        <div style="height:100%;width:{{ $progress }}%;background:#3b82f6;"></div>
    </div>
</div>

{{-- ── RIWAYAT PERSETUJUAN ──────────────────────────── --}}
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Persetujuan
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Direview</div>
            <div style="color:#1e293b;font-weight:600;">
                {{ $item->reviewedBy?->name ?? '—' }}
                <span style="color:#94a3b8;font-weight:400;">
                    {{ $item->tanggal_direview ? '· ' . \Carbon\Carbon::parse($item->tanggal_direview)->format('d/m/Y') : '' }}
                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Disetujui</div>
            <div style="color:#1e293b;font-weight:600;">
                {{ $item->approvedFinalBy?->name ?? '—' }}
                <span style="color:#94a3b8;font-weight:400;">
                    {{ $item->tanggal_disetujui ? '· ' . \Carbon\Carbon::parse($item->tanggal_disetujui)->format('d/m/Y') : '' }}
                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Disahkan DPS</div>
            <div style="color:#1e293b;font-weight:600;">
                {{ $item->disahkanOleh?->name ?? '—' }}
                <span style="color:#94a3b8;font-weight:400;">
                    {{ $item->tanggal_disahkan ? '· ' . \Carbon\Carbon::parse($item->tanggal_disahkan)->format('d/m/Y') : '' }}
                </span>
            </div>
        </div>
        <div>
            <div style="color:#64748b;">Pencairan</div>
            <div style="color:#1e293b;font-weight:600;">
                {{ $item->tgl_pencairan?->format('d/m/Y') ?? '—' }}
            </div>
        </div>
    </div>
    @if($item->keterangan)
    <div style="margin-top:1rem;padding:0.75rem;background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;color:#475569;">
        <strong>Catatan:</strong> {{ $item->keterangan }}
    </div>
    @endif
</div>

{{-- ── RIWAYAT RESTRUKTURISASI ──────────────────────── --}}
@if($item->restrukturisasiRequests->count())
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Restrukturisasi
    </h3>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Skema</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Piutang Saat Ajuan</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Tenor Baru</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Kemampuan Bayar</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Catatan</th>
                </tr>
            </thead>
            <tbody>
                @foreach($item->restrukturisasiRequests as $req)
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.625rem 0.875rem;font-weight:600;color:#1e293b;">
                        {{ $req->skema_restrukturisasi ?? '—' }}
                        @if($req->addendum_no)
                        <div style="font-size:0.72rem;color:#94a3b8;">Addendum {{ $req->addendum_no }}</div>
                        @endif
                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">
                        Rp {{ number_format($req->sisa_piutang_snapshot ?? 0, 0, ',', '.') }}
                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;color:#1e293b;">{{ $req->tenor_baru ?? '—' }} bln</td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">
                        Rp {{ number_format($req->kemampuan_bayar_baru ?? 0, 0, ',', '.') }}
                    </td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;">
                        @php
                            $reqStyle = match($req->status) {
                                'APPROVED' => 'background:#d1fae5;color:#065f46;',
                                'REJECTED' => 'background:#fee2e2;color:#991b1b;',
                                default    => 'background:#fef3c7;color:#92400e;',
                            };
                        @endphp
                        <span style="padding:0.2rem 0.625rem;border-radius:9999px;font-size:0.72rem;font-weight:600;{{ $reqStyle }}">
                            {{ $req->status }}
                        </span>
                    </td>
                    <td style="padding:0.625rem 0.875rem;color:#475569;">
                        {{ $req->catatan_manajer ?? $req->catatan_ao ?? '—' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ── JADWAL ANGSURAN ──────────────────────────────── --}}
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;overflow:hidden;margin-top:1.25rem;">
    <div style="padding:1.25rem 1.25rem 0.875rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0;">
            Jadwal Angsuran
        </h3>
    </div>
    @if($item->jadwalAngsuran->count())
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.825rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Ke-</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Jatuh Tempo</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Pokok</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Margin</th>
                    <th style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#475569;">Jumlah</th>
                    <th style="padding:0.625rem 0.875rem;text-align:left;font-weight:600;color:#475569;">Tgl Bayar</th>
                    <th style="padding:0.625rem 0.875rem;text-align:center;font-weight:600;color:#475569;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($item->jadwalAngsuran as $a)
                @php
                    $badge = $a->status_badge;
                    $aStyle = match($badge['color']) {
                        'green'  => 'background:#d1fae5;color:#065f46;',
                        'red'    => 'background:#fee2e2;color:#991b1b;',
                        'yellow' => 'background:#fef3c7;color:#92400e;',
                        default  => 'background:#f1f5f9;color:#475569;',
                    };
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.625rem 0.875rem;text-align:center;color:#475569;">{{ $a->angsuran_ke }}</td>
                    <td style="padding:0.625rem 0.875rem;color:#1e293b;">{{ $a->tanggal_jatuh_tempo?->format('d/m/Y') ?? '—' }}</td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">Rp {{ number_format($a->pokok_angsuran ?? 0, 0, ',', '.') }}</td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;color:#1e293b;">Rp {{ number_format($a->margin_ujroh ?? 0, 0, ',', '.') }}</td>
                    <td style="padding:0.625rem 0.875rem;text-align:right;font-weight:600;color:#1e293b;">Rp {{ number_format($a->jumlah_angsuran ?? 0, 0, ',', '.') }}</td>
                    <td style="padding:0.625rem 0.875rem;color:#475569;">{{ $a->tanggal_bayar?->format('d/m/Y') ?? '—' }}</td>
                    <td style="padding:0.625rem 0.875rem;text-align:center;">
                        <span style="padding:0.2rem 0.625rem;border-radius:9999px;font-size:0.72rem;font-weight:600;{{ $aStyle }}">
                            {{ $badge['label'] }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    <div style="padding:0 1.25rem 1.5rem;font-size:0.825rem;color:#94a3b8;">
        Jadwal angsuran belum tersedia.
    </div>
    @endif
</div>

{{-- ── DOKUMEN PENDUKUNG ────────────────────────────── --}}
@if($item->dokumen->count())
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Dokumen Pendukung
    </h3>
    @foreach($item->dokumen as $dok)
    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.625rem 0.875rem;
                background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;margin-bottom:0.5rem;">
        <div>
            <span style="font-weight:600;color:#1e293b;">{{ $dok->nama_file }}</span>
            <span style="color:#94a3b8;margin-left:0.5rem;">{{ $dok->jenis_dokumen }}</span>
        </div>
        <a href="{{ route('berkas.unduh', ['path' => $dok->path_file]) }}" target="_blank"
           style="color:#3b82f6;font-size:0.75rem;font-weight:600;text-decoration:none;">Lihat →</a>
    </div>
    @endforeach
</div>
@endif

{{-- ── FOOTER ───────────────────────────────────────── --}}
<div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;
            margin-top:1.25rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;">
    <span style="font-size:0.825rem;color:#64748b;">
        Halaman monitoring (read-only) — keputusan approval dilakukan pada menu Approval Pembiayaan.
    </span>
    <div style="display:flex;gap:0.5rem;">
        <a href="{{ route('manajer.npf.index') }}"
           style="padding:0.5rem 1.125rem;background:#f1f5f9;color:#475569;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Kembali
        </a>
        @if($hariTunggakan > 0)
        <a href="{{ route('manajer.approval-restrukturisasi.index') }}"
           style="padding:0.5rem 1.125rem;background:#eab308;color:#fff;font-size:0.825rem;
                  font-weight:600;border-radius:0.5rem;text-decoration:none;">
            Ajukan Restrukturisasi
        </a>
        @endif
    </div>
</div>

@endsection
