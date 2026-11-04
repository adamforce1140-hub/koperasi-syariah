<?php

/**
 * Definisi navigasi (sidebar) sebagai DATA, bukan kode.
 *
 * Sebelumnya menu ditulis sebagai `match ($role)` + delapan metode privat di
 * App\Services\NavigationService. Akibatnya peran baru yang dikenal
 * `config/role_hierarki.php` tetapi belum punya metode menu akan jatuh ke
 * `default => []` dan melihat sidebar KOSONG tanpa pesan kesalahan.
 *
 * Dengan file ini:
 *   - menambah peran cukup menambah entri `menu.<peran>` (tanpa mengubah kode);
 *   - ikon & warna badge juga data, bukan ditulis di Blade;
 *   - `alias` menjaga kompatibilitas nilai peran lama (mis. `nasabah`).
 *
 * Struktur satu item menu:
 *   ['label' => ..., 'route' => ..., 'icon' => ..., 'children' => [...]]
 * Item anak boleh menambahkan `'badge' => '<kunci>'`; kunci tersebut
 * diselesaikan oleh NavigationService::badge().
 */
return [

    /*
    |----------------------------------------------------------------------
    | Alias nilai peran
    |----------------------------------------------------------------------
    | Nilai `users.role` untuk pengguna swalayan adalah `anggota`. Alias
    | `nasabah` dipertahankan agar data lama tidak kehilangan menu.
    */
    'alias' => [
        'nasabah' => 'anggota',
    ],

    /*
    |----------------------------------------------------------------------
    | Peta ikon: nama logis -> emoji
    |----------------------------------------------------------------------
    */
    'ikon' => [
        'home' => '🏠',
        'credit-card' => '💳',
        'list' => '📋',
        'archive' => '🗄️',
        'file-text' => '📄',
        'clipboard' => '📎',
        'activity' => '📊',
        'alert-triangle' => '⚠️',
        'check-circle' => '✅',
        'shield' => '🛡️',
        'bar-chart-2' => '📈',
        'eye' => '👁️',
        'check-square' => '☑️',
        'users' => '👥',
        'user' => '👤',
        'piggy-bank' => '🏦',
        'file-plus' => '📑',
        'trending-up' => '📉',
        'dollar-sign' => '💰',
        'settings' => '⚙️',
        'package' => '📦',
        'edit-3' => '✏️',
        'search' => '🔍',
        'bell' => '🔔',
        'calendar' => '📅',
        // Ikon yang sebelumnya hanya dipakai di menu namun tidak ada di peta
        // lama (jatuh ke fallback `›`). Dilengkapi agar seluruh ikon menu
        // benar-benar berasal dari konfigurasi.
        'award' => '🏅',
        'book-open' => '📖',
        'heart' => '💚',
        'bi-file-earmark-bar-graph' => '📊',
        'bi-bar-chart-line' => '📈',
        'bi-journal-text' => '📒',
        'bi-journal-check' => '📗',
        'bi-shield-check' => '🛡️',
        'bi-safe2' => '🔐',
    ],

    /*
    |----------------------------------------------------------------------
    | Warna badge per peran (dipakai tabel Limit Transaksi, bukan kode warna
    | yang ditulis langsung di Blade).
    |----------------------------------------------------------------------
    */
    'warna_role' => [
        'super_admin' => 'badge-purple',
        'ao' => 'badge-green',
        'manajer' => 'badge-blue',
        'pengurus' => 'badge-teal',
        'teller' => 'badge-amber',
    ],
    'warna_role_default' => 'badge-purple',

    /*
    |----------------------------------------------------------------------
    | Menu per peran
    |----------------------------------------------------------------------
    */
    'menu' => [

        // ─────────────────────────────────────────
        // TELLER — transaksi kasir, riwayat harian, rekap kas.
        // Dilarang: approval pinjaman, survei kredit, laporan keuangan.
        // ─────────────────────────────────────────
        'teller' => [
            ['label' => 'Dashboard', 'route' => 'dashboard.teller', 'icon' => 'home', 'children' => []],
            [
                'label' => 'Kas Loket',
                'icon' => 'package',
                'children' => [
                    ['label' => 'Buka Kas (Opening)',  'icon' => 'check-circle', 'route' => 'teller.kas.opening'],
                    ['label' => 'Rekap Kas (Closing)', 'icon' => 'archive',      'route' => 'teller.kas.closing'],
                ],
            ],
            [
                'label' => 'Data CIF Anggota',
                'icon' => 'users',
                'children' => [
                    ['label' => 'Cari Data CIF / Anggota', 'icon' => 'search', 'route' => 'teller.cif.index'],
                    ['label' => 'Daftar Anggota (Lengkap)', 'icon' => 'list',  'route' => 'teller.anggota.index'],
                ],
            ],
            [
                'label' => 'Exit Keanggotaan',
                'icon' => 'user',
                'children' => [
                    ['label' => 'Ajukan Exit / Penutupan CIF', 'icon' => 'file-plus', 'route' => 'member-terminations.create'],
                    ['label' => 'Daftar Pengunduran Diri',     'icon' => 'file-text', 'route' => 'member-terminations.index'],
                ],
            ],
            [
                'label' => 'Transaksi Loket',
                'icon' => 'credit-card',
                'children' => [
                    ['label' => 'Setoran Tunai',                 'icon' => 'trending-up',    'route' => 'teller.setor'],
                    ['label' => 'Penarikan Tunai',               'icon' => 'dollar-sign',    'route' => 'teller.tarik'],
                    ['label' => 'Bayar Angsuran',                'icon' => 'check-square',   'route' => 'teller.angsuran'],
                    ['label' => 'Pencairan Pembiayaan',          'icon' => 'check-circle',   'route' => 'teller.pencairan.index'],
                    ['label' => 'Pengeluaran Beban Operasional', 'icon' => 'dollar-sign',    'route' => 'pengeluaran-beban.index'],
                    ['label' => 'Keringanan Ta\'zir',            'icon' => 'alert-triangle', 'route' => 'teller.keringanan-tazir.index'],
                    // Pusat Konfirmasi Auto-Debit SENGAJA tidak di sini:
                    // konfirmasi/eksekusi auto-debit adalah fungsi back-office
                    // (manajer/admin), bukan tugas kasir (segregation of duties).
                ],
            ],
            [
                'label' => 'Pelaporan Harian',
                'icon' => 'bar-chart-2',
                'children' => [
                    ['label' => 'Riwayat Transaksi',   'icon' => 'list',    'route' => 'teller.riwayat'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // ACCOUNT OFFICER — pengajuan, survei, tracking, tunggakan.
        // ─────────────────────────────────────────
        'ao' => [
            ['label' => 'Dashboard AO', 'route' => 'dashboard.ao', 'icon' => 'home', 'children' => []],
            [
                'label' => 'DATA CIF ANGGOTA',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Daftar Data CIF',        'route' => 'ao.cif.index'],
                    ['label' => 'Daftarkan Anggota Baru', 'route' => 'ao.cif.create'],
                ],
            ],
            [
                'label' => 'PEMBIAYAAN & SURVEI',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Input Pengajuan',            'route' => 'ao.pengajuan.create'],
                    ['label' => 'Daftar Pengajuan',           'route' => 'ao.pengajuan.index'],
                    ['label' => 'Survei & Analisis',          'route' => 'ao.survei.index'],
                    ['label' => 'Tracking Status Pembiayaan', 'route' => 'ao.tracking.index'],
                ],
            ],
            [
                'label' => 'MONITORING NASABAH',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Nasabah Binaan',            'route' => 'ao.nasabah.index'],
                    ['label' => 'Tunggakan & Penagihan',     'route' => 'ao.tunggakan.index'],
                    ['label' => 'Pengajuan Restrukturisasi', 'route' => 'ao.restrukturisasi.index'],
                    ['label' => 'Verifikasi Ta\'zir',        'route' => 'ao.keringanan-tazir.index', 'icon' => 'alert-triangle', 'badge' => 'tazir_pending_ao'],
                ],
            ],
            [
                'label' => 'TOOLS',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Simulasi Angsuran', 'route' => 'ao.simulasi.index'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // MANAJER — approval & monitoring portofolio.
        // Section LAPORAN & LIKUIDITAS dan REKAM JEJAK & AUDIT sengaja tidak
        // ditawarkan; halaman terkait juga ditolak lewat
        // ManajerController::FITUR_DISEMBUNYIKAN.
        // ─────────────────────────────────────────
        'manajer' => [
            ['label' => 'Dashboard Manajer', 'route' => 'dashboard.manajer', 'icon' => 'home', 'children' => []],
            [
                'label' => 'VERIFIKASI & APPROVAL',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Approval Pembiayaan',            'route' => 'manajer.approval-pembiayaan.index'],
                    ['label' => 'Approval Transaksi Over-limit',  'route' => 'manajer.approval-transaksi.index'],
                    ['label' => 'Approval Restrukturisasi',       'route' => 'manajer.approval-restrukturisasi.index'],
                    ['label' => 'Approval Beban Operasional',     'route' => 'manajer.beban.index'],
                    ['label' => 'Approval Closing Kas',           'route' => 'manajer.closing.index', 'badge' => 'closing_pending_manajer'],
                    ['label' => 'Persetujuan Pencairan Exit',     'route' => 'member-terminations.index'],
                    ['label' => 'Keringanan Ta\'zir',             'route' => 'manajer.keringanan-tazir.index'],
                    ['label' => 'Pusat Konfirmasi Auto-Debit',    'route' => 'autodebit.index', 'badge' => 'autodebit_pending'],
                ],
            ],
            [
                'label' => 'MONITORING & PORTOFOLIO',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Data CIF Anggota',            'route' => 'manajer.cif.index'],
                    ['label' => 'Pembiayaan Aktif',            'route' => 'manajer.pembiayaan-aktif.index'],
                    ['label' => 'Pembiayaan Bermasalah / NPF', 'route' => 'manajer.npf.index'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // PENGURUS — kebijakan strategis & keputusan persetujuan.
        // ─────────────────────────────────────────
        'pengurus' => [
            [
                'label' => 'STRATEGIS & APPROVAL',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Dashboard Strategis',            'route' => 'dashboard.pengurus',       'icon' => 'home'],
                    ['label' => 'Approval Pembiayaan (Limit Besar)', 'route' => 'pengurus.pembiayaan.index', 'icon' => 'check-square'],
                    ['label' => 'Pengesahan Bagi Hasil & SHU',    'route' => 'pengurus.shu.index',       'icon' => 'award'],
                    ['label' => 'Approval Transaksi',             'route' => 'pengurus.approval.index',  'icon' => 'check-circle'],
                    ['label' => 'Persetujuan Exit Keanggotaan',   'route' => 'member-terminations.index', 'icon' => 'user'],
                ],
            ],
            [
                'label' => 'KEUANGAN & MONITORING EKSEKUTIF',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Laporan Keuangan Eksekutif',        'route' => 'pengurus.laporan.index',           'icon' => 'trending-up'],
                    ['label' => 'Kas & Rekening Koperasi',           'route' => 'pengurus.kas.index',               'icon' => 'dollar-sign'],
                    ['label' => 'Data CIF Anggota',                  'route' => 'pengurus.cif.index',               'icon' => 'user'],
                    ['label' => 'Daftar Anggota',                    'route' => 'pengurus.anggota.index',           'icon' => 'users'],
                    ['label' => 'Simpanan Anggota',                  'route' => 'pengurus.simpanan.index',          'icon' => 'piggy-bank'],
                    ['label' => 'Laporan Kepatuhan AML',             'route' => 'pengurus.aml.index',               'icon' => 'shield'],
                    ['label' => 'Laporan Opini DPS',                 'route' => 'pengurus.laporan-syariah.index',   'icon' => 'book-open'],
                    ['label' => 'Laporan Keuangan Syariah (PSAK 101)', 'route' => 'laporan.psak101',                'icon' => 'bi-file-earmark-bar-graph'],
                    ['label' => 'Laporan Neraca',                    'route' => 'laporan.neraca',                   'icon' => 'bi-bar-chart-line'],
                    ['label' => 'Buku Besar',                        'route' => 'laporan.buku-besar',               'icon' => 'bi-journal-text'],
                    ['label' => 'CKPN Piutang Pembiayaan',           'route' => 'laporan.ckpn',                     'icon' => 'bi-shield-check'],
                    ['label' => 'Dana Cadangan',                     'route' => 'pengurus.cadangan.index',          'icon' => 'bi-safe2'],
                    ['label' => 'Dana Zakat, Infak & Sedekah',       'route' => 'zakat.index',                      'icon' => 'heart'],
                    ['label' => 'Laporan Sumber & Penggunaan Zakat', 'route' => 'zakat.laporan',                    'icon' => 'bar-chart-2'],
                ],
            ],
            [
                'label' => 'KEBIJAKAN & PRODUK',
                'icon' => 'section',
                'children' => [
                    // Kewenangan level kebijakan, bukan konfigurasi teknis.
                    ['label' => 'Profil & Kebijakan Koperasi', 'route' => 'pengurus.kebijakan.index', 'icon' => 'settings'],
                    // Kalender hari libur = parameter penjadwalan angsuran
                    // (HariLiburService), bukan konfigurasi teknis sistem.
                    ['label' => 'Kalender Hari Libur', 'route' => 'pengurus.hari-libur.index', 'icon' => 'calendar'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // ADMIN — pengaturan sistem. Menu audit sengaja tidak ditawarkan
        // (administrator bukan pengawas independen).
        // ─────────────────────────────────────────
        'admin' => [
            [
                'label' => 'ADMINISTRASI SISTEM',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Pengaturan Aplikasi', 'route' => 'admin.settings.index', 'icon' => 'settings'],
                ],
            ],
            [
                'label' => 'OPERASIONAL',
                'icon' => 'section',
                'children' => [
                    // Admin memegang `autodebit.konfirmasi` (config/izin.php),
                    // jadi menu ini WAJIB ada. Tanpa entri di sini ia berwenang
                    // tetapi hanya bisa masuk lewat URL langsung — wewenang yang
                    // tidak dapat ditemukan dari antarmuka.
                    ['label' => 'Pusat Konfirmasi Auto-Debit', 'route' => 'autodebit.index', 'icon' => 'credit-card', 'badge' => 'autodebit_pending'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // SUPER ADMIN — puncak administratif (level tertinggi). Memegang
        // SELURUH modul yang tersedia bagi peran lain, karena itu menunya
        // adalah gabungan modul Teller, AO, Manajer, Pengurus, dan Pengelola.
        //
        // Yang TIDAK ditautkan di sini (dan memang akan ditolak 403) adalah
        // kewenangan yang sengaja dipegang peran lain demi pemisahan tugas:
        //   - dasbor pribadi peran lain (`dasbor.*`);
        //   - pengawasan & audit independen DPS (`dps.pengawasan`,
        //     `laporan.audit`);
        //   - aksi PERSETUJUAN transaksi/pembiayaan/terminasi
        //     (`approval.transaksi`, `pembiayaan.approve`,
        //     `pembiayaan.persetujuan`, `terminasi.approval`);
        //   - layanan mandiri anggota (`nasabah.swalayan`).
        // Halaman ANTREAN persetujuan tetap ditautkan: Super Admin boleh
        // melihat dan mengawasi, sedangkan tombol putusnya ditolak 403.
        // ─────────────────────────────────────────
        'super_admin' => [
            [
                'label' => 'RINGKASAN SISTEM',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Dashboard Super Admin', 'route' => 'dashboard.super-admin',   'icon' => 'home', 'badge' => 'super_admin_antrean'],
                    ['label' => 'Kinerja Seluruh Unit',  'route' => 'super-admin.kinerja-unit', 'icon' => 'bar-chart-2'],
                ],
            ],
            [
                'label' => 'KEUANGAN & LAPORAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Neraca',                          'route' => 'laporan.neraca',                  'icon' => 'bi-bar-chart-line'],
                    ['label' => 'Buku Besar',                      'route' => 'laporan.buku-besar',              'icon' => 'bi-journal-check'],
                    ['label' => 'Laporan Keuangan Syariah (PSAK 101)', 'route' => 'laporan.psak101',             'icon' => 'bi-file-earmark-bar-graph'],
                    ['label' => 'CKPN',                            'route' => 'laporan.ckpn',                    'icon' => 'file-text'],
                    ['label' => 'Laporan Operasional Manajer',     'route' => 'manajer.laporan.index',           'icon' => 'bar-chart-2'],
                    ['label' => 'Laporan Pengurus',                'route' => 'pengurus.laporan.index',          'icon' => 'clipboard'],
                    ['label' => 'Laporan Syariah',                 'route' => 'pengurus.laporan-syariah.index',  'icon' => 'shield'],
                    ['label' => 'Laporan Dana Kebajikan',          'route' => 'pengurus.laporan.dana-kebajikan', 'icon' => 'heart'],
                    ['label' => 'Laporan AML',                     'route' => 'pengurus.aml.index',              'icon' => 'alert-triangle'],
                    ['label' => 'SHU',                             'route' => 'pengurus.shu.index',              'icon' => 'dollar-sign'],
                    ['label' => 'Pengesahan SHU',                  'route' => 'pengurus.pengesahan-shu.index',   'icon' => 'award'],
                    ['label' => 'Dana Cadangan & Modal',           'route' => 'pengurus.cadangan.index',         'icon' => 'bi-safe2'],
                    ['label' => 'Chart of Accounts',               'route' => 'pengurus.coa.index',              'icon' => 'book-open'],
                    ['label' => 'Kas Pengurus',                    'route' => 'pengurus.kas.index',              'icon' => 'dollar-sign'],
                    ['label' => 'Kas Manajer',                     'route' => 'manajer.kas.index',               'icon' => 'dollar-sign'],
                ],
            ],
            [
                'label' => 'OPERASIONAL LOKET',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Setoran',                 'route' => 'teller.setor',                     'icon' => 'piggy-bank'],
                    ['label' => 'Penarikan',               'route' => 'teller.tarik',                     'icon' => 'dollar-sign'],
                    ['label' => 'Deposito — Form',         'route' => 'teller.deposito',                  'icon' => 'archive'],
                    ['label' => 'Deposito — Daftar',       'route' => 'teller.deposito.index',            'icon' => 'list'],
                    ['label' => 'Angsuran',                'route' => 'teller.angsuran',                  'icon' => 'credit-card'],
                    ['label' => 'Pencairan Pembiayaan',    'route' => 'teller.pencairan.index',           'icon' => 'file-plus'],
                    ['label' => 'Pencairan Disetujui',     'route' => 'teller.pencairan-disetujui.index', 'icon' => 'check-square'],
                    ['label' => 'Simpanan Wajib',          'route' => 'teller.simpanan-wajib.index',      'icon' => 'piggy-bank'],
                    ['label' => 'Riwayat Transaksi',       'route' => 'teller.riwayat',                   'icon' => 'list'],
                    ['label' => 'Rekap Kas',               'route' => 'teller.rekap',                     'icon' => 'clipboard'],
                    ['label' => 'Buka Kas',                'route' => 'teller.kas.opening',               'icon' => 'settings'],
                    ['label' => 'Tutup Kas',               'route' => 'teller.kas.closing',               'icon' => 'settings'],
                    ['label' => 'Keringanan Ta\'zir',      'route' => 'teller.keringanan-tazir.index',    'icon' => 'heart'],
                    ['label' => 'Keringanan Penalti',      'route' => 'teller.keringanan-penalti.index',  'icon' => 'heart'],
                    ['label' => 'Angsuran Pengurus',       'route' => 'pengurus.angsuran.index',          'icon' => 'credit-card'],
                    ['label' => 'Simpanan Pengurus',       'route' => 'pengurus.simpanan.index',          'icon' => 'piggy-bank'],
                ],
            ],
            [
                'label' => 'CIF & KEANGGOTAAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'CIF — AO',                'route' => 'ao.cif.index',                     'icon' => 'file-text'],
                    ['label' => 'CIF Baru',                'route' => 'ao.cif.create',                    'icon' => 'file-plus'],
                    ['label' => 'CIF — Teller',            'route' => 'teller.cif.index',                 'icon' => 'file-text'],
                    ['label' => 'CIF — Manajer',           'route' => 'manajer.cif.index',                'icon' => 'file-text'],
                    ['label' => 'CIF — Pengurus',          'route' => 'pengurus.cif.index',               'icon' => 'file-text'],
                    ['label' => 'Data Anggota',            'route' => 'pengurus.anggota.index',           'icon' => 'users'],
                    ['label' => 'Daftar Anggota (Loket)',  'route' => 'teller.anggota.index',             'icon' => 'user'],
                    ['label' => 'Pengajuan Rekening',      'route' => 'pengelola.pengajuan-rekening.index', 'icon' => 'clipboard'],
                    ['label' => 'Terminasi Anggota',       'route' => 'member-terminations.index',        'icon' => 'alert-triangle'],
                    ['label' => 'Ajukan Terminasi',        'route' => 'member-terminations.create',       'icon' => 'file-plus'],
                ],
            ],
            [
                'label' => 'PEMBIAYAAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Pengajuan Pembiayaan — AO', 'route' => 'ao.pengajuan.index',              'icon' => 'file-text'],
                    ['label' => 'Input Pengajuan',           'route' => 'ao.pengajuan.create',             'icon' => 'file-plus'],
                    ['label' => 'Survei',                    'route' => 'ao.survei.index',                 'icon' => 'search'],
                    ['label' => 'Tracking Pengajuan',        'route' => 'ao.tracking.index',               'icon' => 'activity'],
                    ['label' => 'Nasabah AO',                'route' => 'ao.nasabah.index',                'icon' => 'users'],
                    ['label' => 'Tunggakan',                 'route' => 'ao.tunggakan.index',              'icon' => 'alert-triangle'],
                    ['label' => 'Restrukturisasi',           'route' => 'ao.restrukturisasi.index',        'icon' => 'trending-up'],
                    ['label' => 'Simulasi Pembiayaan',       'route' => 'ao.simulasi.index',               'icon' => 'bar-chart-2'],
                    ['label' => 'Keringanan Ta\'zir — AO',   'route' => 'ao.keringanan-tazir.index',       'icon' => 'heart'],
                    ['label' => 'Pengajuan Pinjaman Pengelola', 'route' => 'pengelola.pengajuan-pinjaman.index', 'icon' => 'clipboard'],
                    ['label' => 'Pengajuan Pembiayaan Terpadu', 'route' => 'pengajuan-pembiayaan.index',     'icon' => 'file-text'],
                    ['label' => 'Pembiayaan Pengurus',       'route' => 'pengurus.pembiayaan.index',       'icon' => 'credit-card'],
                    ['label' => 'Pembiayaan Aktif',          'route' => 'manajer.pembiayaan-aktif.index',  'icon' => 'archive'],
                    ['label' => 'NPF',                       'route' => 'manajer.npf.index',               'icon' => 'alert-triangle'],
                ],
            ],
            [
                'label' => 'ANTREAN PENGAWASAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Antrean Approval Pembiayaan', 'route' => 'manajer.approval-pembiayaan.index',      'icon' => 'check-square'],
                    ['label' => 'Antrean Approval Transaksi',  'route' => 'manajer.approval-transaksi.index',       'icon' => 'check-square'],
                    ['label' => 'Antrean Approval Restrukturisasi', 'route' => 'manajer.approval-restrukturisasi.index', 'icon' => 'check-square'],
                    ['label' => 'Antrean Approval Pengurus',   'route' => 'pengurus.approval.index',                'icon' => 'check-square'],
                    ['label' => 'Approval Beban',              'route' => 'manajer.beban.index',                    'icon' => 'dollar-sign'],
                    ['label' => 'Keringanan Ta\'zir — Manajer', 'route' => 'manajer.keringanan-tazir.index',        'icon' => 'heart'],
                    ['label' => 'Keringanan Penalti — Manajer', 'route' => 'manajer.keringanan-penalti.index',      'icon' => 'heart'],
                    ['label' => 'Approval Closing Kas',        'route' => 'manajer.closing.index',                  'icon' => 'check-circle'],
                    ['label' => 'Approval Closing Kas (alias)', 'route' => 'manajer.closing-kas.index',             'icon' => 'check-circle'],
                ],
            ],
            [
                'label' => 'MONITORING OPERASIONAL',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Kinerja Staf',        'route' => 'manajer.kinerja-staf.index', 'icon' => 'trending-up'],
                    ['label' => 'Bagi Hasil',          'route' => 'manajer.bagi-hasil.index',   'icon' => 'dollar-sign'],
                    ['label' => 'Jejak Audit Manajer', 'route' => 'manajer.audit.index',        'icon' => 'activity'],
                ],
            ],
            [
                'label' => 'BEBAN & ZAKAT',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Pengeluaran Beban', 'route' => 'pengeluaran-beban.index', 'icon' => 'dollar-sign'],
                    ['label' => 'Buku Zakat',        'route' => 'zakat.index',             'icon' => 'heart'],
                    ['label' => 'Laporan Zakat',     'route' => 'zakat.laporan',           'icon' => 'bar-chart-2'],
                    ['label' => 'Ringkasan Zakat',   'route' => 'zakat.ringkasan',         'icon' => 'clipboard'],
                ],
            ],
            [
                'label' => 'MASTER DATA & PRODUK',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Produk Pembiayaan & Akad', 'route' => 'pengurus.produk.index',              'icon' => 'credit-card'],
                    ['label' => 'Produk Pembiayaan Dinamis', 'route' => 'pengelola.produk-pembiayaan.index', 'icon' => 'package'],
                    ['label' => 'Jenis Simpanan',           'route' => 'pengurus.jenis-simpanan.index',      'icon' => 'piggy-bank'],
                ],
            ],
            [
                'label' => 'KEBIJAKAN & PENGATURAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Kebijakan Koperasi', 'route' => 'pengurus.kebijakan.index', 'icon' => 'book-open'],
                    ['label' => 'Hari Libur',         'route' => 'pengurus.hari-libur.index', 'icon' => 'calendar'],
                    ['label' => 'Pengaturan Aplikasi', 'route' => 'admin.settings.index',     'icon' => 'settings'],
                    ['label' => 'Pusat Konfirmasi Auto-Debit', 'route' => 'autodebit.index',  'icon' => 'credit-card', 'badge' => 'autodebit_pending'],
                ],
            ],
            [
                'label' => 'PENGGUNA & HAK AKSES',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Kelola Pengguna & Role', 'route' => 'pengurus.users.index',             'icon' => 'users'],
                    ['label' => 'Riwayat Pengujian',     'route' => 'pengurus.riwayat-pengujian.index', 'icon' => 'check-circle'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // ANGGOTA / NASABAH — layanan swalayan.
        // ─────────────────────────────────────────
        'anggota' => [
            ['label' => 'Dashboard', 'route' => 'dashboard.nasabah', 'icon' => 'home', 'children' => []],
            [
                'label' => 'REKENING & SIMPANAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Simpanan Saya',   'icon' => 'piggy-bank', 'route' => 'nasabah.simpanan.index'],
                    ['label' => 'Mutasi Rekening', 'icon' => 'list',       'route' => 'nasabah.mutasi.index'],
                ],
            ],
            [
                'label' => 'PEMBIAYAAN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Angsuran Saya',        'icon' => 'credit-card', 'route' => 'nasabah.angsuran.index'],
                    ['label' => 'Pengajuan Pembiayaan', 'icon' => 'file-text',   'route' => 'nasabah.pengajuan.index'],
                ],
            ],
            [
                'label' => 'AKUN',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Profil Saya', 'icon' => 'user', 'route' => 'nasabah.profil.index'],
                ],
            ],
        ],

        // ─────────────────────────────────────────
        // DPS — pengawasan kepatuhan syariah.
        //
        // 5f — DPS TIDAK sepenuhnya read-only. Selain membaca data operasional,
        // DPS berwenang MENYERTIFIKASI produk baru
        // (`POST /dps/review-produk/{id}/approve`) dan MENULIS opini/catatan
        // pengawasan (`POST /dps/opini-catatan`); keduanya dikunci
        // `izin:dps.pengawasan` dan `IntegritasRuteDpsTest`. Yang read-only
        // adalah modul operasional (kas, simpanan, pembiayaan, zakat).
        // ─────────────────────────────────────────
        'dps' => [
            ['label' => 'Dashboard DPS', 'route' => 'dashboard.dps', 'icon' => 'home', 'children' => []],
            [
                'label' => 'PENGAWASAN AKAD & PRODUK',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Audit Pembiayaan & Akad', 'route' => 'dps.audit-akad',        'icon' => 'file-text'],
                    ['label' => 'Pengesahan Akad',         'route' => 'dps.pengesahan-akad',   'icon' => 'check-circle'],
                    ['label' => 'Review Produk Baru',      'route' => 'dps.review-produk.index', 'icon' => 'shield'],
                ],
            ],
            [
                'label' => 'DANA KEBAJIKAN & TA\'ZIR',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Dana Kebajikan / Ta\'zir & Infaq', 'route' => 'dps.dana-kebajikan', 'icon' => 'heart'],
                ],
            ],
            [
                'label' => 'ZAKAT, INFAK & SEDEKAH',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Buku Besar Zakat (Read-Only)',            'route' => 'dps.zakat.index',   'icon' => 'heart'],
                    ['label' => 'Laporan Sumber & Penggunaan Zakat',       'route' => 'dps.zakat.laporan', 'icon' => 'bar-chart-2'],
                ],
            ],
            [
                'label' => 'LAPORAN & OPINI SYARIAH',
                'icon' => 'section',
                'children' => [
                    ['label' => 'Laporan Keuangan Syariah',            'route' => 'dps.laporan-keuangan',  'icon' => 'bar-chart-2'],
                    ['label' => 'Jurnal Umum (Read-Only)',             'route' => 'dps.jurnal-umum',       'icon' => 'book-open'],
                    ['label' => 'Audit Beban Operasional (Read-Only)', 'route' => 'dps.beban.index',       'icon' => 'file-text'],
                    ['label' => 'Opini & Catatan Syariah',             'route' => 'dps.opini-catatan',     'icon' => 'edit-3'],
                    ['label' => 'Buku Besar Cadangan',                 'route' => 'laporan.buku-besar',    'icon' => 'bi-journal-check'],
                    ['label' => 'Laporan Neraca',                      'route' => 'laporan.neraca',        'icon' => 'bi-bar-chart-line'],
                    ['label' => 'Laporan Keuangan Syariah (PSAK 101)', 'route' => 'laporan.psak101',       'icon' => 'bi-file-earmark-bar-graph'],
                    ['label' => 'Audit Laporan Keuangan',              'route' => 'audit.laporan.index',   'icon' => 'bi-shield-check'],
                ],
            ],
        ],
    ],
];
