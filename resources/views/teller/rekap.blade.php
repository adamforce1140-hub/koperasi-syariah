@extends('layouts.app')

@section('title', 'Rekap Kas & Closing — ' . config('app.name'))
@section('page-title', 'Rekap Kas & Closing')

@section('content')

@if(session('success'))
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>✅</span> {{ session('success') }}
</div>
@endif

@if(session('error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:center;gap:0.6rem;">
    <span>❌</span> {{ session('error') }}
</div>
@endif

{{-- Gagal memuat rekap kas dari server --}}
@if(!empty($errorKas))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>❌</span>
    <div>
        <div style="font-weight:700;">Data rekap kas tidak dapat dimuat</div>
        <div style="font-size:0.82rem;margin-top:0.2rem;">{{ $errorKas }}</div>
        <div style="font-size:0.78rem;margin-top:0.35rem;">Ringkasan di bawah ditampilkan sebagai Rp 0 karena perhitungan gagal. Jangan melakukan closing sebelum angka berhasil dimuat.</div>
    </div>
</div>
@endif

{{-- Header Info Hari --}}
<div class="card" style="background:linear-gradient(135deg,#1e1b4b,#3730a3);color:white;border:none;margin-bottom:1.5rem;padding:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div style="display:flex;align-items:center;gap:1rem;">
            <div style="font-size:2rem;">📦</div>
            <div>
                <div style="font-size:1.1rem;font-weight:700;">Rekap Kas Harian</div>
                <div style="font-size:0.85rem;opacity:0.85;margin-top:0.2rem;">{{ now()->translatedFormat('l, d F Y') }}</div>
            </div>
        </div>
        <div style="text-align:right;">
            @if($openingData)
            <div style="font-size:0.78rem;opacity:0.8;">Modal Awal</div>
            <div style="font-size:1.3rem;font-weight:700;">Rp {{ number_format($modalAwal, 0, ',', '.') }}</div>
            <div style="font-size:0.75rem;opacity:0.7;">Dibuka: {{ $openingData?->waktu_opening ?? '-' }}</div>
            @else
            <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:0.6rem 1rem;font-size:0.85rem;">
                ⚠️ Belum ada Opening hari ini
            </div>
            @endif
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.2fr;gap:1.5rem;align-items:start;">

    {{-- Kiri: Ringkasan Transaksi --}}
    <div style="display:flex;flex-direction:column;gap:1rem;">

        {{-- Summary Cards --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:0.75rem;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">📥 Total Setoran</div>
                <div style="font-size:1.1rem;font-weight:700;color:#065f46;margin-top:0.35rem;">Rp {{ number_format($totalSetor, 0, ',', '.') }}</div>
            </div>
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#ea580c;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">📤 Total Penarikan</div>
                <div style="font-size:1.1rem;font-weight:700;color:#9a3412;margin-top:0.35rem;">Rp {{ number_format($totalTarik, 0, ',', '.') }}</div>
            </div>
            <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#047857;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">💰 Simpanan Pokok</div>
                <div style="font-size:1.1rem;font-weight:700;color:#064e3b;margin-top:0.35rem;">Rp {{ number_format($totalSimpananPokok, 0, ',', '.') }}</div>
            </div>
            <div style="background:#ecfeff;border:1px solid #a5f3fc;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#0e7490;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">🕌 Simpanan Wajib</div>
                <div style="font-size:1.1rem;font-weight:700;color:#164e63;margin-top:0.35rem;">Rp {{ number_format($totalSimpananWajib, 0, ',', '.') }}</div>
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#1d4ed8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">💳 Total Angsuran</div>
                <div style="font-size:1.1rem;font-weight:700;color:#1e3a8a;margin-top:0.35rem;">Rp {{ number_format($totalAngsuran, 0, ',', '.') }}</div>
            </div>
            <div style="background:#fdf4ff;border:1px solid #e9d5ff;border-radius:10px;padding:1rem;">
                <div style="font-size:0.72rem;color:#9333ea;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">💸 Total Pencairan</div>
                <div style="font-size:1.1rem;font-weight:700;color:#6b21a8;margin-top:0.35rem;">Rp {{ number_format($totalPencairan, 0, ',', '.') }}</div>
            </div>
        </div>

        {{-- Saldo Sistem --}}
        <div style="background:#1e293b;color:white;border-radius:12px;padding:1.25rem;">
            <div style="font-size:0.8rem;opacity:0.7;margin-bottom:0.5rem;">Saldo Kas Sistem</div>
            <div style="font-size:1.6rem;font-weight:700;font-family:monospace;">
                Rp {{ number_format($saldoSistem, 0, ',', '.') }}
            </div>
            <div style="font-size:0.75rem;opacity:0.6;margin-top:0.35rem;">
                = Modal Awal + Total Cash In (Setoran + Simpanan Pokok/Wajib + Angsuran) − Total Cash Out (Penarikan + Pencairan)
            </div>
        </div>

        {{-- Riwayat Transaksi Hari Ini --}}
        <div class="card" style="padding:0;overflow:hidden;">
            <div style="padding:1rem 1.25rem;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:0.9rem;color:#374151;">
                📋 Transaksi Hari Ini ({{ $transaksiHariIni->count() }} trx)
            </div>
            @if($transaksiHariIni->count())
            <div style="max-height:280px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
                    <thead>
                        <tr style="background:#f8fafc;position:sticky;top:0;">
                            <th style="padding:0.6rem 1rem;text-align:left;color:#64748b;font-weight:600;">Waktu</th>
                            <th style="padding:0.6rem 1rem;text-align:left;color:#64748b;font-weight:600;">Jenis</th>
                            <th style="padding:0.6rem 1rem;text-align:right;color:#64748b;font-weight:600;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transaksiHariIni as $trx)
                        <tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:0.5rem 1rem;color:#94a3b8;">{{ \Carbon\Carbon::parse($trx->waktu_transaksi)->format('H:i') }}</td>
                            <td style="padding:0.5rem 1rem;">
                                @php
                                    $jenis = $trx->jenis_transaksi;
                                    $color = str_contains($jenis,'Setor') ? '#065f46' : (str_contains($jenis,'Tarik') ? '#9a3412' : (str_contains($jenis,'Angsuran') ? '#1e3a8a' : '#6b21a8'));
                                @endphp
                                <span style="color:{{ $color }};font-weight:600;">{{ $jenis }}</span>
                            </td>
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
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:0.85rem;">Belum ada transaksi hari ini</div>
            @endif
        </div>
    </div>

    {{-- Kanan: Form Hitung Fisik Kas --}}
    <div class="card">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:1px solid #e2e8f0;">
            <div style="width:40px;height:40px;border-radius:8px;background:#fdf4ff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🧮</div>
            <div>
                <div style="font-weight:700;font-size:1rem;">Hitung Fisik Kas</div>
                <div style="font-size:0.78rem;color:#64748b;">Masukkan denominasi uang yang ada di laci</div>
            </div>
        </div>

        <form method="POST" action="{{ route('teller.kas.closing.store') }}" id="formClosing">
            @csrf

            {{-- Tabel Denominasi --}}
            <div style="margin-bottom:1.5rem;">
                <div style="font-weight:600;font-size:0.875rem;color:#374151;margin-bottom:0.75rem;">Pecahan Uang</div>
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <th style="padding:0.5rem 0.75rem;text-align:left;color:#64748b;font-weight:600;">Pecahan</th>
                            <th style="padding:0.5rem 0.75rem;text-align:center;color:#64748b;font-weight:600;">Lembar/Keping</th>
                            <th style="padding:0.5rem 0.75rem;text-align:right;color:#64748b;font-weight:600;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="tabelDenominasi">
                        @foreach($pecahan as $nilai)
                        <tr style="border-bottom:1px solid #f8fafc;" data-nilai="{{ $nilai }}">
                            <td style="padding:0.5rem 0.75rem;font-weight:600;">
                                Rp {{ number_format($nilai, 0, ',', '.') }}
                            </td>
                            <td style="padding:0.5rem 0.75rem;text-align:center;">
                                <input type="number"
                                    name="denominasi[{{ $nilai }}]"
                                    min="0" value="0"
                                    class="denominasi-input"
                                    data-nilai="{{ $nilai }}"
                                    style="width:70px;padding:0.35rem 0.5rem;border:1px solid #d1d5db;border-radius:6px;text-align:center;font-size:0.875rem;"
                                    oninput="hitungTotal()">
                            </td>
                            <td style="padding:0.5rem 0.75rem;text-align:right;font-family:monospace;" id="sub_{{ $nilai }}">
                                Rp 0
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;">
                            <td colspan="2" style="padding:0.75rem;font-weight:700;color:#374151;">TOTAL FISIK KAS</td>
                            <td style="padding:0.75rem;text-align:right;font-weight:700;font-size:1rem;color:#065f46;font-family:monospace;" id="totalFisik">
                                Rp 0
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Hidden input total fisik --}}
            <input type="hidden" name="fisik_kas" id="inputFisikKas" value="0">

            {{-- Selisih Preview --}}
            <div style="background:#f8fafc;border-radius:10px;padding:1rem;margin-bottom:1.25rem;" id="selisihBox">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;text-align:center;font-size:0.8rem;">
                    <div>
                        <div style="color:#64748b;margin-bottom:0.25rem;">Saldo Sistem</div>
                        <div style="font-weight:700;color:#1e293b;font-size:0.95rem;">Rp {{ number_format($saldoSistem, 0, ',', '.') }}</div>
                    </div>
                    <div>
                        <div style="color:#64748b;margin-bottom:0.25rem;">Fisik Kas</div>
                        <div style="font-weight:700;font-size:0.95rem;" id="previewFisik">Rp 0</div>
                    </div>
                    <div>
                        <div style="color:#64748b;margin-bottom:0.25rem;">Selisih</div>
                        <div style="font-weight:700;font-size:0.95rem;" id="previewSelisih">—</div>
                    </div>
                </div>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-weight:600;font-size:0.875rem;color:#374151;margin-bottom:0.5rem;">Catatan Closing</label>
                <textarea name="catatan" rows="2"
                    style="width:100%;padding:0.625rem 0.75rem;border:1px solid #d1d5db;border-radius:8px;font-size:0.875rem;box-sizing:border-box;resize:none;"
                    placeholder="Keterangan serah terima kas..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;padding:0.75rem;font-size:1rem;font-weight:700;background:#6d28d9;border-color:#6d28d9;">
                📦 Proses Closing & Serahkan Kas
            </button>
        </form>
    </div>

</div>

@endsection

@push('scripts')
<script>
// Guard: pastikan saldo sistem berupa angka valid dari server.
// Bila backend gagal menghitung, hentikan form closing supaya teller tidak
// menyimpan selisih kas yang salah (mis. selisih tampak sebesar seluruh fisik).
const saldoSistemRaw = @json($saldoSistem);
const saldoSistem = Number.isFinite(parseFloat(saldoSistemRaw)) ? parseFloat(saldoSistemRaw) : NaN;

if (!Number.isFinite(saldoSistem)) {
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('previewSelisih');
        if (el) {
            el.textContent = '⚠️ Saldo sistem tidak dapat dimuat';
            el.style.color = '#dc2626';
        }
        document.querySelectorAll('form').forEach(function (f) {
            if (f.querySelector('#inputFisikKas')) {
                f.addEventListener('submit', function (ev) {
                    if (!Number.isFinite(saldoSistem)) {
                        ev.preventDefault();
                        alert('Closing tidak dapat dilakukan: saldo kas sistem tidak berhasil dimuat dari server. Muat ulang halaman atau hubungi administrator.');
                    }
                });
            }
        });
    });
}

function hitungTotal() {
    let total = 0;
    document.querySelectorAll('.denominasi-input').forEach(function(inp) {
        const nilai = parseInt(inp.dataset.nilai);
        const lembar = parseInt(inp.value) || 0;
        const subtotal = nilai * lembar;
        total += subtotal;
        document.getElementById('sub_' + nilai).textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
    });

    document.getElementById('totalFisik').textContent = 'Rp ' + total.toLocaleString('id-ID');
    document.getElementById('previewFisik').textContent = 'Rp ' + total.toLocaleString('id-ID');
    document.getElementById('inputFisikKas').value = total;

    const selisih = total - saldoSistem;
    const el = document.getElementById('previewSelisih');
    if (selisih === 0) {
        el.textContent = '✅ Balanced';
        el.style.color = '#065f46';
    } else if (selisih > 0) {
        el.textContent = '▲ Rp ' + selisih.toLocaleString('id-ID');
        el.style.color = '#1d4ed8';
    } else {
        el.textContent = '▼ Rp ' + Math.abs(selisih).toLocaleString('id-ID');
        el.style.color = '#dc2626';
    }
}
</script>
@endpush
