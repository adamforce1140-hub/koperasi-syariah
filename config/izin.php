<?php

/**
 * SSOT IZIN RUTE — DYN-18.
 *
 * Sebelumnya setiap rute menyebut peran secara langsung (`role:teller,manajer`).
 * Cara itu mengikat nama peran ke definisi rute sehingga:
 *   - menambah peran baru menuntut menyunting puluhan baris rute;
 *   - tidak ada satu tempat untuk menjawab "siapa yang boleh membuka kas?";
 *   - penamaan wewenang tidak semantik (hanya daftar peran).
 *
 * Kini rute menyebut IZIN bernama (mis. `izin:kas.opening`) dan berkas ini
 * memetakan izin tersebut ke peran yang menjalankannya. Middleware `izin:`
 * (App\Http\Middleware\CekIzin) menegakkannya.
 *
 * ATURAN:
 *   1. Nilai peran WAJIB nama peran sah pada config/role_hierarki.php
 *      (`HierarkiRole::peran()`). Nama izin yang tidak terdaftar di sini
 *      ditolak keras oleh middleware (InvalidArgumentException), bukan
 *      diam-diam 403 — supaya salah ketik terlihat saat rute diuji.
 *   2. Satu izin = satu wewenang fungsional. Jangan membuat izin per tombol.
 *   3. Pemetaan di bawah adalah PEMINDAHAN PERSIS dari `role:` yang berlaku
 *      sebelumnya. Tidak ada peran yang ditambah/dikurangi; perubahan hak
 *      akses harus lewat keputusan tersendiri, bukan refactor ini.
 *   4. Beberapa izin pada satu rute bersifat DAN (semua harus lolos).
 *   5. PUNCAK ADMINISTRATIF: `super_admin` terdaftar pada SETIAP izin modul
 *      (seluruh operasi baca/tulis) KECUALI:
 *        - `laporan.audit` & `dps.pengawasan` — pengawasan/audit independen
 *          tetap eksklusif DPS (pemisahan tugas: pengelola tidak mengaudit
 *          dirinya sendiri);
 *        - `approval.transaksi`, `pembiayaan.approve`, `pembiayaan.persetujuan`,
 *          `terminasi.approval` — aksi PERSETUJUAN tetap milik penyetuju
 *          (maker-checker), Super Admin bukan pemutus;
 *        - `nasabah.swalayan` — layanan mandiri anggota, terikat rekaman
 *          Anggota milik pengguna, bukan modul administratif.
 *      Dashboard per peran (`dasbor.*`) juga sengaja eksklusif pemiliknya:
 *      ia ruang kerja pribadi peran, sedangkan Super Admin memiliki
 *      `dashboard.super-admin` yang mencakup ringkasan seluruh unit.
 */
