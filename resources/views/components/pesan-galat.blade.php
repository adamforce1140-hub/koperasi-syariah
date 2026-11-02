@props(['errors'])

{{--
    Komponen umpan balik validasi yang dapat dipakai ulang.

    LATAR BELAKANG (audit UX tingkat keempat):
    Beberapa halaman — mis. Simulasi & Pembagian SHU — memakai penanda
    `id`/`data-attribute` yang sama berulang untuk setiap baris tabel, sehingga
    HTML menjadi tidak valid dan JavaScript yang mengandalkannya mengambil
    elemen yang salah. Komponen ini mengekstraksi daftar error validasi ke
    markup tunggal yang bersih, tanpa id, sehingga tidak ada id ganda.

    Pemakaian:
        <x-pesan-galat :errors="$errors" />

    Komponen ini AMAN dipanggil tanpa guard `@if($errors->any())` karena bila
    tidak ada error ia tidak merender apa pun (mencegah kotak alert kosong yang
    membingungkan dan berisik bagi pembaca layar).
--}}
@if($errors->any())
<div {{ $attributes->merge(['class' => 'alert-shu danger', 'role' => 'alert']) }}>
    <span aria-hidden="true">&#9888;</span>
    <div>
        @foreach($errors->all() as $pesan)
            <div>{{ $pesan }}</div>
        @endforeach
    </div>
</div>
@endif
