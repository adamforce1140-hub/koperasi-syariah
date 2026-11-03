@extends('layouts.app')

@section('title', 'Buka Kas (Opening) — ' . config('app.name'))
@section('page-title', 'Buka Kas / Opening')

@section('styles')
<style>
    /* Animasi halus khusus halaman Buka Kas: kartu muncul berurutan, angka
       saldo "pop" sekali saat halaman dimuat, dan kartu terangkat sedikit
       ketika disorot. Durasi sengaja singkat agar tidak mengganggu teller
       yang bekerja cepat di loket. */
    /* Diakhiri `transform: none` (bukan translateY(0)) agar elemen tidak
       meninggalkan containing block yang bisa menggeser posisi modal fixed. */
    @keyframes kasMuncul {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: none; }
    }
    @keyframes kasDenyut {
        0%   { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.55); }
        70%  { box-shadow: 0 0 0 12px rgba(74, 222, 128, 0); }
        100% { box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); }
    }
    @keyframes kasPop {
        0%   { opacity: 0; transform: scale(0.94); }
        60%  { opacity: 1; transform: scale(1.02); }
        100% { opacity: 1; transform: scale(1); }
    }

    /* Kartu & banner muncul berurutan (stagger) saat halaman dimuat.
       `backwards` (bukan `both`) dipakai agar setelah animasi selesai efek
       hover angkat kartu tetap bisa bekerja — `both` akan mengunci transform
       akhir dan mematikan hover. */
    .opening-page .card,
    .opening-page .alert {
        animation: kasMuncul 0.45s ease-out backwards;
    }
    .opening-page .card:nth-child(2) { animation-delay: 0.07s; }
    .opening-page .card:nth-child(3) { animation-delay: 0.14s; }
    .opening-page .card:nth-child(4) { animation-delay: 0.21s; }
    .opening-page .alert:nth-child(2) { animation-delay: 0.07s; }

    /* Kartu terangkat tipis saat disorot. */
    .opening-page .card {
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }
    .opening-page .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }

    /* Titik status hijau berdenyut lembut (hanya saat kas sudah dibuka). */
    .kas-dot {
        display: inline-block;
        border-radius: 50%;
        animation: kasDenyut 2.4s ease-out infinite;
    }

    /* Angka saldo kas tampil dengan sedikit pembesaran saat pertama dimuat. */
    .kas-saldo-nilai {
        animation: kasPop 0.5s ease-out 0.25s both;
    }

    /* Tombol aksi cepat ikut bereaksi saat disorot. */
    .opening-page .btn {
        transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .opening-page .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
    }

    /* Hormati preferensi pengguna yang mematikan animasi. */
    @media (prefers-reduced-motion: reduce) {
        .opening-page .card,
        .opening-page .alert,
        .kas-dot,
        .kas-saldo-nilai,
        .opening-page .btn {
            animation: none !important;
            transition: none !important;
            transform: none !important;
        }
    }
</style>
@endsection

@section('content')
<div class="opening-page">

