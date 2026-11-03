@extends('layouts.app')
@section('page-title', "Pengajuan Pembiayaan Syariah")

@section('title', 'Pengajuan Pembiayaan Syariah')

@section('content')
<div class="page-header">
    <h1 class="page-title">Pengajuan Pembiayaan Syariah</h1>
    <p class="page-subtitle">Input & monitoring pengajuan pembiayaan (Murabahah / Mudharabah / Musyarakah / Qardh)</p>
</div>

{{-- Alert --}}
@if(session('success'))
    <div class="alert alert-success" style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.9rem 1.2rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-error" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.9rem 1.2rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('error') }}
    </div>
@endif
@if(session('info'))
    <div class="alert alert-info" style="background:#dbeafe;border:1px solid #93c5fd;color:#1e40af;padding:.9rem 1.2rem;border-radius:8px;margin-bottom:1rem;">
        {{ session('info') }}
    </div>
@endif

{{-- Stat Cards --}}
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem;">
    @foreach([
        ['label'=>'Menunggu Review','value'=>$stats['menunggu'],'color'=>'#f59e0b','bg'=>'#fffbeb'],
        ['label'=>'Sedang Direview','value'=>$stats['direview'],'color'=>'#3b82f6','bg'=>'#eff6ff'],
        ['label'=>'Disetujui / Cair','value'=>$stats['disetujui'],'color'=>'#10b981','bg'=>'#ecfdf5'],
        ['label'=>'Ditolak','value'=>$stats['ditolak'],'color'=>'#ef4444','bg'=>'#fef2f2'],
    ] as $card)
    <div style="background:{{ $card['bg'] }};border:1px solid {{ $card['color'] }}33;border-radius:10px;padding:1rem 1.2rem;">
        <div style="font-size:.8rem;color:#6b7280;font-weight:500;">{{ $card['label'] }}</div>
        <div style="font-size:2rem;font-weight:700;color:{{ $card['color'] }};">{{ $card['value'] }}</div>
    </div>
    @endforeach
</div>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:1.5rem;align-items:start;">

