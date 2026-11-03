@extends('layouts.app')
@section('page-title', "Buka Rekening Deposito")

@section('title', 'Buka Rekening Deposito')

@section('sidebar')
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item {{ request()->routeIs('teller.anggota.*') ? 'active' : '' }}">
    <span class="icon">👥</span> Data & Daftar Anggota
</a>
<div class="nav-section">Teller</div>
<a href="{{ route('dashboard.teller') }}" class="nav-item {{ request()->routeIs('dashboard.teller') ? 'active' : '' }}">
    <span class="icon">🏠</span> Dashboard
</a>
<a href="{{ route('teller.setor') }}" class="nav-item {{ request()->routeIs('teller.setor') ? 'active' : '' }}">
    <span class="icon">💰</span> Setor Tunai
</a>
<a href="{{ route('teller.tarik') }}" class="nav-item {{ request()->routeIs('teller.tarik') ? 'active' : '' }}">
    <span class="icon">💸</span> Tarik Tunai
</a>
<a href="{{ route('teller.angsuran') }}" class="nav-item {{ request()->routeIs('teller.angsuran') ? 'active' : '' }}">
    <span class="icon">📊</span> Bayar Angsuran
</a>
<a href="{{ route('teller.deposito') }}" class="nav-item active">
    <span class="icon">🏦</span> Buka Deposito
</a>
@endsection

