@extends('layouts.app')

@section('title', 'Approval Closing Kas — Manajer')
@section('page-title', 'Approval Closing Kas')

@section('content')

@php
    use App\Models\TellerOpname;
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');

    // ── Hak akses: hanya Manajer yang boleh menerima/menolak closing kas ──
    // Route approve/reject sudah dijaga middleware `role:manajer`; gating di sini
    // mencegah tombol aksi muncul bagi peran lain bila view dirender ulang.
    // Daftar peran hidup di config/role_hierarki.php (kelompok
    // `penyetuju_closing_kas`), bukan sebagai literal di view.
    $bolehApprove = auth()->check()
        && \App\Support\HierarkiRole::dalamKelompok(auth()->user()->role, 'penyetuju_closing_kas');

    // View ini juga di-render langsung (mis. oleh test/partial) tanpa middleware
    // ShareErrorsFromSession, sehingga $errors bisa belum tersedia.
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag();
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
    <div style="font-weight:700;margin-bottom:0.3rem;">Periksa kembali:</div>
    <ul style="margin:0;padding-left:1.2rem;font-size:0.85rem;">
        @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
    </ul>
</div>
@endif

@if(!empty($error))
<div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:0.9rem 1.2rem;border-radius:8px;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:0.6rem;">
    <span>❌</span>
    <div>
        <div style="font-weight:700;">Data tidak dapat dimuat</div>
        <div style="font-size:0.82rem;margin-top:0.2rem;">{{ $error }}</div>
    </div>
</div>
@endif

