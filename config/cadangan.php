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

];
