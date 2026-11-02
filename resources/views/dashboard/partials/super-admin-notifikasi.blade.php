{{-- Panel notifikasi Super Admin: kegiatan penting yang menunggu
     persetujuan/penanganan. `totalMenunggu` dihitung dari antrean nyata
     (pembiayaan, pengajuan, autodebit, tazir, opname kas, terminasi). --}}
<div class="card" style="margin-bottom:0;">
    <div class="card-header">
        <span class="card-title">
            🔔 Notifikasi Persetujuan
            @if(($totalMenunggu ?? 0) > 0)
                <span class="badge badge-amber" style="margin-left:0.5rem;">{{ $totalMenunggu }} menunggu</span>
            @else
                <span class="badge badge-green" style="margin-left:0.5rem;">Semua tertangani</span>
            @endif
        </span>
        <span style="font-size:0.74rem;color:#94a3b8;">Pembaruan otomatis · real-time</span>
    </div>

    <div class="notif-list">
        @foreach($antrean['item'] as $n)
            @php
                $adaTujuan = ! empty($n['route']) && \Illuminate\Support\Facades\Route::has($n['route']);
                $tag = $adaTujuan ? 'a' : 'div';
            @endphp
            <{{ $tag }}
                @if($adaTujuan) href="{{ route($n['route']) }}" @endif
                class="notif-card {{ $n['jumlah'] > 0 ? '' : 'notif-card--kosong' }}"
                data-warna="{{ $n['warna'] }}">
                <span class="notif-icon" aria-hidden="true">{{ $n['ikon'] }}</span>
                <span class="notif-body">
                    <span class="notif-jumlah">{{ number_format($n['jumlah'], 0, ',', '.') }}</span>
                    <span class="notif-label">{{ $n['label'] }}</span>
                </span>
            </{{ $tag }}>
        @endforeach
    </div>
</div>
