<?php

/**
 * Konfigurasi modul pembiayaan.
 *
 * `akad_default` dipakai saat kolom `akad_pembiayaan` kosong (data lama atau
 * input yang tidak menyertakan akad). Sebelumnya nilai ini ditulis sebagai
 * literal `'Murabahah'` di 10 tempat sehingga perubahan akad default harus
 * mengubah kode (DYN-09). Nilai di sini divalidasi terhadap daftar akad aktif
 * (`Pembiayaan::daftarAkad()`); bila tidak dikenal, sistem jatuh ke Murabahah.
 */
return [

    'akad_default' => 'Murabahah',

];
