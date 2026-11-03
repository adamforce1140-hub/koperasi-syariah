@extends('layouts.app')

@section('title', 'Pengajuan Pembiayaan Baru')

@section('page-title', 'Pengajuan Pembiayaan Baru')

@section('styles')
<style>
    /* ===== STEPPER ===== */
    .stepper {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        gap: 0;
        margin-bottom: 2rem;
    }
    .step-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        flex: 1;
        max-width: 180px;
    }
    .step-item:not(:last-child)::after {
        content: '';
        position: absolute;
        top: 20px;
        left: calc(50% + 20px);
        right: calc(-50% + 20px);
        height: 2px;
        background: #e2e8f0;
        z-index: 0;
    }
    .step-item.done:not(:last-child)::after,
    .step-item.active:not(:last-child)::after {
        background: #059669;
    }
    .step-circle {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        position: relative;
        z-index: 1;
        transition: all 0.2s;
        border: 2px solid #e2e8f0;
        background: white;
        color: #94a3b8;
    }
    .step-item.active .step-circle {
        background: #059669;
        border-color: #059669;
        color: white;
        box-shadow: 0 0 0 4px rgba(5,150,105,0.15);
    }
    .step-item.done .step-circle {
        background: white;
        border-color: #059669;
        color: #059669;
    }
    .step-label {
        margin-top: 0.5rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: #94a3b8;
        text-align: center;
        line-height: 1.3;
    }
    .step-item.active .step-label { color: #059669; }
    .step-item.done .step-label   { color: #059669; }

    /* ===== FORM CARD ===== */
    .wizard-card {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        overflow: hidden;
    }
    .wizard-card-header {
        background: linear-gradient(135deg, #065f46, #059669);
        color: white;
        padding: 1.25rem 1.75rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .wizard-card-header .step-badge {
        background: rgba(255,255,255,0.2);
        border-radius: 9999px;
        padding: 0.2rem 0.75rem;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .wizard-card-header h2 {
        font-size: 1rem;
        font-weight: 700;
        margin: 0;
    }
    .wizard-body { padding: 1.75rem; }

    /* ===== FORM FIELDS ===== */
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 1.25rem; }
    .form-grid.cols-1 { grid-template-columns: 1fr; }
    .form-group { display: flex; flex-direction: column; gap: 0.35rem; }
    .form-group.full { grid-column: span 2; }
    .form-label {
        font-size: 0.82rem;
        font-weight: 600;
        color: #374151;
    }
    .form-label .req { color: #ef4444; margin-left: 2px; }
    .form-control {
        width: 100%;
        padding: 0.6rem 0.85rem;
        border: 1.5px solid #d1d5db;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-family: inherit;
        color: #1e293b;
        background: white;
        transition: border-color 0.15s, box-shadow 0.15s;
        outline: none;
    }
    .form-control:focus {
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5,150,105,0.12);
    }
    .form-control.is-invalid { border-color: #ef4444; }
    .form-hint {
        font-size: 0.75rem;
        color: #6b7280;
        margin-top: 0.15rem;
    }
    .form-error {
        font-size: 0.75rem;
        color: #ef4444;
        margin-top: 0.15rem;
    }

    /* ===== PRODUK INFO BANNER ===== */
    .produk-info-banner {
        display: none;
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        margin-top: 0.5rem;
        font-size: 0.78rem;
        color: #166534;
    }
    .produk-info-banner .info-row {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .produk-info-banner .info-item span { font-weight: 700; }

    /* ===== KALKULATOR BOX ===== */
    .kalkulator-box {
        display: none;
        background: #f0fdf4;
        border: 1.5px solid #6ee7b7;
        border-radius: 0.75rem;
        padding: 1.25rem;
        margin-top: 1.25rem;
    }
    .kalkulator-box .kalkultor-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #065f46;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .kalkulator-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit,minmax(200px,1fr));
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    .kal-item {
        background: white;
        border-radius: 0.5rem;
        padding: 0.75rem;
        text-align: center;
        border: 1px solid #d1fae5;
    }
    .kal-item .kal-label {
        font-size: 0.7rem;
        color: #6b7280;
        margin-bottom: 0.25rem;
    }
    .kal-item .kal-val {
        font-size: 0.95rem;
        font-weight: 700;
        color: #065f46;
    }
    .kal-item.highlight .kal-val { color: #059669; font-size: 1.05rem; }
    .kalkulator-breakdown {
        background: white;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        border: 1px solid #d1fae5;
    }
    .kalkulator-breakdown .bk-title {
        font-size: 0.75rem;
        font-weight: 700;
        color: #374151;
        margin-bottom: 0.5rem;
    }
    .bk-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.8rem;
        padding: 0.2rem 0;
        color: #4b5563;
    }
    .bk-row.total {
        font-weight: 700;
        color: #065f46;
        border-top: 1px solid #d1fae5;
        margin-top: 0.25rem;
        padding-top: 0.4rem;
        font-size: 0.875rem;
    }

    /* ===== DSR BOX ===== */
    .dsr-box {
        display: none;
        margin-top: 1rem;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .dsr-box.dsr-ok   { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .dsr-box.dsr-warn { background: #fefce8; border: 1px solid #fde68a; color: #92400e; }
    .dsr-box.dsr-bad  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

    /* ===== UPLOAD ZONA ===== */
    .upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 0.75rem;
        padding: 1.5rem;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
        position: relative;
    }
    .upload-area:hover,
    .upload-area.dragover { border-color: #059669; background: #f0fdf4; }
    .upload-area input[type="file"] {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
        width: 100%;
        height: 100%;
    }
    .upload-icon { font-size: 1.75rem; margin-bottom: 0.4rem; }
    .upload-title { font-size: 0.82rem; font-weight: 600; color: #374151; }
    .upload-sub   { font-size: 0.72rem; color: #6b7280; margin-top: 0.15rem; }
    .upload-preview {
        display: none;
        margin-top: 0.5rem;
        font-size: 0.78rem;
        color: #059669;
        font-weight: 600;
    }

    /* ===== NAVIGATION BUTTONS ===== */
    .wizard-footer {
        padding: 1.25rem 1.75rem;
        border-top: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .btn-back {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.6rem 1.25rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid #d1d5db;
        background: white;
        color: #374151;
        font-family: inherit;
        transition: all 0.15s;
    }
    .btn-back:hover { background: #f8fafc; border-color: #94a3b8; }
    .btn-next {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.6rem 1.5rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        background: #059669;
        color: white;
        font-family: inherit;
        transition: background 0.15s;
    }
    .btn-next:hover { background: #047857; }
    .btn-submit {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.6rem 1.75rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 700;
        cursor: pointer;
        border: none;
        background: linear-gradient(135deg, #065f46, #059669);
        color: white;
        font-family: inherit;
        transition: opacity 0.15s;
        box-shadow: 0 2px 8px rgba(5,150,105,0.3);
    }
    .btn-submit:hover { opacity: 0.9; }

    /* ===== ALERT ===== */
    .alert-success {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        color: #166534;
        padding: 0.85rem 1.1rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        margin-bottom: 1.25rem;
    }
    .alert-error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 0.85rem 1.1rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        margin-bottom: 1.25rem;
    }

    /* ===== STEP PANELS ===== */
    .step-panel { display: none; }
    .step-panel.active { display: block; }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 640px) {
        .form-grid { grid-template-columns: 1fr; }
        .form-group.full { grid-column: span 1; }
        .kalkulator-grid { grid-template-columns: 1fr 1fr; }
        .step-label { font-size: 0.7rem; }
    }
</style>
@endsection

@section('content')

{{-- ALERT FLASH --}}
@if(session('success'))
    <div class="alert-success">&#10003; {{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert-error">&#9888; {{ session('error') }}</div>
@endif
{{-- Error validasi server. Wizard ini memakai `novalidate` sehingga validasi
     HTML5 tidak berjalan; tanpa blok ini kegagalan validasi sisi server
     (mis. plafon di luar batas produk) tidak terlihat sama sekali oleh
     pemohon — form hanya "memantul" kembali tanpa penjelasan. --}}
@if($errors->any())
    <div class="alert-error" role="alert">
        <div style="font-weight:600;margin-bottom:.25rem;">Periksa kembali isian Anda:</div>
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach($errors->all() as $pesan)
                <li>{{ $pesan }}</li>
            @endforeach
        </ul>
    </div>
@endif

{{-- STEPPER HEADER --}}
<div class="stepper" id="mainStepper">
    {{-- Step 1 --}}
    <div class="step-item active" id="stepItem1">
        <div class="step-circle" id="stepCircle1">1</div>
        <div class="step-label">Data<br>Pinjaman</div>
    </div>
    {{-- Step 2 --}}
    <div class="step-item" id="stepItem2">
        <div class="step-circle" id="stepCircle2">2</div>
        <div class="step-label">Data<br>Ekonomi</div>
    </div>
    {{-- Step 3 --}}
    <div class="step-item" id="stepItem3">
        <div class="step-circle" id="stepCircle3">3</div>
        <div class="step-label">Upload<br>Dokumen</div>
    </div>
</div>

{{-- MAIN FORM --}}
<form method="POST"
      action="{{ route('nasabah.pengajuan-pinjaman.store.baru') }}"
      enctype="multipart/form-data"
      id="wizardForm"
      novalidate>
    @csrf

    {{-- ==================== STEP 1: DATA PINJAMAN ==================== --}}
    <div class="step-panel active" id="panel1">
        <div class="wizard-card">
            <div class="wizard-card-header">
                <span class="step-badge">Langkah 1 / 3</span>
                <h2>&#128176; Data Pinjaman</h2>
            </div>
            <div class="wizard-body">
                <div class="form-grid">

                    {{-- Produk --}}
                    <div class="form-group full">
                        <label class="form-label" for="produk_id">
                            Produk Pembiayaan <span class="req">*</span>
                        </label>
                        <select name="produk_id" id="produk_id" class="form-control" required
                                onchange="onProdukChange(this)">
                            <option value="">-- Pilih Produk --</option>
                            @foreach($produk as $p)
                            <option value="{{ $p->id }}"
                                data-nama="{{ $p->nama_produk }}"
                                data-akad="{{ $p->akad_syariah }}"
                                data-margin="{{ $p->margin_persen }}"
                                data-min="{{ $p->min_plafon }}"
                                data-max="{{ $p->max_plafon }}"
                                data-min-tenor="{{ $p->min_tenor }}"
                                data-max-tenor="{{ $p->max_tenor }}"
                                {{ old('produk_id') == $p->id ? 'selected' : '' }}>
                                {{ $p->nama_produk }} &mdash; {{ $p->akad_syariah }}
                            </option>
                            @endforeach
                        </select>
                        <div class="form-error" id="err_produk_id" style="display:none;">Pilih produk pembiayaan terlebih dahulu.</div>

                        {{-- Info produk yang dipilih --}}
                        <div class="produk-info-banner" id="produkInfoBanner">
                            <div class="info-row">
                                <div class="info-item">Akad: <span id="infoAkad">-</span></div>
                                <div class="info-item">Margin: <span id="infoMargin">-</span>%/tahun</div>
                                <div class="info-item">Plafon: <span id="infoPlafon">-</span></div>
                                <div class="info-item">Tenor: <span id="infoTenor">-</span> bulan</div>
                            </div>
                        </div>
                    </div>

                    {{-- Nominal --}}
                    <div class="form-group">
                        <label class="form-label" for="jumlah_pengajuan">
                            Nominal Pinjaman (Rp) <span class="req">*</span>
                        </label>
                        <input type="number" name="jumlah_pengajuan" id="jumlah_pengajuan"
                               class="form-control"
                               min="{{ \App\Support\Setting::minimalPengajuanPembiayaan() }}" step="100000"
                               value="{{ old('jumlah_pengajuan') }}"
                               placeholder="Contoh: 5000000"
                               required
                               oninput="hitungKalkulator()">
                        <div class="form-hint" id="hintPlafon"></div>
                        <div class="form-error" id="err_jumlah" style="display:none;">Masukkan nominal pinjaman yang valid.</div>
                    </div>

                    {{-- Tenor --}}
                    <div class="form-group">
                        <label class="form-label" for="tenor">
                            Tenor (bulan) <span class="req">*</span>
                        </label>
                        <input type="number" name="tenor" id="tenor"
                               class="form-control"
                               min="1" max="360"
                               value="{{ old('tenor') }}"
                               placeholder="Contoh: 12"
                               required
                               oninput="hitungKalkulator()">
                        <div class="form-hint" id="hintTenor"></div>
                        <div class="form-error" id="err_tenor" style="display:none;">Masukkan tenor yang valid.</div>
                    </div>

                    {{-- Tujuan --}}
                    <div class="form-group full">
                        <label class="form-label" for="tujuan">
                            Tujuan Penggunaan Dana <span class="req">*</span>
                        </label>
                        <input type="text" name="tujuan" id="tujuan"
                               class="form-control"
                               value="{{ old('tujuan') }}"
                               placeholder="Contoh: Modal usaha warung makan, renovasi rumah, dll."
                               maxlength="255"
                               required>
                        <div class="form-error" id="err_tujuan" style="display:none;">Tujuan penggunaan dana wajib diisi.</div>
                    </div>

                </div>{{-- /form-grid --}}

                {{-- KALKULATOR REALTIME --}}
                <div class="kalkulator-box" id="kalkulatorBox">
                    <div class="kalkultor-title">&#128200; Simulasi Angsuran (Metode Flat)</div>
                    <div class="kalkulator-grid">
                        <div class="kal-item">
                            <div class="kal-label">Pokok / Bulan</div>
                            <div class="kal-val" id="kalPokok">-</div>
                        </div>
                        <div class="kal-item">
                            <div class="kal-label">Margin / Bulan</div>
                            <div class="kal-val" id="kalMarginBulan">-</div>
                        </div>
                        <div class="kal-item highlight">
                            <div class="kal-label">Total Angsuran / Bulan</div>
                            <div class="kal-val" id="kalAngsuran">-</div>
                        </div>
                    </div>
                    <div class="kalkulator-breakdown">
                        <div class="bk-title">Rincian</div>
                        <div class="bk-row">
                            <span>Pokok Pinjaman</span>
                            <span id="bkPokok">-</span>
                        </div>
                        <div class="bk-row">
                            <span>Total Margin (<span id="bkMarginPct">-</span>%/th × <span id="bkTenorTh">-</span> th)</span>
                            <span id="bkTotalMargin">-</span>
                        </div>
                        <div class="bk-row total">
                            <span>Total Pembiayaan</span>
                            <span id="bkTotalPembiayaan">-</span>
                        </div>
                    </div>
                </div>

            </div>{{-- /wizard-body --}}
            <div class="wizard-footer">
                <div></div>
                <button type="button" class="btn-next" onclick="goStep(2)">
                    Selanjutnya &#8594;
                </button>
            </div>
        </div>
    </div>{{-- /panel1 --}}

    {{-- ==================== STEP 2: DATA EKONOMI ==================== --}}
    <div class="step-panel" id="panel2">
        <div class="wizard-card">
            <div class="wizard-card-header">
                <span class="step-badge">Langkah 2 / 3</span>
                <h2>&#128200; Data Ekonomi</h2>
            </div>
            <div class="wizard-body">
                <div class="form-grid">

                    {{-- Penghasilan --}}
                    <div class="form-group">
                        <label class="form-label" for="penghasilan_bulanan">
                            Penghasilan Bulanan (Rp) <span class="req">*</span>
                        </label>
                        <input type="number" name="penghasilan_bulanan" id="penghasilan_bulanan"
                               class="form-control"
                               min="0" step="100000"
                               value="{{ old('penghasilan_bulanan') }}"
                               placeholder="Contoh: 3000000"
                               required
                               oninput="hitungDSR()">
                        <div class="form-error" id="err_penghasilan" style="display:none;">Penghasilan bulanan wajib diisi.</div>
                    </div>

                    {{-- Pengeluaran --}}
                    <div class="form-group">
                        <label class="form-label" for="pengeluaran_bulanan">
                            Pengeluaran Bulanan (Rp) <span class="req">*</span>
                        </label>
                        <input type="number" name="pengeluaran_bulanan" id="pengeluaran_bulanan"
                               class="form-control"
                               min="0" step="100000"
                               value="{{ old('pengeluaran_bulanan') }}"
                               placeholder="Contoh: 1500000"
                               required>
                        <div class="form-error" id="err_pengeluaran" style="display:none;">Pengeluaran bulanan wajib diisi.</div>
                    </div>

                    {{-- Tanggungan --}}
                    <div class="form-group">
                        <label class="form-label" for="jumlah_tanggungan">
                            Jumlah Tanggungan <span class="req">*</span>
                        </label>
                        <select name="jumlah_tanggungan" id="jumlah_tanggungan"
                                class="form-control" required>
                            <option value="">-- Pilih --</option>
                            @for($i = 0; $i <= 10; $i++)
                            <option value="{{ $i }}" {{ old('jumlah_tanggungan') == $i ? 'selected' : '' }}>
                                {{ $i }} orang{{ $i === 0 ? ' (tidak ada)' : '' }}
                            </option>
                            @endfor
                        </select>
                        <div class="form-error" id="err_tanggungan" style="display:none;">Pilih jumlah tanggungan.</div>
                    </div>

                    {{-- DSR Info --}}
                    <div class="form-group" style="justify-content:flex-end;">
                        <div class="dsr-box dsr-ok" id="dsrBox" style="display:none; margin-top:auto;">
                            <span id="dsrText"></span>
                        </div>
                    </div>

                    {{-- Agunan Tipe --}}
                    <div class="form-group">
                        <label class="form-label" for="agunan_tipe">
                            Tipe Agunan <span class="req">*</span>
                        </label>
                        <select name="agunan_tipe" id="agunan_tipe"
                                class="form-control" required>
                            <option value="">-- Pilih Tipe --</option>
                            <option value="BPKB Kendaraan" {{ old('agunan_tipe') == 'BPKB Kendaraan' ? 'selected' : '' }}>BPKB Kendaraan</option>
                            <option value="Sertifikat Tanah" {{ old('agunan_tipe') == 'Sertifikat Tanah' ? 'selected' : '' }}>Sertifikat Tanah / Bangunan</option>
                            <option value="Deposito" {{ old('agunan_tipe') == 'Deposito' ? 'selected' : '' }}>Deposito / Tabungan</option>
                            <option value="SK Pegawai" {{ old('agunan_tipe') == 'SK Pegawai' ? 'selected' : '' }}>SK Pegawai / Pensiun</option>
                            <option value="Emas" {{ old('agunan_tipe') == 'Emas' ? 'selected' : '' }}>Emas / Perhiasan</option>
                            <option value="Lainnya" {{ old('agunan_tipe') == 'Lainnya' ? 'selected' : '' }}>Lainnya</option>
                        </select>
                        <div class="form-error" id="err_agunan_tipe" style="display:none;">Pilih tipe agunan.</div>
                    </div>

                    {{-- Agunan Deskripsi --}}
                    <div class="form-group full">
                        <label class="form-label" for="agunan_deskripsi">
                            Deskripsi Agunan <span class="req">*</span>
                        </label>
                        <input type="text" name="agunan_deskripsi" id="agunan_deskripsi"
                               class="form-control"
                               value="{{ old('agunan_deskripsi') }}"
                               placeholder="Contoh: BPKB Motor Honda Beat 2022 Atas Nama Sendiri"
                               maxlength="500"
                               required>
                        <div class="form-error" id="err_agunan_deskripsi" style="display:none;">Deskripsi agunan wajib diisi.</div>
                    </div>

                </div>{{-- /form-grid --}}
            </div>{{-- /wizard-body --}}
            <div class="wizard-footer">
                <button type="button" class="btn-back" onclick="goStep(1)">
                    &#8592; Kembali
                </button>
                <button type="button" class="btn-next" onclick="goStep(3)">
                    Selanjutnya &#8594;
                </button>
            </div>
        </div>
    </div>{{-- /panel2 --}}

    {{-- ==================== STEP 3: UPLOAD DOKUMEN ==================== --}}
    <div class="step-panel" id="panel3">
        <div class="wizard-card">
            <div class="wizard-card-header">
                <span class="step-badge">Langkah 3 / 3</span>
                <h2>&#128196; Upload Dokumen</h2>
            </div>
            <div class="wizard-body">
                <div style="margin-bottom:1rem;padding:0.75rem 1rem;background:#fef3c7;border:1px solid #fde68a;border-radius:0.5rem;font-size:0.8rem;color:#92400e;">
                    &#9888; Pastikan dokumen terbaca jelas. Format yang diterima: JPG, PNG, PDF. Ukuran maks. 5 MB per file.
                </div>

                <div class="form-grid">

                    {{-- KTP --}}
                    <div class="form-group">
                        <label class="form-label" for="dokumen_ktp">
                            Foto / Scan KTP <span class="req">*</span>
                        </label>
                        <div class="upload-area" id="uploadKtp" ondragover="dragOver(event,this)" ondragleave="dragLeave(this)" ondrop="dropFile(event,this,'dokumen_ktp')">
                            <input type="file" name="dokumen_ktp" id="dokumen_ktp"
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   required
                                   onchange="previewFile(this, 'previewKtp')">
                            <div class="upload-icon">&#128196;</div>
                            <div class="upload-title">KTP (Wajib)</div>
                            <div class="upload-sub">JPG, PNG, PDF &bull; Maks. 5 MB</div>
                        </div>
                        <div class="upload-preview" id="previewKtp"></div>
                        <div class="form-error" id="err_ktp" style="display:none;">Dokumen KTP wajib diunggah.</div>
                    </div>

                    {{-- KK --}}
                    <div class="form-group">
                        <label class="form-label" for="dokumen_kk">
                            Foto / Scan Kartu Keluarga <span class="req">*</span>
                        </label>
                        <div class="upload-area" id="uploadKk" ondragover="dragOver(event,this)" ondragleave="dragLeave(this)" ondrop="dropFile(event,this,'dokumen_kk')">
                            <input type="file" name="dokumen_kk" id="dokumen_kk"
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   required
                                   onchange="previewFile(this, 'previewKk')">
                            <div class="upload-icon">&#128106;</div>
                            <div class="upload-title">Kartu Keluarga (Wajib)</div>
                            <div class="upload-sub">JPG, PNG, PDF &bull; Maks. 5 MB</div>
                        </div>
                        <div class="upload-preview" id="previewKk"></div>
                        <div class="form-error" id="err_kk" style="display:none;">Dokumen KK wajib diunggah.</div>
                    </div>

                    {{-- Slip Gaji / Bukti Usaha --}}
                    <div class="form-group">
                        <label class="form-label" for="dokumen_penghasilan">
                            Slip Gaji / Bukti Usaha <span class="req">*</span>
                        </label>
                        <div class="upload-area" id="uploadPenghasilan" ondragover="dragOver(event,this)" ondragleave="dragLeave(this)" ondrop="dropFile(event,this,'dokumen_penghasilan')">
                            <input type="file" name="dokumen_penghasilan" id="dokumen_penghasilan"
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   required
                                   onchange="previewFile(this, 'previewPenghasilan')">
                            <div class="upload-icon">&#128184;</div>
                            <div class="upload-title">Slip Gaji / Bukti Usaha (Wajib)</div>
                            <div class="upload-sub">JPG, PNG, PDF &bull; Maks. 5 MB</div>
                        </div>
                        <div class="upload-preview" id="previewPenghasilan"></div>
                        <div class="form-error" id="err_penghasilan_dok" style="display:none;">Dokumen bukti penghasilan wajib diunggah.</div>
                    </div>

                    {{-- Foto Agunan (opsional) --}}
                    <div class="form-group">
                        <label class="form-label" for="dokumen_agunan">
                            Foto Agunan
                            <span style="font-weight:400;color:#6b7280;">(Opsional)</span>
                        </label>
                        <div class="upload-area" id="uploadAgunan" ondragover="dragOver(event,this)" ondragleave="dragLeave(this)" ondrop="dropFile(event,this,'dokumen_agunan')">
                            <input type="file" name="dokumen_agunan" id="dokumen_agunan"
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   onchange="previewFile(this, 'previewAgunan')">
                            <div class="upload-icon">&#127968;</div>
                            <div class="upload-title">Foto Agunan (Opsional)</div>
                            <div class="upload-sub">JPG, PNG, PDF &bull; Maks. 5 MB</div>
                        </div>
                        <div class="upload-preview" id="previewAgunan"></div>
                    </div>

                </div>{{-- /form-grid --}}

                {{-- RINGKASAN PENGAJUAN --}}
                <div id="ringkasanBox" style="margin-top:1.5rem;padding:1.25rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;">
                    <div style="font-size:0.85rem;font-weight:700;color:#1e293b;margin-bottom:0.75rem;">&#128203; Ringkasan Pengajuan</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.5rem;font-size:0.8rem;color:#4b5563;">
                        <div>Produk: <strong id="sumProduk">-</strong></div>
                        <div>Nominal: <strong id="sumNominal">-</strong></div>
                        <div>Tenor: <strong id="sumTenor">-</strong></div>
                        <div>Angsuran/Bulan: <strong id="sumAngsuran" style="color:#059669;">-</strong></div>
                        <div>Tujuan: <strong id="sumTujuan">-</strong></div>
                        <div>Agunan: <strong id="sumAgunan">-</strong></div>
                    </div>
                </div>

            </div>{{-- /wizard-body --}}
            <div class="wizard-footer">
                <button type="button" class="btn-back" onclick="goStep(2)">
                    &#8592; Kembali
                </button>
                <button type="submit" class="btn-submit" id="btnSubmit">
                    &#10003; Kirim Pengajuan
                </button>
            </div>
        </div>
    </div>{{-- /panel3 --}}

</form>
@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    /* ============================================================
       STATE
    ============================================================ */
    var currentStep = 1;
    var selectedProduk = null;  // { margin, minPlafon, maxPlafon, minTenor, maxTenor, nama, akad }
    var kalAngsuranPerBulan = 0;

    /* ============================================================
       STEP NAVIGATION
    ============================================================ */
    window.goStep = function (target) {
        if (target > currentStep && !validateStep(currentStep)) return;
        showStep(target);
    };

    function showStep(step) {
        // Hide all panels
        [1, 2, 3].forEach(function (i) {
            document.getElementById('panel' + i).classList.remove('active');
            var item = document.getElementById('stepItem' + i);
            item.classList.remove('active', 'done');

            var circle = document.getElementById('stepCircle' + i);
            if (i < step) {
                item.classList.add('done');
                circle.innerHTML = '&#10003;';
            } else if (i === step) {
                item.classList.add('active');
                circle.innerHTML = i;
            } else {
                circle.innerHTML = i;
            }
        });

        document.getElementById('panel' + step).classList.add('active');
        currentStep = step;

        // Scroll to top of wizard
        var form = document.getElementById('wizardForm');
        if (form) form.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Update ringkasan saat masuk step 3
        if (step === 3) updateRingkasan();
    }

    /* ============================================================
       VALIDATION PER STEP
    ============================================================ */
    function validateStep(step) {
        var ok = true;

        if (step === 1) {
            ok = validateField('produk_id', 'err_produk_id', function (v) { return v !== ''; }) && ok;
            ok = validateField('jumlah_pengajuan', 'err_jumlah', function (v) {
                var n = parseFloat(v);
                return !isNaN(n) && n >= 100000;
            }) && ok;
            ok = validateField('tenor', 'err_tenor', function (v) {
                var n = parseInt(v);
                return !isNaN(n) && n >= 1;
            }) && ok;
            ok = validateField('tujuan', 'err_tujuan', function (v) { return v.trim().length >= 3; }) && ok;
        }

        if (step === 2) {
            ok = validateField('penghasilan_bulanan', 'err_penghasilan', function (v) {
                var n = parseFloat(v);
                return !isNaN(n) && n >= 0;
            }) && ok;
            ok = validateField('pengeluaran_bulanan', 'err_pengeluaran', function (v) {
                var n = parseFloat(v);
                return !isNaN(n) && n >= 0;
            }) && ok;
            ok = validateField('jumlah_tanggungan', 'err_tanggungan', function (v) { return v !== ''; }) && ok;
            ok = validateField('agunan_tipe', 'err_agunan_tipe', function (v) { return v !== ''; }) && ok;
            ok = validateField('agunan_deskripsi', 'err_agunan_deskripsi', function (v) { return v.trim().length >= 3; }) && ok;
        }

        return ok;
    }

    function validateField(fieldId, errId, testFn) {
        var el  = document.getElementById(fieldId);
        var err = document.getElementById(errId);
        if (!el) return true;
        var valid = testFn(el.value);
        el.classList.toggle('is-invalid', !valid);
        if (err) err.style.display = valid ? 'none' : 'block';
        return valid;
    }

    /* ============================================================
       PRODUK CHANGE
    ============================================================ */
    window.onProdukChange = function (sel) {
        var opt = sel.options[sel.selectedIndex];
        if (!opt.value) {
            selectedProduk = null;
            document.getElementById('produkInfoBanner').style.display = 'none';
            document.getElementById('kalkulatorBox').style.display = 'none';
            document.getElementById('hintPlafon').textContent = '';
            document.getElementById('hintTenor').textContent = '';
            return;
        }

        selectedProduk = {
            nama:      opt.dataset.nama,
            akad:      opt.dataset.akad,
            margin:    parseFloat(opt.dataset.margin) || 0,
            minPlafon: parseFloat(opt.dataset.min)   || 0,
            maxPlafon: parseFloat(opt.dataset.max)   || 0,
            minTenor:  parseInt(opt.dataset.minTenor) || 1,
            maxTenor:  parseInt(opt.dataset.maxTenor) || 360,
        };

        // Update banner
        document.getElementById('infoAkad').textContent    = selectedProduk.akad;
        document.getElementById('infoMargin').textContent  = selectedProduk.margin;
        document.getElementById('infoPlafon').textContent  =
            'Rp ' + fmt(selectedProduk.minPlafon) + ' – Rp ' + fmt(selectedProduk.maxPlafon);
        document.getElementById('infoTenor').textContent   =
            selectedProduk.minTenor + ' – ' + selectedProduk.maxTenor;
        document.getElementById('produkInfoBanner').style.display = 'block';

        // Update hints
        document.getElementById('hintPlafon').textContent =
            'Min Rp ' + fmt(selectedProduk.minPlafon) + ' – Maks Rp ' + fmt(selectedProduk.maxPlafon);
        document.getElementById('hintTenor').textContent  =
            'Min ' + selectedProduk.minTenor + ' – Maks ' + selectedProduk.maxTenor + ' bulan';

        // Update tenor min/max
        var tenorEl = document.getElementById('tenor');
        tenorEl.min = selectedProduk.minTenor;
        tenorEl.max = selectedProduk.maxTenor;

        hitungKalkulator();
    };

    /* ============================================================
       KALKULATOR REALTIME
    ============================================================ */
    window.hitungKalkulator = function () {
        if (!selectedProduk) return;

        var pokok = parseFloat(document.getElementById('jumlah_pengajuan').value) || 0;
        var tenor = parseInt(document.getElementById('tenor').value)              || 0;

        if (pokok <= 0 || tenor <= 0) {
            document.getElementById('kalkulatorBox').style.display = 'none';
            kalAngsuranPerBulan = 0;
            return;
        }

        // Flat rate formula
        var marginPct      = selectedProduk.margin;                        // % per tahun
        var totalMargin    = pokok * (marginPct / 100) * (tenor / 12);     // total margin selama tenor
        var totalPembiayaan = pokok + totalMargin;
        var angsuranBulan  = totalPembiayaan / tenor;
        var pokokBulan     = pokok / tenor;
        var marginBulan    = totalMargin / tenor;

        kalAngsuranPerBulan = angsuranBulan;

        // Tampilkan
        document.getElementById('kalPokok').textContent       = 'Rp ' + fmt(pokokBulan);
        document.getElementById('kalMarginBulan').textContent = 'Rp ' + fmt(marginBulan);
        document.getElementById('kalAngsuran').textContent    = 'Rp ' + fmt(angsuranBulan);

        document.getElementById('bkPokok').textContent          = 'Rp ' + fmt(pokok);
        document.getElementById('bkMarginPct').textContent      = marginPct;
        document.getElementById('bkTenorTh').textContent        = (tenor / 12).toFixed(2);
        document.getElementById('bkTotalMargin').textContent    = 'Rp ' + fmt(totalMargin);
        document.getElementById('bkTotalPembiayaan').textContent = 'Rp ' + fmt(totalPembiayaan);

        document.getElementById('kalkulatorBox').style.display = 'block';

        // Hitung DSR jika ada penghasilan
        hitungDSR();
    };

    /* ============================================================
       DSR (DEBT SERVICE RATIO)
    ============================================================ */
    window.hitungDSR = function () {
        var penghasilan = parseFloat(document.getElementById('penghasilan_bulanan').value) || 0;
        var dsrBox      = document.getElementById('dsrBox');

        if (penghasilan <= 0 || kalAngsuranPerBulan <= 0) {
            dsrBox.style.display = 'none';
            return;
        }

        var dsr = (kalAngsuranPerBulan / penghasilan) * 100;
        var dsrStr = dsr.toFixed(1) + '%';
        var txt;

        dsrBox.classList.remove('dsr-ok', 'dsr-warn', 'dsr-bad');

        if (dsr <= 30) {
            txt = '&#10003; DSR ' + dsrStr + ' — Kemampuan angsuran sangat baik.';
            dsrBox.classList.add('dsr-ok');
        } else if (dsr <= 40) {
            txt = '&#9888; DSR ' + dsrStr + ' — Batas wajar, perlu dipertimbangkan.';
            dsrBox.classList.add('dsr-warn');
        } else {
            txt = '&#9888; DSR ' + dsrStr + ' — Melebihi batas 40%, pengajuan berisiko ditolak.';
            dsrBox.classList.add('dsr-bad');
        }

        document.getElementById('dsrText').innerHTML = txt;
        dsrBox.style.display = 'block';
    };

    /* ============================================================
       UPLOAD PREVIEW
    ============================================================ */
    window.previewFile = function (input, previewId) {
        var preview = document.getElementById(previewId);
        if (input.files && input.files[0]) {
            var f = input.files[0];
            preview.textContent = '&#10003; ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
            preview.style.display = 'block';
            // Visual feedback
            var area = input.closest('.upload-area');
            if (area) area.style.borderColor = '#059669';
        } else {
            preview.style.display = 'none';
        }
    };

    window.dragOver = function (e, area) {
        e.preventDefault();
        area.classList.add('dragover');
    };

    window.dragLeave = function (area) {
        area.classList.remove('dragover');
    };

    window.dropFile = function (e, area, inputId) {
        e.preventDefault();
        area.classList.remove('dragover');
        var input = document.getElementById(inputId);
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            // Determine preview ID from inputId
            var previewMap = {
                'dokumen_ktp': 'previewKtp',
                'dokumen_kk': 'previewKk',
                'dokumen_penghasilan': 'previewPenghasilan',
                'dokumen_agunan': 'previewAgunan',
            };
            window.previewFile(input, previewMap[inputId] || '');
        }
    };

    /* ============================================================
       RINGKASAN (Step 3)
    ============================================================ */
    function updateRingkasan() {
        var produkEl   = document.getElementById('produk_id');
        var nominalVal = parseFloat(document.getElementById('jumlah_pengajuan').value) || 0;
        var tenorVal   = parseInt(document.getElementById('tenor').value)              || 0;
        var tujuanVal  = document.getElementById('tujuan').value;
        var agunanTipe = document.getElementById('agunan_tipe').value;
        var agunanDesk = document.getElementById('agunan_deskripsi').value;

        var produkNama = produkEl && produkEl.selectedIndex > 0
            ? produkEl.options[produkEl.selectedIndex].dataset.nama
            : '-';

        document.getElementById('sumProduk').textContent   = produkNama;
        document.getElementById('sumNominal').textContent  = nominalVal ? 'Rp ' + fmt(nominalVal) : '-';
        document.getElementById('sumTenor').textContent    = tenorVal ? tenorVal + ' bulan' : '-';
        document.getElementById('sumAngsuran').textContent = kalAngsuranPerBulan ? 'Rp ' + fmt(kalAngsuranPerBulan) : '-';
        document.getElementById('sumTujuan').textContent   = tujuanVal || '-';
        document.getElementById('sumAgunan').textContent   = agunanTipe ? agunanTipe + (agunanDesk ? ' – ' + agunanDesk : '') : '-';
    }

    /* ============================================================
       FORM SUBMIT VALIDATION (Step 3 dokumen)
    ============================================================ */
    document.getElementById('wizardForm').addEventListener('submit', function (e) {
        var ok = true;

        // Validasi ulang semua step sebelum submit
        if (!validateStep(1) || !validateStep(2)) {
            e.preventDefault();
            alert('Terdapat data yang belum lengkap. Silakan periksa kembali langkah sebelumnya.');
            return;
        }

        // Validasi dokumen wajib
        ok = validateFileField('dokumen_ktp', 'err_ktp')          && ok;
        ok = validateFileField('dokumen_kk', 'err_kk')            && ok;
        ok = validateFileField('dokumen_penghasilan', 'err_penghasilan_dok') && ok;

        if (!ok) {
            e.preventDefault();
            return;
        }

        // Nonaktifkan tombol submit (anti double-click)
        document.getElementById('btnSubmit').disabled = true;
        document.getElementById('btnSubmit').textContent = 'Mengirim...';
    });

    function validateFileField(inputId, errId) {
        var input = document.getElementById(inputId);
        var err   = document.getElementById(errId);
        var valid = input && input.files && input.files.length > 0;
        if (err) err.style.display = valid ? 'none' : 'block';
        if (input) input.closest('.upload-area').style.borderColor = valid ? '#059669' : '#ef4444';
        return valid;
    }

    /* ============================================================
       HELPER FORMAT RUPIAH
    ============================================================ */
    function fmt(n) {
        return Math.round(n).toLocaleString('id-ID');
    }

    /* ============================================================
       INIT — restore produk jika ada old() value
    ============================================================ */
    (function init() {
        var produkEl = document.getElementById('produk_id');
        if (produkEl && produkEl.value) {
            window.onProdukChange(produkEl);
        }
    }());

}());
</script>
@endsection
