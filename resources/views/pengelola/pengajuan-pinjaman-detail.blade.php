@extends('layouts.app')
@section('page-title', "Detail Pengajuan Pembiayaan")

@section('title', 'Detail Pengajuan Pembiayaan')

@section('content')
@php
    $st   = strtolower($pengajuan->status ?? '');
    $role = auth()->user()->role ?? '';
    // Daftar peran berwenang berasal dari config/role_hierarki.php.
    $peranPeninjau = \App\Support\HierarkiRole::dalamKelompok($role, 'peninjau_pembiayaan');
    $peranPemutus  = \App\Support\HierarkiRole::dalamKelompok($role, 'pemutus_pembiayaan');
@endphp

<div class="page-header">
    <h1 class="page-title">Detail Pengajuan Pembiayaan</h1>
    <p class="page-subtitle">{{ $pengajuan->no_pengajuan ?? '-' }}</p>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-error">{{ session('error') }}</div>
@endif

<div style="margin-bottom:1rem;">
    <a href="{{ route('pengelola.pengajuan-pinjaman.index') }}"
       style="font-size:.85rem;color:#2563eb;text-decoration:none;">&larr; Kembali ke Daftar Pengajuan</a>
</div>

{{-- RINGKASAN --}}
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Ringkasan Pengajuan</span>
        @if(in_array($st, ['menunggu', 'direview', 'diproses']))
            <span class="badge badge-amber">Menunggu Review</span>
        @elseif(in_array($st, ['disetujui']))
            <span class="badge badge-green">Disetujui</span>
        @elseif(in_array($st, ['ditolak']))
            <span class="badge badge-red">Ditolak</span>
        @else
            <span class="badge">{{ $pengajuan->status ?? '-' }}</span>
        @endif
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.2rem;">
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Nasabah</div>
            <div style="font-weight:600;color:#1e293b;">{{ $pengajuan->anggota?->nama_lengkap ?? '-' }}</div>
            <div style="font-size:.78rem;color:#94a3b8;">CIF: {{ $pengajuan->anggota?->no_cif ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Produk</div>
            <div style="font-weight:600;color:#1e293b;">{{ $pengajuan->produk?->nama_produk ?? $pengajuan->jenis_akad ?? '-' }}</div>
            <div style="font-size:.78rem;color:#94a3b8;">Akad: {{ $pengajuan->jenis_akad ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Jumlah Pengajuan</div>
            <div style="font-weight:700;color:#059669;">Rp {{ number_format((float) ($pengajuan->jumlah_pengajuan ?? 0), 0, ',', '.') }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tenor</div>
            <div style="font-weight:600;color:#1e293b;">{{ (int) ($pengajuan->tenor ?? 0) }} bulan</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Margin</div>
            <div style="font-weight:600;color:#1e293b;">{{ rtrim(rtrim(number_format((float) ($pengajuan->margin_persen ?? 0), 2, ',', '.'), '0'), ',') }}%</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Angsuran / Bulan</div>
            <div style="font-weight:600;color:#1e293b;">Rp {{ number_format((float) ($pengajuan->angsuran_per_bulan ?? 0), 0, ',', '.') }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tujuan</div>
            <div style="font-weight:600;color:#1e293b;">{{ $pengajuan->tujuan ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Agunan</div>
            <div style="font-weight:600;color:#1e293b;">{{ $pengajuan->agunan ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Tanggal Pengajuan</div>
            <div style="font-weight:600;color:#1e293b;">{{ optional($pengajuan->created_at)->format('d/m/Y H:i') ?? '-' }}</div>
        </div>
    </div>
</div>

{{-- ANALISIS 5C --}}
@if(!empty($pengajuan->analisis_5c))
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Analisis 5C</span>
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;">
        @foreach((array) $pengajuan->analisis_5c as $kunci => $nilai)
            <div style="background:#f8fafc;border:1px solid #f1f5f9;border-radius:8px;padding:.9rem;">
                <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">{{ $kunci }}</div>
                <div style="font-weight:600;color:#1e293b;">{{ is_array($nilai) ? json_encode($nilai) : $nilai }}</div>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- CATATAN SURVEI --}}
@if(!empty($pengajuan->catatan_survei))
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Catatan Survei</span>
    </div>
    <div style="padding:1.5rem;color:#334155;line-height:1.6;">{{ $pengajuan->catatan_survei }}</div>
</div>
@endif

{{-- DOKUMEN --}}
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Dokumen Pendukung</span>
        <span style="font-size:.78rem;color:#94a3b8;">{{ $pengajuan->dokumen->count() }} berkas</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>Jenis Dokumen</th>
                <th>Nama File</th>
                <th>Ukuran</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($pengajuan->dokumen as $dok)
            <tr>
                <td>{{ $dok->jenis_dokumen ?? '-' }}</td>
                <td>{{ $dok->nama_file ?? '-' }}</td>
                <td>{{ isset($dok->ukuran_file) ? $dok->ukuran_file . ' KB' : '-' }}</td>
                <td>
                    @if(!empty($dok->path_file))
                        <a href="{{ route('berkas.unduh', ['path' => $dok->path_file]) }}"
                           target="_blank" rel="noopener"
                           style="font-size:.8rem;color:#2563eb;text-decoration:none;">Lihat</a>
                    @else
                        <span style="font-size:.8rem;color:#94a3b8;">-</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:1.5rem;">Belum ada dokumen</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- JEJAK PERSETUJUAN --}}
<div class="data-card" style="margin-bottom:1.5rem;">
    <div class="card-header">
        <span class="card-title">Jejak Persetujuan</span>
    </div>
    <div style="padding:1.5rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.2rem;">
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Diproses Oleh</div>
            <div style="font-weight:600;color:#1e293b;">{{ $pengajuan->diprosesOleh?->name ?? '-' }}</div>
            <div style="font-size:.78rem;color:#94a3b8;">{{ optional($pengajuan->tanggal_diproses)->format('d/m/Y H:i') ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Catatan Manajer</div>
            <div style="color:#334155;">{{ $pengajuan->catatan_manajer ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Catatan Pengurus</div>
            <div style="color:#334155;">{{ $pengajuan->catatan_pengurus ?? '-' }}</div>
        </div>
        <div>
            <div style="font-size:.72rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Kontrak Pembiayaan</div>
            @if($pengajuan->pembiayaan)
                <div style="font-weight:600;color:#059669;">{{ $pengajuan->pembiayaan->no_pembiayaan ?? '-' }}</div>
            @else
                <div style="color:#94a3b8;">Belum dibuat</div>
            @endif
        </div>
    </div>
</div>

{{-- AKSI: hanya tampil bila kontrak pembiayaan sudah terbentuk,
     karena rute review/approve bertipe Pembiayaan (bukan PengajuanPembiayaan). --}}
@if($pengajuan->pembiayaan)
<div style="display:flex;gap:.6rem;flex-wrap:wrap;">
    {{-- Konfirmasi WAJIB: kedua aksi ber-status PATCH dan langsung mengubah
         status pengajuan (review → diteruskan ke pengurus; approve → kontrak
         pembiayaan aktif). Tanpa `onsubmit`, satu klik tak sengaja langsung
         memproses approval tanpa kesempatan membatalkan. --}}
    @if($peranPeninjau && in_array($st, ['menunggu']))
        <form method="POST" action="{{ route('pengelola.pengajuan-pinjaman.review', $pengajuan->pembiayaan) }}"
            onsubmit="return confirm('Teruskan pengajuan ini ke Pengurus untuk disetujui?');">
            @csrf
            @method('PATCH')
            <input type="hidden" name="catatan_manajer" value="Direview dari halaman detail">
            <button type="submit"
                style="padding:.6rem 1.2rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
                Teruskan ke Pengurus
            </button>
        </form>
    @endif

    @if($peranPemutus && in_array($st, ['direview', 'diproses']))
        <form method="POST" action="{{ route('pengelola.pengajuan-pinjaman.approve', $pengajuan->pembiayaan) }}"
            onsubmit="return confirm('Setujui pengajuan ini? Pencairan dana dilakukan oleh pengguna lain.');">
            @csrf
            @method('PATCH')
            <input type="hidden" name="catatan_pengelola" value="Disetujui dari halaman detail">
            <button type="submit"
                style="padding:.6rem 1.2rem;background:#059669;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
                Setujui Pengajuan
            </button>
        </form>
    @endif
</div>
@endif
@endsection
