<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identitas Koperasi untuk Dokumen Cetak
    |--------------------------------------------------------------------------
    |
    | Dipakai sebagai kop resmi pada dokumen PDF (mis. Bilyet Simpanan
    | Berjangka). Identitas ini sengaja disimpan di konfigurasi — bukan di
    | database — karena tabel `kebijakan_koperasis` belum menyediakan kolom
    | alamat/telepon, sedangkan kop dokumen resmi wajib lengkap.
    |
    | Nilai dapat ditimpa lewat .env tanpa mengubah kode:
    |   KOPERASI_NAMA, KOPERASI_ALAMAT, KOPERASI_KOTA, KOPERASI_TELEPON,
    |   KOPERASI_EMAIL, KOPERASI_LOGO
    |
    */

    'koperasi' => [
        'nama' => env('KOPERASI_NAMA', 'KOPERASI SYARIAH SEJAHTERA BERSAMA'),
        'alamat' => env('KOPERASI_ALAMAT', 'Jl. Raya Syariah No. 1, Kompleks Perkantoran Madani'),
        'kota' => env('KOPERASI_KOTA', 'Jakarta'),
        'kode_pos' => env('KOPERASI_KODE_POS', '10110'),
        'telepon' => env('KOPERASI_TELEPON', '(021) 555-0123'),
        'email' => env('KOPERASI_EMAIL', 'koperasi@syariahbersama.co.id'),
        'website' => env('KOPERASI_WEBSITE', 'www.syariahbersama.co.id'),

        /*
         * Badan hukum: nomor izin usaha koperasi. Bila dikosongkan, baris ini
         * tidak dirender sama sekali pada kop dokumen.
         */
        'badan_hukum' => env('KOPERASI_BADAN_HUKUM', 'Nomor Induk Koperasi: 1234/BH/XVI.4/2024'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logo Koperasi
    |--------------------------------------------------------------------------
    |
    | Path relatif terhadap direktori `public/`. Bila file tidak ditemukan,
    | bilyet otomatis memakai emblem monogram HTML/CSS sebagai gantinya
    | sehingga dokumen tetap tercetak rapi tanpa aset gambar.
    |
    */

    'logo' => env('KOPERASI_LOGO', 'images/logo-koperasi.png'),

    /*
    |--------------------------------------------------------------------------
    | Pengaturan Halaman Bilyet
    |--------------------------------------------------------------------------
    |
    | Format A4 portrait dengan margin 2 cm di semua sisi.
    | Ukuran margin dinyatakan dalam satuan milimeter sesuai standar dompdf.
    |
    */

    'bilyet' => [
        'paper' => env('BILYET_PAPER', 'a4'),
        'orientation' => env('BILYET_ORIENTATION', 'portrait'),
        'margin' => [
            'top' => 20,
            'right' => 20,
            'bottom' => 20,
            'left' => 20,
        ],
        'font' => env('BILYET_FONT', 'DejaVu Sans'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jabatan Penandatangan Bilyet
    |--------------------------------------------------------------------------
    |
    | Nama pejabat diambil dari pengguna yang mencetak dokumen, sedangkan
    | jabatan mengikuti peran (role) pengguna tersebut.
    |
    | SUMBER PUSAT: pemetaan peran → jabatan kini hidup di
    | `config/role_hierarki.php` (kunci `jabatan`) dan dibaca lewat
    | `App\Support\HierarkiRole::jabatan()`. Nilai di bawah ini diturunkan
    | otomatis agar tetap kompatibel dengan pemanggil `config('pdf.jabatan')`,
    | dan TIDAK boleh disunting manual (tambahkan peran di role_hierarki).
    |
    */

    'jabatan' => (static function (): array {
        // Baca langsung file sumber (bukan config() — binding belum siap saat
        // konfigurasi dimuat). Hasilnya array murni sehingga aman di-cache.
        $peran = (require __DIR__.'/role_hierarki.php')['peran'] ?? [];
        $peta = [];

        foreach ($peran as $kunci => $p) {
            $peta[$kunci] = $p['jabatan'] ?? ($p['label'] ?? $kunci);
        }

        return $peta;
    })(),

];
