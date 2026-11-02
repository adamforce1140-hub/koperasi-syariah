<?php

/**
 * Hierarki peran (RBAC) & batas operasional default.
 *
 * SATU-SATUNYA SUMBER KEBENARAN untuk pertanyaan:
 *   - peran apa saja yang dikenal sistem, dan bagaimana urutan kewenangannya;
 *   - peran apa yang berwenang menyetujui pengajuan peran lain (rantai approval);
 *   - batas nominal transaksi, frekuensi aksi, dan volume akses data per peran.
 *
 * Dibaca melalui App\Support\HierarkiRole — JANGAN memanggil `config()` langsung
 * dari controller/service agar aturan tidak bercabang (lihat pola yang sama pada
 * App\Support\LaporanKeuanganPermission).
 *
 * DUA LAPIS KONFIGURASI:
 *   1. File ini  — default aman, berlaku bila tidak ada override.
 *   2. Tabel `limit_transaksi_role` — override yang dapat diubah dari UI tanpa
 *      deploy. Nilai di tabel MENANG atas nilai di file ini.
 *
 * P4 "Default Aman": seluruh nilai di bawah ini SENGAJA disetel sama dengan
 * perilaku sistem saat ini. Membiarkan konfigurasi apa adanya TIDAK mengubah
 * apa pun. Perubahan hanya terjadi bila operator menetapkan override eksplisit.
 *
 * SKALABILITAS: menambah peran baru cukup dengan menambah satu entri di
 * `peran` + `rantai` + `batas`. Tidak ada kode yang perlu diubah — status
 * approval diturunkan otomatis dari nama peran (lihat `status_menunggu`).
 *
 * Regulasi acuan: UU No. 25/1992 Pasal 30 (kewenangan Pengurus), PP No. 7/2021,
 * Permen KUKM No. 09/2018, POJK No. 76/2016, UU PDP No. 27/2022.
 */

