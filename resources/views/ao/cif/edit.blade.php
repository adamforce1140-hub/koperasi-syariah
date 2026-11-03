@extends('layouts.app')

@section('title', 'Edit Data CIF — ' . $anggota->nama_lengkap)
@section('page-title', 'Edit Data CIF')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;max-width:860px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Edit Data CIF</h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">{{ $anggota->nama_lengkap }}</p>
        </div>
        <a href="{{ route('ao.cif.show', $anggota->id) }}"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.875rem;
                  background:#f1f5f9;color:#475569;font-size:0.8rem;font-weight:600;
                  border-radius:0.5rem;text-decoration:none;"
           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
            ← Kembali
        </a>
    </div>

    {{-- Info Card CIF --}}
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.875rem;padding:1rem 1.25rem;
                display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;">
        <div>
            <div style="font-size:0.72rem;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:0.05em;">No CIF</div>
            <div style="font-family:monospace;font-size:0.9rem;font-weight:700;color:#1e40af;margin-top:0.2rem;">
                {{ $anggota->no_cif ?? '—' }}
            </div>
        </div>
        <div>
            <div style="font-size:0.72rem;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:0.05em;">No Anggota</div>
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;margin-top:0.2rem;">{{ $anggota->no_anggota ?? '—' }}</div>
        </div>
        <div>
            <div style="font-size:0.72rem;font-weight:600;color:#3b82f6;text-transform:uppercase;letter-spacing:0.05em;">Tanggal Bergabung</div>
            <div style="font-size:0.9rem;font-weight:700;color:#1e293b;margin-top:0.2rem;">
                {{ $anggota->tanggal_gabung ? $anggota->tanggal_gabung->format('d M Y') : '—' }}
            </div>
        </div>
    </div>

    {{-- Error --}}
    @if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:0.75rem;padding:1rem 1.25rem;">
        <div style="font-size:0.85rem;font-weight:600;color:#b91c1c;margin-bottom:0.5rem;">Terdapat kesalahan input:</div>
        <ul style="margin:0;padding-left:1.25rem;">
            @foreach($errors->all() as $error)
            <li style="font-size:0.825rem;color:#dc2626;margin-bottom:0.2rem;">{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('ao.cif.update', $anggota->id) }}">
        @csrf
        @method('PUT')

        {{-- Identitas Diri --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Identitas Diri
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                {{-- Kiri --}}
                <div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="nik">
                            NIK <span style="color:#dc2626;">*</span>
                        </label>
                        <input id="nik" type="text" name="nik" value="{{ old('nik', $anggota->nik) }}" maxlength="16"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('nik') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('nik')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="nama_lengkap">
                            Nama Lengkap <span style="color:#dc2626;">*</span>
                        </label>
                        <input id="nama_lengkap" type="text" name="nama_lengkap" value="{{ old('nama_lengkap', $anggota->nama_lengkap) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('nama_lengkap') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('nama_lengkap')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tempat_lahir">Tempat Lahir</label>
                        <input id="tempat_lahir" type="text" name="tempat_lahir" value="{{ old('tempat_lahir', $anggota->tempat_lahir) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="tanggal_lahir">Tanggal Lahir</label>
                        <input id="tanggal_lahir" type="date" name="tanggal_lahir"
                               value="{{ old('tanggal_lahir', $anggota->tanggal_lahir ? $anggota->tanggal_lahir->format('Y-m-d') : '') }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            Jenis Kelamin <span style="color:#dc2626;">*</span>
                        </label>
                        <select name="jenis_kelamin"
                                style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('jenis_kelamin') ? '#fca5a5' : '#cbd5e1' }};
                                       border-radius:0.5rem;font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;box-sizing:border-box;"
                                onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                            <option value="">— Pilih —</option>
                            <option value="Laki-laki"  {{ old('jenis_kelamin', $anggota->jenis_kelamin) === 'Laki-laki'  ? 'selected' : '' }}>Laki-laki</option>
                            <option value="Perempuan"  {{ old('jenis_kelamin', $anggota->jenis_kelamin) === 'Perempuan'  ? 'selected' : '' }}>Perempuan</option>
                        </select>
                        @error('jenis_kelamin')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Status Perkawinan</label>
                        <select name="status_perkawinan"
                                style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                       border-radius:0.5rem;font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;box-sizing:border-box;"
                                onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                            <option value="">— Pilih —</option>
                            <option value="Belum Menikah" {{ old('status_perkawinan', $anggota->status_perkawinan) === 'Belum Menikah' ? 'selected' : '' }}>Belum Menikah</option>
                            <option value="Menikah"       {{ old('status_perkawinan', $anggota->status_perkawinan) === 'Menikah'       ? 'selected' : '' }}>Menikah</option>
                            <option value="Cerai"         {{ old('status_perkawinan', $anggota->status_perkawinan) === 'Cerai'         ? 'selected' : '' }}>Cerai</option>
                        </select>
                    </div>
                </div>

                {{-- Kanan --}}
                <div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="no_hp">
                            No HP <span style="color:#dc2626;">*</span>
                        </label>
                        <input id="no_hp" type="text" name="no_hp" value="{{ old('no_hp', $anggota->no_hp) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('no_hp') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('no_hp')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="pekerjaan">Pekerjaan</label>
                        <input id="pekerjaan" type="text" name="pekerjaan" value="{{ old('pekerjaan', $anggota->pekerjaan) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $anggota->email) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="nama_ibu_kandung">Nama Ibu Kandung</label>
                        <input id="nama_ibu_kandung" type="text" name="nama_ibu_kandung" value="{{ old('nama_ibu_kandung', $anggota->nama_ibu_kandung) }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    @php
                        $statusSaatIni   = \App\Models\Anggota::normalizeStatus($anggota->status_anggota) ?? $anggota->status_anggota;
                        $bolehUbahStatus = \App\Models\Anggota::peranBolehUbahStatus(auth()->user()->role ?? null);
                    @endphp

                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            Status Keanggotaan
                        </label>

                        @if($bolehUbahStatus)
                            <select name="status_anggota"
                                    style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('status_anggota') ? '#fca5a5' : '#cbd5e1' }};
                                           border-radius:0.5rem;font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;box-sizing:border-box;"
                                    onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                                <option value="{{ $statusSaatIni }}" {{ old('status_anggota', $statusSaatIni) === $statusSaatIni ? 'selected' : '' }}>
                                    {{ \App\Models\Anggota::STATUS_LABEL[$statusSaatIni] ?? $statusSaatIni }} (saat ini)
                                </option>
                                @foreach(\App\Models\Anggota::STATUS_PILIHAN_MANUAL as $optSt)
                                    @continue($optSt === $statusSaatIni)
                                    <option value="{{ $optSt }}" {{ old('status_anggota') === $optSt ? 'selected' : '' }}>
                                        {{ \App\Models\Anggota::STATUS_LABEL[$optSt] ?? $optSt }}
                                    </option>
                                @endforeach
                            </select>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:0.3rem;line-height:1.4;">
                                Perubahan ke SUSPENDED / INACTIVE / Keluar hanya dapat dilakukan Super Admin &amp; Pengurus.
                                Status ACTIVE lahir otomatis setelah setoran awal tervalidasi Teller.
                            </div>
                        @else
                            <div style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 0.75rem;
                                        background:#f1f5f9;border:1px solid #e2e8f0;border-radius:0.5rem;">
                                <span style="font-size:0.78rem;font-weight:700;color:#334155;">
                                    {{ \App\Models\Anggota::STATUS_LABEL[$statusSaatIni] ?? $statusSaatIni }}
                                </span>
                            </div>
                            <div style="font-size:0.7rem;color:#64748b;margin-top:0.3rem;line-height:1.4;">
                                Status hanya dapat diubah oleh Super Admin/Pengurus melalui halaman Detail/Edit Anggota.
                            </div>
                        @endif

                        @error('status_anggota')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>

                    <div>
                        <label style="display:flex;align-items:flex-start;gap:0.6rem;cursor:pointer;
                                      padding:0.7rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.5rem;">
                            <input type="hidden" name="is_auto_debit" value="0">
                            <input type="checkbox" name="is_auto_debit" value="1"
                                   {{ old('is_auto_debit', $anggota->is_auto_debit) ? 'checked' : '' }}
                                   style="margin-top:0.15rem;width:1rem;height:1rem;cursor:pointer;">
                            <span>
                                <span style="display:block;font-size:0.78rem;font-weight:700;color:#166534;">
                                    Auto-Debit Simpanan Wajib
                                </span>
                                <span style="display:block;font-size:0.7rem;color:#15803d;margin-top:0.2rem;line-height:1.4;">
                                    Bila diaktifkan, tagihan Simpanan Wajib bulanan dipotong otomatis dari saldo
                                    Simpanan Sukarela (akun 2-1001). Bila tidak, tagihan dibayar manual via Teller.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- Alamat --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Alamat
            </div>
            <div style="margin-bottom:0.875rem;">
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="alamat">
                    Alamat <span style="color:#dc2626;">*</span>
                </label>
                <input id="alamat" type="text" name="alamat" value="{{ old('alamat', $anggota->alamat) }}"
                       style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('alamat') ? '#fca5a5' : '#cbd5e1' }};
                              border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                       onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                @error('alamat')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:0.875rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="rt_rw">RT/RW</label>
                    <input id="rt_rw" type="text" name="rt_rw" value="{{ old('rt_rw', $anggota->rt_rw) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kelurahan">Kelurahan</label>
                    <input id="kelurahan" type="text" name="kelurahan" value="{{ old('kelurahan', $anggota->kelurahan) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kecamatan">Kecamatan</label>
                    <input id="kecamatan" type="text" name="kecamatan" value="{{ old('kecamatan', $anggota->kecamatan) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kota">
                        Kota <span style="color:#dc2626;">*</span>
                    </label>
                    <input id="kota" type="text" name="kota" value="{{ old('kota', $anggota->kota) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('kota') ? '#fca5a5' : '#cbd5e1' }};
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    @error('kota')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="provinsi">
                        Provinsi <span style="color:#dc2626;">*</span>
                    </label>
                    <input id="provinsi" type="text" name="provinsi" value="{{ old('provinsi', $anggota->provinsi) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('provinsi') ? '#fca5a5' : '#cbd5e1' }};
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    @error('provinsi')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;" for="kode_pos">Kode Pos</label>
                    <input id="kode_pos" type="text" name="kode_pos" value="{{ old('kode_pos', $anggota->kode_pos) }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
        </div>

        {{-- Keterangan --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1.25rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Keterangan
            </div>
            <textarea name="keterangan" rows="3" aria-label="Keterangan"
                      style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                             border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;
                             resize:vertical;box-sizing:border-box;"
                      onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">{{ old('keterangan', $anggota->keterangan) }}</textarea>
        </div>

        {{-- Tombol --}}
        <div style="display:flex;gap:0.75rem;">
            <button type="submit"
                    style="padding:0.6rem 1.5rem;background:#059669;color:#fff;font-size:0.875rem;font-weight:600;
                           border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                Simpan Perubahan
            </button>
            <a href="{{ route('ao.cif.show', $anggota->id) }}"
               style="display:inline-flex;align-items:center;padding:0.6rem 1.25rem;background:#f1f5f9;
                      color:#64748b;font-size:0.875rem;font-weight:600;border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                Batal
            </a>
        </div>

    </form>
</div>
@endsection