return [

    'izin' => [

        // ─── Dasbor (satu izin per peran; halaman ringkas ikut izin yang sama) ───
        // Eksklusif pemilik peran: ruang kerja pribadi. Super Admin memakai
        // `dashboard.super-admin` (izin `super_admin.administrasi`).
        'dasbor.teller' => ['teller'],
        'dasbor.ao' => ['ao'],
        'dasbor.manajer' => ['manajer'],
        'dasbor.pengurus' => ['pengurus'],

        // ─── Modul Account Officer ───
        'ao.pembiayaan' => ['ao', 'super_admin'],
        'ao.cif' => ['ao', 'super_admin'],

        // ─── CIF Anggota (satu izin per peran pemilik halaman) ───
        'cif.teller' => ['teller', 'super_admin'],
        'cif.manajer' => ['manajer', 'super_admin'],
        'cif.pengurus' => ['pengurus', 'super_admin'],

        // ─── Operasional harian ───
        // T-4 — `ao` SENGAJA ikut di sini meski AO bukan pengelola beban.
        // Gerbang rute (`izin:`) hanya menentukan siapa yang boleh MENYENTUH
        // modul; wewenang sebenarnya diperiksa `PengeluaranBebanController`
        // lewat kelompok `pengelola_beban` (teller + manajer). AO diloloskan
        // gerbang rute supaya controller dapat memberi PENGALIHAN RAMAH
        // (`role_hierarki.kelompok_tolak.pengelola_beban`) alih-alih 403
        // mentah. Bila `ao` dicabut dari sini, pengalihan itu tidak akan
        // pernah tercapai dan AO menerima 403.
        'beban.operasional' => ['teller', 'manajer', 'ao', 'super_admin'],
        'manajer.operasional' => ['manajer', 'super_admin'],
        'pengurus.operasional' => ['pengurus', 'super_admin'],
        'teller.loket' => ['teller', 'manajer', 'pengurus', 'super_admin'],

        // K-2 — EKSEKUSI KAS, bukan sekadar modul loket.
        // `teller.loket` di atas bersifat "boleh masuk modul loket" sehingga
        // manajer/pengurus ikut di dalamnya (untuk koreksi & pengawasan).
        // Pembukaan deposito MEMINDAHKAN DANA anggota, jadi wewenangnya
        // dipersempit ke pemegang laci saja: teller, ditambah super_admin
        // sebagai peran teknis tertinggi. Manajer/pengurus menyetujui
        // (mis. `pembiayaan.approve`) tetapi tidak mengeksekusi kas.
        'kas.loket.eksekusi' => ['teller', 'super_admin'],
        'anggota.edit' => ['teller', 'super_admin'],
        'angsuran.setoran' => ['teller', 'super_admin'],

        // ─── Master data & konfigurasi sistem (Super Admin) ───
        'master.data' => ['super_admin'],   // produk/akad, user & hak akses, riwayat pengujian
        'simpanan.jenis' => ['super_admin'],
        'coa.kelola' => ['super_admin'],
        'super_admin.administrasi' => ['super_admin'],

        // ─── Kebijakan & keuangan Pengurus ───
        'kebijakan.koperasi' => ['pengurus', 'super_admin'],
        'pengurus.keuangan' => ['pengurus', 'super_admin'],      // AML, laporan syariah, dana kebajikan, SHU
        'dana.cadangan' => ['pengurus', 'super_admin'],
        'zakat.kelola' => ['pengurus', 'manajer', 'super_admin'],
        'ckpn.lihat' => ['manajer', 'pengurus', 'dps', 'super_admin'],
        'ckpn.posting' => ['pengurus', 'super_admin'],

        // ─── Pengawasan DPS ───
        // Eksklusif DPS: pengawasan syariah & audit independen.
        'dps.pengawasan' => ['dps'],

        // ─── Pengajuan & persetujuan ───
        'pengajuan.rekening' => ['teller', 'manajer', 'pengurus', 'super_admin'],
        'approval.transaksi' => ['manajer', 'pengurus'],
        'pembiayaan.pengajuan' => ['ao', 'manajer', 'pengurus', 'super_admin'],

        // Menginput pengajuan baru (form pada halaman pengajuan-pembiayaan).
        // Halaman boleh dibuka `pengurus` untuk memantau, tetapi memasukkan
        // pengajuan adalah tugas AO/Manajer — bukan wewenang Pengurus.
        'pembiayaan.pengajuan.masukan' => ['ao', 'manajer', 'super_admin'],
        'pembiayaan.approve' => ['manajer', 'pengurus'],
        'pembiayaan.persetujuan' => ['pengurus'],
        'produk.pembiayaan' => ['pengurus', 'super_admin'],
        'autodebit.konfirmasi' => ['manajer', 'super_admin', 'admin'],

        // ─── Tulis buku besar lewat API (K-1 — pemisahan tugas) ───
        // routes/api.php sebelumnya menyebut `role:admin,manajer,pengurus,teller`
        // sehingga `admin` — yang menurut matriks ini hanya memegang wewenang
        // administratif (autodebit.konfirmasi, pengaturan.admin) — dapat
        // membuat/mengubah/menghapus rekening, transaksi, angsuran, dan jurnal.
        // `admin` sengaja TIDAK dicantumkan: penulis buku besar adalah pemegang
        // operasi/akuntansi (teller, manajer, pengurus) plus super_admin.
        'buku_besar.tulis' => ['teller', 'manajer', 'pengurus', 'super_admin'],

        // ─── Baca API operasional (migrasi `role:` pada routes/api.php) ───
        // Pemindahan persis gerbang lama `role:admin,manajer,pengurus,teller,ao,dps`
        // ditambah `super_admin` (aturan 5). `admin` memang berwenang MEMBACA
        // daftar/laporan; menulis buku besar tetap di `buku_besar.tulis`.
        'api.baca.operasional' => ['admin', 'manajer', 'pengurus', 'teller', 'ao', 'dps', 'super_admin'],

        // ─── Deposito: wewenang fungsional ───
        // Mencetak bilyet: pemindahan persis `role:admin,ao,manajer,pengurus,teller`
        // ditambah `super_admin`. Batas "hanya bilyet milik sendiri" tetap
        // ditegakkan controller lewat kelompok `pencetak_bilyet`.
        'deposito.cetak_bilyet' => ['admin', 'ao', 'manajer', 'pengurus', 'teller', 'super_admin'],

        // Pencairan dini mengeluarkan dana dari laci: hanya pemegang laci.
        // `super_admin` sengaja DIKECUALIKAN (lihat `pewaris_puncak_kecuali`
        // pada config/role_hierarki.php), sejalan dengan controller.
        'deposito.pencairan_dini' => ['teller'],

        // Kontrak pembiayaan menyentuh AKAD, bukan kas loket: `teller` dan
        // `admin` tidak berwenang. Sejalan dengan penulis akad di web.
        // Endpoint API tulis pembiayaan juga berada di balik halaman
        // `pembiayaan.approve` (menu Manajer/Pengurus), sehingga super_admin
        // menjangkaunya lewat induk tersebut tanpa perlu menu tersendiri.
        'pembiayaan.kontrak' => ['manajer', 'pengurus', 'super_admin'],

        // ─── Keanggotaan ───
        'terminasi.pengajuan' => ['teller', 'manajer', 'pengurus', 'super_admin'],
        'terminasi.eksekusi' => ['teller', 'manajer', 'pengurus', 'super_admin'],
        'terminasi.approval' => ['manajer', 'pengurus'],
        'nasabah.swalayan' => ['anggota'],

        // ─── Laporan ───
        'laporan.neraca' => ['super_admin', 'manajer', 'pengurus', 'dps'],
        'laporan.buku_besar' => ['super_admin', 'manajer', 'pengurus', 'dps'],
        'laporan.psak101' => ['super_admin', 'manajer', 'pengurus', 'dps'],
        'laporan.manajer' => ['manajer', 'super_admin'],
        'laporan.audit' => ['dps'],

        // ─── Pengaturan kebijakan (alias UI /admin/settings) ───
        'pengaturan.admin' => ['super_admin', 'admin', 'pengurus'],
    ],

];
