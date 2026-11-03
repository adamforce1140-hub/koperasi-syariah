@extends('layouts.app')
@section('page-title', "Kartu Anggota")

@section('title', 'Kartu Anggota — ' . $anggota->nama_lengkap)

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
/* ── Kartu Anggota Digital ─────────────────────── */
.member-card-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.5rem;
}
.member-card {
    width: 360px;
    background: linear-gradient(135deg, #065f46 0%, #047857 55%, #059669 100%);
    border-radius: 18px;
    padding: 1.5rem 1.6rem;
    color: #fff;
    box-shadow: 0 8px 32px rgba(6,95,70,.35);
    position: relative;
    overflow: hidden;
    font-family: 'Segoe UI', sans-serif;
}
.member-card::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    background: rgba(255,255,255,.08);
    border-radius: 50%;
}
.member-card::after {
    content: '';
    position: absolute;
    bottom: -60px; left: -30px;
    width: 220px; height: 220px;
    background: rgba(255,255,255,.05);
    border-radius: 50%;
}
.card-logo {
    display: flex;
    align-items: center;
    gap: .6rem;
    margin-bottom: 1.2rem;
    position: relative;
    z-index: 1;
}
.card-logo-icon {
    width: 36px; height: 36px;
    background: rgba(255,255,255,.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
}
.card-logo-text {
    font-size: .85rem;
    font-weight: 700;
    letter-spacing: .04em;
    line-height: 1.3;
}
.card-logo-sub {
    font-size: .7rem;
    opacity: .75;
    font-weight: 400;
}
.card-name {
    font-size: 1.15rem;
    font-weight: 700;
    letter-spacing: .02em;
    position: relative;
    z-index: 1;
    margin-bottom: .2rem;
}
.card-no {
    font-size: .75rem;
    opacity: .8;
    font-family: monospace;
    letter-spacing: .08em;
    position: relative;
    z-index: 1;
    margin-bottom: 1rem;
}
.card-details {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(230px,1fr));
    gap: .5rem .75rem;
    position: relative;
    z-index: 1;
    margin-bottom: 1.1rem;
}
.card-detail-label {
    font-size: 0.7rem;
    opacity: .65;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: .1rem;
}
.card-detail-value {
    font-size: .82rem;
    font-weight: 600;
}
.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    position: relative;
    z-index: 1;
    border-top: 1px solid rgba(255,255,255,.2);
    padding-top: .75rem;
}
.card-badge {
    font-size: .7rem;
    background: rgba(255,255,255,.15);
    padding: .2rem .55rem;
    border-radius: 999px;
    font-weight: 600;
}
.qr-box {
    background: #fff;
    border-radius: 8px;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qr-box img {
    width: 52px;
    height: 52px;
    display: block;
}

/* ── Info Panel ──────────────────────────────── */
.info-panel {
    width: 360px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 1.25rem 1.5rem;
    font-size: .9rem;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: .45rem 0;
    border-bottom: 1px solid #f1f5f9;
    color: #374151;
}
.info-row:last-child { border-bottom: none; }
.info-label { color: #64748b; font-size: .85rem; }
.info-value { font-weight: 600; text-align: right; }

/* ── Print ───────────────────────────────────── */
@media print {
    body * { visibility: hidden; }
    .printable, .printable * { visibility: visible; }
    .printable { position: fixed; top: 0; left: 0; width: 100%; }
    .no-print { display: none !important; }
}
</style>

<div style="max-width:520px;margin:0 auto;">

    {{-- Breadcrumb --}}
    <div style="margin-bottom:1.25rem;font-size:.875rem;color:#64748b;" class="no-print">
        <a href="{{ route('teller.anggota.index') }}" style="color:#2563eb;text-decoration:none;">Data Anggota</a>
        <span style="margin:0 .4rem;">/</span>
        <span>Kartu Anggota</span>
    </div>

    {{-- Tombol Cetak --}}
    <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;" class="no-print">
        <button onclick="window.print()"
                style="background:#7c3aed;color:#fff;padding:.55rem 1.2rem;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:.9rem;">
            🖨️ Cetak Kartu
        </button>
    </div>

    <div class="member-card-wrap printable">

        {{-- ══ KARTU ANGGOTA DIGITAL ══ --}}
        <div class="member-card">
            <div class="card-logo">
                <div class="card-logo-icon">🌿</div>
                <div>
                    <div class="card-logo-text">KOPERASI SYARIAH</div>
                    <div class="card-logo-sub">Kartu Tanda Anggota</div>
                </div>
            </div>

            <div class="card-name">{{ strtoupper($anggota->nama_lengkap) }}</div>
            <div class="card-no">No. {{ $anggota->no_anggota ?? 'Belum ditetapkan' }}</div>

            {{-- NIK penuh --}}
            <div style="position:relative;z-index:1;margin-bottom:.6rem;">
                <div class="card-detail-label">NIK</div>
                <div class="card-detail-value" style="font-family:monospace;font-size:.8rem;letter-spacing:.08em;">
                    {{ $anggota->nik }}
                </div>
            </div>

            <div class="card-details">
                <div>
                    <div class="card-detail-label">Tempat, Tgl Lahir</div>
                    <div class="card-detail-value" style="font-size:.76rem;">
                        {{ $anggota->tempat_lahir ?? '-' }},
                        {{ $anggota->tanggal_lahir ? $anggota->tanggal_lahir->translatedFormat('d M Y') : '-' }}
                    </div>
                </div>
                <div>
                    <div class="card-detail-label">Jenis Kelamin</div>
                    <div class="card-detail-value">{{ $anggota->jenis_kelamin }}</div>
                </div>
                <div>
                    <div class="card-detail-label">No. HP</div>
                    <div class="card-detail-value" style="font-size:.78rem;">{{ $anggota->no_hp ?? '-' }}</div>
                </div>
                <div>
                    <div class="card-detail-label">Pekerjaan</div>
                    <div class="card-detail-value" style="font-size:.78rem;">{{ $anggota->pekerjaan ?? '-' }}</div>
                </div>
            </div>

            {{-- Alamat --}}
            <div style="position:relative;z-index:1;margin-bottom:.9rem;">
                <div class="card-detail-label">Alamat</div>
                <div class="card-detail-value" style="font-size:.76rem;line-height:1.4;">
                    {{ $anggota->alamat }}{{ $anggota->kota ? ', ' . $anggota->kota : '' }}{{ $anggota->provinsi ? ', ' . $anggota->provinsi : '' }}
                </div>
            </div>

            <div class="card-footer">
                <div>
                    <span class="card-badge">&#10003; {{ $anggota->status_anggota }}</span>
                    <div style="font-size:0.7rem;opacity:.65;margin-top:.3rem;">
                        Bergabung: {{ $anggota->tanggal_gabung ? $anggota->tanggal_gabung->format('d M Y') : '-' }}<br>
                        Berlaku: Seumur Hidup
                    </div>
                </div>
                {{-- QR Code via api.qrserver.com (andal, gratis, tidak butuh library) --}}
                <div class="qr-box">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=60x60&data={{ urlencode($anggota->no_anggota ?? $anggota->nik) }}&format=png"
                         alt="QR {{ $anggota->no_anggota }}"
                         width="60" height="60">
                </div>
            </div>
        </div>

        {{-- ══ INFO REKENING ══ --}}
        <div class="info-panel no-print">
            <h4 style="margin:0 0 .75rem;font-size:.95rem;color:#1e293b;font-weight:700;">Rekening Simpanan</h4>
            @forelse($anggota->rekening as $rek)
            <div class="info-row">
                <div>
                    <div class="info-label">{{ $rek->jenis_rekening }}</div>
                    <div style="font-size:.78rem;color:#94a3b8;font-family:monospace;">{{ $rek->no_rekening }}</div>
                </div>
                <div class="info-value" style="color:#059669;">
                    Rp {{ number_format($rek->saldo, 0, ',', '.') }}
                </div>
            </div>
            @empty
            <p style="color:#94a3b8;font-size:.875rem;margin:0;">Belum ada rekening.</p>
            @endforelse

            @if($anggota->rekening->count() > 0)
            <div style="display:flex;justify-content:space-between;padding:.55rem 0 0;font-size:.85rem;font-weight:700;">
                <span style="color:#374151;">Total Simpanan</span>
                <span style="color:#059669;">Rp {{ number_format($anggota->total_saldo_simpanan, 0, ',', '.') }}</span>
            </div>
            @endif
        </div>

    </div>

</div>
@endsection
