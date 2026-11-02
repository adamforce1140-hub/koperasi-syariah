@extends('layouts.app')

@section('title', 'Kas Opname & Closing — ' . config('app.name'))
@section('page-title', 'Kas Opname & Closing')

@section('content')

@php
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

{{-- ── FLASH MESSAGE ──────────────────────────────────── --}}
@if(session('success'))
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>✅</span><div>{{ session('success') }}</div>
</div>
@endif

@if(session('error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>❌</span><div>{{ session('error') }}</div>
</div>
@endif

@if($errors->any())
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;">
    <div style="font-weight:700;margin-bottom:0.3rem;">Periksa kembali input Anda:</div>
    <ul style="margin:0;padding-left:1.2rem;font-size:0.85rem;">
        @foreach($errors->all() as $err)
        <li>{{ $err }}</li>
        @endforeach
    </ul>
</div>
@endif

{{-- ── GAGAL MEMUAT POSISI KAS ─────────────────────────── --}}
@if(!empty($errorKas))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>❌</span>
    <div>
        <div style="font-weight:700;">Data posisi kas tidak dapat dimuat</div>
        <div style="font-size:0.82rem;margin-top:0.2rem;">{{ $errorKas }}</div>
        <div style="font-size:0.78rem;margin-top:0.35rem;">
            Saldo sistem ditampilkan Rp 0 karena perhitungan gagal.
            <strong>Jangan mengajukan opname</strong> sebelum angka berhasil dimuat — ajukan perbaikan ke admin.
        </div>
    </div>
</div>
@endif

{{-- ── HEADER SESI ─────────────────────────────────────── --}}
<div class="card" style="background:linear-gradient(135deg,#1e1b4b,#3730a3);color:white;border:none;margin-bottom:1.5rem;padding:1.5rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div style="display:flex;align-items:center;gap:1rem;">
            <div style="font-size:2rem;">🧾</div>
            <div>
                <div style="font-size:1.1rem;font-weight:700;">Kas Opname & Closing</div>
                <div style="font-size:0.85rem;opacity:0.85;margin-top:0.2rem;">
                    {{ \Carbon\CarbonImmutable::parse($tanggal)->translatedFormat('l, d F Y') }}
                    @if($tanggal !== $hariIni)
                    <span style="background:rgba(255,255,255,0.18);border-radius:6px;padding:0.1rem 0.45rem;font-size:0.7rem;margin-left:0.35rem;">SESI LINTAS HARI</span>
                    @endif
                </div>
                @if($kasHarian)
                <div style="font-size:0.75rem;opacity:0.75;margin-top:0.25rem;">
                    Dibuka {{ $kasHarian->waktu_opening ?? '-' }} · Status:
                    {{ \App\Models\KasHarian::labelStatusOpname($kasHarian->status_opname) }}
                </div>
                @endif
            </div>
        </div>
        <div style="text-align:right;">
            @if($kasHarian)
            <div style="font-size:0.78rem;opacity:0.8;">Saldo Kas Sistem</div>
            <div style="font-size:1.4rem;font-weight:700;">Rp {{ $fmt($saldoSistem) }}</div>
            @else
            <div style="background:rgba(255,255,255,0.15);border-radius:8px;padding:0.6rem 1rem;font-size:0.85rem;">
                ⚠️ Belum ada Opening pada tanggal ini
            </div>
            @endif
        </div>
    </div>
</div>

{{-- ── PEMILIH TANGGAL SESI (CLOSING LINTAS HARI) ──────── --}}
<div class="card" style="padding:0.9rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <form method="GET" action="{{ route('teller.kas.closing') }}" style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
        <label for="tanggalSesi" style="font-size:0.82rem;font-weight:600;color:#334155;">Tanggal sesi kas</label>
        <input type="date" id="tanggalSesi" name="tanggal" value="{{ $tanggal }}" max="{{ $hariIni }}"
               style="padding:0.4rem 0.6rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.82rem;color:#1e293b;background:#fff;">
        <button type="submit" class="btn btn-primary" style="font-size:0.8rem;padding:0.4rem 0.8rem;">Tampilkan</button>
    </form>
    @if($tanggal !== $hariIni)
    <a href="{{ route('teller.kas.closing') }}" style="font-size:0.8rem;color:#4338ca;font-weight:600;">
        ← Kembali ke sesi hari ini
    </a>
    @endif
</div>

{{-- ── SESI LINTAS HARI YANG BELUM DITUTUP ─────────────── --}}
@if(($sesiTertunda ?? collect())->isNotEmpty())
<div style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;">
    <div style="font-weight:700;font-size:0.88rem;margin-bottom:0.35rem;">⚠️ Sesi kas lintas hari belum ditutup</div>
    <div style="font-size:0.8rem;margin-bottom:0.45rem;">
        Selesaikan Closing sesi berikut sebelum Buka Kas hari ini agar saldo laci tidak tercampur.
    </div>
    <ul style="margin:0;padding-left:1.2rem;font-size:0.8rem;">
        @foreach($sesiTertunda as $sesi)
            @php $tglSesi = $sesi->tanggal?->toDateString() ?? (string) $sesi->tanggal; @endphp
            @if($tglSesi !== $tanggal)
            <li style="margin-bottom:0.2rem;">
                <strong>{{ $tglSesi }}</strong> — {{ \App\Models\KasHarian::labelStatusOpname($sesi->status_opname) }}
                · <a href="{{ route('teller.kas.closing', ['tanggal' => $tglSesi]) }}" style="color:#b45309;font-weight:700;text-decoration:underline;">
                    Buka Closing sesi ini
                </a>
            </li>
            @endif
        @endforeach
    </ul>
</div>
@endif

@if(! $kasHarian)
{{-- ── BELUM OPENING ───────────────────────────────────── --}}
<div class="card" style="padding:2rem;text-align:center;background:#fffbeb;border:1px solid #fcd34d;">
    <div style="font-size:2.5rem;margin-bottom:0.75rem;">🔒</div>
    <div style="font-weight:700;color:#92400e;font-size:1rem;">
        Belum ada Opening {{ $tanggal === $hariIni ? 'hari ini' : 'pada tanggal ' . $tanggal }}
    </div>
    <p style="color:#b45309;font-size:0.85rem;margin:0.5rem auto 1.25rem;max-width:32rem;">
        Opname fisik hanya dapat dilakukan setelah Anda membuka kas. Silakan lakukan Buka Kas
        dan input saldo awal terlebih dahulu.
    </p>
    @if($tanggal === $hariIni)
    <a href="{{ route('teller.kas.opening') }}" class="btn btn-primary">Buka Kas Sekarang</a>
    @else
    <a href="{{ route('teller.kas.closing') }}" class="btn btn-primary">Kembali ke Sesi Hari Ini</a>
    @endif
</div>

@elseif($opnameAktif && $opnameAktif->isPending())
{{-- ── SUDAH DIAJUKAN, MENUNGGU MANAJER ────────────────── --}}
@php $w = \App\Models\TellerOpname::warnaStatusSelisih($opnameAktif->status_selisih); @endphp
<div class="card" style="padding:1.75rem;background:{{ $w['bg'] }};border:1px solid {{ $w['border'] }};">
    <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:1rem;">
        <span style="font-size:1.6rem;">⏳</span>
        <div>
            <div style="font-weight:700;color:{{ $w['text'] }};font-size:1.05rem;">Opname Menunggu Verifikasi Manajer</div>
            <div style="font-size:0.8rem;color:{{ $w['text'] }};opacity:0.85;">
                Diajukan {{ $opnameAktif->updated_at?->translatedFormat('d F Y, H:i') }} — kas tidak dapat ditransaksikan sampai disetujui.
            </div>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0.85rem;margin-bottom:1.25rem;">
        <div style="background:#fff;border-radius:8px;padding:0.85rem 1rem;">
            <div style="font-size:0.75rem;color:#64748b;">Saldo Sistem</div>
            <div style="font-size:1.15rem;font-weight:700;color:#1e293b;">Rp {{ $fmt($opnameAktif->saldo_sistem) }}</div>
        </div>
        <div style="background:#fff;border-radius:8px;padding:0.85rem 1rem;">
            <div style="font-size:0.75rem;color:#64748b;">Total Fisik</div>
            <div style="font-size:1.15rem;font-weight:700;color:#1e293b;">Rp {{ $fmt($opnameAktif->total_fisik) }}</div>
        </div>
        <div style="background:#fff;border-radius:8px;padding:0.85rem 1rem;">
            <div style="font-size:0.75rem;color:#64748b;">Selisih</div>
            <div style="font-size:1.15rem;font-weight:700;color:{{ $w['text'] }};">
                {{ $opnameAktif->selisih > 0 ? '+' : '' }} Rp {{ $fmt(abs($opnameAktif->selisih)) }}
            </div>
        </div>
        <div style="background:#fff;border-radius:8px;padding:0.85rem 1rem;">
            <div style="font-size:0.75rem;color:#64748b;">Status</div>
            <div style="font-size:0.95rem;font-weight:700;color:{{ $w['text'] }};">{{ $w['icon'] }} {{ \App\Models\TellerOpname::labelStatusSelisih($opnameAktif->status_selisih) }}</div>
        </div>
    </div>

    {{-- Rincian pecahan yang diajukan --}}
    <div style="background:#fff;border-radius:8px;padding:1rem;">
        <div style="font-size:0.8rem;font-weight:700;color:#1e293b;margin-bottom:0.6rem;">Rincian Pecahan Fisik yang Diajukan</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.4rem;">
            @foreach($opnameAktif->rincian_pecahan ?? [] as $p)
                @if(($p['lembar'] ?? 0) > 0)
                <div style="display:flex;justify-content:space-between;font-size:0.8rem;padding:0.3rem 0.5rem;background:#f8fafc;border-radius:5px;">
                    <span>Rp {{ $fmt($p['pecahan']) }} × {{ $p['lembar'] }}</span>
                    <strong>{{ $fmt($p['subtotal']) }}</strong>
                </div>
                @endif
            @endforeach
        </div>
    </div>

    @if($opnameAktif->catatan)
    <div style="margin-top:1rem;font-size:0.8rem;color:#475569;">
        <strong>Catatan Anda:</strong> {{ $opnameAktif->catatan }}
    </div>
    @endif

    @can('tutupKas', [\App\Models\User::class, $opnameAktif->user_id ?? null, $tanggal])
    <div style="margin-top:1.25rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
        <form method="POST" action="{{ route('teller.kas.closing.batal', $opnameAktif->id) }}"
              onsubmit="return confirm('Batalkan pengajuan opname ini? Anda dapat menghitung ulang fisik kas setelahnya.');">
            @csrf
            <button type="submit" class="btn" style="background:#fff;border:1px solid #fca5a5;color:#991b1b;">
                ↩️ Batalkan & Hitung Ulang
            </button>
        </form>
    </div>
    @endcan
</div>

@else
{{-- ── FORM OPNAME ─────────────────────────────────────── --}}
@can('tutupKas', [\App\Models\User::class, null, $tanggal])
<form method="POST" action="{{ route('teller.kas.closing.store') }}" id="formOpname">
    @csrf
    {{-- Tanggal sesi yang sedang ditutup; mendukung Closing lintas hari. --}}
    <input type="hidden" name="tanggal" value="{{ $tanggal }}">

    <div style="display:grid;grid-template-columns:1fr 1.35fr;gap:1.5rem;align-items:start;">

        {{-- ══ KIRI: KARTU RINGKASAN ══ --}}
        <div style="display:flex;flex-direction:column;gap:1rem;">

            <div class="card" style="padding:1.25rem;">
                <div style="font-size:0.8rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:0.9rem;">
                    Ringkasan Mutasi Kas Hari Ini
                </div>

                <div style="display:flex;flex-direction:column;gap:0.55rem;">
                    <div style="display:flex;justify-content:space-between;padding:0.6rem 0.8rem;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:6px;">
                        <span style="font-size:0.83rem;color:#166534;">Total Setoran (Cash In)</span>
                        <strong style="font-size:0.88rem;color:#166534;">Rp {{ $fmt($totalSetoran) }}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:0.6rem 0.8rem;background:#fef2f2;border-left:3px solid #ef4444;border-radius:6px;">
                        <span style="font-size:0.83rem;color:#991b1b;">Total Penarikan (Cash Out)</span>
                        <strong style="font-size:0.88rem;color:#991b1b;">Rp {{ $fmt($totalPenarikan) }}</strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding:0.6rem 0.8rem;background:#eff6ff;border-left:3px solid #3b82f6;border-radius:6px;">
                        <span style="font-size:0.83rem;color:#1e40af;">Total Angsuran Diterima</span>
                        <strong style="font-size:0.88rem;color:#1e40af;">Rp {{ $fmt($totalAngsuran) }}</strong>
                    </div>
                </div>

                <div style="margin-top:1rem;padding-top:1rem;border-top:1px dashed #e2e8f0;">
                    <div style="font-size:0.8rem;color:#64748b;">Kas Awal (Modal Opening)</div>
                    <div style="font-size:1rem;font-weight:600;color:#1e293b;margin-bottom:0.5rem;">
                        Rp {{ $fmt($posisiKas['kas_awal'] ?? 0) }}
                    </div>
                    <div style="font-size:0.8rem;color:#64748b;">Total Cash In / Cash Out</div>
                    <div style="font-size:1rem;font-weight:600;color:#1e293b;margin-bottom:0.5rem;">
                        Rp {{ $fmt($posisiKas['cash_in'] ?? 0) }} / Rp {{ $fmt($posisiKas['cash_out'] ?? 0) }}
                    </div>
                </div>

                <div style="margin-top:1rem;padding:1rem;background:linear-gradient(135deg,#1e1b4b,#3730a3);border-radius:9px;color:#fff;">
                    <div style="font-size:0.75rem;opacity:0.85;">SALDO KAS SISTEM</div>
                    <div style="font-size:1.5rem;font-weight:700;margin-top:0.2rem;">Rp {{ $fmt($saldoSistem) }}</div>
                    <div style="font-size:0.68rem;opacity:0.7;margin-top:0.3rem;">
                        Kas Awal + Total Cash In − Total Cash Out
                    </div>
                </div>

                <div style="margin-top:0.85rem;font-size:0.72rem;color:#94a3b8;line-height:1.5;">
                    {{ $posisiKas['jumlah_transaksi'] ?? 0 }} transaksi aktif dihitung.
                    Transaksi berstatus Gagal/Dibatalkan/Pending tidak diikutkan.
                </div>
            </div>

            {{-- Indikator selisih live --}}
            <div id="kartuSelisih" class="card" style="padding:1.25rem;background:#d1fae5;border:1px solid #6ee7b7;transition:all .2s;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                    <div style="font-size:0.78rem;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;color:#065f46;" id="labelSelisih">
                        ✅ BALANCED
                    </div>
                </div>
                <div style="font-size:1.6rem;font-weight:700;color:#065f46;" id="nilaiSelisih">Rp 0</div>
                <div style="font-size:0.75rem;margin-top:0.35rem;color:#065f46;opacity:0.85;" id="pesanSelisih">
                    Kas fisik sesuai dengan catatan sistem.
                </div>
            </div>

        </div>

        {{-- ══ KANAN: FORM PECAHAN ══ --}}
        <div class="card" style="padding:1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
                <div>
                    <div style="font-size:0.9rem;font-weight:700;color:#1e293b;">Perhitungan Fisik Uang per Pecahan</div>
                    <div style="font-size:0.75rem;color:#64748b;margin-top:0.15rem;">Masukkan jumlah lembar/keping. Subtotal dihitung otomatis.</div>
                </div>
                <button type="button" id="btnResetPecahan" class="btn"
                        style="background:#f1f5f9;color:#475569;font-size:0.78rem;padding:0.4rem 0.8rem;">
                    Reset
                </button>
            </div>

            <div style="display:flex;flex-direction:column;gap:0.4rem;">
                <div style="display:grid;grid-template-columns:1fr 110px 1fr;gap:0.5rem;font-size:0.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;padding:0 0.5rem;">
                    <span>Pecahan</span>
                    <span style="text-align:center;">Lembar / Keping</span>
                    <span style="text-align:right;">Subtotal</span>
                </div>

                @foreach($pecahanList as $pecahan)
                <div style="display:grid;grid-template-columns:1fr 110px 1fr;gap:0.5rem;align-items:center;padding:0.45rem 0.5rem;border-radius:6px;background:{{ $loop->even ? '#f8fafc' : '#fff' }};border:1px solid #f1f5f9;">
                    <span style="font-size:0.85rem;font-weight:600;color:#334155;">
                        Rp {{ $fmt($pecahan) }}
                    </span>
                    <input type="number"
                           name="rincian_pecahan[{{ $pecahan }}]"
                           id="pecahan_{{ $pecahan }}"
                           data-pecahan="{{ $pecahan }}"
                           class="input-pecahan"
                           min="0" step="1" value="{{ old('rincian_pecahan.' . $pecahan, 0) }}"
                           style="width:100%;text-align:center;padding:0.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.85rem;">
                    <span style="text-align:right;font-size:0.85rem;font-weight:600;color:#1e293b;font-variant-numeric:tabular-nums;"
                          id="subtotal_{{ $pecahan }}">Rp 0</span>
                </div>
                @endforeach
            </div>

            {{-- Input total fisik manual sebagai pembanding (opsional) --}}
            <div style="margin-top:1.1rem;padding:0.9rem 1rem;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;">
                <label style="font-size:0.78rem;font-weight:600;color:#475569;display:block;margin-bottom:0.3rem;">
                    Total Fisik Kas (hasil hitung sistem)
                </label>
                <input type="text" id="totalFisikTampil" readonly value="Rp 0"
                       style="width:100%;padding:0.6rem 0.8rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font-size:1.15rem;font-weight:700;color:#1e293b;font-variant-numeric:tabular-nums;">
            </div>

            {{-- Catatan --}}
            <div style="margin-top:1rem;">
                <label for="catatan" style="font-size:0.78rem;font-weight:600;color:#475569;display:block;margin-bottom:0.3rem;">
                    Catatan untuk Manajer (opsional)
                </label>
                <textarea name="catatan" id="catatan" rows="3" maxlength="1000"
                          placeholder="Contoh: selisih kurang karena pembulatan kembalian, atau penjelasan lain..."
                          style="width:100%;padding:0.6rem 0.8rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.83rem;resize:vertical;">{{ old('catatan') }}</textarea>
            </div>

            {{-- Peringatan selisih --}}
            <div id="peringatanSelisih" style="display:none;margin-top:1rem;padding:0.85rem 1rem;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;font-size:0.8rem;color:#92400e;">
                ⚠️ Kas fisik <strong>tidak sama</strong> dengan saldo sistem. Pastikan hitungan sudah benar —
                pengajuan dengan selisih akan tercatat sebagai kas lebih/kurang dan membentuk jurnal penyesuaian.
            </div>

            <div style="margin-top:1.25rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" id="btnSubmitOpname" style="flex:1;min-width:200px;">
                    📤 Ajukan Opname & Closing
                </button>
                <a href="{{ route('teller.kas.opening') }}" class="btn" style="background:#f1f5f9;color:#475569;">
                    Batal
                </a>
            </div>

            <div style="margin-top:0.75rem;font-size:0.72rem;color:#94a3b8;">
                Setelah diajukan, status kas menjadi <strong>menunggu approval Manajer</strong> dan transaksi kas tidak dapat dilakukan.
            </div>
        </div>

    </div>
</form>
@endcan
@endif

@push('scripts')
<script>
(function () {
    'use strict';

    const form = document.getElementById('formOpname');
    if (!form) return;

    const SALDO_SISTEM     = {{ (float) $saldoSistem }};
    const inputs           = Array.from(document.querySelectorAll('.input-pecahan'));
    const totalFisikTampil = document.getElementById('totalFisikTampil');
    const kartuSelisih     = document.getElementById('kartuSelisih');
    const labelSelisih     = document.getElementById('labelSelisih');
    const nilaiSelisih     = document.getElementById('nilaiSelisih');
    const pesanSelisih     = document.getElementById('pesanSelisih');
    const peringatan       = document.getElementById('peringatanSelisih');
    const btnSubmit        = document.getElementById('btnSubmitOpname');

    /** Format angka gaya Indonesia: 1234567 -> "1.234.567" */
    function rupiah(n) {
        return 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
    }

    /**
     * Hitung ulang subtotal per pecahan, total fisik, dan selisih.
     * Indikator warna: hijau balanced, kuning/oranye over, merah short.
     */
    function hitung() {
        let total = 0;

        inputs.forEach(function (input) {
            const pecahan = parseInt(input.dataset.pecahan, 10) || 0;
            let lembar    = parseInt(input.value, 10);

            if (!Number.isFinite(lembar) || lembar < 0) { lembar = 0; }

            const subtotal = pecahan * lembar;
            total += subtotal;

            const cellSubtotal = document.getElementById('subtotal_' + pecahan);
            if (cellSubtotal) {
                cellSubtotal.textContent = rupiah(subtotal);
                cellSubtotal.style.color = subtotal > 0 ? '#1e293b' : '#cbd5e1';
            }
        });

        totalFisikTampil.value = rupiah(total);

        const selisih = total - SALDO_SISTEM;

        // Nilai selisih
        nilaiSelisih.textContent = (selisih === 0 ? '' : (selisih > 0 ? '+ ' : '- ')) + rupiah(selisih);

        if (selisih === 0) {
            labelSelisih.textContent = '✅ BALANCED';
            pesanSelisih.textContent = 'Kas fisik sesuai dengan catatan sistem. Tidak ada jurnal penyesuaian.';
            kartuSelisih.style.background = '#d1fae5';
            kartuSelisih.style.borderColor = '#6ee7b7';
            labelSelisih.style.color = pesanSelisih.style.color = nilaiSelisih.style.color = '#065f46';
            peringatan.style.display = 'none';
        } else if (selisih > 0) {
            labelSelisih.textContent = '⚠️ LEBIH / CASH OVER';
            pesanSelisih.textContent = 'Kas fisik lebih ' + rupiah(selisih) + ' dari sistem. Dicatat sebagai Titipan Selisih Kas.';
            kartuSelisih.style.background = '#fef3c7';
            kartuSelisih.style.borderColor = '#fcd34d';
            labelSelisih.style.color = pesanSelisih.style.color = nilaiSelisih.style.color = '#92400e';
            peringatan.style.display = 'block';
        } else {
            labelSelisih.textContent = '🔻 KURANG / CASH SHORT';
            pesanSelisih.textContent = 'Kas fisik kurang ' + rupiah(selisih) + ' dari sistem. Dicatat sebagai Piutang Teller.';
            kartuSelisih.style.background = '#fee2e2';
            kartuSelisih.style.borderColor = '#fca5a5';
            labelSelisih.style.color = pesanSelisih.style.color = nilaiSelisih.style.color = '#991b1b';
            peringatan.style.display = 'block';
        }

        // Cegah submit saat fisik 0 (backend juga memvalidasi)
        btnSubmit.disabled = total <= 0;
        btnSubmit.style.opacity = total <= 0 ? '0.55' : '1';
        btnSubmit.style.cursor = total <= 0 ? 'not-allowed' : 'pointer';

        return selisih;
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', hitung);
        // Pilih isi kolom saat difokus supaya input cepat
        input.addEventListener('focus', function () { input.select(); });
    });

    document.getElementById('btnResetPecahan')?.addEventListener('click', function () {
        inputs.forEach(function (i) { i.value = 0; });
        hitung();
    });

    form.addEventListener('submit', function (e) {
        const selisih = hitung();

        if (selisih !== 0) {
            const arah = selisih > 0 ? 'LEBIH' : 'KURANG';
            const ok = confirm(
                'Kas fisik ' + arah + ' ' + rupiah(selisih) + ' dari saldo sistem.\n\n' +
                'Pengajuan akan tercatat sebagai kas ' + arah.toLowerCase() +
                ' dan Manajer akan membentuk jurnal penyesuaian.\n\nLanjutkan mengajukan?'
            );
            if (!ok) { e.preventDefault(); }
        }
    });

    hitung();
})();
</script>
@endpush

@endsection