return [

    /*
    |----------------------------------------------------------------------
    | Daftar peran & tingkat kewenangan
    |----------------------------------------------------------------------
    | `level`   : makin besar = makin tinggi kewenangannya. Dipakai untuk
    |             pertanyaan "apakah A lebih tinggi dari B".
    | `kategori`: pengawas | pengurus | pengelola | sistem | anggota.
    | `penyetuju`: boleh menjadi pemutus (checker) pada rantai approval.
    |             DPS & Admin TIDAK berada di rantai operasional.
    | `operasional`: terlibat transaksi harian (teller/ao/manajer/pengurus).
    |
    | CATATAN HIERARKI (Super Admin):
    | `super_admin` adalah PUNCAK ADMINISTRATIF — peran teknis dengan level
    | TERTINGGI (100) di atas seluruh peran lain, termasuk `dps` (60).
    | Ia memegang SELURUH izin modul, seluruh kelompok peran, dan seluruh
    | prefiks `izin_dokumen`, sehingga tidak ada halaman atau fitur yang
    | terblokir baginya.
    | Hierarki TIDAK memberi akses secara implisit: seluruh penegak izin
    | memakai pemeriksaan eksplisit (`in_array`). Karena itu cakupan penuh
    | diwujudkan sebagai PENAMBAHAN EKSPLISIT pada tiap lapisan konfigurasi.
    | Meski berlevel tertinggi, `super_admin` TETAP BUKAN penyetuju transaksi
    | (`penyetuju` false) dan BUKAN auditor: pemisahan tugas tetap utuh —
    | pemutus pembiayaan tetap Pengurus dan audit laporan keuangan tetap
    | eksklusif DPS.
    */
    'peran' => [
        'dps' => [
            'label' => 'DPS',
            'level' => 60,
            'kategori' => 'pengawas',
            'penyetuju' => false,
            'operasional' => false,
            'dashboard_route' => 'dashboard.dps',
            'jabatan' => 'Dewan Pengawas Syariah',
        ],
        'pengurus' => [
            'label' => 'Pengurus',
            'level' => 50,
            'kategori' => 'pengurus',
            'penyetuju' => true,
            'operasional' => true,
            'dashboard_route' => 'dashboard.pengurus',
            'jabatan' => 'Pengurus Koperasi',
        ],
        'manajer' => [
            'label' => 'Manajer',
            'level' => 40,
            'kategori' => 'pengelola',
            'penyetuju' => true,
            'operasional' => true,
            'dashboard_route' => 'dashboard.manajer',
            'jabatan' => 'Manajer',
        ],
        'ao' => [
            'label' => 'Account Officer',
            'level' => 30,
            'kategori' => 'pengelola',
            'penyetuju' => true,
            'operasional' => true,
            'dashboard_route' => 'dashboard.ao',
            'jabatan' => 'Account Officer',
        ],
        'teller' => [
            'label' => 'Teller',
            'level' => 20,
            'kategori' => 'pengelola',
            'penyetuju' => false,
            'operasional' => true,
            'dashboard_route' => 'dashboard.teller',
            'jabatan' => 'Teller',
        ],
        /*
         | Super Admin — puncak administratif dengan level TERTINGGI (100),
         | di atas `dps` (60) dan seluruh peran operasional/tata kelola.
         | Bukan penyetuju transaksi (`penyetuju` false) agar pemisahan tugas
         | maker-checker tidak rusak; audit laporan tetap eksklusif DPS.
         */
        'super_admin' => [
            'label' => 'Super Admin',
            'level' => 100,
            'kategori' => 'sistem',
            'penyetuju' => false,
            'operasional' => false,
            'dashboard_route' => 'dashboard.super-admin',
            'jabatan' => 'Super Admin',
        ],
        'admin' => [
            'label' => 'Administrator',
            'level' => 10,
            'kategori' => 'sistem',
            'penyetuju' => false,
            'operasional' => false,
            'dashboard_route' => 'admin.settings.index',
            'jabatan' => 'Administrator',
        ],
        'anggota' => [
            'label' => 'Anggota',
            'level' => 0,
            'kategori' => 'anggota',
            'penyetuju' => false,
            'operasional' => false,
            'dashboard_route' => 'dashboard.nasabah',
            'jabatan' => 'Anggota',
        ],
        /*
         | OJK-06 — `nasabah` adalah peran NON-ANGGOTA (calon anggota / pengguna
         | jasa yang belum menjadi anggota koperasi), selaras dengan POJK
         | No. 76/2016 yang membedakan "anggota" dari "nasabah/calon anggota".
         | Level & kategorinya sama dengan `anggota` (akses swalayan terbatas):
         | hanya dapat melihat produk & mengajukan keanggotaan, tanpa akses
         | transaksi operasional. Tidak berada di rantai approval.
         */
        'nasabah' => [
            'label' => 'Nasabah',
            'level' => 0,
            'kategori' => 'anggota',
            'penyetuju' => false,
            'operasional' => false,
            // T-1 — sebelumnya kunci ini TIDAK ADA sehingga
            // `WebAuthController::redirectByRole()` mengalihkan nasabah ke `/`
            // yang lalu mengalihkan lagi ke `/` (lingkar tanpa akhir).
            // Nasabah bukan anggota, jadi sasaran yang benar adalah formulir
            // pengajuan keanggotaan (rute publik, tanpa gerbang izin).
            'dashboard_route' => 'pengajuan-rekening.publik.form',
            'jabatan' => 'Nasabah',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Rantai approval (eskalasi berjenjang)
    |----------------------------------------------------------------------
    | Menjawab: "bila peran X mengajukan sesuatu yang melebihi limitnya,
    | peran apa yang berwenang menyetujui?" Nilai `null` = puncak rantai
    | (tidak ada peran di atasnya).
    |
    | Hierarki: Pengurus > Manajer > AO > Teller.
    */
    'rantai' => [
        'teller' => 'ao',
        'ao' => 'manajer',
        'manajer' => 'pengurus',
        'pengurus' => null,
    ],

    /*
    |----------------------------------------------------------------------
    | Pemetaan peran penyetuju -> status baris `approval_transaksi`
    |----------------------------------------------------------------------
    | Diturunkan otomatis dengan pola `PENDING_<PERAN_HURUF_BESAR>` sehingga
    | peran baru tidak memerlukan perubahan kode. Dua nilai pertama sudah ada
    | sejak awal (PENDING_MANAJER, PENDING_PENGURUS); PENDING_AO ditambahkan
    | agar jenjang AO benar-benar tercatat, bukan dilebur ke Manajer.
    */
    'status_menunggu' => [
        'ao' => 'PENDING_AO',
        'manajer' => 'PENDING_MANAJER',
        'pengurus' => 'PENDING_PENGURUS',
    ],

    /*
    |----------------------------------------------------------------------
    | Jenis transaksi yang tunduk pada batas operasional
    |----------------------------------------------------------------------
    */
    'jenis_transaksi' => [
        'setor_tunai' => 'Setoran Tunai',
        'tarik_tunai' => 'Penarikan Tunai',
        'non_tunai' => 'Transaksi Non-Tunai',
    ],

    /*
    |----------------------------------------------------------------------
    | Batas nominal per transaksi & batas harian
    |----------------------------------------------------------------------
    | limit_per_transaksi : NULL = tanpa batas, 0 = dilarang sama sekali.
    | limit_harian        : akumulasi nominal per hari. NULL = tanpa batas.
    | butuh_approval      : apakah transaksi di atas limit wajib disetujui.
    | approval_role       : peran pertama yang berwenang menyetujui; sistem
    |                       akan menaikkan (eskalasi) bila nominal juga
    |                       melebihi limit peran tersebut.
    */
    'batas' => [
        'teller' => [
            'setor_tunai' => ['limit_per_transaksi' => 50000000, 'limit_harian' => null, 'butuh_approval' => true,  'approval_role' => 'ao'],
            'tarik_tunai' => ['limit_per_transaksi' => 5000000,  'limit_harian' => null, 'butuh_approval' => true,  'approval_role' => 'ao'],
            'non_tunai' => ['limit_per_transaksi' => 0,        'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
        ],
        'ao' => [
            'setor_tunai' => ['limit_per_transaksi' => 100000000, 'limit_harian' => null, 'butuh_approval' => true,  'approval_role' => 'manajer'],
            'tarik_tunai' => ['limit_per_transaksi' => 20000000,  'limit_harian' => null, 'butuh_approval' => true,  'approval_role' => 'manajer'],
            'non_tunai' => ['limit_per_transaksi' => 0,         'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
        ],
        'manajer' => [
            'setor_tunai' => ['limit_per_transaksi' => null, 'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
            'tarik_tunai' => ['limit_per_transaksi' => null, 'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
            'non_tunai' => ['limit_per_transaksi' => 50000000, 'limit_harian' => null, 'butuh_approval' => true, 'approval_role' => 'pengurus'],
        ],
        'pengurus' => [
            'setor_tunai' => ['limit_per_transaksi' => null, 'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
            'tarik_tunai' => ['limit_per_transaksi' => null, 'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
            'non_tunai' => ['limit_per_transaksi' => null, 'limit_harian' => null, 'butuh_approval' => false, 'approval_role' => null],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Batas yang nilainya berasal dari kolom kebijakan koperasi
    |----------------------------------------------------------------------
    | Pasangan (peran, jenis transaksi) di bawah ini menimpa `batas` dengan
    | nilai kolom kebijakan yang dapat diubah Pengurus dari UI. Dulu aturan
    | ini ditulis sebagai `if ($peran === 'teller' ...)` di dalam kode
    | (DYN-07); kini peran lain yang setara dapat memperoleh kebijakan yang
    | sama cukup dengan menambah entri di sini, tanpa mengubah kode.
    */
    'batas_dari_kebijakan' => [
        'teller' => [
            'setor_tunai' => 'limit_setoran_teller',
            'tarik_tunai' => 'limit_penarikan_teller',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Frekuensi aksi (jumlah aksi per hari)
    |----------------------------------------------------------------------
    | OJK-07 — kini ada BATAS DEFAULT (bukan lagi `null` = tanpa batas) untuk
    | seluruh peran operasional, sebagai benteng terhadap penyalahgunaan
    | berulang. Nilai sengaja dilonggarkan agar operasional normal tidak
    | terganggu, namun tetap memberi plafon yang nyata. NULL = tanpa batas.
    */
    'frekuensi' => [
        'teller' => ['maks_transaksi_per_hari' => 500],
        'ao' => ['maks_transaksi_per_hari' => 300],
        'manajer' => ['maks_transaksi_per_hari' => 200],
        'pengurus' => ['maks_transaksi_per_hari' => 200],
    ],

    /*
    |----------------------------------------------------------------------
    | Volume akses data (batas jumlah baris yang boleh ditarik sekaligus)
    |----------------------------------------------------------------------
    | OJK-07 — batas default ditegakkan pada jalur ekspor/unduh agar peran
    | dengan hak terbatas tidak dapat menarik seluruh basis data dalam satu
    | permintaan (UU PDP No. 27/2022 — minimalisasi data). Peran `admin`
    | dan `super_admin` sengaja dibiarkan `null` (tanpa batas) karena
    | keduanya peran sistem dengan akses penuh untuk pemeliharaan; batasnya
    | dapat ditimpa operator.
    */
    'akses_data' => [
        'super_admin' => ['maks_baris_ekspor' => null],
        'admin' => ['maks_baris_ekspor' => null],
        'pengurus' => ['maks_baris_ekspor' => 50000],
        'manajer' => ['maks_baris_ekspor' => 20000],
        'dps' => ['maks_baris_ekspor' => 50000],
    ],

    /*
    |----------------------------------------------------------------------
    | KELOMPOK PERAN TERPUSAT (kategori peran)
    |----------------------------------------------------------------------
    | Menjawab pertanyaan "peran apa saja yang setara Trio Pengelola / Pengurus
    | / Pengawas / Sistem" dari SATU tempat, sehingga tidak ada lagi `in_array`
    | literal peran yang tersebar di model/controller/view.
    |
    | Setiap kunci di bawah adalah ALIAS dari `peran.*.kategori` di atas — jadi
    | menambah peran cukup menambah entri `peran` (dengan kategori yang sesuai)
    | dan peran itu OTOMATIS masuk ke seluruh kelompok di bawah. Tidak ada kode
    | otorisasi yang perlu diubah.
    */
    'kelompok' => [
        // Administrator sistem — wewenang konfigurasi & pemeliharaan, bukan
        // transaksi operasional. (dulu: User::isAdminSistem())
        'sistem' => ['super_admin', 'admin'],

        // Pengawas syariah. (dulu: literal di User::isPengelola())
        'pengawas' => ['dps'],

        // Trio Pengelola operasional (penerima notifikasi administratif &
        // staf internal). (dulu: literal di User::isPengelola())
        'pengelola' => ['teller', 'ao', 'manajer'],

        // Peran yang terlibat transaksi harian. (dulu: User::hasOperationalAccess())
        'operasional' => ['teller', 'ao', 'manajer', 'pengurus'],

        // Penerima notifikasi khas per modul (dulu: whereIn('role', ...) yang
        // ditulis ulang di tiap service).
        //
        // Catatan: penerima notifikasi usulan auto-debit TIDAK didaftarkan di
        // sini. Daftarnya diturunkan langsung dari `Izin::peran(
        // 'autodebit.konfirmasi')` agar penerima notifikasi selalu identik
        // dengan peran yang benar-benar boleh membuka Pusat Konfirmasi —
        // dua daftar terpisah pernah menyimpang dan membuat Teller/Pengurus
        // menerima tautan yang berakhir 403.
        'notifikasi_simpanan_wajib' => ['pengurus', 'manajer'],
        'notifikasi_shu_ditahan' => ['pengurus'],

        // Peran berwenang mengubah status keanggotaan secara manual.
        // (dulu: Anggota::PERAN_PENGUBAH_STATUS = ['admin','pengurus'])
        'pengubah_status_anggota' => ['admin', 'pengurus'],

        // Pengelola beban operasional: input & approval. AO ditolak (dialihkan
        // ke dasbor AO), peran lain 403. (dulu: literal di
        // PengeluaranBebanController::__construct())
        'pengelola_beban' => ['teller', 'manajer'],

        // Peran wali data yang boleh melayani rekening & mencetak bilyet
        // anggota lain tanpa perlu menjadi pemiliknya.
        // (dulu: literal di Api\DepositoController & TransaksiMobileController)
        'wali_data_rekening' => ['teller', 'admin', 'manajer', 'pengurus'],

        // Peran berwenang mencairkan deposito sebelum jatuh tempo — transaksi
        // kas keluar, sehingga hanya petugas loket (segregation of duties).
        'pencairan_dini' => ['teller'],

        // Peran wali data yang boleh mencetak bilyet deposito anggota lain.
        // (dulu: literal di Api\DepositoController::bolehCetakBilyet())
        'pencetak_bilyet' => ['pengurus', 'teller'],

        // Peran yang boleh melihat tabel "10 Mutasi Terakhir" pada Portofolio
        // CIF (privasi transaksi anggota). (dulu: literal di
        // partials.mutasi-terakhir)
        'lihat_mutasi' => ['teller', 'manajer', 'pengurus'],

        // Peran yang boleh mengeksekusi/menyetujui permohonan Exit Keanggotaan.
        // (dulu: literal di member-terminations.show)
        'eksekusi_terminasi' => ['teller', 'manajer', 'pengurus'],

        // Peran yang butuh kolom ringkas (jumlah rekening & pembiayaan aktif)
        // pada daftar Portofolio CIF. (dulu: literal di CifController::index)
        'lihat_detil_cif' => ['manajer', 'pengurus'],

        // Peran petugas yang laporan kinerjanya dihitung per orang pada
        // "Kinerja Staf" Manajer. Ditulis eksplisit agar daftar peran hidup di
        // sini, BUKAN tersembunyi sebagai literal di controller.
        // Catatan: staf di luar kelompok ini yang benar-benar menangani
        // pengajuan/transaksi tetap disertakan sebagai baris tambahan
        // (lihat ManajerController::kinerjaAOLuarRole()).
        'kinerja_ao' => ['ao'],
        'kinerja_teller' => ['teller'],

        // Peran pemutus (checker) closing kas teller. (dulu: literal
        // `role === 'manajer'` di manajer/closing/index.blade.php)
        'penyetuju_closing_kas' => ['manajer'],

        // Peran yang boleh mengelola Bagan Akun — konfigurasi teknis
        // akuntansi, bukan kewenangan tata kelola. (dulu: literal
        // `role === 'super_admin'` di laporan/neraca.blade.php)
        'pengelola_coa' => ['super_admin'],

        // Peran yang me-review (meneruskan) pengajuan pembiayaan ke pemutus.
        // (dulu: literal `$role === 'manajer'` di
        // pengelola/pengajuan-pinjaman*.blade.php)
        'peninjau_pembiayaan' => ['manajer'],

        // Peran pemutus final pengajuan pembiayaan. (dulu: literal
        // `$role === 'pengurus'` di pengelola/pengajuan-pinjaman*.blade.php)
        'pemutus_pembiayaan' => ['pengurus'],

        // Tujuan eskalasi pembiayaan over-limit dari Manajer. Menentukan
        // peran penerima yang sah pada aksi "Teruskan" (DYN-05). Status
        // tujuan diturunkan dari `status_menunggu` peran tersebut, sehingga
        // menambah tahap baru cukup dengan menambah entri di sini.
        'eskalasi_pembiayaan' => ['pengurus', 'dps'],
    ],

    /*
    |----------------------------------------------------------------------
    | PEWARISAN PUNCAK ADMINISTRATIF
    |----------------------------------------------------------------------
    | Peran `super_admin` adalah puncak administratif: ia OTOMATIS dianggap
    | anggota setiap kelompok pada `kelompok` di atas (dan setiap kategori
    | peran), sehingga seluruh gerbang `HierarkiRole::dalamKelompok()` di
    | controller/model/view terbuka tanpa perlu menyalin namanya ke tiap
    | kelompok. Isi `kelompok` tetap persis seperti semula — pewarisan terjadi
    | di satu titik pusat (`dalamKelompok`), bukan dengan menyunting daftar.
    |
    | `pewaris_puncak_kecuali` adalah pengecualian yang DISENGAJA. Kelompok
    | berikut semata-mata berisi kewenangan MENYETUJUI / mengeksekusi kas /
    | mengisi baris staf — bukan modul administratif. Memberikannya kepada
    | Super Admin akan merusak pemisahan tugas (maker-checker): penyetuju dan
    | pelaksana kas harus tetap peran operasional, dan auditor tetap DPS.
    */
    'pewaris_puncak' => 'super_admin',

    'pewaris_puncak_kecuali' => [
        'pengawas',              // pengawasan syariah DPS — auditor independen
        'pencairan_dini',        // kas keluar loket (break deposito)
        'pencetak_bilyet',       // penerbitan bilyet oleh petugas loket
        'kinerja_ao',            // baris laporan kinerja per staf
        'kinerja_teller',        // baris laporan kinerja per staf
        'penyetuju_closing_kas', // checker closing kas teller
        'peninjau_pembiayaan',   // rantai persetujuan pembiayaan
        'pemutus_pembiayaan',    // pemutus final pembiayaan
        'eskalasi_pembiayaan',   // penerima eskalasi over-limit
    ],

    /*
    |----------------------------------------------------------------------
    | PENGALIHAN RAMAH UNTUK PERAN YANG DITOLAK (kelompok_tolak)
    |----------------------------------------------------------------------
    | Sebagian peran sengaja TIDAK diberi kewenangan pada suatu modul, tetapi
    | juga tidak layak menerima halaman 403 telanjang — lebih baik dialihkan ke
    | dasbornya dengan pesan penjelas. Pola lama menulis ini sebagai literal
    | `if ($role === 'ao') { redirect()->route('dashboard.ao') ... }` di dalam
    | controller. Kini pasangan (kelompok → peran → tujuan) hidup di sini.
    |
    | Kelompok yang TIDAK punya entri → peran yang tidak berwenang menerima
    | `abort(403)` (perilaku aman bawaan).
    */
    'kelompok_tolak' => [
        // Pengelola beban operasional: AO ditolak tetapi dialihkan ke
        // dasbornya dengan pesan ramah (bukan 403). (dulu: literal di
        // PengeluaranBebanController::__construct())
        'pengelola_beban' => [
            'ao' => [
                'route' => 'dashboard.ao',
                'pesan' => 'Akses ditolak. Anda tidak memiliki izin mengelola beban operasional.',
            ],
        ],

        // Fitur Manajer yang DISEMBUNYIKAN dari sidebar (kinerja staf, kas,
        // bagi hasil, audit, laporan). Route-nya sengaja tetap terdaftar di
        // grup `role:manajer` agar percobaan akses langsung lewat address bar
        // tetap tercatat — bukan dihapus. Manajer sendiri dialihkan dengan
        // pesan ramah, bukan 403. (dulu: literal di
        // ManajerController::__construct() via FITUR_DISEMBUNYIKAN)
        'manajer_fitur_tersembunyi' => [
            'manajer' => [
                'route' => 'dashboard.manajer',
                'pesan' => 'Akses ditolak. Halaman ini tidak tersedia untuk peran Manajer.',
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Izin unduh dokumen privat (PII) per prefiks folder
    |----------------------------------------------------------------------
    | Menjawab "peran apa yang boleh mengunduh berkas di folder X" dari SATU
    | tempat. (dulu: DokumenController::IZIN_PER_PREFIKS yang dipatri di kode).
    |
    | Folder di luar peta ini dianggap tidak ada (404), sehingga tidak ada
    | berkas yang dapat diunduh secara tak sengaja.
    */
    'izin_dokumen' => [
        // Dokumen survei & agunan pembiayaan.
        'dokumen_survei' => ['admin', 'ao', 'teller', 'manajer', 'pengurus', 'dps', 'super_admin'],
        // Foto KTP anggota (dokumen CIF).
        'ktp' => ['admin', 'ao', 'teller', 'manajer', 'pengurus', 'dps', 'super_admin'],
        // Dokumen pengajuan pembiayaan (KTP, KK, slip gaji, agunan).
        'dokumen-pengajuan' => ['admin', 'ao', 'teller', 'manajer', 'pengurus', 'dps', 'super_admin'],
        // Foto KTP/selfie & bukti bayar pengajuan rekening.
        'pengajuan' => ['admin', 'ao', 'teller', 'manajer', 'pengurus', 'dps', 'super_admin'],
        // Lampiran opini & catatan DPS.
        'dps_opini' => ['admin', 'manajer', 'pengurus', 'dps', 'super_admin'],
        // Dokumen pendukung verifikasi AML/PPATK.
        'aml_documents' => ['admin', 'manajer', 'pengurus', 'dps', 'super_admin'],
    ],

];
