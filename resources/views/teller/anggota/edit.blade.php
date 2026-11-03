@extends('layouts.app')
@section('page-title', "Edit Data Anggota")

@section('title', 'Edit Data Anggota — ' . $anggota->nama_lengkap)

@section('content')
<div style="max-width:720px;margin:0 auto;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;">
        <a href="{{ route('teller.anggota.show', $anggota->id) }}"
           style="color:#64748b;text-decoration:none;font-size:.9rem;">&#8592; Kembali</a>
        <span style="color:#cbd5e1;">|</span>
        <div>
            <h2 style="margin:0;font-size:1.3rem;font-weight:700;color:#1e293b;">&#9998; Edit Data Anggota</h2>
            <p style="margin:.15rem 0 0;color:#64748b;font-size:.85rem;">No. Anggota: {{ $anggota->no_anggota }}</p>
        </div>
    </div>

    {{-- Error Summary --}}
    @if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:.85rem 1rem;border-radius:8px;margin-bottom:1.2rem;">
        <strong>Terdapat kesalahan pada form:</strong>
        <ul style="margin:.4rem 0 0;padding-left:1.2rem;">
            @foreach($errors->all() as $err)
            <li style="font-size:.875rem;">{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Form --}}
    <form action="{{ route('teller.anggota.update', $anggota->id) }}" method="POST"
          style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.8rem;">
        @csrf
        @method('PUT')

        {{-- Nama Lengkap --}}
        <div style="margin-bottom:1.1rem;">
            <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="nama_lengkap">
                Nama Lengkap <span style="color:#ef4444;">*</span>
            </label>
            <input id="nama_lengkap" type="text" name="nama_lengkap" value="{{ old('nama_lengkap', $anggota->nama_lengkap) }}"
                   style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('nama_lengkap') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                   placeholder="Nama lengkap sesuai KTP">
        </div>

        {{-- NIK --}}
        <div style="margin-bottom:1.1rem;">
            <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="nik">
                NIK (16 Digit) <span style="color:#ef4444;">*</span>
            </label>
            <input id="nik" type="text" name="nik" value="{{ old('nik', $anggota->nik) }}" maxlength="16"
                   style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('nik') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;font-family:monospace;box-sizing:border-box;"
                   placeholder="16 digit NIK">
        </div>

        {{-- Tempat & Tanggal Lahir --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1.1rem;">
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="tempat_lahir">
                    Tempat Lahir
                </label>
                <input id="tempat_lahir" type="text" name="tempat_lahir" value="{{ old('tempat_lahir', $anggota->tempat_lahir) }}"
                       style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                       placeholder="Kota tempat lahir">
            </div>
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="tanggal_lahir">
                    Tanggal Lahir
                </label>
                <input id="tanggal_lahir" type="date" name="tanggal_lahir"
                       value="{{ old('tanggal_lahir', $anggota->tanggal_lahir ? $anggota->tanggal_lahir->format('Y-m-d') : '') }}"
                       style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;box-sizing:border-box;">
            </div>
        </div>

        {{-- Jenis Kelamin & No. HP --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1.1rem;">
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                    Jenis Kelamin <span style="color:#ef4444;">*</span>
                </label>
                <select name="jenis_kelamin"
                        style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('jenis_kelamin') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;background:#fff;">
                    <option value="">-- Pilih --</option>
                    <option value="Laki-laki" {{ old('jenis_kelamin', $anggota->jenis_kelamin) === 'Laki-laki' ? 'selected' : '' }}>Laki-laki</option>
                    <option value="Perempuan" {{ old('jenis_kelamin', $anggota->jenis_kelamin) === 'Perempuan' ? 'selected' : '' }}>Perempuan</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="no_hp">
                    No. HP <span style="color:#ef4444;">*</span>
                </label>
                <input id="no_hp" type="text" name="no_hp" value="{{ old('no_hp', $anggota->no_hp) }}"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('no_hp') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                       placeholder="Contoh: 08123456789">
            </div>
        </div>

        {{-- Pekerjaan --}}
        <div style="margin-bottom:1.1rem;">
            <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="pekerjaan">
                Pekerjaan / Profesi
            </label>
            <input id="pekerjaan" type="text" name="pekerjaan" value="{{ old('pekerjaan', $anggota->pekerjaan) }}"
                   style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                   placeholder="Contoh: Wiraswasta, PNS, dsb.">
        </div>

        {{-- Alamat --}}
        <div style="margin-bottom:1.1rem;">
            <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="alamat">
                Alamat Domisili <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="alamat" name="alamat" rows="3"
                      style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('alamat') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;resize:vertical;"
                      placeholder="Jalan, RT/RW, Kelurahan, Kecamatan">{{ old('alamat', $anggota->alamat) }}</textarea>
        </div>

        {{-- Kota & Provinsi --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1rem;margin-bottom:1.1rem;">
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="kota">
                    Kota / Kabupaten <span style="color:#ef4444;">*</span>
                </label>
                <input id="kota" type="text" name="kota" value="{{ old('kota', $anggota->kota) }}"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('kota') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                       placeholder="Contoh: Jakarta Selatan">
            </div>
            <div>
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="provinsi">
                    Provinsi <span style="color:#ef4444;">*</span>
                </label>
                <input id="provinsi" type="text" name="provinsi" value="{{ old('provinsi', $anggota->provinsi) }}"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('provinsi') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;"
                       placeholder="Contoh: DKI Jakarta">
            </div>
        </div>

        @php
            $statusSaatIni = \App\Models\Anggota::normalizeStatus($anggota->status_anggota) ?? $anggota->status_anggota;
        @endphp

        {{-- Status Keanggotaan (read-only untuk Teller) --}}
        <div style="margin-bottom:1.5rem;">
            <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;">
                Status Keanggotaan
            </label>
            <div style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .8rem;
                        background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <span style="font-size:.875rem;font-weight:700;color:#334155;">
                    {{ \App\Models\Anggota::STATUS_LABEL[$statusSaatIni] ?? $statusSaatIni }}
                </span>
            </div>
            <div style="font-size:.78rem;color:#64748b;margin-top:.35rem;line-height:1.4;">
                Status keanggotaan hanya dapat diubah oleh Super Admin/Pengurus melalui halaman
                Detail/Edit Anggota pada modul CIF.
            </div>
        </div>

        {{-- Preferensi Auto-Debit Simpanan Wajib --}}
        <div style="margin-bottom:1.5rem;">
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;padding:.7rem .8rem;
                          background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                <input type="hidden" name="is_auto_debit" value="0">
                <input type="checkbox" name="is_auto_debit" value="1"
                       {{ old('is_auto_debit', $anggota->is_auto_debit) ? 'checked' : '' }}
                       style="margin-top:.15rem;width:1rem;height:1rem;cursor:pointer;">
                <span>
                    <span style="display:block;font-size:.875rem;font-weight:700;color:#166534;">Auto-Debit Simpanan Wajib</span>
                    <span style="display:block;font-size:.78rem;color:#15803d;margin-top:.2rem;line-height:1.4;">
                        Bila diaktifkan, tagihan Simpanan Wajib bulanan dipotong otomatis dari saldo
                        Simpanan Sukarela (akun 2-1001). Bila tidak, tagihan dibayar manual via Teller.
                    </span>
                </span>
            </label>
        </div>

        {{-- Tombol --}}
        <div style="display:flex;gap:.75rem;justify-content:flex-end;">
            <a href="{{ route('teller.anggota.show', $anggota->id) }}"
               style="padding:.6rem 1.2rem;border:1px solid #d1d5db;border-radius:8px;color:#374151;text-decoration:none;font-size:.9rem;font-weight:600;">
                Batal
            </a>
            <button type="submit"
                    style="padding:.6rem 1.4rem;background:#059669;color:#fff;border:none;border-radius:8px;font-size:.9rem;font-weight:600;cursor:pointer;">
                Simpan Perubahan
            </button>
        </div>

    </form>
</div>
@endsection