{{-- ============================================================ --}}
{{-- FORM PENGAJUAN (AO) --}}
{{-- ============================================================ --}}
@can('role', ['ao','manajer'])
<div class="data-card">
    <div class="card-header">
        <span class="card-title">Form Pengajuan Pembiayaan</span>
    </div>
    <form method="POST" action="{{ route('pengajuan-pembiayaan.store') }}" id="formPengajuan" style="padding:1.2rem;">
        @csrf

        {{-- 1. Pilih Anggota --}}
        <div style="margin-bottom:1rem;">
            <label class="form-label">CIF / Anggota <span style="color:#ef4444">*</span></label>
            <select name="anggota_id" id="anggota_id" required
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                onchange="cekEligibility(this.value)">
                <option value="">-- Pilih Anggota --</option>
                @foreach($anggota as $a)
                <option value="{{ $a->id }}" {{ old('anggota_id') == $a->id ? 'selected' : '' }}>
                    {{ $a->nama_lengkap }} ({{ $a->no_cif ?? $a->no_anggota }})
                </option>
                @endforeach
            </select>
        </div>

        {{-- Eligibility Result Panel --}}
        <div id="eligibilityPanel" style="display:none;margin-bottom:1rem;padding:.8rem 1rem;border-radius:8px;font-size:.85rem;"></div>

        {{-- 2. Akad Pembiayaan --}}
        <div style="margin-bottom:1rem;">
            <label class="form-label">Jenis Akad <span style="color:#ef4444">*</span></label>
            <select name="akad_pembiayaan" id="akad_pembiayaan" required
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                onchange="updateAkadInfo(this.value)">
                <option value="">-- Pilih Akad --</option>
                <option value="Murabahah" {{ old('akad_pembiayaan')=='Murabahah'?'selected':'' }}>Murabahah (Jual Beli - Margin)</option>
                <option value="Mudharabah" {{ old('akad_pembiayaan')=='Mudharabah'?'selected':'' }}>Mudharabah (Bagi Hasil - Bank Kelola)</option>
                <option value="Musyarakah" {{ old('akad_pembiayaan')=='Musyarakah'?'selected':'' }}>Musyarakah (Bagi Hasil - Patungan)</option>
                <option value="Qardh" {{ old('akad_pembiayaan')=='Qardh'?'selected':'' }}>Qardh (Pinjaman Kebajikan)</option>
                <option value="Ijarah" {{ old('akad_pembiayaan')=='Ijarah'?'selected':'' }}>Ijarah (Sewa)</option>
                <option value="Ijarah Muntahia Bittamlik" {{ old('akad_pembiayaan')=='Ijarah Muntahia Bittamlik'?'selected':'' }}>Ijarah Muntahia Bittamlik (Sewa Beli)</option>
                <option value="Istishna" {{ old('akad_pembiayaan')=='Istishna'?'selected':'' }}>Istishna (Pesanan)</option>
                <option value="Salam" {{ old('akad_pembiayaan')=='Salam'?'selected':'' }}>Salam (Pesanan di Muka)</option>
            </select>
            <div id="akadInfo" style="margin-top:.4rem;font-size:.78rem;color:#6b7280;"></div>
        </div>

        {{-- 3. Nominal & Tenor --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:.8rem;margin-bottom:1rem;">
            <div>
                <label class="form-label" for="jumlah_pembiayaan">Nominal Pengajuan (Rp) <span style="color:#ef4444">*</span></label>
                <input type="number" name="jumlah_pembiayaan" id="jumlah_pembiayaan"
                    value="{{ old('jumlah_pembiayaan') }}" min="{{ \App\Support\Setting::minimalPengajuanPembiayaan() }}" step="1000" required
                    placeholder="Contoh: 5000000"
                    style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                    oninput="hitungSimulasi()">
            </div>
            <div>
                <label class="form-label" for="tenor">Tenor (Bulan) <span style="color:#ef4444">*</span></label>
                <input type="number" name="tenor" id="tenor"
                    value="{{ old('tenor') }}" min="1" max="360" required
                    placeholder="Contoh: 12"
                    style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                    oninput="hitungSimulasi()">
            </div>
        </div>

        {{-- 4. Margin / Nisbah --}}
        <div id="wrap-margin-nisbah" style="margin-bottom:1rem;">
            <label class="form-label" for="margin_nisbah">Margin / Nisbah (% per tahun) <span style="color:#ef4444">*</span></label>
            <input type="number" name="margin_nisbah" id="margin_nisbah"
                value="{{ old('margin_nisbah', 12) }}" min="0" max="100" step="0.01" required
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                oninput="hitungSimulasi()">
            <div id="hint-margin-coa" style="display:none;margin-top:.4rem;font-size:.75rem;color:#1d4ed8;"></div>
        </div>

        {{-- Simulasi Angsuran --}}
        <div id="simulasiPanel" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.85rem;">
            <div style="font-weight:600;color:#166534;margin-bottom:.4rem;">Simulasi Angsuran</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.5rem;">
                <div><div style="color:#6b7280;">Total Pembiayaan</div><div id="simTotal" style="font-weight:600;color:#166534;"></div></div>
                <div><div style="color:#6b7280;">Total Margin</div><div id="simMargin" style="font-weight:600;color:#166534;"></div></div>
                <div><div style="color:#6b7280;">Angsuran/Bulan</div><div id="simAngsuran" style="font-weight:700;color:#15803d;font-size:1rem;"></div></div>
            </div>
        </div>

        {{-- 5. Tujuan --}}
        <div style="margin-bottom:1rem;">
            <label class="form-label" for="tujuan_pembiayaan">Tujuan Pembiayaan <span style="color:#ef4444">*</span></label>
            <input id="tujuan_pembiayaan" type="text" name="tujuan_pembiayaan" value="{{ old('tujuan_pembiayaan') }}" required
                placeholder="Contoh: Modal usaha perdagangan sembako"
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;">
        </div>

        {{-- 6. Agunan --}}
        <div style="margin-bottom:.8rem;">
            <label class="form-label">Tipe Agunan</label>
            <select name="agunan_tipe" id="agunan_tipe"
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
                onchange="toggleDeposito(this.value)">
                <option value="">-- Tanpa Agunan / Lainnya --</option>
                <option value="bpkb" {{ old('agunan_tipe')=='bpkb'?'selected':'' }}>BPKB Kendaraan</option>
                <option value="sertifikat" {{ old('agunan_tipe')=='sertifikat'?'selected':'' }}>Sertifikat Tanah/Bangunan</option>
                <option value="deposito_backtoback" {{ old('agunan_tipe')=='deposito_backtoback'?'selected':'' }}>Deposito Back-to-Back</option>
                <option value="lainnya" {{ old('agunan_tipe')=='lainnya'?'selected':'' }}>Lainnya</option>
            </select>
        </div>

        {{-- 7. Deposito Back-to-Back --}}
        <div id="depositoSection" style="display:none;margin-bottom:1rem;background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:.8rem 1rem;">
            <label class="form-label" style="color:#1d4ed8;">Pilih Rekening Deposito (Agunan Back-to-Back)</label>
            <select name="deposito_id" id="deposito_id"
                style="width:100%;padding:.55rem .8rem;border:1px solid #93c5fd;border-radius:6px;font-size:.88rem;background:#fff;">
                <option value="">-- Pilih Rekening Deposito Anggota --</option>
            </select>
            <div id="depositoWarning" style="margin-top:.4rem;font-size:.78rem;color:#b45309;display:none;">
                Saldo deposito kurang dari nominal pengajuan.
            </div>
            <div style="margin-top:.4rem;font-size:.75rem;color:#1d4ed8;">
                Bilyet deposito akan dikunci selama masa pembiayaan. Status pengajuan otomatis <strong>Low Risk</strong>.
            </div>
        </div>

        {{-- 8. Keterangan --}}
        <div style="margin-bottom:1.2rem;">
            <label class="form-label" for="keterangan">Keterangan Tambahan</label>
            <textarea id="keterangan" name="keterangan" rows="2"
                placeholder="Catatan agunan, kondisi usaha, dll."
                style="width:100%;padding:.55rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;resize:vertical;">{{ old('keterangan') }}</textarea>
        </div>

        <button type="submit" id="btnSubmit"
            style="width:100%;padding:.7rem;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;">
            Simpan & Ajukan Pembiayaan
        </button>
    </form>
</div>
@endcan

{{-- ============================================================ --}}
{{-- DAFTAR PENGAJUAN --}}
{{-- ============================================================ --}}
<div class="data-card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <span class="card-title">Daftar Pengajuan</span>
        <form method="GET" action="{{ route('pengajuan-pembiayaan.index') }}" style="display:flex;gap:.5rem;">
            <input type="text" name="search" value="{{ request('search') }}" aria-label="Cari nomor atau nama pengajuan" placeholder="Cari no/nama..."
                style="padding:.4rem .7rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;width:160px;">
            <select name="status" style="padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;font-size:.83rem;">
                <option value="">Semua Status</option>
                <option value="pending_ao" {{ request('status')=='pending_ao'?'selected':'' }}>Pengajuan</option>
                <option value="direview" {{ request('status')=='direview'?'selected':'' }}>Direview</option>
                <option value="dicairkan" {{ request('status')=='dicairkan'?'selected':'' }}>Dicairkan</option>
                <option value="ditolak" {{ request('status')=='ditolak'?'selected':'' }}>Ditolak</option>
            </select>
            <button type="submit" style="padding:.4rem .8rem;background:#3b82f6;color:#fff;border:none;border-radius:6px;font-size:.83rem;cursor:pointer;">Cari</button>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
            <thead>
                <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                    <th style="padding:.6rem .8rem;text-align:left;color:#374151;font-weight:600;">No. Pembiayaan</th>
                    <th style="padding:.6rem .8rem;text-align:left;color:#374151;font-weight:600;">Anggota</th>
                    <th style="padding:.6rem .8rem;text-align:left;color:#374151;font-weight:600;">Akad</th>
                    <th style="padding:.6rem .8rem;text-align:right;color:#374151;font-weight:600;">Nominal</th>
                    <th style="padding:.6rem .8rem;text-align:center;color:#374151;font-weight:600;">Tenor</th>
                    <th style="padding:.6rem .8rem;text-align:center;color:#374151;font-weight:600;">Agunan</th>
                    <th style="padding:.6rem .8rem;text-align:center;color:#374151;font-weight:600;">Status</th>
                    <th style="padding:.6rem .8rem;text-align:center;color:#374151;font-weight:600;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($daftarPengajuan as $p)
                @php
                    $statusColor = match(strtolower($p->status_pembiayaan)) {
                        'pengajuan','pending_ao','pending','pending_manajer','pending_pengurus','pending_dps' => ['bg'=>'#fef3c7','color'=>'#92400e','label'=>'Menunggu'],
                        'direview' => ['bg'=>'#dbeafe','color'=>'#1e40af','label'=>'Direview'],
                        'disetujui' => ['bg'=>'#d1fae5','color'=>'#065f46','label'=>'Disetujui'],
                        'dicairkan' => ['bg'=>'#d1fae5','color'=>'#065f46','label'=>'Dicairkan'],
                        'ditolak' => ['bg'=>'#fee2e2','color'=>'#991b1b','label'=>'Ditolak'],
                        default => ['bg'=>'#f3f4f6','color'=>'#374151','label'=>ucfirst($p->status_pembiayaan)],
                    };
                @endphp
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:.6rem .8rem;font-weight:600;color:#1d4ed8;">{{ $p->no_pembiayaan }}</td>
                    <td style="padding:.6rem .8rem;">
                        <div style="font-weight:500;">{{ $p->anggota?->nama_lengkap }}</div>
                        <div style="font-size:.75rem;color:#6b7280;">{{ $p->anggota?->no_cif ?? $p->anggota?->no_anggota }}</div>
                    </td>
                    <td style="padding:.6rem .8rem;">{{ $p->akad_pembiayaan }}</td>
                    <td style="padding:.6rem .8rem;text-align:right;">Rp {{ number_format($p->jumlah_pembiayaan, 0, ',', '.') }}</td>
                    <td style="padding:.6rem .8rem;text-align:center;">{{ $p->tenor }} bln</td>
                    <td style="padding:.6rem .8rem;text-align:center;">
                        @if($p->agunan_tipe === 'deposito_backtoback')
                            <span style="background:#dbeafe;color:#1e40af;padding:.2rem .5rem;border-radius:4px;font-size:.75rem;font-weight:600;">Deposito B2B</span>
                            @if($p->is_low_risk)
                                <span style="background:#d1fae5;color:#065f46;padding:.2rem .4rem;border-radius:4px;font-size:.7rem;margin-left:.2rem;">Low Risk</span>
                            @endif
                        @else
                            <span style="color:#6b7280;font-size:.78rem;">{{ $p->agunan_tipe ?? '-' }}</span>
                        @endif
                    </td>
                    <td style="padding:.6rem .8rem;text-align:center;">
                        <span style="background:{{ $statusColor['bg'] }};color:{{ $statusColor['color'] }};padding:.25rem .6rem;border-radius:20px;font-size:.75rem;font-weight:600;">
                            {{ $statusColor['label'] }}
                        </span>
                    </td>
                    <td style="padding:.6rem .8rem;text-align:center;">
                        <a href="{{ route('pengajuan-pembiayaan.show', $p->id) }}"
                            style="background:#3b82f6;color:#fff;padding:.3rem .7rem;border-radius:5px;font-size:.78rem;text-decoration:none;">
                            Detail
                        </a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;padding:2rem;color:#9ca3af;">Belum ada data pengajuan.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($daftarPengajuan->hasPages())
    <div style="padding:.8rem 1rem;border-top:1px solid #f3f4f6;">
        {{ $daftarPengajuan->withQueryString()->links() }}
    </div>
    @endif
</div>

</div>{{-- end grid --}}

@endsection

@push('scripts')
<script>
// ── Format rupiah ──────────────────────────────────────────────
function fmtRp(n) {
    return 'Rp ' + Math.round(n).toLocaleString('id-ID');
}

// ── Konfigurasi COA dinamis (rate margin dari Master COA) ───────
// Rate margin pembiayaan Murabahah ditarik dari Master COA (1-2001) agar
// perubahan di menu Master COA langsung dipakai pada pengajuan baru.
const COA_CTX = {
    urlCoaConfig: @json(url('/api/deposito/coa-config')),
    kodeMargin:   @json(\App\Services\AutoJournalingService::PIUTANG_MURABAHAH),
};

let coaConfig = null;
let sedangMemuatCoa = false;

function coaUntuk(kunci) {
    return coaConfig && coaConfig.coa ? (coaConfig.coa[kunci] || null) : null;
}

function muatKonfigurasiCoa(lanjut) {
    if (coaConfig) { if (lanjut) lanjut(true); return; }
    if (sedangMemuatCoa) { return; }
    sedangMemuatCoa = true;

    fetch(COA_CTX.urlCoaConfig, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            coaConfig = (res && res.data) ? res.data : null;
            sedangMemuatCoa = false;
            if (lanjut) lanjut(!!coaConfig);
        })
        .catch(() => {
            sedangMemuatCoa = false;
            if (lanjut) lanjut(false);
        });
}

// Sembunyikan field yang tidak relevan bila COA tidak menerapkan persentase
// (mis. Simpanan Pokok/Wajib yang imbalannya lewat SHU Tahunan).
function terapkanPersentaseAkad(akad) {
    const coa = akad === 'Murabahah' ? coaUntuk('margin') : null;
    const pakaiMargin = ! coa || coa.has_percentage;

    const wrapMargin = document.getElementById('wrap-margin-nisbah');
    const input      = document.getElementById('margin_nisbah');
    const hint       = document.getElementById('hint-margin-coa');

    if (wrapMargin) {
        wrapMargin.style.display = (akad === 'Murabahah' && ! pakaiMargin) ? 'none' : '';
    }
    if (input) {
        input.readOnly = !!(coa && coa.has_percentage);
        input.style.background = input.readOnly ? '#f3f4f6' : '';
    }
    if (hint) {
        hint.style.display = (akad === 'Murabahah' && coa && coa.has_percentage) ? 'block' : 'none';
        if (coa && coa.has_percentage) {
            hint.textContent = 'Rate margin flat per tahun dari Master COA ' + coa.kode_akun
                + ' (' + Number(coa.default_percentage_member).toFixed(2) + '%) — dapat diubah di menu Master COA.';
        }
    }

    if (akad === 'Murabahah' && coa && coa.has_percentage) {
        if (document.getElementById('margin_nisbah')) {
            document.getElementById('margin_nisbah').value = Number(coa.default_percentage_member).toFixed(2);
        }
    }

    hitungSimulasi();
}

// ── Akad info teks ─────────────────────────────────────────────
function updateAkadInfo(akad) {
    const map = {
        'Murabahah':  'Jual Beli dengan tambahan margin keuntungan tetap. Jurnal: Debet Piutang Murabahah | Kredit Simpanan Sukarela + Pendapatan Margin Ditangguhkan.',
        'Mudharabah': 'Bagi hasil — bank sebagai pemilik modal (shahibul maal). Jurnal: Debet Pembiayaan Mudharabah | Kredit Simpanan Sukarela.',
        'Musyarakah': 'Bagi hasil — patungan modal antara bank dan nasabah. Jurnal: Debet Pembiayaan Musyarakah | Kredit Simpanan Sukarela.',
        'Qardh':      'Pinjaman kebajikan tanpa margin/keuntungan. Jurnal: Debet Pinjaman Qardh | Kredit Simpanan Sukarela.',
        'Ijarah':     'Sewa manfaat aset. Jurnal: Debet Piutang Ijarah | Kredit Simpanan Sukarela.',
        'Istishna':   'Pemesanan barang manufaktur. Jurnal: Debet Piutang Murabahah | Kredit Simpanan Sukarela.',
    };
    document.getElementById('akadInfo').textContent = map[akad] || '';

    // Tarik konfigurasi COA (rate margin) secara real-time lalu terapkan.
    if (! coaConfig) {
        muatKonfigurasiCoa(function (ok) { terapkanPersentaseAkad(akad); });
    } else {
        terapkanPersentaseAkad(akad);
    }
}

// ── Simulasi angsuran ──────────────────────────────────────────
function hitungSimulasi() {
    const pokok   = parseFloat(document.getElementById('jumlah_pembiayaan').value) || 0;
    const tenor   = parseInt(document.getElementById('tenor').value) || 0;
    const margin  = parseFloat(document.getElementById('margin_nisbah').value) || 0;
    const akad    = document.getElementById('akad_pembiayaan').value;

    const panel = document.getElementById('simulasiPanel');
    if (!pokok || !tenor) { panel.style.display = 'none'; return; }

    const totalMargin  = akad === 'Qardh' ? 0 : Math.round(pokok * margin / 100);
    const totalPmb     = pokok + totalMargin;
    const angsuranBln  = Math.round(totalPmb / tenor);

    document.getElementById('simTotal').textContent    = fmtRp(totalPmb);
    document.getElementById('simMargin').textContent   = fmtRp(totalMargin);
    document.getElementById('simAngsuran').textContent = fmtRp(angsuranBln);
    panel.style.display = 'block';

    // Validasi deposito jika dipilih
    cekDepositoCukup();
}

// ── Toggle deposito section ────────────────────────────────────
function toggleDeposito(tipe) {
    const sec = document.getElementById('depositoSection');
    sec.style.display = tipe === 'deposito_backtoback' ? 'block' : 'none';
    if (tipe !== 'deposito_backtoback') {
        document.getElementById('deposito_id').value = '';
    }
}

// ── Cek deposito cukup ─────────────────────────────────────────
function cekDepositoCukup() {
    const sel     = document.getElementById('deposito_id');
    const nominal = parseFloat(document.getElementById('jumlah_pembiayaan').value) || 0;
    const warn    = document.getElementById('depositoWarning');
    if (!sel || !sel.value) { warn.style.display='none'; return; }
    const opt     = sel.options[sel.selectedIndex];
    const saldo   = parseFloat(opt.dataset.saldo || 0);
    warn.style.display = (saldo > 0 && saldo < nominal) ? 'block' : 'none';
}

// ── Eligibility check AJAX ─────────────────────────────────────
let depositoData = [];

function cekEligibility(anggotaId) {
    if (!anggotaId) return;
    const panel = document.getElementById('eligibilityPanel');
    panel.style.display = 'block';
    panel.style.background = '#f9fafb';
    panel.style.border = '1px solid #e5e7eb';
    panel.style.color = '#374151';
    panel.innerHTML = '<em>Memvalidasi kelayakan anggota...</em>';

    fetch(`{{ route('pengajuan-pembiayaan.eligibility-check') }}?anggota_id=${anggotaId}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        let html = '';
        const btn = document.getElementById('btnSubmit');

        if (data.eligible) {
            panel.style.background = '#f0fdf4';
            panel.style.border = '1px solid #86efac';
            panel.style.color = '#166534';
            html += `<strong>✓ Anggota Layak</strong> — ${data.anggota.nama} (${data.anggota.no_cif || data.anggota.no_anggota})<br>`;
            html += `Status: <strong>${data.anggota.status}</strong> | Simp. Pokok: <strong>Rp ${data.anggota.simpanan_pokok.toLocaleString('id-ID')}</strong>`;
            if (data.warnings.length) {
                html += `<div style="margin-top:.4rem;color:#92400e;background:#fffbeb;padding:.4rem .6rem;border-radius:5px;">`;
                data.warnings.forEach(w => html += `⚠ ${w}<br>`);
                html += `</div>`;
            }
            btn.disabled = false;
            btn.style.opacity = '1';
        } else {
            panel.style.background = '#fef2f2';
            panel.style.border = '1px solid #fca5a5';
            panel.style.color = '#991b1b';
            html += `<strong>✗ Anggota Tidak Layak</strong><br>`;
            data.issues.forEach(i => html += `• ${i}<br>`);
            btn.disabled = true;
            btn.style.opacity = '.5';
        }

        panel.innerHTML = html;

        // Isi dropdown deposito
        depositoData = data.rekening_deposito || [];
        const sel = document.getElementById('deposito_id');
        sel.innerHTML = '<option value="">-- Pilih Rekening Deposito Anggota --</option>';
        depositoData.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.dataset.saldo = d.saldo;
            opt.textContent = `${d.no_rekening} | ${d.jenis_rekening} | Saldo: Rp ${d.saldo.toLocaleString('id-ID')}${d.tanggal_jatuh_tempo ? ' | JT: ' + d.tanggal_jatuh_tempo : ''}`;
            sel.appendChild(opt);
        });
    })
    .catch(() => {
        panel.innerHTML = '<em style="color:#6b7280;">Gagal validasi. Periksa koneksi.</em>';
    });
}

// ── Init --
document.getElementById('deposito_id').addEventListener('change', cekDepositoCukup);

// Muat konfigurasi COA lebih awal lalu terapkan rate margin bila akad sudah terpilih.
muatKonfigurasiCoa(function () {
    const akad = document.getElementById('akad_pembiayaan');
    if (akad && akad.value) { terapkanPersentaseAkad(akad.value); }
});
</script>
@endpush
