@extends('layouts.app')
@section('page-title', "Tarik Tunai")

@section('title', 'Tarik Tunai')

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
@endsection

@section('styles')
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<style>
.ts-wrapper { width:100% !important; }
.ts-wrapper.single .ts-control { border-radius:6px !important; padding:.6rem .8rem !important; font-size:.95rem !important; border-color:#d1d5db !important; min-height:auto !important; }
.ts-wrapper.focus.single .ts-control { border-color:#dc2626 !important; box-shadow:0 0 0 2px rgba(220,38,38,.15) !important; }
</style>
@endsection

@section('content')
<div style="max-width:600px;margin:0 auto;">
    <div class="data-card">
        <div class="card-header">
            <span class="card-title">💸 Tarik Tunai</span>
        </div>

        @if(session('success'))
        <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            {{ session('success') }}
        </div>
        @endif
        @if(session('warning'))
        <div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
            ⚠️ {{ session('warning') }}
        </div>
        @endif

        <form method="POST" action="{{ route('teller.tarik.store') }}">
            @csrf

            {{-- Pilih Anggota --}}
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Anggota</label>
                <select name="anggota_id" id="anggota_id" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required>
                    <option value="">-- Pilih Anggota --</option>
                    @foreach($anggota as $a)
                    <option value="{{ $a->id }}" {{ old('anggota_id') == $a->id ? 'selected' : '' }}>
                        {{ $a->nama_lengkap }} ({{ $a->no_anggota }})
                    </option>
                    @endforeach
                </select>
            </div>

            {{-- Pilih Rekening --}}
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;">Rekening</label>
                <select name="rekening_id" id="rekening_id" style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;" required>
                    <option value="">-- Pilih Anggota Dulu --</option>
                </select>
                <div id="saldo-info" style="margin-top:.4rem;font-size:.85rem;color:#6b7280;"></div>
                @error('rekening_id')<div style="color:#ef4444;font-size:.85rem;margin-top:.3rem;">{{ $message }}</div>@enderror
            </div>

            {{-- Jumlah --}}
            <div style="margin-bottom:1rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;" for="jumlah">Jumlah Tarik (Rp)</label>
                <input type="number" name="jumlah" id="jumlah" min="1000" step="1000"
                    value="{{ old('jumlah') }}"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Contoh: 200000" required>
                @error('jumlah')<div style="color:#ef4444;font-size:.85rem;margin-top:.3rem;">{{ $message }}</div>@enderror
            </div>

            {{-- Catatan --}}
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:600;margin-bottom:.4rem;color:#374151;" for="catatan">Catatan (opsional)</label>
                <input id="catatan" type="text" name="catatan" value="{{ old('catatan') }}"
                    style="width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;"
                    placeholder="Keterangan tambahan...">
            </div>

            <div style="display:flex;gap:.75rem;">
                <button type="submit" style="flex:1;background:#dc2626;color:#fff;padding:.7rem 1.5rem;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;">
                    &#128184; Proses Tarik
                </button>
                {{-- Tujuan Batal WAJIB dashboard teller: `dashboard.pengelola`
                     dibatasi middleware role:manajer,pengurus sehingga Teller
                     akan menerima 403 saat membatalkan. --}}
                <a href="{{ route('dashboard.teller') }}" style="flex:1;text-align:center;background:#f3f4f6;color:#374151;padding:.7rem 1.5rem;border-radius:6px;font-size:1rem;font-weight:600;text-decoration:none;">
                    Batal
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('anggota_id');
    if (el) {
        if (el.tomselect) el.tomselect.destroy();
        new TomSelect('#anggota_id', {
            create: false,
            maxOptions: 100,
            placeholder: 'Ketik nama, No. CIF, atau NIK...',
            onChange: function (anggotaId) {
                loadRekening(anggotaId);
            }
        });
    }

    function loadRekening(anggotaId) {
        const rekeningSelect = document.getElementById('rekening_id');
        const saldoInfo = document.getElementById('saldo-info');
        const jumlahInput = document.getElementById('jumlah');

        rekeningSelect.innerHTML = '<option value="">Memuat...</option>';
        saldoInfo.textContent = '';

        if (!anggotaId) {
            rekeningSelect.innerHTML = '<option value="">-- Pilih Anggota Dulu --</option>';
            return;
        }

        fetch(`{{ route('teller.rekening-by-anggota') }}?anggota_id=${anggotaId}`)
            .then(r => r.json())
            .then(data => {
                rekeningSelect.innerHTML = '<option value="">-- Pilih Rekening --</option>';
                data.forEach(rek => {
                    rekeningSelect.innerHTML += `<option value="${rek.id}" data-saldo="${rek.saldo}">
                        ${rek.no_rekening} (${rek.jenis_rekening}) - Saldo: Rp ${new Intl.NumberFormat('id-ID').format(rek.saldo)}
                    </option>`;
                });
                if (data.length === 0) {
                    // Endpoint `teller.rekening-by-anggota` tidak lagi menyertakan
                    // Simpanan Pokok/Wajib (3-1001/3-1002) — keduanya Modal/Ekuitas
                    // anggota yang tidak dapat ditarik selama anggota aktif.
                    rekeningSelect.innerHTML = '<option value="">Tidak ada rekening yang dapat ditarik</option>';
                }
            });
    }

    document.getElementById('rekening_id').addEventListener('change', function () {
        const opt = this.options[this.selectedIndex];
        const saldo = opt.dataset.saldo;
        const info = document.getElementById('saldo-info');
        const jumlahInput = document.getElementById('jumlah');

        if (saldo !== undefined && saldo !== '') {
            const fmt = new Intl.NumberFormat('id-ID').format(saldo);
            info.textContent = `Saldo tersedia: Rp ${fmt}`;
            info.style.color = '#059669';
            jumlahInput.max = saldo;
        } else {
            info.textContent = '';
        }
    });
});
</script>
@endsection
