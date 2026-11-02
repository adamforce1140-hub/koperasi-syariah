<?php

/**
 * Konfigurasi produk simpanan — melengkapi master `jenis_simpanans`.
 *
 * `auto_debit_kode` menentukan KODE produk yang boleh mengaktifkan auto-debit
 * pindah buku. Sebelumnya daftar ini berupa konstanta di dalam model sehingga
 * menambah produk memerlukan deploy (DYN-15). Nilai di sini adalah DEFAULT
 * AMAN: hanya WAJIB, sama persis dengan perilaku sistem sebelumnya.
 *
 * Perubahan daftar tetap dijaga RBAC (hanya Manajer/Pengurus) dan kolom
 * `jenis_simpanans.allow_auto_debit` per produk.
 */
return [

    'auto_debit_kode' => ['WAJIB'],

];
