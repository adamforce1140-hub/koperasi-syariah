@extends('layouts.app')
@section('title', 'Tagihan & Jadwal Angsuran')
@section('page-title', 'Tagihan & Jadwal Angsuran')

@section('styles')
<style>
    /* ── Alert tagihan ── */
    .tagihan-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 1rem;
        margin-bottom: 1.75rem;
    }
    .tagihan-card {
        border-radius: 0.875rem;
        padding: 1rem 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .tagihan-card.merah {
        background: #fef2f2;
        border: 1.5px solid #fca5a5;
    }
    .tagihan-card.oranye {
        background: #fff7ed;
        border: 1.5px solid #fdba74;
    }
    .tagihan-card.biru {
        background: #eff6ff;
        border: 1.5px solid #93c5fd;
    }
    .tagihan-card-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        opacity: 0.7;
    }
    .tagihan-card.merah  .tagihan-card-label { color: #991b1b; }
    .tagihan-card.oranye .tagihan-card-label { color: #9a3412; }
    .tagihan-card.biru   .tagihan-card-label { color: #1e40af; }
    .tagihan-card-nominal {
        font-size: 1.3rem;
        font-weight: 700;
    }
    .tagihan-card.merah  .tagihan-card-nominal { color: #dc2626; }
    .tagihan-card.oranye .tagihan-card-nominal { color: #ea580c; }
    .tagihan-card.biru   .tagihan-card-nominal { color: #2563eb; }
    .tagihan-card-meta {
        font-size: 0.775rem;
        color: #64748b;
    }
    .tagihan-card-footer {
        margin-top: 0.25rem;
    }

    /* ── Accordion pembiayaan ── */
    .accordion {
        display: flex;
        flex-direction: column;
        gap: 0.875rem;
    }
    .accordion-item {
        border: 1.5px solid #e2e8f0;
        border-radius: 0.875rem;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    }
    .accordion-header {
        background: #f8fafc;
        padding: 0.875rem 1.25rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        user-select: none;
        transition: background 0.15s;
    }
    .accordion-header:hover {
        background: #f1f5f9;
    }
    .accordion-header-left {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .accordion-no {
        font-size: 0.8rem;
        font-weight: 700;
        color: #1e293b;
    }
    .accordion-produk {
        font-size: 0.8rem;
        color: #64748b;
    }
    .accordion-meta {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .accordion-meta-item {
        font-size: 0.75rem;
        color: #475569;
    }
    .accordion-meta-item strong {
        color: #1e293b;
    }
    .accordion-chevron {
        font-size: 0.8rem;
        color: #94a3b8;
        transition: transform 0.2s;
        flex-shrink: 0;
    }
    .accordion-item.open .accordion-chevron {
        transform: rotate(180deg);
    }
    .accordion-body {
        display: none;
        padding: 0 1.25rem 1.25rem;
        overflow-x: auto;
    }
    .accordion-item.open .accordion-body {
        display: block;
    }
    .accordion-body table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    .accordion-body th {
        background: #f1f5f9;
        padding: 0.5rem 0.75rem;
        text-align: left;
        font-weight: 600;
        color: #475569;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .accordion-body td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        white-space: nowrap;
    }
    .accordion-body tr:last-child td {
        border-bottom: none;
    }
    .accordion-body tr:hover td {
        background: #f8fafc;
    }

    /* ── Badge status angsuran ── */
    .badge-lunas  { background: #dcfce7; color: #166534; }
    .badge-belumjt { background: #dbeafe; color: #1e40af; }
    .badge-terlambat { background: #fee2e2; color: #991b1b; }
    .badge-sebagian  { background: #ffedd5; color: #9a3412; }

    /* ── Badge status tagihan ── */
    .badge-tagihan-biru   { background: #dbeafe; color: #1e40af; border-radius: 999px; padding: 0.2rem 0.65rem; font-size: 0.7rem; font-weight: 600; }
    .badge-tagihan-oranye { background: #ffedd5; color: #9a3412; border-radius: 999px; padding: 0.2rem 0.65rem; font-size: 0.7rem; font-weight: 600; }
    .badge-tagihan-merah  { background: #fee2e2; color: #991b1b; border-radius: 999px; padding: 0.2rem 0.65rem; font-size: 0.7rem; font-weight: 600; }

    /* ── Empty state ── */
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        color: #94a3b8;
    }
    .empty-state .empty-icon { font-size: 3.5rem; margin-bottom: 1rem; }
    .empty-state h3 { font-size: 1.1rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; }
    .empty-state p  { font-size: 0.875rem; }
</style>
@endsection

@section('content')

{{-- ─────────────────────────────────────────
     SECTION: Tagihan Mendatang
───────────────────────────────────────── --}}
@if(isset($angsuranTagihan) && $angsuranTagihan->count() > 0)
<div style="margin-bottom:0.75rem;">
    <h2 style="font-size:0.9rem;font-weight:700;color:#374151;margin-bottom:0.75rem;">
        &#128276; Tagihan Mendatang
    </h2>
    <div class="tagihan-grid">
        @foreach($angsuranTagihan as $tagihan)
        @php
            $statusTag = $tagihan->status_angsuran ?? 'Belum Jatuh Tempo';
            // 'Lewat' identik dengan 'Terlambat' (lihat Angsuran::isTerlambat),
            // 'Dibayar Sebagian' adalah tagihan yang masih menyisakan pokok.
            $cardClass = match(strtolower($statusTag)) {
                'terlambat', 'lewat' => 'merah',
                'jatuh tempo'        => 'oranye',
                'dibayar sebagian'   => 'oranye',
                default              => 'biru',
            };
            $badgeTagClass = match(strtolower($statusTag)) {
                'terlambat', 'lewat' => 'badge-tagihan-merah',
                'jatuh tempo'        => 'badge-tagihan-oranye',
                'dibayar sebagian'   => 'badge-tagihan-oranye',
                default              => 'badge-tagihan-biru',
            };
        @endphp
        <div class="tagihan-card {{ $cardClass }}">
            <div class="tagihan-card-label">
                @if($cardClass === 'merah') &#9888; Terlambat
                @elseif($cardClass === 'oranye' && strtolower($statusTag) === 'dibayar sebagian') &#9888; Dibayar Sebagian
                @elseif($cardClass === 'oranye') &#9201; Jatuh Tempo
                @else &#128197; Mendatang
                @endif
            </div>
            <div class="tagihan-card-nominal">
                {{-- Nominal tagihan = kewajiban angsuran; `total_bayar` hanya terisi setelah dibayar. --}}
                Rp {{ number_format(($tagihan->pokok_angsuran ?? 0) + ($tagihan->margin_ujroh ?? 0), 0, ',', '.') }}
            </div>
            <div class="tagihan-card-meta">
                Angsuran ke-{{ $tagihan->angsuran_ke }} &bull;
                JT: {{ $tagihan->tanggal_jatuh_tempo ? \Carbon\Carbon::parse($tagihan->tanggal_jatuh_tempo)->format('d M Y') : '-' }}
            </div>
            @if($tagihan->pembiayaan)
            <div class="tagihan-card-meta">
                {{ $tagihan->pembiayaan->produk?->nama_produk ?? '-' }}
            </div>
            @endif
            <div class="tagihan-card-footer">
                <span class="{{ $badgeTagClass }}">{{ $statusTag }}</span>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ─────────────────────────────────────────
     SECTION: Jadwal Angsuran per Pembiayaan
───────────────────────────────────────── --}}
<div class="card">
    <div class="card-header">
        <span class="card-title">&#128195; Jadwal Angsuran per Pembiayaan</span>
    </div>

    @if(isset($pembiayaanList) && $pembiayaanList->count() > 0)
    <div class="accordion">
        @foreach($pembiayaanList as $pmb)
        @php
            $statusPmb = (string) ($pmb->status_pembiayaan ?? '');
            // Satu sumber kebenaran label & warna: Pembiayaan::statusMeta().
            // Sebelumnya hanya 'lunas'/'aktif'/'macet' yang dikenali, sehingga
            // akad yang baru cair ('dicairkan') tampil abu-abu generik.
            $metaPmb = \App\Models\Pembiayaan::statusMeta()[$statusPmb] ?? null;
            // Petakan berdasarkan STRING status (bukan kode warna hex) agar tidak
            // rapuh bila palet di statusMeta() berubah.
            $badgePmbClass = match ($statusPmb) {
                \App\Models\Pembiayaan::STATUS_LUNAS                  => 'badge-green',
                \App\Models\Pembiayaan::STATUS_MACET                  => 'badge-red',
                \App\Models\Pembiayaan::STATUS_DICAIRKAN,
                \App\Models\Pembiayaan::STATUS_AKTIF                  => 'badge-blue',
                \App\Models\Pembiayaan::STATUS_DISETUJUI,
                \App\Models\Pembiayaan::STATUS_DISAHKAN               => 'badge-teal',
                default                                               => 'badge-gray',
            };
        @endphp
        <div class="accordion-item" id="acc-{{ $pmb->id }}">
            <div class="accordion-header" onclick="toggleAccordion('{{ $pmb->id }}')">
                <div class="accordion-header-left">
                    <span class="accordion-no">
                        {{ $pmb->no_pembiayaan ?? ('PMB-' . str_pad($pmb->id, 6, '0', STR_PAD_LEFT)) }}
                    </span>
                    <span class="accordion-produk">
                        {{ $pmb->produk?->nama_produk ?? '-' }}
                    </span>
                    <div class="accordion-meta">
                        <span class="accordion-meta-item">
                            {{-- Kolom pembiayaan: jumlah_pembiayaan (plafon pokok) — bukan jumlah_disetujui/jumlah_pengajuan milik pengajuan. --}}
                            Plafond: <strong>Rp {{ number_format((float) ($pmb->jumlah_pembiayaan ?? 0), 0, ',', '.') }}</strong>
                        </span>
                        <span class="accordion-meta-item">
                            {{-- sisa_pembiayaan = sisa kewajiban pokok + margin yang belum dibayar. --}}
                            Sisa Kewajiban: <strong>Rp {{ number_format((float) ($pmb->sisa_pembiayaan ?? 0), 0, ',', '.') }}</strong>
                        </span>
                        <span class="badge {{ $badgePmbClass }}">{{ $metaPmb['label'] ?? ucfirst($statusPmb) }}</span>
                    </div>
                </div>
                <span class="accordion-chevron">&#9660;</span>
            </div>
            <div class="accordion-body">
                @if($pmb->angsuran->isNotEmpty())
                <table>
                    <thead>
                        <tr>
                            <th>Angsuran Ke-</th>
                            <th>Tanggal JT</th>
                            <th>Pokok (Rp)</th>
                            <th>Margin (Rp)</th>
                            <th>Total (Rp)</th>
                            <th>Status</th>
                            <th>Tgl Bayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pmb->angsuran as $ang)
                        @php
                            $stAng = $ang->status_angsuran ?? 'Belum Jatuh Tempo';
                            $badgeAngClass = match(strtolower($stAng)) {
                                'lunas', 'sudah dibayar' => 'badge-lunas',
                                'terlambat', 'lewat'     => 'badge-terlambat',
                                // 'Dibayar Sebagian' = pokok belum tertagih penuh;
                                // jangan disamakan dengan "belum jatuh tempo".
                                'dibayar sebagian'       => 'badge-sebagian',
                                default                  => 'badge-belumjt',
                            };
                        @endphp
                        <tr>
                            <td style="text-align:center;font-weight:600;">{{ $ang->angsuran_ke }}</td>
                            <td>
                                {{ $ang->tanggal_jatuh_tempo
                                    ? \Carbon\Carbon::parse($ang->tanggal_jatuh_tempo)->format('d M Y')
                                    : '-' }}
                            </td>
                            <td>{{ number_format($ang->pokok_angsuran ?? 0, 0, ',', '.') }}</td>
                            <td>{{ number_format($ang->margin_ujroh ?? 0, 0, ',', '.') }}</td>
                            <td style="font-weight:600;">
                                {{-- Kolom "Total" = kewajiban angsuran (pokok + margin). --}}
                                {{ number_format(($ang->pokok_angsuran ?? 0) + ($ang->margin_ujroh ?? 0), 0, ',', '.') }}
                            </td>
                            <td>
                                <span class="badge {{ $badgeAngClass }}">{{ $stAng }}</span>
                            </td>
                            <td>
                                {{ $ang->tanggal_bayar
                                    ? \Carbon\Carbon::parse($ang->tanggal_bayar)->format('d M Y')
                                    : '-' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                <p style="color:#94a3b8;font-size:0.825rem;padding:0.75rem 0;">
                    Belum ada jadwal angsuran untuk pembiayaan ini.
                </p>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    @else
    <div class="empty-state">
        <div class="empty-icon">&#128195;</div>
        <h3>Belum Ada Pembiayaan Aktif</h3>
        <p>Anda belum memiliki pembiayaan aktif. Silakan ajukan pembiayaan terlebih dahulu.</p>
    </div>
    @endif
</div>

@endsection

@section('scripts')
<script>
function toggleAccordion(id) {
    var item = document.getElementById('acc-' + id);
    if (item) {
        item.classList.toggle('open');
    }
}
// Buka accordion pertama secara default
document.addEventListener('DOMContentLoaded', function () {
    var first = document.querySelector('.accordion-item');
    if (first) first.classList.add('open');
});
</script>
@endsection
