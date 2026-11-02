@extends('layouts.app')

@section('title', 'Detail Pengajuan — Manajer')
@section('page-title', 'Tinjau Pengajuan Pembiayaan')

@section('content')

@php
    $statusLabel = match($item->status_pembiayaan ?? '') {
        'pending_ao'       => 'Menunggu AO',
        'pending_manajer'  => 'Menunggu Manajer',
        'pending_pengurus' => 'Menunggu Pengurus',
        'pending_dps'      => 'Menunggu DPS',
        'disetujui'        => 'Disetujui',
        'dicairkan'        => 'Dicairkan',
        'ditolak'          => 'Ditolak',
        default            => ucfirst($item->status_pembiayaan ?? '-'),
    };
    $statusStyle = match($item->status_pembiayaan ?? '') {
        'pending_manajer'  => 'background:#dbeafe;color:#1e40af;',
        'pending_pengurus' => 'background:#ede9fe;color:#6d28d9;',
        'pending_dps'      => 'background:#e0e7ff;color:#3730a3;',
        'disetujui'        => 'background:#d1fae5;color:#065f46;',
        'dicairkan'        => 'background:#e0f2fe;color:#0369a1;',
        'ditolak'          => 'background:#fee2e2;color:#991b1b;',
        default            => 'background:#fef3c7;color:#92400e;',
    };
    $isPendingManajer = $item->status_pembiayaan === 'pending_manajer';
@endphp

{{-- Flash messages --}}
@if(session('success'))
<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:0.625rem;padding:0.875rem 1.125rem;
            margin-bottom:1rem;display:flex;align-items:center;gap:0.625rem;color:#065f46;font-size:0.875rem;font-weight:500;">
    ✅ {{ session('success') }}