@if(session('success'))
<div class="alert alert-success" style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>✅</span> {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="alert alert-error" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>❌</span> {{ session('error') }}
</div>
@endif

@if(session('info'))
<div class="alert alert-info" style="background:#e0f2fe;border:1px solid #7dd3fc;color:#075985;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>ℹ️</span> {{ session('info') }}
</div>
@endif

{{-- Gagal memuat posisi kas dari server --}}
@if(!empty($errorKas))
<div class="alert alert-error" style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>❌</span>
    <div>
        <div style="font-weight:700;">Data posisi kas tidak dapat dimuat</div>
        <div style="font-size:0.82rem;margin-top:0.2rem;">{{ $errorKas }}</div>
        <div style="font-size:0.78rem;margin-top:0.35rem;">Angka di bawah ini ditampilkan sebagai Rp 0 karena perhitungan gagal — bukan berarti kas Anda benar-benar kosong. Muat ulang halaman; bila tetap gagal, hubungi administrator.</div>
    </div>
</div>
@endif

{{-- Aturan lintas hari: sesi kas tanggal sebelumnya belum ditutup --}}
@if(($sesiTertunda ?? collect())->isNotEmpty())
<div class="alert" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;">
    <div style="font-weight:700;font-size:0.88rem;">⚠️ Sesi kas sebelumnya belum ditutup</div>
    <div style="font-size:0.8rem;margin-top:0.2rem;margin-bottom:0.45rem;">
        Buka Kas hari ini diblokir sampai sesi berikut diselesaikan melalui Closing.
    </div>
    <ul style="margin:0;padding-left:1.2rem;font-size:0.8rem;">
        @foreach($sesiTertunda as $sesi)
            @php $tglSesi = $sesi->tanggal?->toDateString() ?? (string) $sesi->tanggal; @endphp
            <li style="margin-bottom:0.2rem;">
                <strong>{{ $tglSesi }}</strong> — {{ \App\Models\KasHarian::labelStatusOpname($sesi->status_opname) }}
                · <a href="{{ route('teller.kas.closing', ['tanggal' => $tglSesi]) }}" style="color:#b45309;font-weight:700;text-decoration:underline;">
                    Selesaikan Closing
                </a>
            </li>
        @endforeach
    </ul>
</div>
@endif

{{-- Peringatan: shift lama yang terlanjur dibuka dengan modal Rp 0 --}}
@if($sudahOpening && (float) $sudahOpening->modal_awal <= 0 && empty($errorKas))
<div class="alert" style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>⚠️</span>
    <div>
        <div style="font-weight:700;">Shift ini tercatat dengan Modal Awal Rp 0</div>
        <div style="font-size:0.82rem;margin-top:0.2rem;">
            Modal awal kini wajib diisi saat pembukaan kas. Karena shift ini sudah terlanjur dibuka
            dengan Rp 0, gunakan tombol <strong>+ Tambah Modal Kas</strong> di bawah untuk melengkapi
            kas fisik awal agar posisi kas mencerminkan saldo aktual.
        </div>
    </div>
</div>
@endif

{{-- Status Banner --}}
@if($sudahOpening)
<div class="card" style="background:linear-gradient(135deg,#065f46,#059669);color:white;border:none;margin-bottom:1.5rem;padding:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:1rem;">
            <div class="kas-dot" style="font-size:2rem;">🟢</div>
            <div>
                <div style="font-size:1.1rem;font-weight:700;">Kas Sudah Dibuka Hari Ini</div>
                <div style="font-size:0.85rem;opacity:0.9;margin-top:0.25rem;">
                    Modal Saat Ini: <strong>Rp {{ number_format($sudahOpening->modal_awal, 0, ',', '.') }}</strong>
                    &nbsp;·&nbsp; Waktu: <strong>{{ $sudahOpening->waktu_opening }}</strong>
                    @if($sudahOpening->catatan_opening)
                    &nbsp;·&nbsp; Catatan: {{ $sudahOpening->catatan_opening }}
                    @endif
                </div>
            </div>
        </div>
        {{-- Tombol Tambah Modal --}}
        @if($sudahClosing)
        <span style="background:rgba(255,255,255,0.2);color:#fff;border:2px solid rgba(255,255,255,0.5);padding:.55rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:700;white-space:nowrap;">
            📦 Kas Sudah Closing — {{ $sudahOpening->waktu_closing }}
        </span>
        @else
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <button type="button" onclick="bukaModalTambahModal()"
                    style="background:rgba(255,255,255,0.2);color:#fff;border:2px solid rgba(255,255,255,0.5);padding:.55rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                + Tambah Modal Kas
            </button>
            <button type="button" onclick="bukaModalSetorBrankas()"
                    style="background:rgba(255,255,255,0.2);color:#fff;border:2px solid rgba(255,255,255,0.5);padding:.55rem 1.1rem;border-radius:8px;font-size:.875rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                &#127974; Setor ke Brankas
            </button>
        </div>
        @endif
    </div>
</div>

{{-- Modal Popup: Form Tambah Modal --}}
@unless($sudahClosing)
<div id="modalTambahModal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:1.8rem;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:#1e293b;">+ Tambah Modal Kas</h3>
            <button type="button" onclick="tutupModalTambahModal()"
                    style="background:none;border:none;font-size:1.4rem;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <form action="{{ route('teller.kas.tambah-modal') }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="nominal_tambah">
                    Nominal Tambahan (Rp) <span style="color:#ef4444;">*</span>
                </label>
                <input id="nominal_tambah" type="number" name="nominal_tambah" min="{{ (int) \App\Support\Setting::minimumTransaksiTeller() }}" step="1000" required
                       value="{{ old('nominal_tambah') }}"
                       placeholder="Contoh: 5000000"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('nominal_tambah') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;">
                @error('nominal_tambah')
                <div style="color:#ef4444;font-size:.78rem;margin-top:.25rem;">{{ $message }}</div>
                @enderror
                <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem;">
                    Minimal Rp {{ number_format(\App\Support\Setting::minimumTransaksiTeller(), 0, ',', '.') }}
                    &nbsp;·&nbsp; Saldo Brankas: <strong>Rp {{ number_format(\App\Models\KasHarian::saldoBrankas(), 0, ',', '.') }}</strong>
                </div>
            </div>
            <div style="margin-bottom:1.4rem;">
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="keterangan">
                    Keterangan
                </label>
                <input id="keterangan" type="text" name="keterangan" maxlength="255"
                       value="{{ old('keterangan') }}"
                       placeholder="Contoh: Suntikan dari brankas utama"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('keterangan') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;">
                @error('keterangan')
                <div style="color:#ef4444;font-size:.78rem;margin-top:.25rem;">{{ $message }}</div>
                @enderror
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                <button type="button" onclick="tutupModalTambahModal()"
                        style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:.875rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                        style="padding:.55rem 1.1rem;background:#059669;color:#fff;border:none;border-radius:8px;font-size:.875rem;font-weight:700;cursor:pointer;">
                    Tambahkan
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Popup: Setoran Hasil Kas ke Brankas Utama (Vault) --}}
<div id="modalSetorBrankas"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:1.8rem;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
            <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:#1e293b;">&#127974; Setor Hasil Kas ke Brankas</h3>
            <button type="button" onclick="tutupModalSetorBrankas()"
                    style="background:none;border:none;font-size:1.4rem;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <form action="{{ route('teller.kas.setor-brankas') }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="nominal_setor">
                    Nominal Setoran (Rp) <span style="color:#ef4444;">*</span>
                </label>
                <input id="nominal_setor" type="number" name="nominal_setor" min="{{ (int) \App\Support\Setting::minimumTransaksiTeller() }}" step="1000" required
                       value="{{ old('nominal_setor') }}"
                       placeholder="Contoh: 3000000"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('nominal_setor') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;">
                @error('nominal_setor')
                <div style="color:#ef4444;font-size:.78rem;margin-top:.25rem;">{{ $message }}</div>
                @enderror
                <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem;">
                    Minimal Rp {{ number_format(\App\Support\Setting::minimumTransaksiTeller(), 0, ',', '.') }}
                    &nbsp;·&nbsp; Saldo Laci: <strong>Rp {{ number_format(\App\Models\KasHarian::hitungSaldoKasTeller(auth()->id())['saldo'], 0, ',', '.') }}</strong>
                </div>
            </div>
            <div style="margin-bottom:1.4rem;">
                <label style="display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:.4rem;" for="keterangan-setor">
                    Keterangan
                </label>
                <input id="keterangan-setor" type="text" name="keterangan" maxlength="255"
                       value="{{ old('keterangan') }}"
                       placeholder="Contoh: Setoran hasil kas harian"
                       style="width:100%;padding:.6rem .8rem;border:1px solid {{ $errors->has('keterangan') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:.9rem;box-sizing:border-box;">
                @error('keterangan')
                <div style="color:#ef4444;font-size:.78rem;margin-top:.25rem;">{{ $message }}</div>
                @enderror
            </div>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                <button type="button" onclick="tutupModalSetorBrankas()"
                        style="padding:.55rem 1.1rem;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:.875rem;font-weight:600;cursor:pointer;">
                    Batal
                </button>
                <button type="submit"
                        style="padding:.55rem 1.1rem;background:#065f46;color:#fff;border:none;border-radius:8px;font-size:.875rem;font-weight:700;cursor:pointer;">
                    Setorkan
                </button>
            </div>
        </form>
    </div>
</div>
@endunless

{{-- Riwayat Penambahan Modal Hari Ini --}}
@if(!empty($sudahOpening->tambah_modal))
@php
    $riwayatModal = collect($sudahOpening->tambah_modal)
        ->filter(fn($m) => is_array($m) && isset($m['nominal']));
    $totalInjeksi = $riwayatModal->sum(fn($m) => (float) $m['nominal']);
@endphp
@if($riwayatModal->isNotEmpty())
<div class="card" style="margin-bottom:1.5rem;">
    <div style="font-weight:700;font-size:.9rem;color:#374151;margin-bottom:.85rem;padding-bottom:.6rem;border-bottom:1px solid #e2e8f0;">
        &#128184; Riwayat Penambahan Modal Kas Hari Ini
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
        <thead>
            <tr style="background:#f8fafc;">
                <th style="padding:.5rem .75rem;text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Waktu</th>
                <th style="padding:.5rem .75rem;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Nominal</th>
                <th style="padding:.5rem .75rem;text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach($riwayatModal as $mutasi)
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:.5rem .75rem;color:#64748b;font-family:monospace;">{{ $mutasi['waktu'] ?? '-' }}</td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:600;color:#059669;">
                    + Rp {{ number_format((float) $mutasi['nominal'], 0, ',', '.') }}
                </td>
                <td style="padding:.5rem .75rem;color:#374151;">{{ $mutasi['keterangan'] ?? 'Suntikan dari brankas' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background:#f0fdf4;">
                <td style="padding:.5rem .75rem;font-weight:700;color:#374151;">Total Injeksi</td>
                <td style="padding:.5rem .75rem;text-align:right;font-weight:700;color:#059669;">
                    + Rp {{ number_format($totalInjeksi, 0, ',', '.') }}
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
@endif
@endif
@endif

@push('scripts')
<script>
function bukaModalTambahModal() {
    var modal = document.getElementById('modalTambahModal');
    if (modal) { modal.style.display = 'flex'; }
}
function tutupModalTambahModal() {
    var modal = document.getElementById('modalTambahModal');
    if (modal) { modal.style.display = 'none'; }
}
// Buka kembali modal otomatis bila validasi tambah modal gagal.
@if($errors->has('nominal_tambah') || $errors->has('keterangan'))
document.addEventListener('DOMContentLoaded', bukaModalTambahModal);
@endif

function bukaModalSetorBrankas() {
    var modal = document.getElementById('modalSetorBrankas');
    if (modal) { modal.style.display = 'flex'; }
}
function tutupModalSetorBrankas() {
    var modal = document.getElementById('modalSetorBrankas');
    if (modal) { modal.style.display = 'none'; }
}
// Buka kembali modal otomatis bila validasi setoran ke brankas gagal.
@if($errors->has('nominal_setor'))
document.addEventListener('DOMContentLoaded', bukaModalSetorBrankas);
@endif

// ── Form Buka Kas: input modal awal ─────────────────────────────
// Tidak ada nilai default Rp 0 — teller wajib mengisi sendiri. Input
// diformat dengan pemisah ribuan Rupiah saat diketik, dan nilainya
// dibersihkan menjadi digit murni sebelum dikirim ke server.
(function () {
    var input = document.getElementById('modal_awal');
    var raw   = document.getElementById('modal_awal_raw');
    if (!input || !raw) { return; }

    function digitSaja(nilai) {
        return String(nilai == null ? '' : nilai).replace(/[^0-9]/g, '');
    }

    function formatRupiah(digit) {
        return digit === '' ? '' : Number(digit).toLocaleString('id-ID');
    }

    function sinkronkan() {
        var digit = digitSaja(input.value).slice(0, 12);
        raw.value      = digit;
        input.value    = formatRupiah(digit);
        input.style.borderColor = (digit !== '' && Number(digit) > 0) ? '#d1d5db' : '#ef4444';
    }

    // Format nilai lama (repopulasi setelah validasi gagal) tanpa mengubah nol jadi kosong.
    if (input.value !== '') { sinkronkan(); }

    input.addEventListener('input', sinkronkan);

    input.addEventListener('blur', function () {
        if (raw.value === '0') { raw.value = ''; input.value = ''; sinkronkan(); }
    });

    var form = input.closest('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var digit = digitSaja(input.value);

            if (digit === '' || Number(digit) <= 0) {
                e.preventDefault();
                input.style.borderColor = '#ef4444';
                input.focus();
                alert('Modal awal wajib diisi dan harus lebih besar dari Rp 0.');
                return;
            }

            // Kirim digit murni (tanpa titik pemisah) ke server.
            input.value = digit;
        });
    }
})();
</script>
@endpush

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:1.5rem;align-items:start;">

    {{-- Form Buka Kas --}}
    <div class="card">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #e2e8f0;">
            <div style="width:40px;height:40px;border-radius:8px;background:#d1fae5;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🔓</div>
            <div>
                <div style="font-weight:700;font-size:1rem;">Form Buka Kas</div>
                <div style="font-size:0.78rem;color:#64748b;">{{ now()->translatedFormat('l, d F Y') }}</div>
            </div>
        </div>

        @if($sudahOpening)
        <div style="text-align:center;padding:2rem 1rem;color:#64748b;">
            <div style="font-size:2.5rem;margin-bottom:0.75rem;">✅</div>
            <div style="font-weight:600;margin-bottom:0.5rem;">Kas sudah dibuka</div>
            @if($sudahClosing)
            <div style="font-size:0.85rem;margin-bottom:1.5rem;">
                Kas hari ini sudah di-closing pada {{ $sudahOpening->waktu_closing }}.
                Perubahan modal dan transaksi baru tidak lagi memengaruhi kas hari ini.
            </div>
            <a href="{{ route('teller.kas.closing') }}" class="btn btn-primary">Lihat Rekap Closing</a>
            @else
            <div style="font-size:0.85rem;margin-bottom:1.5rem;">Anda sudah melakukan opening kas hari ini. Silakan mulai melayani transaksi.</div>
            <a href="{{ route('dashboard.teller') }}" class="btn btn-primary">Ke Dashboard</a>
            @endif
        </div>
        @else
        <form method="POST" action="{{ route('teller.kas.opening.store') }}">
            @csrf
            <div class="form-group" style="margin-bottom:1.25rem;">
                <label for="modal_awal" class="form-label" style="display:block;font-weight:600;font-size:0.875rem;color:#374151;margin-bottom:0.5rem;">
                    Modal Awal (Float Cash) <span style="color:#ef4444;">*</span>
                </label>
                <div style="position:relative;">
                    <span style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:#64748b;font-weight:600;">Rp</span>
                    {{-- type="text" + inputmode numeric: pakai pemisah ribuan Rupiah, tanpa nilai default. --}}
                    <input type="text" name="modal_awal" id="modal_awal" inputmode="numeric" autocomplete="off"
                        class="form-input @error('modal_awal') is-invalid @enderror"
                        style="width:100%;padding:0.625rem 0.75rem 0.625rem 2.5rem;border:1px solid {{ $errors->has('modal_awal') ? '#ef4444' : '#d1d5db' }};border-radius:8px;font-size:0.95rem;box-sizing:border-box;"
                        placeholder="Contoh: 500.000"
                        value="{{ old('modal_awal') }}" required>
                    <input type="hidden" name="modal_awal_raw" id="modal_awal_raw" value="{{ old('modal_awal') }}">
                </div>
                @error('modal_awal')
                <div style="color:#ef4444;font-size:0.8rem;margin-top:0.3rem;">{{ $message }}</div>
                @enderror
                <div style="font-size:0.78rem;color:#64748b;margin-top:0.35rem;">
                    Wajib diisi: masukkan jumlah kas fisik yang dibawa ke loket hari ini (harus lebih besar dari Rp 0).
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1.5rem;">
                <label class="form-label" style="display:block;font-weight:600;font-size:0.875rem;color:#374151;margin-bottom:0.5rem;" for="catatan">
                    Catatan (Opsional)
                </label>
                <textarea id="catatan" name="catatan" rows="2"
                    class="form-input"
                    style="width:100%;padding:0.625rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.95rem;box-sizing:border-box;resize:none;"
                    placeholder="Catatan tambahan...">{{ old('catatan') }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:0.75rem;font-size:1rem;font-weight:600;">
                🔓 Buka Kas Sekarang
            </button>
        </form>
        @endif
    </div>

    {{-- Info Panel --}}
    <div style="display:flex;flex-direction:column;gap:1rem;">

        {{-- Ringkasan Hari Ini --}}
        <div class="card" style="padding:1.25rem;">
            <div style="font-weight:700;font-size:0.9rem;color:#374151;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e2e8f0;">
                📊 Transaksi Hari Ini
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.75rem;">
                <div style="background:#f0fdf4;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#16a34a;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Total Setoran</div>
                    <div style="font-size:1rem;font-weight:700;color:#065f46;margin-top:0.25rem;">
                        Rp {{ number_format($totalSetorHariIni, 0, ',', '.') }}
                    </div>
                </div>
                <div style="background:#ecfdf5;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#047857;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Simpanan Pokok</div>
                    <div style="font-size:1rem;font-weight:700;color:#064e3b;margin-top:0.25rem;">
                        Rp {{ number_format($totalSimpananPokokHariIni, 0, ',', '.') }}
                    </div>
                </div>
                <div style="background:#ecfeff;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#0e7490;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Simpanan Wajib</div>
                    <div style="font-size:1rem;font-weight:700;color:#164e63;margin-top:0.25rem;">
                        Rp {{ number_format($totalSimpananWajibHariIni, 0, ',', '.') }}
                    </div>
                </div>
                <div style="background:#eff6ff;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#1d4ed8;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Total Angsuran</div>
                    <div style="font-size:1rem;font-weight:700;color:#1e3a8a;margin-top:0.25rem;">
                        Rp {{ number_format($totalAngsuranHariIni, 0, ',', '.') }}
                    </div>
                </div>
                <div style="background:#fff7ed;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#ea580c;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Total Penarikan</div>
                    <div style="font-size:1rem;font-weight:700;color:#9a3412;margin-top:0.25rem;">
                        Rp {{ number_format($totalTarikHariIni, 0, ',', '.') }}
                    </div>
                </div>
                <div style="background:#fdf4ff;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:0.72rem;color:#9333ea;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">Total Pencairan</div>
                    <div style="font-size:1rem;font-weight:700;color:#6b21a8;margin-top:0.25rem;">
                        Rp {{ number_format($totalPencairanHariIni, 0, ',', '.') }}
                    </div>
                </div>
            </div>

            {{-- Saldo Kas Fisik Teller --}}
            <div style="background:#1e293b;color:white;border-radius:10px;padding:1rem;margin-top:0.9rem;">
                <div style="font-size:0.72rem;opacity:0.7;">Saldo Kas Teller Saat Ini</div>
                <div class="kas-saldo-nilai" style="font-size:1.3rem;font-weight:700;font-family:monospace;margin-top:0.25rem;">
                    Rp {{ number_format($kasTeller['saldo'], 0, ',', '.') }}
                </div>
                <div style="font-size:0.7rem;opacity:0.6;margin-top:0.3rem;">
                    Kas Awal Rp {{ number_format($kasTeller['kas_awal'], 0, ',', '.') }}
                    + Cash In Rp {{ number_format($kasTeller['cash_in'], 0, ',', '.') }}
                    − Cash Out Rp {{ number_format($kasTeller['cash_out'], 0, ',', '.') }}
                </div>
            </div>
        </div>

        {{-- Riwayat Transaksi Kas Hari Ini --}}
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:0.9rem;color:#374151;">
                 Riwayat Transaksi Kas Hari Ini ({{ $riwayatKas->count() }} trx)
            </div>
            @if($riwayatKas->count())
            <div style="max-height:260px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;position:sticky;top:0;">
                            <th style="padding:0.6rem 1rem;text-align:left;color:#64748b;font-weight:600;">Waktu</th>
                            <th style="padding:0.6rem 1rem;text-align:left;color:#64748b;font-weight:600;">Jenis</th>
                            <th style="padding:0.6rem 1rem;text-align:right;color:#64748b;font-weight:600;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($riwayatKas as $trx)
                        <tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:0.5rem 1rem;color:#94a3b8;font-family:monospace;">{{ \Carbon\Carbon::parse($trx->waktu_transaksi)->format('H:i') }}</td>
                            <td style="padding:0.5rem 1rem;font-weight:600;color:#374151;">{{ $trx->jenis_transaksi }}</td>
                            <td style="padding:0.5rem 1rem;text-align:right;font-weight:600;font-family:monospace;color:{{ $trx->isMasuk() ? '#059669' : '#dc2626' }};">
                                {{-- Arah mutasi mengikuti Transaksi::isMasuk(). --}}
                                {{ $trx->isMasuk() ? '+' : '−' }} Rp {{ number_format($trx->jumlah, 0, ',', '.') }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:0.85rem;">
                Belum ada transaksi kas hari ini
            </div>
            @endif
        </div>

        {{-- Panduan Opening --}}
        <div class="card" style="padding:1.25rem;">
            <div style="font-weight:700;font-size:0.9rem;color:#374151;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e2e8f0;">
                📋 Panduan Buka Kas
            </div>
            <ol style="margin:0;padding-left:1.4rem;color:#64748b;font-size:0.85rem;line-height:1.8;">
                <li>Hitung uang fisik yang dibawa ke loket</li>
                <li>Masukkan jumlah sebagai <strong>Modal Awal</strong></li>
                <li>Klik <strong>Buka Kas Sekarang</strong></li>
                <li>Mulai melayani transaksi nasabah</li>
                <li>Lakukan <strong>Closing</strong> di akhir hari kerja</li>
            </ol>
        </div>

        {{-- Quick Actions --}}
        @if($sudahOpening)
        <div class="card" style="padding:1.25rem;">
            <div style="font-weight:700;font-size:0.9rem;color:#374151;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e2e8f0;">
                ⚡ Aksi Cepat
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.5rem;">
                <a href="{{ route('teller.setor') }}" class="btn btn-primary" style="text-align:center;font-size:0.82rem;padding:0.6rem 0.5rem;">📥 Setoran</a>
                <a href="{{ route('teller.tarik') }}" class="btn" style="text-align:center;font-size:0.82rem;padding:0.6rem 0.5rem;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa;">📤 Penarikan</a>
                <a href="{{ route('teller.angsuran') }}" class="btn" style="text-align:center;font-size:0.82rem;padding:0.6rem 0.5rem;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">💳 Angsuran</a>
                <a href="{{ route('teller.kas.closing') }}" class="btn" style="text-align:center;font-size:0.82rem;padding:0.6rem 0.5rem;background:#faf5ff;color:#7e22ce;border:1px solid #e9d5ff;">📦 Closing</a>
            </div>
        </div>
        @endif

    </div>
</div>
</div>

@endsection
