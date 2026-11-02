{{--
    Banner sapaan seragam untuk seluruh dashboard (audit T6).

    Variables (semua opsional kecuali $peran):
      $peran        Label peran di bawah nama pengguna. Wajib.
      $tema         'hijau' (default) | 'emas' | 'ungu'
      $nama         Nama yang ditampilkan. Default: nama user yang login.
      $salam        Kalimat pembuka. Default: 'Assalamualaikum,'
      $badge        Label singkat di sisi kanan, mis. 'READ-ONLY'
      $badgeKetika Keterangan kecil di bawah $badge.

    Pemakaian:
      @include('dashboard.partials.sapaan', ['peran' => 'Teller'])
--}}
<div class="sapaan sapaan--{{ $tema ?? 'hijau' }}">
    <div class="sapaan__identitas">
        <div class="sapaan__avatar">{{ strtoupper(mb_substr($nama ?? auth()->user()->name, 0, 1)) }}</div>
        <div class="sapaan__teks">
            <div class="sapaan__salam">{{ $salam ?? 'Assalamualaikum,' }}</div>
            <div class="sapaan__nama">{{ $nama ?? auth()->user()->name }}</div>
            <div class="sapaan__meta">{{ $peran }} · {{ now()->translatedFormat('l, d F Y') }}</div>
        </div>
    </div>
    @if(! empty($badge))
        <div class="sapaan__badge">
            <div class="sapaan__badge-judul">{{ $badge }}</div>
            @if(! empty($badgeKetika))
                <div class="sapaan__badge-keterangan">{{ $badgeKetika }}</div>
            @endif
        </div>
    @endif
</div>