@section('content')
<div style="max-width:640px;margin:0 auto;">

    {{-- Header --}}
    <div style="background:linear-gradient(135deg,#065f46,#059669);color:white;border-radius:1rem;padding:1.5rem 2rem;margin-bottom:1.5rem;">
        <div style="font-size:1.25rem;font-weight:700;">🏦 Buka Rekening Simpanan Berjangka</div>
        <div style="font-size:.85rem;opacity:.85;margin-top:.3rem;">Deposito Mudharabah — PSAK 105 | Jurnal: D: Kas Teller (1-1001) / K: Simpanan Berjangka (2-1003)</div>
    </div>

    {{-- Alert --}}
    @if(session('success'))
    <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        ✅ {{ session('success') }}
        {{-- Tautan_laporan hanya ditampilkan bila peran pemangku modul laporan
             benar-benar memiliki hak LIHAT. Rute `laporan.buku-besar` dan
             `laporan.neraca` dijaga `laporan.akses:*` + `role:manajer,pengurus,dps`,
             sehingga tanpa penjaga ini setiap teller yang membuka deposito akan
             melihat dua tautan yang PASTI berakhir 403. Satu-satunya sumber
             kebenaran adalah LaporanKeuanganPermission — sama dengan yang dibaca
             middleware, sehingga tampilan dan gerbang tidak pernah berbeda. --}}
        @php
            $bolehBukuBesar = \App\Support\LaporanKeuanganPermission::boleh(
                auth()->user()->role,
                \App\Support\LaporanKeuanganPermission::MODUL_BUKU_BESAR,
                \App\Support\LaporanKeuanganPermission::LIHAT
            );
            $bolehNeraca = \App\Support\LaporanKeuanganPermission::boleh(
                auth()->user()->role,
                \App\Support\LaporanKeuanganPermission::MODUL_NERACA,
                \App\Support\LaporanKeuanganPermission::LIHAT
            );
        @endphp
        @if($bolehBukuBesar || $bolehNeraca)
        <div style="margin-top:.4rem;font-size:.82rem;">
            @if($bolehBukuBesar)
            <a href="{{ route('laporan.buku-besar', ['kode_akun' => '2-1003']) }}" style="color:#065f46;font-weight:600;">
                → Lihat Buku Besar Deposito (2-1003)
            </a>
            @endif
            @if($bolehBukuBesar && $bolehNeraca)
                &nbsp;|&nbsp;
            @endif
            @if($bolehNeraca)
            <a href="{{ route('laporan.neraca') }}" style="color:#065f46;font-weight:600;">
                → Lihat Laporan Neraca
            </a>
            @endif
        </div>
        @endif
    </div>
    @endif
    @if($errors->any())
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
        @foreach($errors->all() as $err)
            <div>• {{ $err }}</div>
        @endforeach
    </div>
    @endif

    <div class="card">
        <div class="card-header">
            <span class="card-title">Form Pembukaan Deposito Mudharabah</span>
        </div>

        <form method="POST" action="{{ route('teller.deposito.store') }}" id="formDeposito">
            @csrf

            {{-- Pilih Anggota --}}
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Anggota <span style="color:#ef4444;">*</span>
                </label>
                <select name="anggota_id" id="anggota_id"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required>
                    <option value="">-- Pilih Anggota --</option>
                    @foreach($anggota as $a)
                    <option value="{{ $a->id }}" {{ old('anggota_id') == $a->id ? 'selected' : '' }}>
                        {{ $a->nama_lengkap }} ({{ $a->no_anggota }})
                    </option>
                    @endforeach
                </select>
                @error('anggota_id')<div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;">{{ $message }}</div>@enderror
            </div>

            {{-- Jenis Rekening (read-only, otomatis Simpanan Berjangka) --}}
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Jenis Rekening</label>
                <input type="text" value="Simpanan Berjangka / Deposito" readonly
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;background:#f3f4f6;color:#6b7280;box-sizing:border-box;">
            </div>

            {{-- Akad (otomatis Mudharabah, locked) --}}
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Akad Syariah</label>
                <input type="text" value="Mudharabah (Otomatis — PSAK 105)" readonly
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;background:#f3f4f6;color:#059669;font-weight:600;box-sizing:border-box;">
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Akad deposito mudharabah ditetapkan secara otomatis sesuai PSAK 105.
                </div>
            </div>

            {{-- Nominal --}}
            @php
                $minPembukaan = (float) ($batasPembukaan['minimum'] ?? 10000);
                $maksPembukaan = (float) ($batasPembukaan['maksimum'] ?? 50000000);
            @endphp
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;" for="nominal">
                    Nominal Setoran Awal (Rp) <span style="color:#ef4444;">*</span>
                </label>
                <input type="number" name="nominal" id="nominal"
                    min="{{ $minPembukaan }}" max="{{ $maksPembukaan }}" step="1000"
                    data-min="{{ $minPembukaan }}" data-max="{{ $maksPembukaan }}"
                    data-min-kebijakan="{{ $minPembukaan }}"
                    value="{{ old('nominal') }}"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Contoh: {{ (int) $minPembukaan }}" required
                    oninput="updatePreview()">
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Nominal pembukaan rekening yang diizinkan:
                    Rp {{ number_format($minPembukaan, 0, ',', '.') }} – Rp {{ number_format($maksPembukaan, 0, ',', '.') }}
                    (batas dapat diubah pengurus melalui Parameter Kebijakan Koperasi).
                </div>
                <div id="nominal-preview" style="font-size:.82rem;color:#059669;margin-top:.25rem;font-weight:600;"></div>
                <div id="nominal-warn" style="color:#ef4444;font-size:.82rem;margin-top:.25rem;display:none;"></div>
                @error('nominal')<div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;">{{ $message }}</div>@enderror
            </div>

            {{-- Produk Deposito: sumber nisbah & tenor (akad), bukan input bebas --}}
            <div style="margin-bottom:1.1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">
                    Produk / Tenor Deposito <span style="color:#ef4444;">*</span>
                </label>
                @if($produkDeposito->isEmpty())
                    <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;font-size:.85rem;">
                        Belum ada produk deposito aktif. Pengurus harus mengaktifkan produk
                        deposito (tenor &amp; nisbah) terlebih dahulu.
                    </div>
                @else
                <select name="deposito_product_id" id="deposito_product_id"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required
                    onchange="updateProduk()">
                    <option value="">-- Pilih Produk --</option>
                    @foreach($produkDeposito as $p)
                    <option value="{{ $p->id }}"
                        data-tenor="{{ (int) $p->tenor_months }}"
                        data-nisbah="{{ (float) $p->nisbah_anggota }}"
                        data-nisbah-koperasi="{{ (float) $p->nisbah_koperasi }}"
                        data-min="{{ (float) $p->min_amount }}"
                        {{ old('deposito_product_id') == $p->id ? 'selected' : '' }}>
                        {{ $p->name }} — {{ (int) $p->tenor_months }} bulan
                    </option>
                    @endforeach
                </select>
                @error('deposito_product_id')<div style="color:#ef4444;font-size:.82rem;margin-top:.25rem;">{{ $message }}</div>@enderror
                <div style="font-size:.78rem;color:#6b7280;margin-top:.25rem;">
                    Tenor dan nisbah ditetapkan oleh produk (akad Mudharabah) dan tidak dapat diubah manual.
                </div>
                <div id="jatuh-tempo-info" style="font-size:.82rem;color:#374151;margin-top:.35rem;font-weight:600;"></div>
                <div id="nisbah-info" style="font-size:.82rem;color:#6b7280;margin-top:.2rem;">
                    Nisbah anggota: <span id="nisbah-nasabah-val">-</span>% | Nisbah koperasi: <span id="nisbah-koperasi">-</span>%
                </div>
                @endif
            </div>

            {{-- Catatan --}}
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;" for="catatan">Catatan (opsional)</label>
                <input id="catatan" type="text" name="catatan" value="{{ old('catatan') }}"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Keterangan tambahan...">
            </div>

            {{-- Preview Jurnal --}}
            <div id="jurnal-preview" style="background:#f0fdf4;border:1px solid #86efac;border-radius:.75rem;padding:1rem;margin-bottom:1.5rem;display:none;">
                <div style="font-size:.82rem;font-weight:700;color:#166534;margin-bottom:.5rem;">📒 Preview Jurnal PSAK 105 (Double-Entry)</div>
                <table style="width:100%;font-size:.82rem;border-collapse:collapse;">
                    <tr style="border-bottom:1px solid #bbf7d0;">
                        <th style="text-align:left;padding:.3rem .5rem;color:#374151;">Akun</th>
                        <th style="text-align:right;padding:.3rem .5rem;color:#374151;">Debet</th>
                        <th style="text-align:right;padding:.3rem .5rem;color:#374151;">Kredit</th>
                    </tr>
                    <tr>
                        <td style="padding:.3rem .5rem;">1-1001 Kas Teller</td>
                        <td style="text-align:right;padding:.3rem .5rem;color:#059669;font-weight:600;" id="preview-debet">-</td>
                        <td style="text-align:right;padding:.3rem .5rem;">-</td>
                    </tr>
                    <tr>
                        <td style="padding:.3rem .5rem;">2-1003 Simpanan Berjangka/Deposito</td>
                        <td style="text-align:right;padding:.3rem .5rem;">-</td>
                        <td style="text-align:right;padding:.3rem .5rem;color:#2563eb;font-weight:600;" id="preview-kredit">-</td>
                    </tr>
                </table>
            </div>

            {{-- Tombol --}}
            <div style="display:flex;gap:.75rem;">
                <button type="submit"
                    style="flex:1;background:#059669;color:#fff;padding:.75rem 1.5rem;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;">
                    ✅ Buka Rekening Deposito
                </button>
                <a href="{{ route('dashboard.teller') }}"
                    style="flex:1;text-align:center;background:#f3f4f6;color:#374151;padding:.75rem 1.5rem;border-radius:8px;font-size:1rem;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>

