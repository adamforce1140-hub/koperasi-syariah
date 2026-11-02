@extends('layouts.app')
@section('page-title', "Bayar Angsuran")

@section('title', 'Bayar Angsuran')

@section('sidebar')
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">&#128101;</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="{{ route('dashboard.teller') }}" class="nav-item {{ request()->routeIs('dashboard.teller') ? 'active' : '' }}">
    <span class="icon">&#127968;</span> Dashboard
</a>
<a href="{{ route('teller.setor') }}" class="nav-item {{ request()->routeIs('teller.setor') ? 'active' : '' }}">
    <span class="icon">&#128176;</span> Setor Tunai
</a>
<a href="{{ route('teller.tarik') }}" class="nav-item {{ request()->routeIs('teller.tarik') ? 'active' : '' }}">
    <span class="icon">&#128184;</span> Tarik Tunai
</a>
<a href="{{ route('teller.angsuran') }}" class="nav-item active">
    <span class="icon">&#128200;</span> Bayar Angsuran
</a>
@endsection

@section('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.ts-wrapper { width:100% !important; }
.ts-wrapper.single .ts-control { border-radius:6px !important; padding:.6rem .8rem !important; font-size:.95rem !important; border-color:#d1d5db !important; min-height:auto !important; }
.ts-wrapper.focus.single .ts-control { border-color:#1d4ed8 !important; box-shadow:0 0 0 2px rgba(29,78,216,.15) !important; }
.tazir-box { background:#fefce8; border:1px solid #fde68a; border-radius:8px; padding:1rem; margin-bottom:1.2rem; }
.tazir-box.dibebaskan { background:#f0fdf4; border-color:#86efac; }
.metode-radio { display:flex; gap:1rem; flex-wrap:wrap; margin-top:.5rem; }
.metode-option { display:flex; align-items:center; gap:.5rem; padding:.5rem .9rem; border:2px solid #e2e8f0; border-radius:8px; cursor:pointer; transition:all .2s; font-size:.9rem; }
.metode-option:has(input:checked) { border-color:#1d4ed8; background:#eff6ff; font-weight:600; }
.metode-option input[type=radio] { accent-color:#1d4ed8; }
</style>
@endsection

@section('content')
<div style="max-width:760px;margin:0 auto;">
    <div class="data-card">
        <div class="card-header">
            <span class="card-title">&#128200; Bayar Angsuran Pembiayaan</span>
        </div>

        {{-- Notifikasi --}}
        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            &#10003; {{ session('success') }}
        </div>
        @endif
        {{-- Pesan kegagalan dari controller (mis. pembiayaan sudah lunas,
             jurnal tidak balance, autodebet gagal). Sebelumnya hanya success
             & warning yang dirender, sehingga kegagalan terlihat "senyap". --}}
        @if(session('error'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            &#9888; {{ session('error') }}
        </div>
        @endif
        @if(session('warning'))
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            &#9888; {{ session('warning') }}
        </div>
        @endif
        @if($errors->has('angsuran'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            {{ $errors->first('angsuran') }}
        </div>
        @endif
        @if($errors->has('alasan_keringanan'))
        <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            {{ $errors->first('alasan_keringanan') }}
        </div>
        @endif

        {{-- Form Bayar Angsuran --}}
        <form method="POST" action="" id="form-bayar-angsuran">
            @csrf

            {{-- Pilih Anggota --}}
            <div style="margin-bottom:1.2rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Pilih Anggota</label>
                <select id="select_anggota" name="anggota_id"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;">
                    <option value="">-- Pilih Anggota --</option>
                    @foreach($anggota as $a)
                    <option value="{{ $a->id }}">
                        {{ $a->nama_lengkap }} ({{ $a->no_anggota }})
                    </option>
                    @endforeach
                </select>
                @if($anggota->isEmpty())
                <p style="margin-top:.5rem;font-size:.85rem;color:#64748b;">
                    Belum ada anggota dengan pembiayaan aktif yang belum lunas.
                </p>
                @endif
            </div>

            {{-- Loading indicator --}}
            <div id="loading-info" style="display:none;text-align:center;padding:1rem;color:#64748b;">
                Memuat data pembiayaan...
            </div>

            {{-- Card Info Pembiayaan (muncul setelah AJAX) --}}
            <div id="card-pembiayaan" style="display:none;">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-bottom:1.2rem;">
                    <h4 style="margin:0 0 .75rem;color:#1e40af;font-size:.95rem;">&#128196; Informasi Pembiayaan Aktif</h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.6rem .8rem;font-size:.9rem;">
                        <div>
                            <span style="color:#64748b;">No. Pembiayaan / Akad</span><br>
                            <input type="text" id="txt_no_akad" readonly
                                style="width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;border-radius:5px;background:#f1f5f9;font-weight:600;font-size:.9rem;">
                        </div>
                        <div>
                            <span style="color:#64748b;">Angsuran Ke-</span><br>
                            <input type="text" id="txt_angsuran_ke" readonly
                                style="width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;border-radius:5px;background:#f1f5f9;font-weight:600;font-size:.9rem;">
                        </div>
                        <div>
                            <span style="color:#64748b;">Total Pembiayaan</span><br>
                            <input type="text" id="txt_total_pembiayaan" readonly
                                style="width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;border-radius:5px;background:#f1f5f9;font-size:.9rem;">
                        </div>
                        <div>
                            <span style="color:#64748b;">Sisa Pembiayaan</span><br>
                            <input type="text" id="txt_sisa_pembiayaan" readonly
                                style="width:100%;padding:.4rem .6rem;border:1px solid #e2e8f0;border-radius:5px;background:#fff0f0;font-weight:600;color:#dc2626;font-size:.9rem;">
                        </div>
                        <div style="grid-column:1/-1;">
                            <span style="color:#64748b;">Tagihan Angsuran Bulan Ini</span><br>
                            <input type="text" id="txt_tagihan" readonly
                                style="width:100%;padding:.4rem .6rem;border:1px solid #bbf7d0;border-radius:5px;background:#f0fdf4;font-weight:700;color:#15803d;font-size:1rem;">
                        </div>
                    </div>
                </div>

                {{-- Hidden fields --}}
                <input type="hidden" name="pembiayaan_id" id="pembiayaan_id">
                <input type="hidden" name="angsuran_id"   id="angsuran_id">

                {{-- Input Jumlah Bayar — READONLY: selalu tersinkron dengan Tagihan Angsuran Bulan Ini --}}
                <div style="margin-bottom:1.2rem;">
                    <label for="input_jumlah_bayar" style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                        Jumlah Bayar <span style="font-weight:400;color:#64748b;">(otomatis — mengikuti Tagihan Angsuran Bulan Ini)</span>
                    </label>
                    <input type="number" id="input_jumlah_bayar"
                        min="1" step="1" placeholder="Pilih anggota terlebih dahulu"
                        style="width:100%;padding:.6rem .8rem;border:1px solid #e2e8f0;border-radius:6px;font-size:1rem;font-weight:600;background:#f1f5f9;color:#334155;cursor:not-allowed;"
                        readonly aria-readonly="true" tabindex="-1"
                        onkeydown="return false" onwheel="return false" oninput="return false">
                    {{-- Penampung nilai yang benar-benar dikirim: tetap terkirim walau field di atas readonly --}}
                    <input type="hidden" name="jumlah_bayar" id="jumlah_bayar_value" value="">
                    <input type="hidden" name="tagihan_angsuran" id="tagihan_angsuran_value" value="">
                    <p id="info_jumlah_bayar" style="margin:.4rem 0 0;font-size:.78rem;color:#78716c;">
                        Nominal terkunci sesuai tagihan angsuran bulan ini.
                    </p>
                </div>

                {{-- Metode Pembayaran --}}
                <div style="margin-bottom:1.2rem;">
                    <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                        Metode Pembayaran
                    </label>
                    <div class="metode-radio">
                        <label class="metode-option">
                            <input type="radio" name="metode_bayar" value="TUNAI" id="metode_tunai" checked>
                            &#128176; Tunai di Loket
                        </label>
                        <label class="metode-option">
                            <input type="radio" name="metode_bayar" value="AUTODEBET" id="metode_autodebet">
                            &#128184; Autodebet Simpanan Sukarela
                        </label>
                    </div>
                    <p style="margin:.4rem 0 0;font-size:.78rem;color:#78716c;" id="info_metode">
                        Tunai: kas masuk ke loket teller. Jurnal: DEBET Kas Teller (1-1001).
                    </p>
                </div>

                {{-- Ta'zir / Denda --}}
                <div class="tazir-box" id="tazir-box">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
                        <label style="font-weight:600;color:#92400e;">
                            Nominal Ta'zir / Denda <span style="font-weight:400;font-size:.82rem;color:#a16207;">(Dialokasikan ke Dana Kebajikan 2-3001 — PSAK 109)</span>
                        </label>
                        <div id="tazir-status-badge" style="font-size:.78rem;"></div>
                    </div>

                    {{-- Info kalkulasi otomatis --}}
                    <div id="tazir-info" style="font-size:.82rem;color:#78350f;background:#fef9c3;border-radius:6px;padding:.5rem .75rem;margin-bottom:.6rem;display:none;">
                        <span id="tazir-info-text"></span>
                    </div>

                    <input type="number" name="nominal_tazir" id="input_nominal_tazir"
                        min="0" step="1" placeholder="0 (kosongkan jika tidak ada denda)"
                        style="width:100%;padding:.6rem .8rem;border:1px solid #fcd34d;border-radius:6px;font-size:1rem;background:#fffbeb;">

                    {{-- Checkbox Ajukan Keringanan Ta'zir --}}
                    <div style="margin-top:.75rem;padding:.6rem .75rem;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">
                        <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.9rem;font-weight:500;">
                            <input type="checkbox" name="ajukan_keringanan" id="cb_ajukan_keringanan" value="1"
                                style="width:16px;height:16px;accent-color:#16a34a;">
                            <span>&#9989; Ajukan Keringanan / Pembebasan Ta'zir</span>
                        </label>
                        <p style="margin:.35rem 0 0 1.6rem;font-size:.78rem;color:#64748b;">
                            Centang jika anggota mengajukan keringanan (musibah / kesulitan keuangan).
                            Ta'zir <strong>ditangguhkan (Rp 0)</strong> dan pengajuan dikirim ke <strong>AO</strong> lalu
                            <strong>Manajer</strong> untuk disetujui.
                        </p>
                    </div>

                    {{-- Alasan Keringanan (muncul jika mengajukan keringanan) --}}
                    <div id="wrap_alasan" style="display:none;margin-top:.75rem;">
                        <label style="display:block;font-weight:600;margin-bottom:.3rem;font-size:.9rem;color:#374151;">
                            Alasan Keringanan (Musibah / Kesulitan Keuangan) <span style="color:#dc2626;">*</span>
                        </label>
                        <textarea name="alasan_keringanan" id="input_alasan" rows="2"
                            placeholder="Contoh: Musibah bencana alam, sakit keras, kesulitan keuangan..."
                            style="width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;resize:vertical;"></textarea>
                    </div>

                    <p style="margin:.5rem 0 0;font-size:.78rem;color:#78716c;">
                        Jurnal otomatis: DEBET Simpanan Sukarela (2-1001) / Kas Teller (1-1001) &rarr; KREDIT Dana Kebajikan (2-3001)
                    </p>
                </div>

                {{-- Tunggakan Ta'zir Bulan Lalu (keringanan ditolak Manajer) --}}
                <div id="tunggakan-box" style="display:none;background:#fff1f2;border:1px solid #fecdd3;border-radius:8px;padding:.85rem 1rem;margin-bottom:1.2rem;">
                    <div style="font-weight:600;color:#9f1239;margin-bottom:.5rem;">
                        &#9888; Tunggakan Ta'zir Bulan Lalu (Keringanan Ditolak Manajer)
                    </div>
                    <div id="tunggakan-detail" style="font-size:.84rem;color:#881337;"></div>
                    <div style="margin-top:.5rem;font-size:.95rem;font-weight:700;color:#9f1239;border-top:1px solid #fecdd3;padding-top:.5rem;">
                        Total Tunggakan: <span id="tunggakan-total">Rp 0</span>
                    </div>
                    <p style="margin:.45rem 0 0;font-size:.76rem;color:#9f1239;">
                        Tunggakan ini wajib dilunasi pada transaksi ini. Dibukukan ke Dana Kebajikan (2-3001)
                        hanya saat uang benar-benar diterima (cash basis, PSAK 109).
                    </p>
                </div>

                {{-- Ringkasan Total --}}
                <div id="ringkasan-total" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:.85rem 1rem;margin-bottom:1.2rem;display:none;">
                    <div style="font-weight:600;color:#0369a1;margin-bottom:.5rem;">&#128203; Ringkasan Pembayaran</div>
                    <div style="display:grid;grid-template-columns:1fr auto;gap:.3rem;font-size:.88rem;">
                        <span style="color:#374151;">Angsuran (Pokok + Margin)</span>
                        <span id="summary_angsuran" style="font-weight:600;text-align:right;"></span>
                        <span style="color:#374151;">Ta'zir / Denda Bulan Ini</span>
                        <span id="summary_tazir" style="font-weight:600;text-align:right;color:#d97706;"></span>
                        <span id="label_summary_tunggakan" style="color:#9f1239;display:none;">Tunggakan Ta'zir Bulan Lalu</span>
                        <span id="summary_tunggakan" style="font-weight:600;text-align:right;color:#be123c;display:none;"></span>
                        <span style="color:#374151;font-weight:700;border-top:1px solid #bae6fd;padding-top:.3rem;">Total Dibayar</span>
                        <span id="summary_total" style="font-weight:700;text-align:right;color:#0369a1;font-size:1rem;border-top:1px solid #bae6fd;padding-top:.3rem;"></span>
                    </div>
                </div>

                {{-- Tombol Bayar --}}
                <div style="text-align:right;">
                    <button type="submit" id="btn-bayar" disabled
                        style="background:#93c5fd;color:#fff;border:none;padding:.65rem 1.8rem;border-radius:7px;font-size:.95rem;font-weight:600;cursor:not-allowed;">
                        &#128176; Simpan Pembayaran Angsuran
                    </button>
                </div>
            </div>

            {{-- Tabel Jadwal Angsuran --}}
            <div id="tabel-jadwal" style="display:none;margin-top:1.5rem;">
                <h4 style="margin:0 0 .75rem;color:#1e40af;font-size:.95rem;">&#128197; Jadwal & Riwayat Angsuran</h4>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                        <thead>
                            <tr style="background:#f1f5f9;">
                                <th style="padding:.5rem .75rem;text-align:center;border:1px solid #e2e8f0;white-space:nowrap;">Ke-</th>
                                <th style="padding:.5rem .75rem;text-align:center;border:1px solid #e2e8f0;white-space:nowrap;">Jatuh Tempo</th>
                                <th style="padding:.5rem .75rem;text-align:right;border:1px solid #e2e8f0;white-space:nowrap;">Jumlah Angsuran</th>
                                <th style="padding:.5rem .75rem;text-align:center;border:1px solid #e2e8f0;white-space:nowrap;">Tgl Bayar</th>
                                <th style="padding:.5rem .75rem;text-align:center;border:1px solid #e2e8f0;white-space:nowrap;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-jadwal">
                            {{-- diisi via JavaScript --}}
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Pesan jika tidak ada pembiayaan --}}
            <div id="no-pembiayaan" style="display:none;background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:1rem;color:#92400e;">
                &#9888; Anggota ini tidak memiliki pembiayaan aktif yang perlu dibayar.
            </div>

        </form>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const cardPembiayaan  = document.getElementById('card-pembiayaan');
    const noPembiayaan    = document.getElementById('no-pembiayaan');
    const loadingInfo     = document.getElementById('loading-info');
    const formBayar       = document.getElementById('form-bayar-angsuran');
    const tabelJadwal     = document.getElementById('tabel-jadwal');
    const tbodyJadwal     = document.getElementById('tbody-jadwal');
    const inputTazir      = document.getElementById('input_nominal_tazir');
    const cbBebaskan      = document.getElementById('cb_ajukan_keringanan');
    const wrapAlasan      = document.getElementById('wrap_alasan');
    const tazirBox        = document.getElementById('tazir-box');
    const tazirInfo       = document.getElementById('tazir-info');
    const tazirInfoText   = document.getElementById('tazir-info-text');
    const tazirBadge      = document.getElementById('tazir-status-badge');
    const ringkasan       = document.getElementById('ringkasan-total');
    const inputJumlah     = document.getElementById('input_jumlah_bayar');
    const inputJumlahVal  = document.getElementById('jumlah_bayar_value');
    const inputTagihanVal = document.getElementById('tagihan_angsuran_value');
    const txtTagihan      = document.getElementById('txt_tagihan');
    const infoJumlahBayar = document.getElementById('info_jumlah_bayar');
    const tunggakanBox    = document.getElementById('tunggakan-box');
    const tunggakanDetail = document.getElementById('tunggakan-detail');
    const tunggakanTotalEl= document.getElementById('tunggakan-total');
    let   tunggakanNominal = 0;

    // Inisialisasi Tom Select
    const el = document.getElementById('select_anggota');
    if (el) {
        if (el.tomselect) el.tomselect.destroy();
        new TomSelect('#select_anggota', {
            create: false,
            maxOptions: 100,
            placeholder: 'Ketik nama, No. CIF, atau NIK...',
            onChange: function (anggotaId) { loadPembiayaan(anggotaId); }
        });
    }

    // Checkbox ajukan keringanan ta'zir
    cbBebaskan.addEventListener('change', function () {
        if (this.checked) {
            inputTazir.value = 0;
            inputTazir.readOnly = true;
            inputTazir.style.background = '#f0fdf4';
            inputTazir.style.borderColor = '#86efac';
            wrapAlasan.style.display = 'block';
            tazirBox.classList.add('dibebaskan');
            tazirBadge.innerHTML = '<span style="background:#d1fae5;color:#065f46;padding:.2rem .6rem;border-radius:10px;">Ditangguhkan</span>';
        } else {
            inputTazir.readOnly = false;
            inputTazir.style.background = '#fffbeb';
            inputTazir.style.borderColor = '#fcd34d';
            wrapAlasan.style.display = 'none';
            tazirBox.classList.remove('dibebaskan');
            tazirBadge.innerHTML = '';
            // Kembalikan nilai ta'zir otomatis
            const tazirAuto = inputTazir.dataset.auto || 0;
            inputTazir.value = tazirAuto;
        }
        updateRingkasan();
    });

    // Update ringkasan saat input berubah
    inputJumlah.addEventListener('input', updateRingkasan);
    inputTazir.addEventListener('input', updateRingkasan);

    // Update info metode pembayaran
    document.querySelectorAll('input[name="metode_bayar"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            const infoEl = document.getElementById('info_metode');
            if (this.value === 'AUTODEBET') {
                infoEl.textContent = 'Autodebet: saldo simpanan sukarela anggota dipotong otomatis. Jurnal: DEBET Simpanan Sukarela (2-1001).';
                infoEl.style.color = '#1d4ed8';
            } else {
                infoEl.textContent = 'Tunai: kas masuk ke loket teller. Jurnal: DEBET Kas Teller (1-1001).';
                infoEl.style.color = '#78716c';
            }
        });
    });

    // ── SINKRONISASI JUMLAH BAYAR ─────────────────────────────────────────────
    // Jumlah Bayar WAJIB identik dengan Tagihan Angsuran Bulan Ini. Field-nya
    // readonly untuk Teller, dan nilai yang dikirim form diambil dari tagihan
    // (satu-satunya sumber kebenaran), bukan dari angka yang bisa disentuh UI.
    function sinkronJumlahBayar() {
        const tagihan = parseFloat(txtTagihan.dataset.nilai) || 0;
        inputJumlah.value      = tagihan > 0 ? String(tagihan) : '';
        inputJumlahVal.value   = tagihan > 0 ? String(tagihan) : '';
        inputTagihanVal.value  = tagihan > 0 ? String(tagihan) : '';
        return tagihan;
    }

    // Field readonly tetap dipantau: bila ada upaya modifikasi (DevTools, skrip,
    // atau input device), nilainya segera dikembalikan ke tagihan.
    ['input', 'change', 'keydown', 'keyup', 'wheel', 'paste', 'drop'].forEach(function (ev) {
        inputJumlah.addEventListener(ev, function () { sinkronJumlahBayar(); });
    });

    // Perubahan nilai Tagihan Angsuran Bulan Ini langsung tercermin di Jumlah Bayar,
    // termasuk bila tagihan diisi/diubah oleh skrip lain selama form terbuka.
    new MutationObserver(sinkronJumlahBayar).observe(txtTagihan, {
        attributes: true,
        attributeFilter: ['value'],
        childList: true,
        characterData: true,
        subtree: true,
    });
    txtTagihan.addEventListener('change', sinkronJumlahBayar);
    txtTagihan.__setTagihan = function (nominal) {
        txtTagihan.dataset.nilai = String(nominal ?? 0);
        txtTagihan.value         = 'Rp ' + formatRupiah(nominal || 0);
        sinkronJumlahBayar();
    };

    function updateRingkasan() {
        const angsuran = parseFloat(inputJumlah.value) || 0;
        const tazir    = parseFloat(inputTazir.value) || 0;
        const total    = angsuran + tazir + tunggakanNominal;
        if (angsuran > 0) {
            ringkasan.style.display = 'block';
            document.getElementById('summary_angsuran').textContent = 'Rp ' + formatRupiah(angsuran);
            document.getElementById('summary_tazir').textContent    = tazir > 0 ? 'Rp ' + formatRupiah(tazir) : '-';

            const elLabelTunggakan = document.getElementById('label_summary_tunggakan');
            const elTunggakan      = document.getElementById('summary_tunggakan');
            if (tunggakanNominal > 0) {
                elLabelTunggakan.style.display = 'block';
                elTunggakan.style.display      = 'block';
                elTunggakan.textContent        = 'Rp ' + formatRupiah(tunggakanNominal);
            } else {
                elLabelTunggakan.style.display = 'none';
                elTunggakan.style.display      = 'none';
            }

            document.getElementById('summary_total').textContent    = 'Rp ' + formatRupiah(total);
        } else {
            ringkasan.style.display = 'none';
        }
    }

    async function loadPembiayaan(anggotaId) {
        cardPembiayaan.style.display = 'none';
        noPembiayaan.style.display   = 'none';
        tabelJadwal.style.display    = 'none';
        tbodyJadwal.innerHTML        = '';
        ringkasan.style.display      = 'none';

        // Kosongkan tagihan & jumlah bayar agar tidak ada sisa nilai anggota sebelumnya
        txtTagihan.dataset.nilai    = '0';
        txtTagihan.value            = '';
        inputJumlah.value           = '';
        inputJumlahVal.value        = '';
        inputTagihanVal.value       = '';

        // Reset tunggakan ta'zir
        tunggakanNominal           = 0;
        tunggakanBox.style.display = 'none';
        tunggakanDetail.innerHTML  = '';

        if (!anggotaId) return;
        loadingInfo.style.display = 'block';

        fetch(`/teller/angsuran/get-pembiayaan/${anggotaId}`)
            .then(response => {
                if (!response.ok) throw new Error('not_found');
                return response.json();
            })
            .then(async data => {
                loadingInfo.style.display = 'none';
                if (!data) { noPembiayaan.style.display = 'block'; return; }

                // K-02: pembentukan jadwal angsuran dilakukan lewat POST (sekali),
                // bukan sebagai efek samping GET. Setelah dibentuk, muat ulang.
                if (data.perlu_generate_jadwal) {
                    try {
                        await fetch(`/teller/angsuran/generate-jadwal/${data.pembiayaan_id}`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'Accept': 'application/json',
                            },
                        });
                    } catch (e) { /* abaikan; muat ulang akan menampilkan kondisi terkini */ }
                    return loadPembiayaan(anggotaId);
                }

                document.getElementById('pembiayaan_id').value        = data.pembiayaan_id;
                document.getElementById('angsuran_id').value          = data.angsuran_id ?? '';
                document.getElementById('txt_no_akad').value          = data.no_pembiayaan ?? '-';
                document.getElementById('txt_angsuran_ke').value      = 'Ke-' + data.angsuran_ke_next;
                document.getElementById('txt_total_pembiayaan').value = 'Rp ' + formatRupiah(data.total_pembiayaan);
                document.getElementById('txt_sisa_pembiayaan').value  = 'Rp ' + formatRupiah(data.sisa_pembiayaan);

                // Tagihan Angsuran Bulan Ini → sumber tunggal untuk Jumlah Bayar
                const tagihan = Math.round(parseFloat(data.angsuran_per_bulan) || 0);
                txtTagihan.__setTagihan(tagihan);

                // Info metode baca (readonly) untuk Teller
                if (infoJumlahBayar) {
                    infoJumlahBayar.textContent = tagihan > 0
                        ? 'Nominal terkunci Rp ' + formatRupiah(tagihan) + ' sesuai Tagihan Angsuran Bulan Ini.'
                        : 'Nominal terkunci sesuai tagihan angsuran bulan ini.';
                }

                updateRingkasan();

                // Set ta'zir otomatis dari server
                const tazirNominal = data.tazir ? Math.round(data.tazir.nominal) : 0;
                inputTazir.value = tazirNominal;
                inputTazir.dataset.auto = tazirNominal;

                // Tampilkan info ta'zir jika ada
                if (data.tazir && data.tazir.nominal > 0) {
                    tazirInfo.style.display = 'block';
                    tazirInfoText.innerHTML =
                        `<strong>Terlambat ${data.tazir.hari_terlambat} hari</strong>` +
                        (data.tazir.grace_period > 0 ? ` (grace period ${data.tazir.grace_period} hari)` : '') +
                        ` | Efektif: ${data.tazir.hari_efektif} hari &times; Rp ${formatRupiah(data.tazir.rate)}` +
                        ` = <strong>Rp ${formatRupiah(data.tazir.nominal)}</strong>` +
                        ` [${data.tazir.mode}]`;
                    tazirBadge.innerHTML = '<span style="background:#fef3c7;color:#92400e;padding:.2rem .6rem;border-radius:10px;font-size:.78rem;">Terlambat ' + data.tazir.hari_terlambat + ' hari</span>';
                } else if (data.tazir && data.tazir.mode === 'DISABLED') {
                    tazirInfo.style.display = 'block';
                    tazirInfoText.innerHTML = 'Mode ta\'zir: <strong>DISABLED</strong> — tidak ada denda keterlambatan.';
                } else {
                    tazirInfo.style.display = 'none';
                    tazirBadge.innerHTML = '';
                }

                // Render tunggakan ta'zir bulan lalu (keringanan ditolak Manajer)
                tunggakanNominal = data.tunggakan_tazir ? Math.round(data.tunggakan_tazir) : 0;
                if (tunggakanNominal > 0) {
                    let rows = '';
                    (data.tunggakan_detail || []).forEach(function (t) {
                        rows += `<div style="display:flex;justify-content:space-between;gap:.5rem;padding:.2rem 0;">` +
                                `<span>Angsuran ke-${t.angsuran_ke ?? '-'} &middot; ${t.tanggal ?? '-'}</span>` +
                                `<span style="font-weight:600;">Rp ${formatRupiah(t.nominal)}</span></div>`;
                    });
                    tunggakanDetail.innerHTML = rows;
                    tunggakanTotalEl.textContent = 'Rp ' + formatRupiah(tunggakanNominal);
                    tunggakanBox.style.display = 'block';
                } else {
                    tunggakanBox.style.display = 'none';
                }

                // Reset checkbox bebaskan
                cbBebaskan.checked = false;
                inputTazir.readOnly = false;
                inputTazir.style.background = '#fffbeb';
                inputTazir.style.borderColor = '#fcd34d';
                wrapAlasan.style.display = 'none';
                tazirBox.classList.remove('dibebaskan');

                // Update action form
                const btnBayar = document.getElementById('btn-bayar');
                if (data.angsuran_id) {
                    formBayar.action = `/teller/angsuran/${data.angsuran_id}`;
                    btnBayar.disabled         = false;
                    btnBayar.style.background = '#2563eb';
                    btnBayar.style.cursor     = 'pointer';
                } else {
                    formBayar.action          = '';
                    btnBayar.disabled         = true;
                    btnBayar.style.background = '#93c5fd';
                    btnBayar.style.cursor     = 'not-allowed';
                }

                cardPembiayaan.style.display = 'block';
                updateRingkasan();

                // Render tabel jadwal
                if (data.jadwal && data.jadwal.length > 0) {
                    tbodyJadwal.innerHTML = '';
                    data.jadwal.forEach(function (row) {
                        const statusColor = {
                            'Sudah Dibayar'     : 'background:#d1fae5;color:#065f46',
                            'Terlambat'         : 'background:#fee2e2;color:#991b1b',
                            'Jatuh Tempo'       : 'background:#fef3c7;color:#92400e',
                            'Belum Jatuh Tempo' : 'background:#f1f5f9;color:#475569',
                        }[row.status_angsuran] ?? 'background:#f1f5f9;color:#475569';

                        tbodyJadwal.innerHTML += `<tr>
                            <td style="padding:.45rem .75rem;text-align:center;border:1px solid #e2e8f0;">${row.angsuran_ke}</td>
                            <td style="padding:.45rem .75rem;text-align:center;border:1px solid #e2e8f0;">${row.tanggal_jatuh_tempo ?? '-'}</td>
                            <td style="padding:.45rem .75rem;text-align:right;border:1px solid #e2e8f0;">Rp ${formatRupiah(row.jumlah_angsuran)}</td>
                            <td style="padding:.45rem .75rem;text-align:center;border:1px solid #e2e8f0;">${row.tanggal_bayar ?? '-'}</td>
                            <td style="padding:.45rem .75rem;text-align:center;border:1px solid #e2e8f0;">
                                <span style="padding:.2rem .6rem;border-radius:12px;font-size:.8rem;font-weight:600;${statusColor}">
                                    ${row.status_angsuran}
                                </span>
                            </td>
                        </tr>`;
                    });
                    tabelJadwal.style.display = 'block';
                }
            })
            .catch(() => {
                loadingInfo.style.display  = 'none';
                noPembiayaan.style.display = 'block';
            });
    }

    // Guard: blokir submit jika action kosong
    formBayar.addEventListener('submit', function (e) {
        if (!this.action || this.action === window.location.href) {
            e.preventDefault();
            alert('Pilih anggota terlebih dahulu.');
            return;
        }

        // Jumlah Bayar terkunci ke tagihan; tolak bila belum ada tagihan
        // (mis. form dikirim tanpa memilih anggota) agar nilai tidak pernah nol.
        const tagihan = sinkronJumlahBayar();
        if (!(tagihan > 0)) {
            e.preventDefault();
            alert('Tagihan angsuran belum tersedia. Pilih anggota terlebih dahulu.');
            return;
        }

        // Validasi alasan keringanan
        if (cbBebaskan.checked && !document.getElementById('input_alasan').value.trim()) {
            e.preventDefault();
            alert('Alasan keringanan ta\'zir wajib diisi.');
            document.getElementById('input_alasan').focus();
        }
    });

    function formatRupiah(angka) {
        return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(angka);
    }
});
</script>
@endsection
