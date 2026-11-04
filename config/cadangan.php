<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enkripsi Cadangan Basis Data (R6)
    |--------------------------------------------------------------------------
    |
    | Cadangan memuat seluruh jurnal, saldo, dan PII anggota. Berkas disimpan
    | dalam bentuk terenkripsi (.sql.gz.enc) memakai XChaCha20-Poly1305.
    |
    | `kunci` adalah base64 dari tepat 32 byte. Bila kosong, kunci diturunkan
    | dari APP_KEY dengan HKDF-SHA256. Tanpa kunci yang benar, cadangan tidak
    | dapat dipulihkan — simpan salinan kunci di tempat terpisah dari berkas
    | cadangan.
    |
    */

    'enkripsi' => env('BACKUP_ENKRIPSI', true),

    'kunci' => env('BACKUP_ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Direktori Cadangan Lokal
    |--------------------------------------------------------------------------
    |
    | Satu sumber untuk pembuat cadangan (`db:cadangkan`) dan pemeriksa
    | kesegaran cadangan (`denyut:pemeriksaan`). Bila keduanya membaca lokasi
    | berbeda, pemantauan dapat melaporkan "tidak ada cadangan" padahal
    | cadangan dibuat setiap hari.
    |
    */

    'direktori' => env('BACKUP_DIREKTORI') ?: storage_path('app/backups'),

    /*
    |--------------------------------------------------------------------------
    | Direktori Salinan Offsite
    |--------------------------------------------------------------------------
    |
    | Cadangan yang hanya ada di satu disk tidak melindungi dari kerusakan
    | disk itu sendiri. Isi dengan jalur disk kedua, USB, atau share jaringan
    | pada host cadangan. Kosongkan bila belum tersedia — cadangan lokal tetap
    | dibuat, hanya tanpa salinan kedua. Berkas yang disalin sudah terenkripsi.
    |
    */

    'salinan_direktori' => env('BACKUP_SALINAN_DIREKTORI') ?: null,

];