@section('scripts')
<script>
function formatRp(val) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(val);
}

function updatePreview() {
    const input   = document.getElementById('nominal');
    const nominal = parseFloat(input.value) || 0;
    const prev    = document.getElementById('nominal-preview');
    const jprev   = document.getElementById('jurnal-preview');
    const warn    = document.getElementById('nominal-warn');

    // Batas minimum/maksimum dari parameter kebijakan koperasi (dinamis).
    const minParam  = parseFloat(input.dataset.min || '0');
    const maksParam = parseFloat(input.dataset.max || '0');
    let pesan = '';

    if (nominal > 0 && minParam > 0 && nominal < minParam) {
        pesan = 'Nominal tidak boleh kurang dari ' + formatRp(minParam)
              + ' (nilai nominal di bawah batas minimum yang diizinkan).';
    } else if (nominal > 0 && maksParam > 0 && nominal > maksParam) {
        pesan = 'Nominal tidak boleh melebihi ' + formatRp(maksParam)
              + ' (nilai nominal di atas batas maksimum yang diizinkan).';
    }

    if (pesan) {
        warn.textContent = pesan;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }

    if (nominal >= 1000) {
        prev.textContent = '= ' + formatRp(nominal);
        document.getElementById('preview-debet').textContent  = formatRp(nominal);
        document.getElementById('preview-kredit').textContent = formatRp(nominal);
        jprev.style.display = 'block';
    } else {
        prev.textContent    = '';
        jprev.style.display = 'none';
    }
}

// Tenor & nisbah dibaca dari produk terpilih (data-attribute), bukan input bebas.
function updateProduk() {
    const sel = document.getElementById('deposito_product_id');
    if (!sel) return;

    const opt = sel.options[sel.selectedIndex];
    const info = document.getElementById('jatuh-tempo-info');
    const nasabah = document.getElementById('nisbah-nasabah-val');
    const koperasi = document.getElementById('nisbah-koperasi');

    if (!sel.value || !opt) {
        info.textContent = '';
        nasabah.textContent = '-';
        koperasi.textContent = '-';
        return;
    }

    const tenor = parseInt(opt.dataset.tenor || '0');
    const nisbahNasabah = parseFloat(opt.dataset.nisbah || '0');
    const nisbahKoperasi = parseFloat(opt.dataset.nisbahKoperasi || '0');
    const minProduk = parseFloat(opt.dataset.min || '0');

    // Jatuh tempo dihitung sistem: hari ini + tenor produk (tanpa overflow bulan).
    const tgl = new Date();
    const hari = tgl.getDate();
    tgl.setDate(1);
    tgl.setMonth(tgl.getMonth() + tenor);
    const akhirBulan = new Date(tgl.getFullYear(), tgl.getMonth() + 1, 0).getDate();
    tgl.setDate(Math.min(hari, akhirBulan));

    const opts = { day: 'numeric', month: 'long', year: 'numeric' };
    info.textContent = 'Jatuh tempo (dihitung sistem): ' + tgl.toLocaleDateString('id-ID', opts)
        + ' (' + tenor + ' bulan)';

    nasabah.textContent = nisbahNasabah;
    koperasi.textContent = nisbahKoperasi;

    // Minimum produk dapat lebih tinggi dari batas kebijakan koperasi.
    // Selalu dihitung ulang dari batas kebijakan (bukan dari nilai sebelumnya).
    const input = document.getElementById('nominal');
    if (input && minProduk > 0) {
        const minKebijakan = parseFloat(input.dataset.minKebijakan || '0');
        const batas = Math.max(minKebijakan, minProduk);
        input.dataset.min = batas;
        input.setAttribute('min', batas);
        updatePreview();
    }
}

// Init preview jika ada old value (validasi error)
updatePreview();
updateProduk();
</script>
@endsection
@endsection
