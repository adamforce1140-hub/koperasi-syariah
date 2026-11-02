@extends('layouts.app')

@section('title', 'Daftarkan Anggota Baru — AO')
@section('page-title', 'Daftarkan Anggota Baru')

@section('content')
<div style="display:flex;flex-direction:column;gap:1.25rem;max-width:860px;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;">Daftarkan Anggota Baru</h1>
            <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">Isi formulir berikut untuk membuat CIF anggota baru.</p>
        </div>
        <a href="{{ route('ao.cif.index') }}"
           style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.45rem 0.875rem;
                  background:#f1f5f9;color:#475569;font-size:0.8rem;font-weight:600;
                  border-radius:0.5rem;text-decoration:none;"
           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
            ← Kembali
        </a>
    </div>

    {{-- Flash Message --}}
    @if(session('error'))
    <div class="alert alert-error" style="background:#fef2f2;border:1px solid #fecaca;border-radius:0.75rem;padding:1rem 1.25rem;font-size:0.85rem;font-weight:600;color:#b91c1c;">
        {{ session('error') }}
    </div>
    @endif

    @if(session('success'))
    <div class="alert alert-success" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.75rem;padding:1rem 1.25rem;font-size:0.85rem;font-weight:600;color:#15803d;">
        {{ session('success') }}
    </div>
    @endif

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

    <form method="POST" action="{{ route('ao.cif.store') }}">
        @csrf

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
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            NIK <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="nik" value="{{ old('nik') }}" maxlength="16" placeholder="16 digit NIK"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('nik') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('nik')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            Nama Lengkap <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="nama_lengkap" value="{{ old('nama_lengkap') }}" placeholder="Nama sesuai KTP"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('nama_lengkap') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('nama_lengkap')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Tempat Lahir</label>
                        <input type="text" name="tempat_lahir" value="{{ old('tempat_lahir') }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Tanggal Lahir</label>
                        <input type="date" name="tanggal_lahir" value="{{ old('tanggal_lahir') }}"
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
                            <option value="Laki-laki"  {{ old('jenis_kelamin') === 'Laki-laki'  ? 'selected' : '' }}>Laki-laki</option>
                            <option value="Perempuan"  {{ old('jenis_kelamin') === 'Perempuan'  ? 'selected' : '' }}>Perempuan</option>
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
                            <option value="Belum Menikah" {{ old('status_perkawinan') === 'Belum Menikah' ? 'selected' : '' }}>Belum Menikah</option>
                            <option value="Menikah"       {{ old('status_perkawinan') === 'Menikah'       ? 'selected' : '' }}>Menikah</option>
                            <option value="Cerai"         {{ old('status_perkawinan') === 'Cerai'         ? 'selected' : '' }}>Cerai</option>
                        </select>
                    </div>
                </div>

                {{-- Kanan --}}
                <div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            No HP <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="text" name="no_hp" value="{{ old('no_hp') }}" placeholder="08xxxxxxxxxx"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('no_hp') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        @error('no_hp')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Pekerjaan</label>
                        <input type="text" name="pekerjaan" value="{{ old('pekerjaan') }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Email</label>
                        <input type="email" name="email" value="{{ old('email') }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div style="margin-bottom:0.875rem;">
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Nama Ibu Kandung</label>
                        <input type="text" name="nama_ibu_kandung" value="{{ old('nama_ibu_kandung') }}"
                               style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    <div>
                        <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                            Status Pendaftaran
                        </label>

                        {{-- Badge statis: status awal SELALU PENDING dan tidak dapat
                             diubah dari form. Perubahan status ke SUSPENDED /
                             INACTIVE / CLOSED hanya oleh Super Admin/Pengurus
                             melalui halaman Detail/Edit Anggota. --}}
                        <div style="display:flex;align-items:center;gap:0.5rem;padding:0.55rem 0.75rem;
                                    background:#fef3c7;border:1px solid #fde68a;border-radius:0.5rem;">
                            <span style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.75rem;
                                         font-weight:700;color:#92400e;background:#fde68a;padding:0.2rem 0.5rem;
                                         border-radius:9999px;white-space:nowrap;">
                                <span style="width:0.4rem;height:0.4rem;border-radius:9999px;background:#d97706;display:inline-block;"></span>
                                PENDING
                            </span>
                            <span style="font-size:0.78rem;color:#78350f;font-weight:600;">
                                Status Pendaftaran: PENDING (Menunggu Setoran Awal di Teller)
                            </span>
                        </div>
                        <p style="margin:0.35rem 0 0;font-size:0.7rem;color:#64748b;line-height:1.4;">
                            Status ditetapkan otomatis oleh sistem dan tidak dapat dipilih manual.
                            Anggota menjadi
                            <strong>{{ \App\Models\Anggota::STATUS_LABEL[\App\Models\Anggota::STATUS_ACTIVE] }}</strong>
                            setelah setoran awal (Simpanan Pokok + Simpanan Wajib bulan ke-1) tervalidasi Teller.
                        </p>
                    </div>

                    <div style="margin-top:0.875rem;">
                        <label style="display:flex;align-items:flex-start;gap:0.6rem;cursor:pointer;
                                      padding:0.7rem 0.75rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:0.5rem;">
                            <input type="hidden" name="is_auto_debit" value="0">
                            <input type="checkbox" name="is_auto_debit" value="1"
                                   {{ old('is_auto_debit') ? 'checked' : '' }}
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
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Alamat <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" name="alamat" value="{{ old('alamat') }}" placeholder="Jl. nama jalan, nomor"
                       style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('alamat') ? '#fca5a5' : '#cbd5e1' }};
                              border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                       onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                @error('alamat')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:0.875rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">RT/RW</label>
                    <input type="text" name="rt_rw" value="{{ old('rt_rw') }}" placeholder="001/002"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Kelurahan</label>
                    <input type="text" name="kelurahan" value="{{ old('kelurahan') }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Kecamatan</label>
                    <input type="text" name="kecamatan" value="{{ old('kecamatan') }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Kota <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="kota" value="{{ old('kota') }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('kota') ? '#fca5a5' : '#cbd5e1' }};
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    @error('kota')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Provinsi <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="provinsi" value="{{ old('provinsi') }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('provinsi') ? '#fca5a5' : '#cbd5e1' }};
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    @error('provinsi')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Kode Pos</label>
                    <input type="text" name="kode_pos" value="{{ old('kode_pos') }}"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>
        </div>

        {{-- Setoran Pertama --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Setoran Pertama
            </div>
            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.8rem;color:#92400e;">
                Setoran pertama minimal <strong>Rp {{ number_format($minimumSetoranAwal, 0, ',', '.') }}</strong>
                (Simpanan Pokok Rp {{ number_format($simpananPokok, 0, ',', '.') }} +
                Simpanan Wajib bulan ke-1 Rp {{ number_format($simpananWajib, 0, ',', '.') }}) dan akan langsung dicatat ke rekening anggota.
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Jumlah Setoran Pertama <span style="color:#dc2626;">*</span>
                    </label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);font-size:0.825rem;color:#64748b;font-weight:600;">Rp</span>
                        <input type="number" name="setoran_pertama" value="{{ old('setoran_pertama', $minimumSetoranAwal) }}"
                               min="{{ $minimumSetoranAwal }}" step="1000"
                               style="width:100%;padding:0.5rem 0.75rem 0.5rem 2.5rem;border:1px solid {{ $errors->has('setoran_pertama') ? '#fca5a5' : '#cbd5e1' }};
                                      border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                               onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                    </div>
                    @error('setoran_pertama')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">Jenis Setoran</label>
                    <select name="jenis_setoran"
                            style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                   border-radius:0.5rem;font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;box-sizing:border-box;"
                            onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        <option value="Simpanan Pokok" {{ old('jenis_setoran','Simpanan Pokok') === 'Simpanan Pokok' ? 'selected' : '' }}>Simpanan Pokok</option>
                        <option value="Simpanan Wajib"  {{ old('jenis_setoran') === 'Simpanan Wajib'  ? 'selected' : '' }}>Simpanan Wajib</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- OJK-08: CDD / PEP / Pemilik Manfaat (POJK 27/2022, UU 8/2010 Ps. 18) --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:0.5rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Customer Due Diligence (CDD) &amp; Pemilik Manfaat
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;font-size:0.78rem;color:#1e40af;">
                Sesuai POJK No. 27/POJK.03/2022 &amp; UU No. 8/2010 Pasal 18, calon anggota wajib
                dideklarasikan status PEP dan pemilik manfaatnya. Profil risiko &amp; jenis CDD
                ditentukan otomatis oleh sistem berdasarkan jawaban di bawah.
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:0.875rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Status PEP (Politically Exposed Person) <span style="color:#dc2626;">*</span>
                    </label>
                    <select name="pep_status" required
                            style="width:100%;padding:0.5rem 0.75rem;border:1px solid {{ $errors->has('pep_status') ? '#fca5a5' : '#cbd5e1' }};
                                   border-radius:0.5rem;font-size:0.825rem;color:#1e293b;background:#fff;outline:none;appearance:auto;box-sizing:border-box;"
                            onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                        <option value="" disabled {{ old('pep_status') ? '' : 'selected' }}>— Pilih —</option>
                        @foreach(\App\Models\Anggota::PEP_PILIHAN as $pep)
                        <option value="{{ $pep }}" {{ old('pep_status') === $pep ? 'selected' : '' }}>{{ $pep }}</option>
                        @endforeach
                    </select>
                    @error('pep_status')<div style="font-size:0.75rem;color:#dc2626;margin-top:0.25rem;">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Keterangan PEP / Jabatan Publik
                    </label>
                    <input type="text" name="pep_keterangan" value="{{ old('pep_keterangan') }}" placeholder="Nama jabatan/instansi (bila PEP)"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:0.875rem;">
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Nama Pemilik Manfaat
                    </label>
                    <input type="text" name="pemilik_manfaat_nama" value="{{ old('pemilik_manfaat_nama') }}" placeholder="Nama lengkap pemilik manfaat (opsional)"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
                <div>
                    <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                        Hubungan Pemilik Manfaat
                    </label>
                    <input type="text" name="pemilik_manfaat_hubungan" value="{{ old('pemilik_manfaat_hubungan') }}" placeholder="cth: Pemilik perusahaan, Keluarga"
                           style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                  border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;box-sizing:border-box;"
                           onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">
                </div>
            </div>

            <div>
                <label style="display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:0.35rem;">
                    Keterangan Pemilik Manfaat
                </label>
                <textarea name="pemilik_manfaat_keterangan" rows="2" placeholder="Penjelasan tambahan (opsional)"
                          style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                                 border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;
                                 resize:vertical;box-sizing:border-box;"
                          onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">{{ old('pemilik_manfaat_keterangan') }}</textarea>
            </div>
        </div>

        {{-- Keterangan --}}
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.875rem;padding:1.25rem;
                    box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:1.25rem;">
            <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:1rem;
                        padding-bottom:0.625rem;border-bottom:1px solid #f1f5f9;">
                Keterangan
            </div>
            <textarea name="keterangan" rows="3" placeholder="Catatan tambahan (opsional)"
                      style="width:100%;padding:0.5rem 0.75rem;border:1px solid #cbd5e1;
                             border-radius:0.5rem;font-size:0.825rem;color:#1e293b;outline:none;
                             resize:vertical;box-sizing:border-box;"
                      onfocus="this.style.borderColor='#059669'" onblur="this.style.borderColor='#cbd5e1'">{{ old('keterangan') }}</textarea>
        </div>

        {{-- Tombol --}}
        <div style="display:flex;gap:0.75rem;">
            <button type="submit"
                    style="padding:0.6rem 1.5rem;background:#059669;color:#fff;font-size:0.875rem;font-weight:600;
                           border:none;border-radius:0.5rem;cursor:pointer;"
                    onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                Simpan Data CIF
            </button>
            <a href="{{ route('ao.cif.index') }}"
               style="display:inline-flex;align-items:center;padding:0.6rem 1.25rem;background:#f1f5f9;
                      color:#64748b;font-size:0.875rem;font-weight:600;border-radius:0.5rem;text-decoration:none;"
               onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                Batal
            </a>
        </div>

    </form>
</div>
@endsection
