{{-- Panel Pengaturan Sistem Super Admin: ringkasan konfigurasi inti
     koperasi (jumlah pengguna per peran, produk, jenis simpanan, limit,
     kebijakan) beserta pintasan ke modul pengelolaan sistem. --}}
@php
    $pintasan = [
        ['label' => 'Kelola Pengguna &amp; Role', 'route' => 'pengurus.users.index',        'ikon' => '👤'],
        ['label' => 'Produk Pembiayaan &amp; Akad', 'route' => 'pengurus.produk.index',    'ikon' => '💳'],
        ['label' => 'Jenis Simpanan',             'route' => 'pengurus.jenis-simpanan.index', 'ikon' => '🏦'],
        ['label' => 'Kebijakan Operasional',      'route' => 'pengurus.kebijakan.index',   'ikon' => '📜'],
        ['label' => 'Pengaturan Aplikasi',        'route' => 'admin.settings.index',       'ikon' => '⚙️'],
    ];
@endphp
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">⚙️ Pengaturan Sistem</span>
        <span style="font-size:0.74rem;color:#94a3b8;">
            {{ number_format($sistem['totalPengguna'] ?? 0, 0, ',', '.') }} pengguna terdaftar
        </span>
    </div>

    {{-- Ringkasan konfigurasi --}}
    <div class="sys-grid">
        <div class="sys-cell">
            <div class="sys-val">{{ number_format($sistem['produkAktif'] ?? 0, 0, ',', '.') }}</div>
            <div class="sys-cap">Produk Pembiayaan Aktif</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val">{{ number_format($sistem['jenisSimpanan'] ?? 0, 0, ',', '.') }}</div>
            <div class="sys-cap">Jenis Simpanan</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val">{{ number_format($sistem['limitTeller'] ?? 0, 0, ',', '.') }}</div>
            <div class="sys-cap">Parameter Limit</div>
        </div>
        <div class="sys-cell">
            <div class="sys-val">{{ number_format($sistem['kebijakanAktif'] ?? 0, 0, ',', '.') }}</div>
            <div class="sys-cap">Kebijakan Tercatat</div>
        </div>
    </div>

    {{-- Sebaran pengguna per peran --}}
    <div style="margin-top:1rem;">
        <div class="fin-cap" style="margin-bottom:0.5rem;">Pengguna per Peran</div>
        <div class="peran-list">
            @foreach(($sistem['peran'] ?? []) as $slug => $meta)
                <span class="peran-chip">{{ $meta['label'] }} · {{ number_format($meta['jumlah'], 0, ',', '.') }}</span>
            @endforeach
        </div>
    </div>

    {{-- Pintasan modul pengelolaan sistem --}}
    <div style="margin-top:1.1rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
        @foreach($pintasan as $p)
            @if(\Illuminate\Support\Facades\Route::has($p['route']))
                <a href="{{ route($p['route']) }}" class="btn"
                   style="font-size:0.76rem;padding:0.42rem 0.8rem;background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;">
                    {{ $p['ikon'] }} {!! $p['label'] !!}
                </a>
            @endif
        @endforeach
    </div>
</div>
