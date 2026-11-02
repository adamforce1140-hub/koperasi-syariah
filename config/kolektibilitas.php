<?php

/*
|----------------------------------------------------------------------
| Label & gaya badge kolektibilitas pembiayaan
|----------------------------------------------------------------------
| Kunci di bawah ini mengikuti kode yang dihasilkan
| `Setting::kolektibilitasDariHari()`: kol1..kol4 dari ambang hari pada
| kebijakan koperasi, dan kol5 sebagai kategori terburuk (Macet).
|
| Sebelumnya label & kelas Tailwind dikunci di dalam `ManajerController`
| (DYN-06) sehingga menambah/menggabung kategori mengharuskan perubahan
| kode. Kini cukup mengubah peta ini — ambang hari dan persentase CKPN
| tetap dibaca dari `Setting`.
|
| `default` dipakai bila kode yang diminta tidak dikenal, agar perilaku
| lama (kode asing ditampilkan sebagai Macet) tetap terjaga.
*/

return [

    'default' => 'kol5',

    'label' => [
        'kol1' => 'Lancar (Kol-1)',
        'kol2' => 'Dalam Perhatian Khusus (Kol-2)',
        'kol3' => 'Kurang Lancar (Kol-3)',
        'kol4' => 'Diragukan (Kol-4)',
        'kol5' => 'Macet (Kol-5)',
    ],

    /*
    | Setiap entri: [kelas Tailwind, inline style cadangan].
    | Inline style dipakai agar warna tetap tampil meski build Tailwind
    | tidak memuat kelas dinamis.
    */
    'badge' => [
        'kol1' => ['bg-green-100 text-green-800', 'background:#dcfce7;color:#15803d;'],
        'kol2' => ['bg-yellow-100 text-yellow-800', 'background:#fef9c3;color:#ca8a04;'],
        'kol3' => ['bg-orange-100 text-orange-800', 'background:#ffedd5;color:#c2410c;'],
        'kol4' => ['bg-red-100 text-red-800', 'background:#fee2e2;color:#dc2626;'],
        'kol5' => ['bg-purple-100 text-purple-800', 'background:#f3e8ff;color:#7e22ce;'],
    ],

];