</div>
@endif
@if(session('error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:0.625rem;padding:0.875rem 1.125rem;
            margin-bottom:1rem;color:#991b1b;font-size:0.875rem;font-weight:500;">
    ❌ {{ session('error') }}
</div>
@endif

{{-- Header --}}
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
    <div>
        <a href="{{ route('manajer.approval-pembiayaan.index') }}"
           style="color:#2563eb;text-decoration:none;font-size:0.85rem;display:inline-flex;align-items:center;gap:0.35rem;">
            ← Kembali ke Daftar
        </a>
        <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin-top:0.3rem;">
            Detail Pengajuan Pembiayaan
        </h1>
        <p style="color:#94a3b8;font-size:0.85rem;margin:0.15rem 0 0;">
            {{ $item->no_pembiayaan ?? ('PMB-' . str_pad($item->id, 6, '0', STR_PAD_LEFT)) }}
            &middot;
            {{ $item->created_at?->format('d/m/Y H:i') ?? '-' }}
        </p>
    </div>
    <span style="padding:0.35rem 1rem;border-radius:9999px;font-size:0.8rem;font-weight:700;{{ $statusStyle }}">
        {{ $statusLabel }}
    </span>
</div>

{{-- Stepper --}}
@php
    $steps = ['pending_ao' => 'AO', 'pending_manajer' => 'Manajer', 'pending_pengurus' => 'Pengurus', 'pending_dps' => 'DPS', 'disetujui' => 'Setujui', 'dicairkan' => 'Cair'];
    $stepKeys = array_keys($steps);
    $currentIdx = array_search($item->status_pembiayaan, $stepKeys);
    if ($currentIdx === false) $currentIdx = -1;
@endphp
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-bottom:1.25rem;
            display:flex;align-items:center;justify-content:space-between;overflow-x:auto;gap:0.5rem;">
    @foreach($steps as $key => $label)
    @php $idx = array_search($key, $stepKeys); @endphp
    <div style="display:flex;flex-direction:column;align-items:center;gap:0.35rem;min-width:60px;">
        <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                    font-size:0.75rem;font-weight:700;
                    {{ $idx < $currentIdx ? 'background:#10b981;color:#fff;' : ($idx === $currentIdx ? 'background:#f59e0b;color:#fff;' : 'background:#e2e8f0;color:#94a3b8;') }}">
            {{ $idx < $currentIdx ? '✓' : ($idx + 1) }}
        </div>
        <span style="font-size:0.68rem;color:{{ $idx <= $currentIdx ? '#1e293b' : '#94a3b8' }};font-weight:{{ $idx === $currentIdx ? '700' : '500' }};">
            {{ $label }}
        </span>
    </div>
    @if(!$loop->last)
    <div style="flex:1;height:2px;background:{{ $idx < $currentIdx ? '#10b981' : '#e2e8f0' }};min-width:20px;margin-bottom:1rem;"></div>
    @endif
    @endforeach
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

    {{-- Data Anggota --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Data Anggota & Identitas
        </h3>
        @php $anggota = $item->anggota; @endphp
        @foreach([
            'Nama Lengkap'  => $anggota?->nama_lengkap,
            'No. Anggota'   => $anggota?->no_anggota,
            'NIK'           => $anggota?->nik,
            'Jenis Kelamin' => $anggota?->jenis_kelamin ? ($anggota->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : '—',
            'Pekerjaan'     => $anggota?->pekerjaan ?? '—',
            'No. HP'        => $anggota?->no_hp,
            'Alamat'        => $anggota?->alamat,
        ] as $label => $value)
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;">{{ $label }}</span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;">{{ $value ?? '—' }}</span>
        </div>
        @endforeach

        @if($anggota && $anggota->foto_ktp)
        <div style="margin-top:1rem;padding-top:0.75rem;border-top:1px dashed #e2e8f0;">
            <div style="font-size:0.825rem;font-weight:600;color:#475569;margin-bottom:0.4rem;">Foto KTP Anggota:</div>
            <a href="{{ route('berkas.unduh', ['path' => $anggota->foto_ktp]) }}" target="_blank">
                <img src="{{ route('berkas.unduh', ['path' => $anggota->foto_ktp]) }}" alt="Foto KTP"
                     style="max-width:180px;height:auto;border-radius:6px;border:1px solid #cbd5e1;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
            </a>
        </div>
        @endif
    </div>

    {{-- Detail Pembiayaan & Analisis --}}
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
            Detail Pembiayaan & Analisis Kelayakan (5C)
        </h3>
        @foreach([
            'Akad'          => $item->akad_pembiayaan,
            'Jumlah'        => 'Rp ' . number_format($item->jumlah_pembiayaan ?? 0, 0, ',', '.'),
            'Tenor'         => ($item->tenor ?? '—') . ' bulan',
            'Margin/Nisbah' => ($item->margin_nisbah ?? '—') . '%',
            'Total'         => 'Rp ' . number_format($item->total_pembiayaan ?? 0, 0, ',', '.'),
            'Angsuran/Bln'  => 'Rp ' . number_format($item->jumlah_angsuran ?? 0, 0, ',', '.'),
            'Tujuan'        => $item->tujuan_pembiayaan,
            'Agunan (Collateral)' => $item->agunan ?? '—',
        ] as $label => $value)
        <div style="display:flex;justify-content:space-between;padding:0.45rem 0;border-bottom:1px solid #f1f5f9;font-size:0.825rem;">
            <span style="color:#64748b;">{{ $label }}</span>
            <span style="color:#1e293b;font-weight:500;text-align:right;max-width:60%;">{{ $value ?? '—' }}</span>
        </div>
        @endforeach
    </div>

</div>

{{-- Riwayat Approval --}}
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Riwayat Persetujuan
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;font-size:0.825rem;">
        <div>
            <div style="color:#64748b;">Direview AO</div>
            <div style="color:#1e293b;font-weight:600;">{{ $item->tanggal_direview ? \Carbon\Carbon::parse($item->tanggal_direview)->format('d/m/Y') : '—' }}</div>
        </div>
        <div>
            <div style="color:#64748b;">Disetujui Manajer</div>
            <div style="color:#1e293b;font-weight:600;">{{ $item->tanggal_disetujui ? \Carbon\Carbon::parse($item->tanggal_disetujui)->format('d/m/Y') : '—' }}</div>
        </div>
        <div>
            <div style="color:#64748b;">Disahkan Pengurus/DPS</div>
            <div style="color:#1e293b;font-weight:600;">{{ $item->tanggal_disahkan ? \Carbon\Carbon::parse($item->tanggal_disahkan)->format('d/m/Y') : '—' }}</div>
        </div>
        <div>
            <div style="color:#64748b;">Rencana Cair</div>
            <div style="color:#1e293b;font-weight:600;">{{ $item->tgl_pencairan ? \Carbon\Carbon::parse($item->tgl_pencairan)->format('d/m/Y') : '—' }}</div>
        </div>
    </div>
    @if($item->keterangan)
    <div style="margin-top:1rem;padding:0.75rem;background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;color:#475569;">
        <strong>Catatan:</strong> {{ $item->keterangan }}
    </div>
    @endif
</div>

{{-- Dokumen Pendukung --}}
@if($item->dokumen && $item->dokumen->count())
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;">
    <h3 style="font-size:0.875rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin:0 0 1rem;">
        Dokumen Pendukung
    </h3>
    <div style="display:flex;flex-direction:column;gap:0.5rem;">
        @foreach($item->dokumen as $dok)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.625rem 0.875rem;
                    background:#f8fafc;border-radius:0.5rem;font-size:0.825rem;">
            <div>
                <span style="font-weight:600;color:#1e293b;">{{ $dok->nama_file }}</span>
                <span style="color:#94a3b8;margin-left:0.5rem;">{{ $dok->jenis_dokumen }}</span>
            </div>
            <a href="{{ route('berkas.unduh', ['path' => $dok->path_file]) }}" target="_blank"
               style="color:#2563eb;font-size:0.75rem;font-weight:600;text-decoration:none;">
                Lihat →
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Tombol Aksi --}}
@if($isPendingManajer)
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;margin-top:1.25rem;
            display:flex;justify-content:flex-end;gap:0.75rem;align-items:center;flex-wrap:wrap;">
    
    {{-- Minta Revisi ke AO --}}
    <button type="button" onclick="document.getElementById('modalRevisi').style.display='flex'"
        style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.1rem;
               background:#d97706;color:#fff;font-size:0.825rem;font-weight:600;
               border:none;border-radius:0.5rem;cursor:pointer;">
        🔄 Minta Revisi Survei (AO)
    </button>

    {{-- Tolak --}}
    <button type="button" onclick="document.getElementById('modalReject').style.display='flex'"
        style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.1rem;
               background:#dc2626;color:#fff;font-size:0.825rem;font-weight:600;
               border:none;border-radius:0.5rem;cursor:pointer;">
        ✕ Tolak Pengajuan
    </button>

    {{-- Teruskan ke Pengurus / DPS --}}
    <button type="button" onclick="document.getElementById('modalForward').style.display='flex'"
        style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.1rem;
               background:#4f46e5;color:#fff;font-size:0.825rem;font-weight:600;
               border:none;border-radius:0.5rem;cursor:pointer;">
        ↗ Teruskan ke Pengurus / DPS
    </button>

    {{-- Setujui --}}
    <form action="{{ route('manajer.approval-pembiayaan.approve', $item->id) }}" method="POST"
          onsubmit="return confirm('Setujui pengajuan ini dan teruskan ke Pengurus?')">
        @csrf
        <button type="submit"
            style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;
                   background:#059669;color:#fff;font-size:0.875rem;font-weight:600;
                   border:none;border-radius:0.5rem;cursor:pointer;">
            ✓ Setujui (Limit Manajer)
        </button>
    </form>
</div>
@else
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1rem 1.25rem;margin-top:1.25rem;
            font-size:0.85rem;color:#64748b;">
    Status saat ini: <strong style="color:#1e293b;">{{ $statusLabel }}</strong> — tidak ada aksi yang tersedia untuk Manajer.
</div>
@endif

{{-- Modal Tolak --}}
<div id="modalReject" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0.875rem;padding:1.5rem;max-width:450px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Tolak Pengajuan Pembiayaan</h3>
        <form action="{{ route('manajer.approval-pembiayaan.reject', $item->id) }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.825rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Alasan Penolakan <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="catatan_tolak" rows="4" required placeholder="Tuliskan alasan penolakan..."
                    style="width:100%;padding:0.65rem 0.85rem;border:1px solid #d1d5db;border-radius:0.5rem;
                           font-size:0.875rem;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalReject').style.display='none'"
                    style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;border-radius:0.5rem;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.5rem 1.25rem;background:#dc2626;color:#fff;border:none;border-radius:0.5rem;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Ya, Tolak
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Revisi Survei --}}
<div id="modalRevisi" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0.875rem;padding:1.5rem;max-width:450px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Minta Revisi Survei ke AO</h3>
        <form action="{{ route('manajer.approval-pembiayaan.revisi', $item->id) }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.825rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Catatan Revisi / Dokumen Tambahan <span style="color:#dc2626;">*</span>
                </label>
                <textarea name="catatan_revisi" rows="4" required placeholder="Jelaskan hal yang perlu diperbaiki atau ditambah oleh AO..."
                    style="width:100%;padding:0.65rem 0.85rem;border:1px solid #d1d5db;border-radius:0.5rem;
                           font-size:0.875rem;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalRevisi').style.display='none'"
                    style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;border-radius:0.5rem;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.5rem 1.25rem;background:#d97706;color:#fff;border:none;border-radius:0.5rem;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Kirim Permintaan Revisi
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Teruskan (Over Limit) --}}
<div id="modalForward" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);
     z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:0.875rem;padding:1.5rem;max-width:450px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <h3 style="font-size:1rem;font-weight:700;color:#1e293b;margin:0 0 1rem;">Teruskan Pembiayaan (Over-Limit)</h3>
        <form action="{{ route('manajer.approval-pembiayaan.forward', $item->id) }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.825rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Tujuan Eskalasi <span style="color:#dc2626;">*</span>
                </label>
                <select name="target" style="width:100%;padding:0.55rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.875rem;">
                    <option value="pengurus">Pengurus Koperasi (Plafond Tinggi)</option>
                    <option value="dps">Dewan Pengawas Syariah (DPS - Syariah Review)</option>
                </select>
            </div>
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:0.825rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">
                    Catatan Rekomendasi Manajer
                </label>
                <textarea name="catatan" rows="3" placeholder="Alasan penerusan / rekomendasi komite..."
                    style="width:100%;padding:0.65rem 0.85rem;border:1px solid #d1d5db;border-radius:0.5rem;
                           font-size:0.875rem;resize:vertical;box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalForward').style.display='none'"
                    style="padding:0.5rem 1.25rem;background:#f1f5f9;color:#475569;border:none;border-radius:0.5rem;cursor:pointer;font-size:0.875rem;">
                    Batal
                </button>
                <button type="submit"
                    style="padding:0.5rem 1.25rem;background:#4f46e5;color:#fff;border:none;border-radius:0.5rem;font-weight:600;cursor:pointer;font-size:0.875rem;">
                    Teruskan Pengajuan
                </button>
            </div>
        </form>
    </div>
</div>

@endsection
