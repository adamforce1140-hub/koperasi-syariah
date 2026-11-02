@extends('layouts.app')
@section('page-title', "Profil Anggota")

@section('title', 'Profil Anggota — ' . $anggota->nama_lengkap)

@section('sidebar')
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
<div class="nav-section">Kelola Anggota</div>
<a href="{{ route('teller.anggota.index') }}" class="nav-item active">
    <span class="icon">👥</span> Data Anggota
</a>
@endsection

@section('content')
<style>
.section-title {
    font-size: .875rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin: 1.5rem 0 .75rem;
}
.stat-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1rem 1.25rem;
}
.stat-label { font-size: .8rem; color: #64748b; margin-bottom: .25rem; }
.stat-value { font-size: 1.3rem; font-weight: 700; color: #1e293b; }
.action-btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .5rem 1rem;
    border-radius: 8px;
    font-size: .875rem;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
}
.btn-green  { background: #059669; color: #fff; }
.btn-red    { background: #dc2626; color: #fff; }
.btn-blue   { background: #2563eb; color: #fff; }
.btn-purple { background: #7c3aed; color: #fff; }
.btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
.progress-bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 999px;
    overflow: hidden;
    margin-top: .5rem;
}
.progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #059669, #10b981);
    transition: width .4s;
}
</style>

<div style="max-width:960px;margin:0 auto;">

    {{-- Breadcrumb --}}
    <div style="margin-bottom:1rem;font-size:.875rem;color:#64748b;">
        <a href="{{ route('teller.anggota.index') }}" style="color:#2563eb;text-decoration:none;">Data Anggota</a>
        <span style="margin:0 .4rem;">/</span>
        <span>{{ $anggota->nama_lengkap }}</span>
    </div>

    {{-- ══ HEADER CARD ══ --}}
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:1.5rem;display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap;margin-bottom:1.5rem;">
        {{-- Avatar --}}
        <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#065f46,#059669);display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#fff;flex-shrink:0;">
            {{ mb_strtoupper(mb_substr($anggota->nama_lengkap, 0, 1)) }}
        </div>

        {{-- Info --}}
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                <h2 style="margin:0;font-size:1.25rem;font-weight:700;color:#1e293b;">
                    {{ $anggota->nama_lengkap }}
                </h2>
                @php
                    use App\Models\Anggota;

                    $sc = match($anggota->status_anggota) {
                        Anggota::STATUS_ACTIVE, 'Aktif'
                                        => 'background:#d1fae5;color:#065f46;',
                        Anggota::STATUS_PENDING
                                        => 'background:#fef3c7;color:#92400e;',
                        Anggota::STATUS_SUSPENDED, 'Dibekukan'
                                        => 'background:#ede9fe;color:#5b21b6;',
                        Anggota::STATUS_KELUAR, 'Keluar'
                                        => 'background:#fee2e2;color:#991b1b;',
                        default         => 'background:#f1f5f9;color:#475569;',
                    };
                @endphp
                <span style="{{ $sc }} padding:.2rem .7rem;border-radius:999px;font-size:.78rem;font-weight:600;">
                    {{ $anggota->status_label }}
                </span>
            </div>
            <div style="margin-top:.35rem;font-size:.875rem;color:#64748b;display:flex;gap:1.25rem;flex-wrap:wrap;">
                <span>No. Anggota: <strong style="color:#374151;">{{ $anggota->no_anggota ?? '-' }}</strong></span>
                <span>NIK: <strong style="color:#374151;font-family:monospace;">{{ $anggota->nik }}</strong></span>
                <span>No. HP: <strong style="color:#374151;">{{ $anggota->no_hp ?? '-' }}</strong></span>
            </div>

            {{-- Badge Umur Keanggotaan (Member Age) — POIN 4 --}}
            <div style="margin-top:.5rem;display:inline-flex;align-items:center;gap:.4rem;flex-wrap:wrap;
                        padding:.3rem .7rem;background:#eff6ff;border:1px solid #bfdbfe;
                        border-radius:.5rem;font-size:.78rem;color:#1e40af;font-weight:600;">
                <span>&#128197;</span>
                <span>
                    Bergabung sejak {{ $anggota->tanggalMulaiKeanggotaan()?->format('d M Y') ?? '-' }}
                    &mdash; Masa Aktif: {{ $anggota->tenure_label }}
                </span>
                <span style="color:#64748b;font-weight:500;">({{ $anggota->member_age }})</span>
            </div>
        </div>

        {{-- Tombol --}}
        <a href="{{ route('teller.anggota.card', $anggota->id) }}" class="action-btn btn-purple">
            🪪 Cetak Kartu
        </a>
    </div>

    {{-- ══ SECTION 1: SIMPANAN & TABUNGAN ══ --}}
    <div class="section-title">Simpanan & Tabungan</div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1rem;">
        <div class="stat-card" style="border-top:3px solid #059669;">
            <div class="stat-label">Simpanan Pokok</div>
            <div class="stat-value" style="color:#059669;">Rp {{ number_format($saldoPokok, 0, ',', '.') }}</div>
        </div>
        <div class="stat-card" style="border-top:3px solid #2563eb;">
            <div class="stat-label">Simpanan Wajib</div>
            <div class="stat-value" style="color:#2563eb;">Rp {{ number_format($saldoWajib, 0, ',', '.') }}</div>
        </div>
        <div class="stat-card" style="border-top:3px solid #7c3aed;">
            <div class="stat-label">Simpanan Sukarela / Tabungan</div>
            <div class="stat-value" style="color:#7c3aed;">Rp {{ number_format($saldoSukarela, 0, ',', '.') }}</div>
        </div>
    </div>

    {{-- Rekening list ringkas --}}
    @if($anggota->rekening->count())
    <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.875rem;">
        @foreach($anggota->rekening as $rek)
        <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid #e5e7eb;">
            <span style="color:#64748b;">{{ $rek->jenis_rekening }} <span style="font-family:monospace;font-size:.8rem;color:#94a3b8;">{{ $rek->no_rekening }}</span></span>
            <strong style="color:#1e293b;">Rp {{ number_format($rek->saldo, 0, ',', '.') }}</strong>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Tombol aksi cepat simpanan --}}
    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.5rem;">
        <a href="{{ route('teller.setor') }}?anggota_id={{ $anggota->id }}" class="action-btn btn-green">
            ➕ Setoran Tunai
        </a>
        <a href="{{ route('teller.tarik') }}?anggota_id={{ $anggota->id }}" class="action-btn btn-red">
            ➖ Penarikan Tunai
        </a>
    </div>

    {{-- ══ SECTION 2: PEMBIAYAAN & TAGIHAN ══ --}}
    <div class="section-title">Pembiayaan & Tagihan</div>

    @forelse($pembiayaanAktif as $pmb)
    @php
        $pct = $pmb->persentase_terbayar;
        $sc2 = match($pmb->status_pembiayaan) {
            'aktif'     => 'background:#d1fae5;color:#065f46;',
            'disetujui' => 'background:#dbeafe;color:#1e40af;',
            'dicairkan' => 'background:#fef9c3;color:#854d0e;',
            default     => 'background:#f1f5f9;color:#475569;',
        };
    @endphp
    <div class="stat-card" style="margin-bottom:.75rem;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
            <div>
                <div style="font-weight:700;color:#1e293b;margin-bottom:.2rem;">
                    {{ $pmb->no_pembiayaan }} — {{ $pmb->akad_pembiayaan }}
                </div>
                <div style="font-size:.82rem;color:#64748b;">{{ $pmb->tujuan_pembiayaan }}</div>
            </div>
            <span style="{{ $sc2 }} padding:.2rem .7rem;border-radius:999px;font-size:.78rem;font-weight:600;">
                {{ $pmb->status_pembiayaan }}
            </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;margin-top:.75rem;font-size:.85rem;">
            <div>
                <div style="color:#64748b;margin-bottom:.15rem;">Plafond</div>
                <strong>Rp {{ number_format($pmb->jumlah_pembiayaan, 0, ',', '.') }}</strong>
            </div>
            <div>
                <div style="color:#64748b;margin-bottom:.15rem;">Sisa Kewajiban</div>
                <strong style="color:#dc2626;">Rp {{ number_format($pmb->sisa_pembiayaan, 0, ',', '.') }}</strong>
            </div>
            <div>
                <div style="color:#64748b;margin-bottom:.15rem;">Angsuran / Bulan</div>
                <strong>Rp {{ number_format($pmb->jumlah_angsuran, 0, ',', '.') }}</strong>
            </div>
        </div>

        {{-- Progress bar --}}
        <div style="margin-top:.75rem;">
            <div style="display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-bottom:.25rem;">
                <span>Terbayar {{ $pmb->angsuran_sudah_bayar }} / {{ $pmb->tenor }} angsuran</span>
                <span>{{ $pct }}%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width:{{ $pct }}%;"></div>
            </div>
        </div>

        {{-- Tombol aksi --}}
        <div style="display:flex;gap:.6rem;margin-top:.9rem;flex-wrap:wrap;">
            <a href="{{ route('teller.angsuran') }}?pembiayaan_id={{ $pmb->id }}" class="action-btn btn-blue">
                💳 Bayar Angsuran
            </a>
            @if($pmb->isStatus(\App\Models\Pembiayaan::STATUS_DISETUJUI))
            <a href="{{ route('teller.pencairan.index') ?? '#' }}" class="action-btn btn-green">
                🏦 Proses Pencairan
            </a>
            @endif
        </div>
    </div>
    @empty
    <div style="background:#f8fafc;border:1px dashed #d1d5db;border-radius:10px;padding:1.25rem;text-align:center;color:#94a3b8;font-size:.9rem;margin-bottom:1.5rem;">
        Tidak ada pembiayaan aktif.
    </div>
    @endforelse

    {{-- Pengajuan terbaru (jika bukan status aktif) --}}
    @if($pengajuanTerbaru && !in_array(strtolower($pengajuanTerbaru->status_pembiayaan), ['aktif','disetujui','dicairkan','restrukturisasi']))
    @php
        $sc3 = match($pengajuanTerbaru->status_pembiayaan) {
            'pending_ao', 'pending_manajer', 'pending_pengurus' => 'background:#dbeafe;color:#1e40af;',
            'ditolak'   => 'background:#fee2e2;color:#991b1b;',
            'lunas'     => 'background:#d1fae5;color:#065f46;',
            default     => 'background:#f1f5f9;color:#475569;',
        };
    @endphp
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.9rem 1rem;font-size:.875rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <div>
            <span style="color:#92400e;font-weight:600;">Pengajuan Terbaru:</span>
            {{ $pengajuanTerbaru->no_pembiayaan }} — Rp {{ number_format($pengajuanTerbaru->jumlah_pembiayaan, 0, ',', '.') }}
        </div>
        <span style="{{ $sc3 }} padding:.2rem .65rem;border-radius:999px;font-size:.78rem;font-weight:600;">
            {{ $pengajuanTerbaru->status_pembiayaan }}
        </span>
    </div>
    @endif

    {{-- ══ SECTION 3: RIWAYAT MUTASI ══ --}}
    <div class="section-title">Riwayat Mutasi (10 Terakhir)</div>

    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:2rem;">
        @if($mutasi->count())
        <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                    <th style="padding:.65rem 1rem;text-align:left;color:#475569;font-weight:600;">Waktu</th>
                    <th style="padding:.65rem 1rem;text-align:left;color:#475569;font-weight:600;">Jenis</th>
                    <th style="padding:.65rem 1rem;text-align:left;color:#475569;font-weight:600;">Rekening</th>
                    <th style="padding:.65rem 1rem;text-align:right;color:#475569;font-weight:600;">Jumlah</th>
                    <th style="padding:.65rem 1rem;text-align:right;color:#475569;font-weight:600;">Saldo Akhir</th>
                </tr>
            </thead>
            <tbody>
                @foreach($mutasi as $t)
                @php
                    // Arah mutasi mengikuti satu sumber kebenaran: Transaksi::isMasuk().
                    $isKurang = $t->isKeluar();
                @endphp
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:.6rem 1rem;color:#64748b;white-space:nowrap;">
                        {{ $t->waktu_transaksi ? $t->waktu_transaksi->format('d/m/Y H:i') : '-' }}
                    </td>
                    <td style="padding:.6rem 1rem;">
                        <span style="background:{{ !$isKurang ? '#d1fae5' : '#fee2e2' }};color:{{ !$isKurang ? '#065f46' : '#991b1b' }};padding:.15rem .55rem;border-radius:999px;font-size:.78rem;font-weight:600;">
                            {{ !$isKurang ? 'Mutasi Masuk' : 'Mutasi Keluar' }}
                        </span>
                        <div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem;">{{ $t->jenis_transaksi }}</div>
                    </td>
                    <td style="padding:.6rem 1rem;color:#64748b;font-family:monospace;font-size:.8rem;">
                        {{ $t->rekening?->no_rekening ?? '-' }}
                    </td>
                    <td style="padding:.6rem 1rem;text-align:right;font-weight:600;color:{{ !$isKurang ? '#059669' : '#dc2626' }};">
                        {{ !$isKurang ? '+' : '-' }} Rp {{ number_format($t->jumlah, 0, ',', '.') }}
                    </td>
                    <td style="padding:.6rem 1rem;text-align:right;color:#374151;">
                        Rp {{ number_format($t->saldo_setelah, 0, ',', '.') }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @else
        <div style="padding:2rem;text-align:center;color:#94a3b8;">Belum ada riwayat transaksi.</div>
        @endif
    </div>

</div>
@endsection
