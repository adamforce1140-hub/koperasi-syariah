@extends('layouts.app')
@section('title', 'Profil & Kartu Anggota')
@section('page-title', 'Profil & Kartu Anggota Digital')

@section('styles')
<style>
    /* ── Two-column layout ── */
    .profil-layout {
        display: grid;
        grid-template-columns: repeat(auto-fit,minmax(240px,1fr));
        gap: 1.5rem;
        align-items: start;
    }

    /* ── Kartu Anggota Digital ── */
    .membership-card {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        border-radius: 1.25rem;
        padding: 1.5rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(22,163,74,0.35);
        position: relative;
        overflow: hidden;
        min-height: 210px;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    /* decorative circles */
    .membership-card::before {
        content: '';
        position: absolute;
        width: 180px; height: 180px;
        border-radius: 50%;
        background: rgba(255,255,255,0.07);
        top: -60px; right: -50px;
    }
    .membership-card::after {
        content: '';
        position: absolute;
        width: 120px; height: 120px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
        bottom: -40px; left: -30px;
    }
    .card-brand {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        opacity: 0.9;
    }
    .card-brand-icon {
        width: 28px; height: 28px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.9rem;
    }
    .card-member-name {
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        line-height: 1.3;
        margin-top: 0.25rem;
        text-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }
    .card-info-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-top: auto;
    }
    .card-info-item {}
    .card-info-label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        opacity: 0.7;
        margin-bottom: 2px;
    }
    .card-info-value {
        font-size: 0.8rem;
        font-weight: 600;
    }
    .card-status-badge {
        background: rgba(255,255,255,0.25);
        color: #fff;
        border: 1px solid rgba(255,255,255,0.4);
        border-radius: 999px;
        padding: 0.2rem 0.75rem;
        font-size: 0.7rem;
        font-weight: 600;
        backdrop-filter: blur(4px);
    }
    .card-qr-placeholder {
        width: 72px; height: 72px;
        border: 2px solid rgba(255,255,255,0.6);
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        color: rgba(255,255,255,0.8);
        background: rgba(255,255,255,0.1);
        flex-shrink: 0;
    }

    /* ── Form profil ── */
    .form-grid-2 {
        display: grid;
        grid-template-columns: repeat(auto-fit,minmax(230px,1fr));
        gap: 1rem 1.5rem;
    }
    .form-group-p {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }
    .form-group-p.full {
        grid-column: 1 / -1;
    }
    .form-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #374151;
    }
    .form-label .readonly-tag {
        font-size: 0.7rem;
        background: #f1f5f9;
        color: #64748b;
        border-radius: 4px;
        padding: 1px 6px;
        margin-left: 4px;
        font-weight: 500;
    }
    .form-input {
        width: 100%;
        padding: 0.55rem 0.875rem;
        border: 1.5px solid #e2e8f0;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
        color: #1e293b;
        background: #fff;
        transition: border-color 0.15s, box-shadow 0.15s;
        outline: none;
    }
    .form-input:focus {
        border-color: #16a34a;
        box-shadow: 0 0 0 3px rgba(22,163,74,0.12);
    }
    .form-input[readonly] {
        background: #f8fafc;
        color: #64748b;
        cursor: default;
    }
    textarea.form-input {
        resize: vertical;
        min-height: 80px;
    }

    /* ── Flash alert ── */
    .alert-success-p {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
        border-radius: 0.75rem;
        padding: 0.8rem 1.1rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
    }

    /* ── Submit button ── */
    .btn-save {
        background: #16a34a;
        color: #fff;
        border: none;
        padding: 0.6rem 1.75rem;
        border-radius: 0.5rem;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        transition: background 0.15s;
    }
    .btn-save:hover { background: #15803d; }

    /* ── Section divider ── */
    .form-section-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #16a34a;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid #dcfce7;
        margin-bottom: 1rem;
        grid-column: 1 / -1;
    }

    @media (max-width: 900px) {
        .profil-layout {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 560px) {
        .form-grid-2 {
            grid-template-columns: 1fr;
        }
    }
</style>
@endsection

@section('content')

<div class="profil-layout">

    {{-- ─────────────────────────────────────────
         KOLOM KIRI: Kartu Anggota Digital
    ───────────────────────────────────────── --}}
    <div>
        <h2 style="font-size:0.85rem;font-weight:700;color:#374151;margin-bottom:0.75rem;text-transform:uppercase;letter-spacing:0.05em;">
            &#127972; Kartu Anggota Digital
        </h2>

        <div class="membership-card">
            {{-- Brand --}}
            <div class="card-brand">
                <div class="card-brand-icon">&#127975;</div>
                Koperasi Syariah
            </div>

            {{-- Nama anggota --}}
            <div class="card-member-name">
                {{ $anggota->nama_lengkap ?? (Auth::user()->name ?? 'Nama Anggota') }}
            </div>

            {{-- Info baris bawah --}}
            <div class="card-info-row">
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <div class="card-info-item">
                        <div class="card-info-label">No. Anggota</div>
                        <div class="card-info-value">{{ $anggota->no_anggota ?? '-' }}</div>
                    </div>
                    <div class="card-info-item">
                        <div class="card-info-label">Bergabung</div>
                        <div class="card-info-value">
                            {{-- Kolom kanonik `anggota.tanggal_gabung` (bukan `tanggal_masuk`). --}}
                            {{ $anggota->tanggal_gabung
                                ? \Carbon\Carbon::parse($anggota->tanggal_gabung)->format('d M Y')
                                : '-' }}
                        </div>
                    </div>
                    <div>
                        <span class="card-status-badge">
                            {{-- `status_label` menormalkan enum `status_anggota` (ACTIVE/PENDING/…). --}}
                            {{ $anggota->status_label }}
                        </span>
                    </div>
                </div>

                {{-- QR placeholder --}}
                <div class="card-qr-placeholder">QR</div>
            </div>
        </div>

        {{-- Info anggota ringkas di bawah kartu --}}
        <div class="card" style="margin-top:1rem;">
            <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#16a34a;margin-bottom:0.75rem;">
                &#128196; Info Keanggotaan
            </div>
            <div style="display:flex;flex-direction:column;gap:0.5rem;">
                @foreach([
                    ['Status Keanggotaan', $anggota->status_label],
                    ['No. KTP',            $anggota->nik ?? '-'],
                    ['Kota',               $anggota->kota ?? '-'],
                ] as [$lbl, $val])
                <div style="display:flex;justify-content:space-between;font-size:0.8rem;padding:0.35rem 0;border-bottom:1px solid #f1f5f9;">
                    <span style="color:#64748b;">{{ $lbl }}</span>
                    <span style="font-weight:600;color:#1e293b;">{{ $val }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────
         KOLOM KANAN: Form Update Profil
    ───────────────────────────────────────── --}}
    <div>
        <h2 style="font-size:0.85rem;font-weight:700;color:#374151;margin-bottom:0.75rem;text-transform:uppercase;letter-spacing:0.05em;">
            &#9998; Perbarui Profil
        </h2>

        @if(session('success'))
        <div class="alert-success-p">
            <span>&#10003;</span> {{ session('success') }}
        </div>
        @endif

        <div class="card">
            <form method="POST" action="{{ route('nasabah.profil.update') }}">
                @csrf
                @method('PUT')

                <div class="form-grid-2">
                    {{-- ── Data Identitas (read-only) ── --}}
                    <div class="form-section-label">&#128274; Data Identitas (Tidak Dapat Diubah)</div>

                    <div class="form-group-p">
                        <label class="form-label">
                            Nama Lengkap
                            <span class="readonly-tag">read-only</span>
                        </label>
                        <input type="text" class="form-input"
                            value="{{ $anggota->nama_lengkap ?? '' }}" readonly>
                    </div>

                    <div class="form-group-p">
                        <label class="form-label">
                            No. Anggota
                            <span class="readonly-tag">read-only</span>
                        </label>
                        <input type="text" class="form-input"
                            value="{{ $anggota->no_anggota ?? '' }}" readonly>
                    </div>

                    <div class="form-group-p full">
                        <label class="form-label">
                            No. KTP
                            <span class="readonly-tag">read-only</span>
                        </label>
                        <input type="text" class="form-input"
                            value="{{ $anggota->nik ?? '' }}" readonly>
                    </div>

                    {{-- ── Data Kontak (editable) ── --}}
                    <div class="form-section-label" style="margin-top:0.5rem;">
                        &#128241; Data Kontak &amp; Informasi Lainnya
                    </div>

                    <div class="form-group-p">
                        <label class="form-label" for="no_telepon">No. Telepon</label>
                        <input type="text" name="no_telepon" id="no_telepon"
                            class="form-input @error('no_telepon') is-invalid @enderror"
                            value="{{ old('no_telepon', $anggota->no_telepon ?? '') }}"
                            placeholder="021xxxxxxx">
                        @error('no_telepon')
                        <span style="font-size:0.75rem;color:#dc2626;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group-p">
                        <label class="form-label" for="no_hp">No. HP / WhatsApp</label>
                        <input type="text" name="no_hp" id="no_hp"
                            class="form-input @error('no_hp') is-invalid @enderror"
                            value="{{ old('no_hp', $anggota->no_hp ?? '') }}"
                            placeholder="08xxxxxxxxxx">
                        @error('no_hp')
                        <span style="font-size:0.75rem;color:#dc2626;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group-p">
                        <label class="form-label" for="email">Email</label>
                        <input type="email" name="email" id="email"
                            class="form-input @error('email') is-invalid @enderror"
                            value="{{ old('email', Auth::user()->email) }}"
                            placeholder="email@contoh.com">
                        @error('email')
                        <span style="font-size:0.75rem;color:#dc2626;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group-p">
                        <label class="form-label" for="pekerjaan">Pekerjaan</label>
                        <input type="text" name="pekerjaan" id="pekerjaan"
                            class="form-input @error('pekerjaan') is-invalid @enderror"
                            value="{{ old('pekerjaan', $anggota->pekerjaan ?? '') }}"
                            placeholder="Wiraswasta, PNS, dsb.">
                        @error('pekerjaan')
                        <span style="font-size:0.75rem;color:#dc2626;">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group-p full">
                        <label class="form-label" for="alamat">Alamat Lengkap</label>
                        <textarea name="alamat" id="alamat"
                            class="form-input @error('alamat') is-invalid @enderror"
                            placeholder="Jl. Contoh No. 1, Kel. ..., Kec. ..., Kota ...">{{ old('alamat', $anggota->alamat ?? '') }}</textarea>
                        @error('alamat')
                        <span style="font-size:0.75rem;color:#dc2626;">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div style="margin-top:1.5rem;">
                    <button type="submit" class="btn-save">
                        &#128190; Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

@endsection
