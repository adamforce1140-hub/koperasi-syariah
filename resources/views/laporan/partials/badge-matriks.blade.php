{{--
    Badge hak akses untuk satu sel Matriks Hak Akses.
    Satu sel = hanya badge hak yang benar-benar diizinkan; bila tidak ada hak
    sama sekali, tampil SATU badge abu "Tidak ada akses".

    Warna badge mengikuti standar .badge global (layouts/app.blade.php):
    Lihat=hijau, Unduh=biru, Edit=amber, Hapus=merah, Ekspor=teal, Verifikasi=ungu.

    Dipakai oleh laporan/audit.blade.php (render awal). Markup-nya harus identik
    dengan yang dibangun resources/views/laporan/audit.blade.php pada blok JS
    refresh dinamis, agar hasil polling sama persis dengan render server.

    @param array<int|string, string> $hakModul       daftar kode hak yang diizinkan
    @param array<string, string>     $labelAktivitas peta kode hak => label
--}}
@php
    $kelasHak = [
        'lihat'      => '',
        'unduh'      => 'mh-unduh',
        'edit'       => 'mh-edit',
        'hapus'      => 'mh-hapus',
        'ekspor'     => 'mh-ekspor',
        'verifikasi' => 'mh-verifikasi',
    ];
    $hakDiizinkan = array_values(array_intersect(
        array_keys($labelAktivitas),
        $hakModul ?? []
    ));
@endphp
@forelse($hakDiizinkan as $kodeAksi)
    <span class="matriks-hak {{ $kelasHak[$kodeAksi] ?? '' }}">{{ $labelAktivitas[$kodeAksi] }}</span>
@empty
    <span class="matriks-hak mh-tidak">Tidak ada akses</span>
@endforelse
