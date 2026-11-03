@extends('layouts.app')
@section('page-title', "Pusat Konfirmasi Auto-Debit")

@section('title', 'Pusat Konfirmasi Auto-Debit')

@section('sidebar')
<div class="nav-section">Approval &amp; Konfirmasi</div>
<a href="{{ route('autodebit.index') }}" class="nav-item active">
    <span class="icon">&#128276;</span> Pusat Konfirmasi Auto-Debit
</a>
{{-- Role yang berhak: manajer, super_admin, admin (back-office).
     Teller sengaja TIDAK diberi akses — lihat Segregation of Duties. --}}
<a href="{{ url('/') }}" class="nav-item">
    <span class="icon">&#8592;</span> Kembali ke Dasbor
</a>
@endsection

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- Header --}}
    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Pusat Konfirmasi Auto-Debit</h1>
        <p style="margin:.25rem 0 0;font-size:.83rem;color:#64748b;">
            Satu-satunya pintu pemotongan dana otomatis. Sistem (Simpanan Wajib, Angsuran, Ta&rsquo;zir)
            hanya <strong>mengusulkan</strong>; dana <strong>BELUM dipotong</strong> sampai Anda menekan
            tombol <strong>Konfirmasi</strong>. Tanpa konfirmasi di halaman ini, saldo anggota tidak tersentuh.
        </p>
    </div>

    {{-- Notifikasi --}}
    <div aria-live="polite">
        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;">
            &#10003; {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;">
            &#9888; {{ session('error') }}
        </div>
        @endif
        @if(session('warning'))
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;">
            &#9888; {{ session('warning') }}
        </div>
        @endif
        @if($errors->any())
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-top:.5rem;">
            <ul style="margin:0;padding-left:1.1rem;">
                @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>

    {{-- Ringkasan --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;">
            <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Menunggu Konfirmasi</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b45309;">{{ $totalMenunggu }}</div>
            <div style="font-size:.72rem;color:#94a3b8;">Dana belum dipotong</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;">
            <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.03em;">Usulan Hari Ini</div>
            <div style="font-size:1.6rem;font-weight:700;color:#1e293b;">{{ $totalHariIni }}</div>
            <div style="font-size:.72rem;color:#94a3b8;">Dibuat oleh seluruh kanal</div>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" action="{{ route('autodebit.index') }}"
          style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.875rem 1rem;">
        <label style="font-size:.8rem;color:#475569;font-weight:600;">Status</label>
        <select name="status" onchange="this.form.submit()"
                style="padding:.4rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
            @foreach(\App\Models\AutodebitUsulan::STATUS_LABEL as $value => $label)
            <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>

        <label style="font-size:.8rem;color:#475569;font-weight:600;margin-left:.5rem;">Kanal</label>
        <select name="kanal" onchange="this.form.submit()"
                style="padding:.4rem .6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.82rem;">
            <option value="">Semua Kanal</option>
            @foreach(\App\Models\AutodebitUsulan::KANAL_LABEL as $value => $label)
            <option value="{{ $value }}" {{ ($kanal ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>

        <a href="{{ route('autodebit.index') }}"
           style="margin-left:auto;font-size:.8rem;color:#64748b;text-decoration:none;">Reset</a>
    </form>

    {{-- Tabel usulan --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
        @if($usulan->count())
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                <thead>
                    <tr style="background:#f8fafc;text-align:left;color:#475569;">
                        <th style="padding:.7rem .9rem;font-weight:600;">Kanal</th>
                        <th style="padding:.7rem .9rem;font-weight:600;">Anggota</th>
                        <th style="padding:.7rem .9rem;font-weight:600;">Periode / Ref</th>
                        <th style="padding:.7rem .9rem;font-weight:600;text-align:right;">Nominal</th>
                        <th style="padding:.7rem .9rem;font-weight:600;">Status</th>
                        <th style="padding:.7rem .9rem;font-weight:600;">Dibuat</th>
                        <th style="padding:.7rem .9rem;font-weight:600;text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($usulan as $u)
                    <tr style="border-top:1px solid #f1f5f9;">
                        <td style="padding:.7rem .9rem;">
                            <span style="display:inline-block;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:600;
                                background:#e0e7ff;color:#3730a3;">{{ $u->kanal_label }}</span>
                        </td>
                        <td style="padding:.7rem .9rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $u->anggota?->nama ?? '—' }}</div>
                            <div style="font-size:.72rem;color:#94a3b8;">{{ $u->anggota?->no_anggota ?? '' }}</div>
                        </td>
                        <td style="padding:.7rem .9rem;color:#475569;">
                            {{ $u->periode ?: '—' }}
                            <div style="font-size:.72rem;color:#94a3b8;">Ref #{{ $u->referensi_id }}</div>
                        </td>
                        <td style="padding:.7rem .9rem;text-align:right;font-weight:700;color:#0f172a;">{{ $u->nominal_formatted }}</td>
                        <td style="padding:.7rem .9rem;">
                            @php
                                $warna = match($u->status) {
                                    \App\Models\AutodebitUsulan::STATUS_MENUNGGU_KONFIRMASI => ['#fef3c7', '#92400e'],
                                    \App\Models\AutodebitUsulan::STATUS_DIKONFIRMASI        => ['#d1fae5', '#065f46'],
                                    \App\Models\AutodebitUsulan::STATUS_DITOLAK             => ['#e2e8f0', '#334155'],
                                    \App\Models\AutodebitUsulan::STATUS_GAGAL               => ['#fee2e2', '#991b1b'],
                                    default                                                => ['#f1f5f9', '#475569'],
                                };
                            @endphp
                            <span style="display:inline-block;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:600;
                                background:{{ $warna[0] }};color:{{ $warna[1] }};">{{ $u->status_label }}</span>
                            @if($u->alasan)
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:.15rem;">{{ $u->alasan }}</div>
                            @endif
                        </td>
                        <td style="padding:.7rem .9rem;color:#64748b;">{{ $u->dibuat_formatted }}</td>
                        <td style="padding:.7rem .9rem;text-align:right;">
                            @if($u->isMenungguKonfirmasi())
                            <div style="display:flex;gap:.4rem;justify-content:flex-end;">
                                <form method="POST" action="{{ route('autodebit.konfirmasi', $u) }}"
                                      onsubmit="return confirm('Konfirmasi pemotongan {{ $u->nominal_formatted }} dari rekening {{ $u->anggota?->nama }}?\n\nDana akan dipotong setelah Anda menyetujui.');">
                                    @csrf
                                    <input type="hidden" name="catatan" value="">
                                    <button type="submit"
                                            style="background:#059669;color:#fff;border:none;border-radius:6px;padding:.4rem .7rem;font-size:.75rem;font-weight:600;cursor:pointer;">
                                        Konfirmasi
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('autodebit.tolak', $u) }}"
                                      onsubmit="return confirm('Tolak usulan ini? Tidak ada dana yang dipotong.');">
                                    @csrf
                                    <input type="hidden" name="catatan" value="">
                                    <button type="submit"
                                            style="background:#fff;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;padding:.4rem .7rem;font-size:.75rem;font-weight:600;cursor:pointer;">
                                        Tolak
                                    </button>
                                </form>
                            </div>
                            @else
                            <span style="font-size:.72rem;color:#94a3b8;">
                                @if($u->confirmedBy)
                                oleh {{ $u->confirmedBy->name }}
                                @else
                                &mdash;
                                @endif
                            </span>
                            @endif
                        </td>
                    </tr>
                    @if($u->catatan)
                    <tr style="border-top:1px dashed #f1f5f9;background:#fcfcfd;">
                        <td colspan="7" style="padding:.4rem .9rem .7rem;font-size:.75rem;color:#64748b;">
                            <strong>Catatan sistem:</strong> {{ $u->catatan }}
                            @if($u->confirmation_note)
                            <br><strong>Catatan konfirmasi:</strong> {{ $u->confirmation_note }}
                            @endif
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($usulan->hasPages())
        <div style="padding:.875rem 1.25rem;border-top:1px solid #f1f5f9;">{{ $usulan->links() }}</div>
        @endif
        @else
        <div style="padding:3.5rem 1.5rem;text-align:center;">
            <div style="font-size:.95rem;font-weight:600;color:#1e293b;margin-bottom:.375rem;">Tidak ada usulan</div>
            <p style="font-size:.825rem;color:#94a3b8;">Belum ada usulan auto-debit dengan filter ini.</p>
        </div>
        @endif
    </div>

</div>
@endsection