{{-- ── HEADER + STATISTIK ──────────────────────────────── --}}
<div style="display:flex;flex-direction:column;gap:1.25rem;">

    <div>
        <h1 style="font-size:1.35rem;font-weight:700;color:#1e293b;margin:0;line-height:1.3;">
            Approval Closing Kas Teller
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.83rem;color:#64748b;">
            Verifikasi hasil opname fisik kas teller. Persetujuan otomatis membentuk jurnal pemindahan kas ke brankas.
        </p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:0.875rem;">
        <div style="background:linear-gradient(135deg,#b45309,#d97706);border-radius:0.75rem;padding:1.1rem 1.25rem;color:#fff;">
            <div style="font-size:0.775rem;opacity:0.9;">Menunggu Verifikasi</div>
            <div style="font-size:1.75rem;font-weight:700;line-height:1.2;">{{ $statistik['pending'] }}</div>
            <div style="font-size:0.72rem;opacity:0.85;margin-top:0.25rem;">
                Total nilai Rp {{ $fmt($statistik['nilai_pending']) }}
            </div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid #10b981;border-radius:0.75rem;padding:1.1rem 1.25rem;">
            <div style="font-size:0.775rem;color:#64748b;">Sudah Diterima</div>
            <div style="font-size:1.75rem;font-weight:700;color:#1e293b;line-height:1.2;">{{ $statistik['closed'] }}</div>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-left:4px solid #ef4444;border-radius:0.75rem;padding:1.1rem 1.25rem;">
            <div style="font-size:0.775rem;color:#64748b;">Ditolak</div>
            <div style="font-size:1.75rem;font-weight:700;color:#1e293b;line-height:1.2;">{{ $statistik['rejected'] }}</div>
        </div>
    </div>

    {{-- ── FILTER STATUS ───────────────────────────────── --}}
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
        @foreach([
            TellerOpname::STATUS_PENDING  => 'Menunggu',
            TellerOpname::STATUS_CLOSED   => 'Diterima',
            TellerOpname::STATUS_REJECTED => 'Ditolak',
            'semua'                       => 'Semua',
        ] as $key => $label)
        <a href="{{ route('manajer.closing.index', ['status' => $key]) }}"
           style="padding:0.45rem 0.95rem;border-radius:99px;font-size:0.8rem;font-weight:600;text-decoration:none;
                  border:1px solid {{ $status === $key ? '#1d4ed8' : '#e2e8f0' }};
                  background:{{ $status === $key ? '#1d4ed8' : '#fff' }};
                  color:{{ $status === $key ? '#fff' : '#475569' }};">
            {{ $label }}
        </a>
        @endforeach
    </div>

    {{-- ── TABEL ANTREAN ───────────────────────────────── --}}
    @if($opnames->count() === 0)
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;padding:2.5rem;text-align:center;">
        <div style="font-size:2.5rem;margin-bottom:0.6rem;">📭</div>
        <div style="font-weight:600;color:#475569;">Tidak ada pengajuan closing</div>
        <div style="font-size:0.83rem;color:#94a3b8;margin-top:0.3rem;">
            {{ $status === TellerOpname::STATUS_PENDING ? 'Belum ada teller yang mengajukan opname closing.' : 'Tidak ada data untuk filter ini.' }}
        </div>
    </div>
    @else
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:0.75rem;overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.83rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                        <th style="text-align:left;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Teller / Tanggal</th>
                        <th style="text-align:right;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Saldo Sistem</th>
                        <th style="text-align:right;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Total Fisik</th>
                        <th style="text-align:right;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Selisih</th>
                        <th style="text-align:center;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Status</th>
                        <th style="text-align:center;padding:0.75rem 1rem;font-size:0.72rem;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($opnames as $op)
                    @php $w = TellerOpname::warnaStatusSelisih($op->status_selisih); @endphp
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.85rem 1rem;">
                            <div style="font-weight:600;color:#1e293b;">{{ $op->user?->name ?? 'User #' . $op->user_id }}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">
                                {{ $op->tanggal?->translatedFormat('d M Y') }} · diajukan {{ $op->updated_at?->format('H:i') }}
                            </div>
                            @if($op->jurnal)
                            <div style="font-size:0.72rem;color:#059669;margin-top:0.15rem;">🔗 {{ $op->jurnal->no_jurnal }}</div>
                            @endif
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:right;color:#334155;font-variant-numeric:tabular-nums;">
                            Rp {{ $fmt($op->saldo_sistem) }}
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:right;font-weight:600;color:#1e293b;font-variant-numeric:tabular-nums;">
                            Rp {{ $fmt($op->total_fisik) }}
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:right;font-weight:700;color:{{ $w['text'] }};font-variant-numeric:tabular-nums;">
                            {{ $op->selisih > 0 ? '+' : ($op->selisih < 0 ? '-' : '') }} Rp {{ $fmt(abs($op->selisih)) }}
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:center;">
                            <span style="display:inline-block;padding:0.3rem 0.7rem;border-radius:99px;font-size:0.7rem;font-weight:700;
                                         background:{{ $w['bg'] }};color:{{ $w['text'] }};border:1px solid {{ $w['border'] }};">
                                {{ $w['icon'] }} {{ TellerOpname::labelStatusSelisih($op->status_selisih) }}
                            </span>
                            <div style="margin-top:0.35rem;font-size:0.7rem;color:#64748b;">
                                {{ TellerOpname::labelStatus($op->status) }}
                            </div>
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:center;">
                            <button type="button"
                                    class="btn-detail"
                                    data-opname="{{ $op->id }}"
                                    style="padding:0.4rem 0.85rem;border-radius:6px;font-size:0.78rem;font-weight:600;
                                           border:1px solid #cbd5e1;background:#fff;color:#334155;cursor:pointer;">
                                🔍 Detail
                            </button>
                        </td>
                    </tr>

                    {{-- Data detail untuk modal (disembunyikan) --}}
                    {{-- R-02 — Data modal dikirim sebagai JSON di dalam
                         `<script type="application/json">` dan dibaca JS lewat
                         `JSON.parse(tag.textContent)`. WAJIB memakai flag
                         `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`
                         agar catatan pengguna yang memuat `</script>` atau
                         `<`/`&` tidak dapat memutus blok script.
                         Jangan ganti ke `Js::from()`: keluarannya adalah ekspresi
                         JS (`JSON.parse('…')`), bukan JSON, sehingga
                         `JSON.parse(tag.textContent)` akan gagal. --}}
                    <script type="application/json" id="opname-data-{{ $op->id }}">
                        {!! json_encode([
                            'id'              => $op->id,
                            'teller'          => $op->user?->name ?? ('User #' . $op->user_id),
                            'tanggal'         => $op->tanggal?->translatedFormat('d F Y'),
                            'diajukan'        => $op->updated_at?->format('d/m/Y H:i'),
                            'saldo_sistem'    => (float) $op->saldo_sistem,
                            'total_fisik'     => (float) $op->total_fisik,
                            'selisih'         => (float) $op->selisih,
                            'status_selisih'  => $op->status_selisih,
                            'label_selisih'   => TellerOpname::labelStatusSelisih($op->status_selisih),
                            'status'          => $op->status,
                            'label_status'    => TellerOpname::labelStatus($op->status),
                            'catatan'         => $op->catatan,
                            'catatan_approval'=> $op->catatan_approval,
                            'approved_by'     => $op->approvedBy?->name,
                            'approved_at'     => $op->approved_at?->format('d/m/Y H:i'),
                            'jurnal'          => $op->jurnal?->no_jurnal,
                            'rincian_pecahan' => $op->rincian_pecahan ?? [],
                            'approve_url'     => route('manajer.closing.approve', $op->id),
                            'reject_url'      => route('manajer.closing.reject', $op->id),
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
                    </script>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($opnames->hasPages())
    <div>{{ $opnames->links() }}</div>
    @endif
    @endif

</div>

{{-- ══ MODAL DETAIL & KONFIRMASI ═════════════════════════ --}}
<div id="modalDetail" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:1000;padding:1.5rem;overflow-y:auto;">
    <div style="max-width:640px;margin:2rem auto;background:#fff;border-radius:0.875rem;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,0.3);">

        {{-- Header modal --}}
        <div style="background:linear-gradient(135deg,#1e1b4b,#3730a3);color:#fff;padding:1.25rem 1.5rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
            <div>
                <div style="font-size:1.05rem;font-weight:700;">Detail Opname Fisik Kas</div>
                <div style="font-size:0.8rem;opacity:0.85;margin-top:0.2rem;" id="modalSubtitle">—</div>
            </div>
            <button type="button" id="btnTutupModal"
                    style="background:rgba(255,255,255,0.18);border:none;color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:1.1rem;line-height:1;">×</button>
        </div>

        <div class="max-h-[85vh] overflow-y-auto" style="padding:1.5rem;max-height:calc(100vh - 16rem);overflow-y:auto;">

            {{-- Ringkasan angka --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.85rem 1rem;">
                    <div style="font-size:0.72rem;color:#64748b;">Saldo Sistem</div>
                    <div style="font-size:1.05rem;font-weight:700;color:#1e293b;" id="modalSaldoSistem">—</div>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.85rem 1rem;">
                    <div style="font-size:0.72rem;color:#64748b;">Total Fisik</div>
                    <div style="font-size:1.05rem;font-weight:700;color:#1e293b;" id="modalTotalFisik">—</div>
                </div>
                <div id="modalKartuSelisih" style="border-radius:8px;padding:0.85rem 1rem;background:#d1fae5;border:1px solid #6ee7b7;">
                    <div style="font-size:0.72rem;color:#64748b;">Selisih</div>
                    <div style="font-size:1.05rem;font-weight:700;" id="modalSelisih">—</div>
                </div>
            </div>

            <div id="modalBadgeSelisih" style="margin-bottom:1.25rem;padding:0.7rem 1rem;border-radius:8px;font-size:0.83rem;font-weight:700;text-align:center;">
                —
            </div>

            {{-- Tabel pecahan --}}
            <div style="margin-bottom:1.25rem;">
                <div style="font-size:0.8rem;font-weight:700;color:#1e293b;margin-bottom:0.6rem;">
                    Rincian Lembaran / Kepingan Fisik
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="text-align:left;padding:0.5rem 0.75rem;font-size:0.7rem;color:#64748b;text-transform:uppercase;">Pecahan</th>
                            <th style="text-align:center;padding:0.5rem 0.75rem;font-size:0.7rem;color:#64748b;text-transform:uppercase;">Lembar</th>
                            <th style="text-align:right;padding:0.5rem 0.75rem;font-size:0.7rem;color:#64748b;text-transform:uppercase;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="modalTabelPecahan">
                        <tr><td colspan="3" style="padding:1rem;text-align:center;color:#94a3b8;">Memuat…</td></tr>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #e2e8f0;">
                            <td colspan="2" style="padding:0.6rem 0.75rem;font-weight:700;color:#1e293b;">TOTAL FISIK</td>
                            <td style="padding:0.6rem 0.75rem;text-align:right;font-weight:700;font-size:0.95rem;color:#1e293b;" id="modalTotalTabel">—</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Catatan teller --}}
            <div id="modalBlokCatatan" style="display:none;margin-bottom:1.25rem;padding:0.85rem 1rem;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
                <div style="font-size:0.72rem;font-weight:700;color:#92400e;margin-bottom:0.2rem;">CATATAN TELLER</div>
                <div style="font-size:0.83rem;color:#78350f;" id="modalCatatan">—</div>
            </div>

            {{-- Rencana jurnal --}}
            <div id="modalBlokJurnal" style="margin-bottom:1.25rem;padding:0.9rem 1rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                <div style="font-size:0.72rem;font-weight:700;color:#1e40af;margin-bottom:0.5rem;">
                    JURNAL PEMINDAHAN KAS YANG AKAN DIBENTUK
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                    <tbody id="modalTabelJurnal"></tbody>
                </table>
                <div style="font-size:0.7rem;color:#3b82f6;margin-top:0.5rem;">
                    Cek akun 1-1003 (Kas Brankas/Vault) · 1-1001 (Kas Teller) · 1-1099 (Piutang Teller) · 2-3099 (Titipan Selisih Kas)
                </div>
            </div>

            {{-- Hasil verifikasi sebelumnya --}}
            <div id="modalBlokApproval" style="display:none;margin-bottom:1.25rem;padding:0.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <div style="font-size:0.72rem;font-weight:700;color:#475569;margin-bottom:0.3rem;">HASIL VERIFIKASI</div>
                <div style="font-size:0.8rem;color:#334155;" id="modalApprovalInfo">—</div>
            </div>

            {{-- Form aksi (hanya saat pending & pengguna berhak approve) --}}
            <div id="modalBlokAksi" style="display:none;border-top:1px dashed #e2e8f0;padding-top:1.25rem;">
                @if($bolehApprove)
                <label for="catatanApproval" style="font-size:0.78rem;font-weight:600;color:#475569;display:block;margin-bottom:0.3rem;">
                    Catatan Verifikasi <span style="color:#94a3b8;font-weight:400;">(wajib bila menolak)</span>
                </label>
                <textarea id="catatanApproval" rows="2" maxlength="1000"
                          placeholder="Contoh: fisik sudah sesuai, / selisih kurang Rp 25.000 mohon dihitung ulang."
                          style="width:100%;padding:0.6rem 0.8rem;border:1px solid #cbd5e1;border-radius:6px;font-size:0.83rem;resize:vertical;"></textarea>

                <div id="konfirmasiApprove" style="display:none;margin-top:1rem;padding:0.85rem 1rem;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:0.8rem;color:#166534;">
                    Konfirmasi: Anda menyatakan telah <strong>menerima kas fisik</strong> sejumlah yang tertera.
                    Jurnal pemindahan kas ke brankas akan otomatis diposting dan sesi kas teller ditutup.
                </div>

                <div style="margin-top:1rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
                    <button type="button" id="btnKonfirmasiApprove"
                            style="flex:1;min-width:180px;padding:0.7rem 1rem;border-radius:8px;border:none;cursor:pointer;
                                   background:#059669;color:#fff;font-weight:700;font-size:0.88rem;">
                        ✅ Terima Kas &amp; Approve
                    </button>
                    <button type="button" id="btnKonfirmasiReject"
                            style="flex:1;min-width:150px;padding:0.7rem 1rem;border-radius:8px;cursor:pointer;
                                   background:#fff;border:1px solid #fca5a5;color:#991b1b;font-weight:700;font-size:0.88rem;">
                        ❌ Tolak
                    </button>
                </div>
                @else
                {{-- Tanpa hak approve: sampaikan secara eksplisit, jangan tampilkan tombol --}}
                <div style="padding:0.85rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;
                            font-size:0.82rem;color:#475569;">
                    🔒 Anda tidak memiliki hak akses untuk menyetujui atau menolak closing kas.
                    Hanya pengguna dengan peran <strong>Manajer</strong> yang dapat melakukan aksi ini.
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Form tersembunyi untuk submit aksi --}}
<form method="POST" id="formAksiOpname" style="display:none;">
    @csrf
    <input type="hidden" name="catatan_approval" id="inputCatatanApproval">
</form>

@push('scripts')
<script>
(function () {
    'use strict';

    const modal          = document.getElementById('modalDetail');
    const btnTutup       = document.getElementById('btnTutupModal');
    const btnApprove     = document.getElementById('btnKonfirmasiApprove');
    const btnReject      = document.getElementById('btnKonfirmasiReject');
    const formAksi       = document.getElementById('formAksiOpname');
    const inputCatatan   = document.getElementById('inputCatatanApproval');
    const catatanField   = document.getElementById('catatanApproval');
    const blokKonfirmasi = document.getElementById('konfirmasiApprove');
    const blokAksi       = document.getElementById('modalBlokAksi');

    let dataAktif = null;
    let modeApprove = false;

    const WARNA = {
        balanced: { bg: '#d1fae5', border: '#6ee7b7', text: '#065f46', icon: '✅' },
        over:     { bg: '#fef3c7', border: '#fcd34d', text: '#92400e', icon: '⚠️' },
        short:    { bg: '#fee2e2', border: '#fca5a5', text: '#991b1b', icon: '🔻' }
    };

    function rupiah(n) {
        return 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
    }

    function selisihTeks(n) {
        if (Math.round(n * 100) === 0) return 'Rp 0';
        return (n > 0 ? '+ ' : '- ') + rupiah(n);
    }

    /** Bangun baris tabel jurnal yang akan dibentuk (pratinjau, bukan angka otoritatif). */
    function barisJurnal(d) {
        const fisik  = Math.round(d.total_fisik);
        const sistem = Math.round(d.saldo_sistem);
        const selisih = Math.round(d.selisih);
        const baris = [];

        baris.push(['1-1003', 'Kas Brankas Utama / Vault', fisik, 0]);
        baris.push(['1-1001', 'Kas Teller', 0, sistem]);

        if (selisih > 0) {
            baris.push(['2-3099', 'Titipan Selisih Kas', 0, selisih]);
        } else if (selisih < 0) {
            baris.push(['1-1099', 'Piutang Teller / Selisih Kas', Math.abs(selisih), 0]);
        }

        let html = '';
        baris.forEach(function (b) {
            html += '<tr style="border-bottom:1px solid #dbeafe;">'
                 + '<td style="padding:0.35rem 0.5rem;font-family:monospace;font-size:0.75rem;color:#1e40af;white-space:nowrap;">' + b[0] + '</td>'
                 + '<td style="padding:0.35rem 0.5rem;color:#334155;">' + b[1] + '</td>'
                 + '<td style="padding:0.35rem 0.5rem;text-align:right;font-variant-numeric:tabular-nums;">' + (b[2] ? rupiah(b[2]) : '—') + '</td>'
                 + '<td style="padding:0.35rem 0.5rem;text-align:right;font-variant-numeric:tabular-nums;">' + (b[3] ? rupiah(b[3]) : '—') + '</td>'
                 + '</tr>';
        });

        return '<tr style="background:#e0e7ff;">'
             + '<th style="padding:0.35rem 0.5rem;text-align:left;font-size:0.7rem;color:#3730a3;">KODE</th>'
             + '<th style="padding:0.35rem 0.5rem;text-align:left;font-size:0.7rem;color:#3730a3;">AKUN</th>'
             + '<th style="padding:0.35rem 0.5rem;text-align:right;font-size:0.7rem;color:#3730a3;">DEBET</th>'
             + '<th style="padding:0.35rem 0.5rem;text-align:right;font-size:0.7rem;color:#3730a3;">KREDIT</th>'
             + '</tr>' + html;
    }

    function bukaModal(d) {
        dataAktif   = d;
        modeApprove = false;

        const w = WARNA[d.status_selisih] || WARNA.balanced;

        document.getElementById('modalSubtitle').textContent =
            d.teller + ' · ' + (d.tanggal || '-') + ' · diajukan ' + (d.diajukan || '-');
        document.getElementById('modalSaldoSistem').textContent = rupiah(d.saldo_sistem);
        document.getElementById('modalTotalFisik').textContent  = rupiah(d.total_fisik);
        document.getElementById('modalSelisih').textContent     = selisihTeks(d.selisih);

        // Indikator selisih
        const kartu = document.getElementById('modalKartuSelisih');
        kartu.style.background  = w.bg;
        kartu.style.borderColor = w.border;
        document.getElementById('modalSelisih').style.color = w.text;

        const badge = document.getElementById('modalBadgeSelisih');
        badge.style.background  = w.bg;
        badge.style.border      = '1px solid ' + w.border;
        badge.style.color       = w.text;
        badge.textContent       = w.icon + ' ' + d.label_selisih + '  ·  ' + d.label_status;

        // Tabel pecahan
        let rows = '';
        let total = 0;
        (d.rincian_pecahan || []).forEach(function (p) {
            const lembar = parseInt(p.lembar, 10) || 0;
            const sub    = parseFloat(p.subtotal) || 0;
            if (lembar <= 0) return;
            total += sub;
            rows += '<tr style="border-bottom:1px solid #f1f5f9;">'
                 + '<td style="padding:0.45rem 0.75rem;">Rp ' + Math.round(p.pecahan).toLocaleString('id-ID') + '</td>'
                 + '<td style="padding:0.45rem 0.75rem;text-align:center;">' + lembar.toLocaleString('id-ID') + '</td>'
                 + '<td style="padding:0.45rem 0.75rem;text-align:right;font-variant-numeric:tabular-nums;">' + rupiah(sub) + '</td>'
                 + '</tr>';
        });

        document.getElementById('modalTabelPecahan').innerHTML = rows
            || '<tr><td colspan="3" style="padding:1rem;text-align:center;color:#94a3b8;">Tidak ada pecahan dengan lembar &gt; 0.</td></tr>';
        document.getElementById('modalTotalTabel').textContent = rupiah(total || d.total_fisik);

        // Catatan teller
        const blokCatatan = document.getElementById('modalBlokCatatan');
        if (d.catatan) {
            document.getElementById('modalCatatan').textContent = d.catatan;
            blokCatatan.style.display = 'block';
        } else {
            blokCatatan.style.display = 'none';
        }

        // Blok jurnal vs blok hasil verifikasi
        const blokJurnal   = document.getElementById('modalBlokJurnal');
        const blokApproval = document.getElementById('modalBlokApproval');

        if (d.status === 'pending') {
            blokJurnal.style.display   = 'block';
            blokApproval.style.display = 'none';
            document.getElementById('modalTabelJurnal').innerHTML = barisJurnal(d);
            blokAksi.style.display = 'block';
            if (blokKonfirmasi) blokKonfirmasi.style.display = 'none';
            if (catatanField) catatanField.value = '';
            if (btnApprove) {
                btnApprove.style.display = '';
                btnApprove.textContent = '✅ Terima Kas & Approve';
            }
            if (btnReject) btnReject.style.display = 'block';
        } else {
            blokJurnal.style.display   = 'none';
            blokApproval.style.display = 'block';
            blokAksi.style.display = 'none';

            document.getElementById('modalApprovalInfo').innerHTML =
                'Status: <strong>' + d.label_status + '</strong>'
                + (d.approved_by ? ' oleh <strong>' + d.approved_by + '</strong>' : '')
                + (d.approved_at ? ' pada ' + d.approved_at : '')
                + (d.jurnal ? '<br>Jurnal: <strong>' + d.jurnal + '</strong>' : '')
                + (d.catatan_approval ? '<br>Catatan: ' + d.catatan_approval : '');
        }

        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function tutupModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        dataAktif = null;
        modeApprove = false;
    }

    // ── Buka modal dari tombol Detail ──────────────────────
    document.querySelectorAll('.btn-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id  = btn.dataset.opname;
            const tag = document.getElementById('opname-data-' + id);
            if (!tag) return;

            try {
                bukaModal(JSON.parse(tag.textContent));
            } catch (e) {
                alert('Gagal membaca data opname: ' + e.message);
            }
        });
    });

    btnTutup.addEventListener('click', tutupModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) tutupModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display === 'block') tutupModal(); });

    // ── Approve: dua langkah (tampilkan konfirmasi lalu submit) ──
    if (btnApprove) btnApprove.addEventListener('click', function () {
        if (!dataAktif) return;

        if (!modeApprove) {
            modeApprove = true;
            blokKonfirmasi.style.display = 'block';
            btnApprove.textContent = '✅ Ya, Terima Kas & Posting Jurnal';
            btnReject.style.display = 'none';
            blokKonfirmasi.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        formAksi.action = dataAktif.approve_url;
        inputCatatan.value = catatanField.value || '';
        btnApprove.disabled = true;
        btnApprove.textContent = '⏳ Memproses…';
        formAksi.submit();
    });

    // ── Reject: wajib alasan ───────────────────────────────
    if (btnReject) btnReject.addEventListener('click', function () {
        if (!dataAktif) return;

        if (!catatanField.value.trim()) {
            alert('Alasan penolakan wajib diisi agar teller tahu apa yang harus diperbaiki.');
            catatanField.focus();
            return;
        }

        formAksi.action = dataAktif.reject_url;
        inputCatatan.value = catatanField.value;
        btnReject.disabled = true;
        formAksi.submit();
    });
})();
</script>
@endpush

@endsection
