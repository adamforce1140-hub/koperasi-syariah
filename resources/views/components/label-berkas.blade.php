{{--
    Label formulir yang tertaut ke kolom isiannya (S-2).

    Latar belakang: banyak view memakai `placeholder` sebagai "label" karena
    praktis, padahal placeholder bukan label — ia hilang begitu pengguna mulai
    mengetik, kontrasnya rendah, tidak andal dibacakan pembaca layar, dan tidak
    berfungsi sebagai target ketuk (WCAG 1.3.1, 3.3.2, 4.1.2).

    Pemakaian:
        <x-label-berkas for="keterangan">
            Keterangan Transaksi
            <x-slot:penanda><span style="color:#dc2626;">*</span></x-slot:penanda>
        </x-label-berkas>
        <input id="keterangan" name="keterangan" …>

    `teks` boleh memuat `<span>`/`<small>` ber-`style` warna heksadesimal saja
    (lihat `App\Support\TeksLabelAman`); selain itu di-escape.

    `for` HARUS sama dengan `id` kolom isian. Test
    `LabelFormulirUxTest` memeriksa hal itu pada seluruh view.
--}}
@props(['for', 'teks' => null])

<label for="{{ $for }}" {{ $attributes->merge(['style' => 'display:block;font-size:0.78rem;font-weight:600;color:#475569;margin-bottom:.35rem;']) }}>
    @if($teks !== null)
        {!! \App\Support\TeksLabelAman::saring($teks) !!}
    @else
        {{ $slot }}
    @endif
</label>
