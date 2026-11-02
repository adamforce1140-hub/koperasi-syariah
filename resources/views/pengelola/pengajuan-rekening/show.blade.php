@extends('layouts.app')
@section('page-title', "Detail Pengajuan")

@section('title', 'Detail Pengajuan')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('pengelola.pengajuan-rekening.index') }}"
           style="color:#0d9488;text-decoration:none;font-size:0.85rem;">← Kembali ke Daftar</a>
        <h1 class="page-title" style="margin-top:0.25rem;">Detail Pengajuan Rekening</h1>
        <p style="color:#94a3b8;margin:0;">{{ $pengajuan->kode_pengajuan }}</p>
    </div>
    <div>
        <span class="badge {{ $pengajuan->statusBadgeClass() }}" style="font-size:0.9rem;padding:0.4rem 1rem;">
            {{ $pengajuan->status }}
        </span>
    </div>
</div>

@if(session('success'))
<div style="background:rgba(16,185,129,0.15);border:1px solid #10b981;color:#10b981;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div style="background:rgba(239,68,68,0.15);border:1px solid #ef4444;color:#ef4444;padding:0.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
    {{ session('error') }}
</div>
@endif

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;">

    {{-- Kolom Kiri: Data Diri --}}
    <div>
        <div class="card" style="margin-bottom:1.5rem;">
            <h3 style="color:white;margin:0 0 1rem;font-size:1rem;">Data Diri Calon Nasabah</h3>
            <table style="width:100%;border-collapse:collapse;">
                @php
                $fields = [
                    'Nama Lengkap'     => $pengajuan->nama_lengkap,
                    'NIK'              => $pengajuan->nik,
                    'Tempat Lahir'     => $pengajuan->tempat_lahir ?? '-',
                    'Tanggal Lahir'    => $pengajuan->tanggal_lahir?->format('d/m/Y') ?? '-',
                    'Jenis Kelamin'    => $pengajuan->jenis_kelamin,
                    'No. HP'           => $pengajuan->no_hp ?? '-',
                    'Email'            => $pengajuan->email ?? '-',
                    'Pekerjaan'        => $pengajuan->pekerjaan ?? '-',
                    'Alamat'           => $pengajuan->alamat,
                    'Kota'             => $pengajuan->kota ?? '-',
                    'Provinsi'         => $pengajuan->provinsi ?? '-',
                ];
                @endphp
                @foreach($fields as $label => $val)
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;width:40%;">{{ $label }}</td>
                    <td style="padding:0.5rem 0;color:white;font-size:0.85rem;">{{ $val }}</td>
                </tr>
                @endforeach
            </table>
        </div>

        {{-- Dokumen --}}
        <div class="card">
            <h3 style="color:white;margin:0 0 1rem;font-size:1rem;">Dokumen</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;">
                <div>
                    <p style="color:#94a3b8;font-size:0.8rem;margin:0 0 0.5rem;">Foto e-KTP</p>
                    @if($pengajuan->foto_ktp)
                        <img src="{{ route('berkas.unduh', ['path' => $pengajuan->foto_ktp]) }}"
                             style="width:100%;border-radius:8px;border:1px solid rgba(255,255,255,0.1);" />
                    @else
                        <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:2rem;text-align:center;color:#94a3b8;">
                            Belum ada foto
                        </div>
                    @endif
                </div>
                <div>
                    <p style="color:#94a3b8;font-size:0.8rem;margin:0 0 0.5rem;">Selfie + KTP</p>
                    @if($pengajuan->foto_selfie_ktp)
                        <img src="{{ route('berkas.unduh', ['path' => $pengajuan->foto_selfie_ktp]) }}"
                             style="width:100%;border-radius:8px;border:1px solid rgba(255,255,255,0.1);" />
                    @else
                        <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:2rem;text-align:center;color:#94a3b8;">
                            Belum ada foto
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Kolom Kanan: Aksi & Pembayaran --}}
    <div>
        {{-- Info Simpanan --}}
        <div class="card" style="margin-bottom:1.5rem;">
            <h3 style="color:white;margin:0 0 1rem;font-size:1rem;">Informasi Simpanan</h3>
            <table style="width:100%;border-collapse:collapse;">
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;width:50%;">Jenis Simpanan</td>
                    <td style="padding:0.5rem 0;color:white;font-size:0.85rem;font-weight:600;">{{ $pengajuan->jenis_simpanan }}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;">Nominal Awal</td>
                    <td style="padding:0.5rem 0;color:#f0c040;font-size:0.85rem;font-weight:600;">
                        @if($pengajuan->nominal_simpanan_awal > 0)
                            Rp {{ number_format($pengajuan->nominal_simpanan_awal, 0, ',', '.') }}
                        @else — @endif
                    </td>
                </tr>
                @if($pengajuan->no_rekening_terbit)
                <tr>
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;">No. Rekening</td>
                    <td style="padding:0.5rem 0;color:#10b981;font-size:0.85rem;font-weight:700;font-family:monospace;">
                        {{ $pengajuan->no_rekening_terbit }}
                    </td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Aksi Verifikasi --}}
        @if($pengajuan->status === 'Menunggu Verifikasi')
        <div class="card" style="margin-bottom:1.5rem;border:1px solid rgba(59,130,246,0.3);">
            <h3 style="color:white;margin:0 0 1rem;font-size:1rem;">Verifikasi Pengajuan</h3>

            {{-- Setujui --}}
            <form action="{{ route('pengelola.pengajuan-rekening.setujui', $pengajuan) }}" method="POST"
                  style="margin-bottom:1rem;">
                @csrf
                <label style="color:#94a3b8;font-size:0.85rem;display:block;margin-bottom:0.4rem;">
                    Nominal Simpanan Awal (Rp)
                </label>
                @php
                    $minPembukaan = (float) ($batasPembukaan['minimum'] ?? 10000);
                    $maksPembukaan = (float) ($batasPembukaan['maksimum'] ?? 50000000);
                @endphp
                <div style="display:flex;gap:0.5rem;">
                    <input type="number" name="nominal_simpanan_awal"
                           min="{{ $minPembukaan }}" max="{{ $maksPembukaan }}" step="1000"
                           placeholder="Contoh: {{ (int) $minPembukaan }}"
                           style="flex:1;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);
                                  border-radius:8px;padding:0.6rem;color:white;font-size:0.9rem;" required />
                    <button type="submit"
                            style="padding:0.6rem 1.2rem;background:#10b981;color:white;border:none;
                                   border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                        Setujui
                    </button>
                </div>
                <div style="color:#94a3b8;font-size:0.78rem;margin-top:0.35rem;">
                    Nominal yang diizinkan: Rp {{ number_format($minPembukaan, 0, ',', '.') }} –
                    Rp {{ number_format($maksPembukaan, 0, ',', '.') }}
                    (batas mengikuti Parameter Kebijakan Koperasi).
                </div>
                @error('nominal_simpanan_awal')
                    <div style="color:#f87171;font-size:0.8rem;margin-top:0.35rem;">{{ $message }}</div>
                @enderror
            </form>

            {{-- Tolak --}}
            <form action="{{ route('pengelola.pengajuan-rekening.tolak', $pengajuan) }}" method="POST">
                @csrf
                <label style="color:#94a3b8;font-size:0.85rem;display:block;margin-bottom:0.4rem;">
                    Alasan Penolakan
                </label>
                <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                    <textarea name="alasan_tolak" rows="2" placeholder="Jelaskan alasan penolakan..."
                              style="flex:1;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);
                                     border-radius:8px;padding:0.6rem;color:white;font-size:0.9rem;resize:vertical;"
                              required></textarea>
                    <button type="submit"
                            style="padding:0.6rem 1rem;background:#ef4444;color:white;border:none;
                                   border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;white-space:nowrap;">
                        Tolak
                    </button>
                </div>
            </form>
        </div>
        @endif

        {{-- Bukti Pembayaran --}}
        @if($pengajuan->pembayaran)
        <div class="card" style="border:1px solid rgba({{ $pengajuan->pembayaran->status === 'Dikonfirmasi' ? '16,185,129' : '139,92,246' }},0.3);">
            <h3 style="color:white;margin:0 0 1rem;font-size:1rem;">Bukti Pembayaran</h3>
            <table style="width:100%;border-collapse:collapse;margin-bottom:1rem;">
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;width:50%;">Metode Bayar</td>
                    <td style="padding:0.5rem 0;color:white;font-size:0.85rem;">{{ $pengajuan->pembayaran->metode_bayar }}</td>
                </tr>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;">Nominal</td>
                    <td style="padding:0.5rem 0;color:#f0c040;font-size:0.85rem;font-weight:600;">
                        Rp {{ number_format($pengajuan->pembayaran->nominal, 0, ',', '.') }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0.5rem 0;color:#94a3b8;font-size:0.85rem;">Status</td>
                    <td style="padding:0.5rem 0;">
                        <span class="badge {{ $pengajuan->pembayaran->status === 'Dikonfirmasi' ? 'badge-green' : ($pengajuan->pembayaran->status === 'Ditolak' ? 'badge-red' : 'badge-amber') }}">
                            {{ $pengajuan->pembayaran->status }}
                        </span>
                    </td>
                </tr>
            </table>

            @if($pengajuan->pembayaran->bukti_bayar)
            <img src="{{ route('berkas.unduh', ['path' => $pengajuan->pembayaran->bukti_bayar]) }}"
                 style="width:100%;border-radius:8px;margin-bottom:1rem;border:1px solid rgba(255,255,255,0.1);" />
            @endif

            @if($pengajuan->status === 'Menunggu Konfirmasi')
            <div style="display:flex;gap:0.75rem;">
                <form action="{{ route('pengelola.pengajuan-rekening.konfirmasi-pembayaran', $pengajuan) }}" method="POST" style="flex:1;">
                    @csrf
                    <button type="submit" style="width:100%;padding:0.7rem;background:#10b981;color:white;border:none;
                            border-radius:8px;font-size:0.9rem;cursor:pointer;font-weight:600;">
                        Konfirmasi Pembayaran & Terbitkan Rekening
                    </button>
                </form>
            </div>
            <form action="{{ route('pengelola.pengajuan-rekening.tolak-pembayaran', $pengajuan) }}" method="POST"
                  style="margin-top:0.5rem;">
                @csrf
                <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                    <input type="text" name="catatan" placeholder="Alasan penolakan pembayaran..."
                           style="flex:1;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);
                                  border-radius:8px;padding:0.6rem;color:white;font-size:0.85rem;" required />
                    <button type="submit"
                            style="padding:0.6rem 1rem;background:#ef4444;color:white;border:none;
                                   border-radius:8px;font-size:0.85rem;cursor:pointer;font-weight:600;white-space:nowrap;">
                        Tolak
                    </button>
                </div>
            </form>
            @endif
        </div>
        @endif

        {{-- Rekening Terbit --}}
        @if($pengajuan->status === 'Aktif' && $pengajuan->anggota)
        <div class="card" style="border:1px solid rgba(16,185,129,0.3);margin-top:1.5rem;">
            <h3 style="color:#10b981;margin:0 0 0.75rem;font-size:1rem;">Rekening Berhasil Diterbitkan</h3>
            <div style="text-align:center;padding:1rem 0;">
                <div style="font-size:1.5rem;font-weight:700;color:white;font-family:monospace;letter-spacing:2px;">
                    {{ $pengajuan->no_rekening_terbit }}
                </div>
                <div style="color:#94a3b8;font-size:0.85rem;margin-top:0.25rem;">
                    {{ $pengajuan->anggota->no_anggota }} — {{ $pengajuan->nama_lengkap }}
                </div>
            </div>
        </div>
        @endif

        {{-- Info verifikator --}}
        @if($pengajuan->verifikator)
        <div style="margin-top:1rem;padding:0.75rem;background:rgba(255,255,255,0.04);border-radius:8px;">
            <p style="color:#94a3b8;font-size:0.8rem;margin:0;">
                Diverifikasi oleh <strong style="color:white;">{{ $pengajuan->verifikator->name }}</strong>
                pada {{ $pengajuan->waktu_verifikasi?->format('d/m/Y H:i') }}
            </p>
        </div>
        @endif
    </div>

</div>
@endsection
