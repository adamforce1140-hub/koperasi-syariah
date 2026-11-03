<nav class="sidebar" id="sidebarUtama" aria-label="Navigasi utama">
    <div class="sidebar-brand">
        {{-- Logo: huruf K bergaya geometris + simpul islami, tanpa teks agar tetap tajam di ukuran kecil --}}
        <span class="brand-mark">
            <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Logo Koperasi Syariah">
                <rect width="44" height="44" rx="13" fill="#fff"/>
                {{-- Batang K --}}
                <path d="M15.5 12v20" stroke="var(--tema-ke)" stroke-width="3.4" stroke-linecap="round"/>
                {{-- Dua lengan K yang bertemu di simpul --}}
                <path d="M28.5 12l-9 10 9 10" stroke="var(--tema-dari)" stroke-width="3.4"
                      stroke-linecap="round" stroke-linejoin="round"/>
                {{-- Titik simpul sebagai aksen --}}
                <circle cx="28.5" cy="22" r="2.6" fill="var(--tema-aksen-solid)"/>
            </svg>
        </span>
        <div class="sidebar-brand-text">
            <strong>{{ config('app.name', 'Koperasi Syariah') }}</strong>
            <span>Sistem Digital</span>
        </div>
    </div>

    <div class="sidebar-nav">
        @php
            // Gunakan $navMenu dari ShareNavigationMenu middleware,
            // fallback ke NavigationService langsung jika belum di-share
            $menu = $navMenu ?? \App\Services\NavigationService::getMenu(auth()->user()->role ?? '');
        @endphp

        @forelse($menu as $item)
            @if(!empty($item['children']))
                {{-- Section dengan submenu --}}
                <div class="nav-section">
                    @if(\App\Services\NavigationService::adaIkon($item['icon'] ?? null))
                        <span style="margin-right:0.35rem;">{{ \App\Services\NavigationService::ikon($item['icon']) }}</span>
                    @endif
                    {{ $item['label'] }}
                </div>
                @foreach($item['children'] as $child)
                    @php
                        try {
                            $childUrl = route($child['route']);
                            $isActive = request()->routeIs($child['route']);
                        } catch (\Exception $e) {
                            $childUrl = '#';
                            $isActive = false;
                        }
                        $childIcon = \App\Services\NavigationService::adaIkon($child['icon'] ?? null)
                            ? \App\Services\NavigationService::ikon($child['icon'])
                            : '›';
                        $childBadge = !empty($child['badge'])
                            ? \App\Services\NavigationService::badge($child['badge'])
                            : 0;
                    @endphp
                    <a href="{{ $childUrl }}" class="nav-item {{ $isActive ? 'active' : '' }}"
                       @if($isActive) aria-current="page" @endif>
                        <span class="icon" aria-hidden="true">{{ $childIcon }}</span>
                        {{ $child['label'] }}
                        @if($childBadge > 0)
                            <span aria-hidden="true" style="margin-left:auto;background:#ef4444;color:#fff;font-size:0.7rem;
                                font-weight:700;padding:0.1rem 0.45rem;border-radius:9999px;">{{ $childBadge }}</span>
                            <span class="sr-only">({{ $childBadge }} menunggu tindakan)</span>
                        @endif
                    </a>
                @endforeach
            @else
                {{-- Menu langsung tanpa submenu --}}
                @php
                    try {
                        $itemUrl = route($item['route']);
                        $isActive = request()->routeIs($item['route']);
                    } catch (\Exception $e) {
                        $itemUrl = '#';
                        $isActive = false;
                    }
                    $itemIcon = \App\Services\NavigationService::ikon($item['icon'] ?? null);
                @endphp
                <a href="{{ $itemUrl }}" class="nav-item {{ $isActive ? 'active' : '' }}"
                   @if($isActive) aria-current="page" @endif>
                    <span class="icon" aria-hidden="true">{{ $itemIcon }}</span>
                    {{ $item['label'] }}
                </a>
            @endif
        @empty
            <div class="nav-section">Menu</div>
            {{-- Fallback bila NavigationService tidak mengembalikan menu:
                 arahkan ke "/" yang meneruskan ke dashboard sesuai peran. --}}
            <a href="{{ url('/') }}" class="nav-item">Dashboard</a>
        @endforelse
    </div>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="avatar" aria-hidden="true">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div>
                <div class="user-name">{{ auth()->user()->name }}</div>
                <div class="user-role">
                    {{ \App\Support\HierarkiRole::label(auth()->user()->role) }}
                </div>
            </div>
        </div>
    </div>
</nav>
