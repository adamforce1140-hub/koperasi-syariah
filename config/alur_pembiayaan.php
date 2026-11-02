<?php

/**
 * Alur persetujuan (approval) pembiayaan — SATU-SATUNYA SUMBER KEBENARAN.
 *
 * Sebelumnya aturan berjenjang ("siapa menyetujui pada nominal berapa")
 * ditulis prosedural di dalam controller, lengkap dengan literal angka
 * rupiah sebagai fallback. Berkas ini memindahkan aturan tersebut menjadi
 * data yang dapat dibaca — sehingga mengubah kebijakan persetujuan tidak
 * lagi menuntut perubahan kode.
 *
 * Dibaca melalui App\Support\MatriksApproval — JANGAN memanggil `config()`
 * langsung dari controller/service agar aturan tidak bercabang (pola yang
 * sama dengan config/role_hierarki.php + App\Support\HierarkiRole).
 *
 * DEFAULT AMAN: seluruh nilai di bawah ini SENGAJA disetel SAMA PERSIS
 * dengan perilaku sistem saat ini. Membiarkan berkas ini apa adanya TIDAK
 * mengubah apa pun — angka pembiayaan, status, dan jurnal tetap identik.
 *
 * Sumber ambang nominal (dua lapis, sesuai arsitektur yang sudah ada):
 *   1. Kolom kebijakan koperasi (`kebijakan_koperasis`) — dapat diubah
 *      Pengurus dari UI tanpa deploy.
 *   2. `limit_default` di berkas ini — dipakai bila kolom kebijakan kosong,
 *      menggantikan literal yang sebelumnya tertanam di controller.
 *
 * Regulasi acuan: UU No. 25/1992 Pasal 30 (kewenangan Pengurus),
 * POJK No. 76/2016 (tata kelola), PSAK 108 (akuntansi transaksi syariah).
 */

return [

    /*
    |----------------------------------------------------------------------
    | Matriks persetujuan berjenjang per peran
    |----------------------------------------------------------------------
    | Setiap tahap mendeskripsikan:
    |   status_masuk   : status pembiayaan yang menjadi syarat tahap ini;
    |   status_keluar  : status berikutnya (tahap tanpa cabang nominal);
    |   kebijakan_limit: daftar kolom kebijakan yang dibaca BERURUTAN sebagai
    |                    ambang kewenangan penuh peran (yang pertama tidak
    |                    kosong yang dipakai);
    |   limit_default  : ambang fallback bila seluruh kolom kebijakan kosong;
    |   dalam_limit    : cabang bila nominal <= ambang (status, final, label);
    |   luar_limit     : cabang bila nominal > ambang (status, final, label).
    |
    | `final = true` berarti pembiayaan TIDAK naik ke peran berikutnya.
    */
    'persetujuan' => [

        'ao' => [
            'status_masuk' => 'pending_ao',
            'status_keluar' => 'pending_manajer',
            'label' => 'Diteruskan ke Manajer',
        ],

        'manajer' => [
            'status_masuk' => 'pending_manajer',
            'kebijakan_limit' => ['limit_manajer', 'limit_pembiayaan_manajer'],
            'limit_default' => 50000000,
            'dalam_limit' => [
                'status' => 'disetujui',
                'final' => true,
                'label' => 'Disetujui oleh Manajer',
            ],
            'luar_limit' => [
                'status' => 'pending_pengurus',
                'final' => false,
                'label' => 'Diteruskan ke Pengurus (Limit Besar)',
            ],
        ],

        'pengurus' => [
            'status_masuk' => 'pending_pengurus',
            'status_keluar' => 'pending_dps',
            'label' => 'Diteruskan ke DPS untuk audit syariah',
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | State machine status pembiayaan
    |----------------------------------------------------------------------
    | Peta transisi "dari status X boleh menuju status mana saja". Menjadi
    | SATU-SATUNYA SUMBER KEBENARAN daur hidup pembiayaan, sehingga alur
    | status tidak lagi tersebar sebagai rangkaian if/guard prosedural di
    | banyak controller/service.
    |
    | Dibaca melalui App\Support\AlurStatusPembiayaan (JANGAN memanggil
    | `config()` langsung dari controller).
    |
    | DEFAULT AMAN: peta ini MENGGAMBARKAN perilaku sistem saat ini secara
    | persis; ia bersifat DESKRIPTIF (tidak menolak transisi apa pun) sampai
    | pemanggil memilih memakainya sebagai penjaga. Membiarkan berkas ini apa
    | adanya TIDAK mengubah perilaku, angka, atau jurnal.
    |
    | Catatan: status terminal (lunas / ditolak / dibatalkan / hapus_buku)
    | sengaja berdaftar KOSONG — tidak ada transisi keluar.
    */
    'transisi' => [

        'pending_ao' => [
            'pending_manajer',
            'direview',
            'ditolak',
            'dibatalkan',
        ],

        'direview' => [
            'pending_ao',
            'pending_manajer',
            'disetujui',
            'ditolak',
            'dibatalkan',
        ],

        'pending_manajer' => [
            'pending_pengurus',
            'pending_dps',
            'disetujui',
            'pending_ao',
            'direview',
            'ditolak',
            'dibatalkan',
        ],

        'pending_pengurus' => [
            'pending_dps',
            'disetujui',
            'ditolak',
            'dibatalkan',
        ],

        'pending_dps' => [
            'disetujui',
            'disahkan',
            'pending_pencairan',
            'ditolak',
        ],

        'disetujui' => [
            'disahkan',
            'pending_pencairan',
            'dicairkan',
            'ditolak',
            'dibatalkan',
        ],

        'disahkan' => [
            'pending_pencairan',
            'dicairkan',
            'dibatalkan',
        ],

        'pending_pencairan' => [
            'dicairkan',
            'dibatalkan',
        ],

        'dicairkan' => [
            'aktif',
            'lunas',
            'macet',
            'restrukturisasi',
            'pending_restrukturisasi',
            'hapus_buku',
        ],

        'aktif' => [
            'lunas',
            'macet',
            'restrukturisasi',
            'pending_restrukturisasi',
            'hapus_buku',
        ],

        'macet' => [
            'aktif',
            'lunas',
            'restrukturisasi',
            'pending_restrukturisasi',
            'hapus_buku',
        ],

        'restrukturisasi' => [
            'aktif',
            'lunas',
            'macet',
            'pending_restrukturisasi',
            'hapus_buku',
        ],

        'pending_restrukturisasi' => [
            'restrukturisasi',
            'aktif',
            'macet',
            'ditolak',
        ],

        // ── Status terminal: tidak ada transisi keluar ──────────────────
        'lunas' => [],
        'ditolak' => [],
        'dibatalkan' => [],
        'hapus_buku' => [],

    ],

];
