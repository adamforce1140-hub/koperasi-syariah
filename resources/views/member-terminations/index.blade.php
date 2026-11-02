@extends('layouts.app')
@section('page-title', "Pengunduran Diri / Exit Keanggotaan")

@section('title', 'Pengunduran Diri / Exit Keanggotaan')

@section('sidebar')
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">&#128101;</span> Data &amp; Daftar Anggota
</a>
<div class="nav-section">Exit Keanggotaan</div>
<a href="{{ route('member-terminations.index') }}" class="nav-item active">
    <span class="icon">&#128196;</span> Pengunduran Diri
</a>
<a href="{{ route('member-terminations.create') }}" class="nav-item {{ request()->routeIs('member-terminations.create') ? 'active' : '' }}">
    <span class="icon">&#128221;</span> Ajukan Exit Baru
</a>
@endsection

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Header --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Pengunduran Diri / Exit Keanggotaan</h1>
        <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;line-height:1.6;">
            Modul terpusat <code>member_terminations</code> — <strong>satu-satunya</strong> pintu sah pengembalian dana
            Simpanan Pokok (<code>3-1001</code>) &amp; Simpanan Wajib (<code>3-1002</code>).
            Kedua akun adalah <strong>Modal/Ekuitas Koperasi</strong> (PSAK 101 &para; 31) dan tidak dapat ditarik
            selama anggota masih aktif. Pengembalian dana hanya dilakukan setelah seluruh kewajiban lunas
            dan jurnalnya diposting sesuai PSAK 108.
        </p>
    </div>

    {{-- Notifikasi --}}
    <div aria-live="polite">
        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;">
            &#10003; {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;font-size:.83rem;white-space:pre-line;">
            &#9888; {{ session('error') }}
        </div>
        @endif
    </div>

    {{-- Ringkasan --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.5rem;font-weight:700;color:#d97706;">{{ number_format($ringkasan['diajukan'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Pengajuan Menunggu Diproses</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.5rem;font-weight:700;color:#047857;">{{ number_format($ringkasan['disetujui'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Exit Selesai (Dana Dikembalikan)</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1.125rem 1.25rem;">
            <div style="font-size:1.25rem;font-weight:700;color:#1e293b;">Rp {{ number_format($ringkasan['ditotal'], 0, ',', '.') }}</div>
            <div style="font-size:.75rem;color:#64748b;margin-top:.2rem;">Total Dana Dikembalikan</div>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('member-terminations.index') }}"
          style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;padding:1rem 1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div style="display:flex;flex-direction:column;gap:.25rem;">
            <label for="status" style="font-size:.75rem;font-weight:600;color:#475569;">Status</label>
            <select name="status" id="status" style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
                <option value="">Semua Status</option>
                @foreach(\App\Models\MemberTermination::STATUS_LABEL as $nilai => $label)
                <option value="{{ $nilai }}" @selected(request('status') === $nilai)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div style="display:flex;flex-direction:column;gap:.25rem;flex:1;min-width:220px;">
            <label for="cari" style="font-size:.75rem;font-weight:600;color:#475569;">Cari No. Terminasi / Nama / No. Anggota</label>
            <input type="text" name="cari" id="cari" value="{{ request('cari') }}"
                   style="padding:.5rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
        </div>
        <button type="submit" style="padding:.55rem 1.1rem;background:#047857;color:#fff;border:0;border-radius:6px;font-size:.82rem;font-weight:600;cursor:pointer;">
            Filter
        </button>
        <a href="{{ route('member-terminations.create') }}"
           style="padding:.55rem 1.1rem;background:#b45309;color:#fff;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;">
            + Ajukan Exit
        </a>
    </form>

    {{-- Tabel --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                <thead>
                    <tr style="background:#f8fafc;text-align:left;color:#475569;">
                        <th style="padding:.7rem 1rem;font-weight:600;">No. Terminasi</th>
                        <th style="padding:.7rem 1rem;font-weight:600;">Anggota</th>
                        <th style="padding:.7rem 1rem;font-weight:600;">Tgl. Pengunduran Diri</th>
                        <th style="padding:.7rem 1rem;font-weight:600;">Status</th>
                        <th style="padding:.7rem 1rem;font-weight:600;text-align:right;">Pokok</th>
                        <th style="padding:.7rem 1rem;font-weight:600;text-align:right;">Wajib</th>
                        <th style="padding:.7rem 1rem;font-weight:600;text-align:right;">Total</th>
                        <th style="padding:.7rem 1rem;font-weight:600;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($daftar as $t)
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:.7rem 1rem;font-family:monospace;color:#1e293b;">
                            {{ $t->no_terminasi }}
                            @if($t->no_referensi)
                            <div style="font-size:.7rem;color:#94a3b8;">{{ $t->no_referensi }}</div>
                            @endif
                        </td>
                        <td style="padding:.7rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $t->anggota->nama_lengkap ?? '-' }}</div>
                            <div style="font-size:.72rem;color:#94a3b8;">{{ $t->anggota->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:.7rem 1rem;color:#475569;">
                            {{ $t->tanggal_pengunduran_diri ? $t->tanggal_pengunduran_diri->format('d/m/Y') : '-' }}
                        </td>
                        <td style="padding:.7rem 1rem;">
                            @php
                                $warna = match($t->status) {
                                    \App\Models\MemberTermination::STATUS_DIAJUKAN          => ['#fef3c7', '#92400e'],
                                    \App\Models\MemberTermination::STATUS_MENUNGGU_APPROVAL => ['#dbeafe', '#1e40af'],
                                    \App\Models\MemberTermination::STATUS_DISETUJUI         => ['#d1fae5', '#065f46'],
                                    \App\Models\MemberTermination::STATUS_DITOLAK           => ['#fee2e2', '#991b1b'],
                                    default                                                 => ['#f1f5f9', '#475569'],
                                };
                            @endphp
                            <span style="display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:600;background:{{ $warna[0] }};color:{{ $warna[1] }};">
                                {{ $t->status_label }}
                            </span>
                        </td>
                        <td style="padding:.7rem 1rem;text-align:right;color:#475569;">{{ number_format((float) $t->nominal_simpanan_pokok, 0, ',', '.') }}</td>
                        <td style="padding:.7rem 1rem;text-align:right;color:#475569;">{{ number_format((float) $t->nominal_simpanan_wajib, 0, ',', '.') }}</td>
                        <td style="padding:.7rem 1rem;text-align:right;font-weight:600;color:#1e293b;">{{ number_format((float) $t->total_dikembalikan, 0, ',', '.') }}</td>
                        <td style="padding:.7rem 1rem;">
                            <a href="{{ route('member-terminations.show', $t->id) }}"
                               style="color:#047857;font-weight:600;text-decoration:none;">Detail</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" style="padding:2rem 1rem;text-align:center;color:#94a3b8;">
                            Belum ada dokumen pengunduran diri. Klik <strong>+ Ajukan Exit</strong> untuk memulai proses.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>{{ $daftar->links() }}</div>
</div>
@endsection
