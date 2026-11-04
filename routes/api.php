<?php

use App\Http\Controllers\AkunController;
use App\Http\Controllers\AngsuranController;
use App\Http\Controllers\Api\AnggotaController as ApiAnggotaController;
use App\Http\Controllers\Api\DepositoController as ApiDepositoController;
use App\Http\Controllers\ApprovalTransaksiController;
use App\Http\Controllers\DynamicLoanController;
use App\Http\Controllers\JurnalController;
use App\Http\Controllers\JurnalDetailController;
use App\Http\Controllers\PembiayaanController;
use App\Http\Controllers\PengajuanRekeningController;
use App\Http\Controllers\RekeningController;
use App\Http\Controllers\TransaksiController;
use App\Http\Controllers\TransaksiMobileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'Sistem Informasi Koperasi Syariah',
        'version' => '1.0.0',
        'modules' => [
            'users', 'anggota', 'rekening', 'transaksi',
            'pembiayaan', 'angsuran', 'akun', 'jurnal', 'jurnal_detail',
        ],
    ]);
});

/*
 |--------------------------------------------------------------------------
 | MASTER DATA & BUKU BESAR — WAJIB TERAUTENTIKASI (RBAC)
 |--------------------------------------------------------------------------
 | Seluruh endpoint di bawah ini mengekspos data keuangan sensitif (data
 | anggota/NASABAH beserta NIK, saldo rekening, transaksi, portofolio
 | pembiayaan, jurnal umum, dan bagan akun). Sebelumnya endpoint ini
 | terbuka TANPA autentikasi (anonim dapat membaca & memutasi buku besar).
 |
 | Middleware `web` dipasang eksplisit karena routes/api.php tidak mendapat
 | grup `web` dari bootstrap/app.php, sedangkan autentikasi aplikasi ini
 | berbasis sesi (bukan token Sanctum). Urutan `auth` lalu `role:` memastikan
 | pengguna anonim diarahkan ke login dan peran yang tidak berhak menerima 403.
 |
 | Segregation of duties: peran `ao` (analis pembiayaan) dan `dps` (Dewan
 | Pengawas Syariah) hanya diberi akses BACA; hanya pemegang operasi dan
 | akuntansi (teller, manajer, pengurus) yang boleh menulis/memutasi lewat
 | izin bernama `buku_besar.tulis` / `pembiayaan.kontrak`. `admin` — peran
 | administratif — hanya membaca (K-1).
 */
Route::middleware(['web', 'auth', 'izin:api.baca.operasional'])->group(function () {
    Route::apiResource('anggota', ApiAnggotaController::class)->only(['index', 'show']);
    Route::apiResource('rekening', RekeningController::class)->only(['index', 'show']);
    Route::apiResource('transaksi', TransaksiController::class)->only(['index', 'show']);
    Route::apiResource('pembiayaan', PembiayaanController::class)->only(['index', 'show']);
    Route::apiResource('angsuran', AngsuranController::class)->only(['index', 'show']);
    Route::apiResource('jurnal', JurnalController::class)->only(['index', 'show']);

    Route::get('jurnal/{jurnal}/detail', [JurnalDetailController::class, 'index']);
    Route::get('jurnal/{jurnal}/detail/{detail}', [JurnalDetailController::class, 'show']);
});

/*
 * Baca master akun memakai izin operasional yang sama di atas; izin tersebut
 * sudah memuat `super_admin`. Pemegang `coa.kelola` (satu-satunya penulis CoA) harus mampu
 * membaca apa yang baru dituliskannya; tanpa ini `POST /api/akun` menjawab 201
 * tetapi `GET /api/akun/{id}` 403 — wewenang baca lebih sempit daripada tulis.
 */
Route::middleware(['web', 'auth', 'izin:api.baca.operasional'])->group(function () {
    Route::apiResource('akun', AkunController::class)->only(['index', 'show']);
});

/*
 * Mutasi buku besar (create/update/delete, posting, void) dibatasi pada
 * petugas akuntansi. Role `ao`, `dps`, dan `admin` DILARANG menulis jurnal.
 *
 * K-1 — sebelumnya grup ini memakai `role:admin,manajer,pengurus,teller`
 * sehingga `admin` dapat membuat/mengubah/menghapus rekening, transaksi,
 * angsuran, dan jurnal, padahal `config/izin.php` hanya memberinya wewenang
 * administratif. Kini gerbangnya memakai izin bernama agar satu sumber
 * kebenaran (config/izin.php) tegas.
 */
Route::middleware(['web', 'auth', 'izin:buku_besar.tulis'])->group(function () {
    // `anggota` TIDAK punya endpoint tulis di sini: pembuatan/penghapusan
    // anggota wajib lewat alur resmi (CIF, setoran awal, exit keanggotaan)
    // agar nomor anggota, NIK terenkripsi, dan jurnal simpanan konsisten.
    Route::apiResource('rekening', RekeningController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('transaksi', TransaksiController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('angsuran', AngsuranController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('jurnal', JurnalController::class)->only(['store', 'update', 'destroy']);

    Route::post('jurnal/{jurnal}/post', [JurnalController::class, 'post']);
    Route::post('jurnal/{jurnal}/void', [JurnalController::class, 'void']);

    Route::post('jurnal/{jurnal}/detail', [JurnalDetailController::class, 'store']);
    Route::put('jurnal/{jurnal}/detail/{detail}', [JurnalDetailController::class, 'update']);
    Route::delete('jurnal/{jurnal}/detail/{detail}', [JurnalDetailController::class, 'destroy']);
});

/*
 * Master akun (`akun`) dipakai sebagai rujukan `rekening.akun_id` dan
 * `pembiayaan.akun_id`. Menambah/mengubah/menghapusnya mengubah struktur
 * buku besar, sehingga wewenangnya mengikuti SSOT `coa.kelola`
 * (config/izin.php) — sama dengan halaman CoA di web. Sebelumnya endpoint
 * ini berada di grup `role:...teller` sehingga loket dapat mengubah master
 * akun, bertentangan dengan matriks izin.
 */
Route::middleware(['web', 'auth', 'izin:coa.kelola'])->group(function () {
    Route::apiResource('akun', AkunController::class)->only(['store', 'update', 'destroy']);
});

/*
 * T-1 (TINGGI) — Kontrak pembiayaan (create/update/delete) mengubah AKAD &
 * nilai keuangan yang menjadi dasar pencatatan piutang/margin. Ini BUKAN
 * kewenangan loket: `teller` hanya memutasi kas/angsuran. Karena itu menulis
 * kontrak pembiayaan dibatasi pada pejabat yang berwenang atas akad.
 *
 * K-1 — `admin` juga dicabut: peran administratif tidak memegang akad.
 * Gerbang memakai izin bernama `pembiayaan.kontrak` (config/izin.php).
 */
Route::middleware(['web', 'auth', 'izin:pembiayaan.kontrak'])->group(function () {
    Route::apiResource('pembiayaan', PembiayaanController::class)->only(['store', 'update', 'destroy']);
});

// Pengajuan rekening (mobile app)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('pengajuan-rekening', [PengajuanRekeningController::class, 'apiStore']);
    Route::get('pengajuan-rekening/status', [PengajuanRekeningController::class, 'apiStatus']);
    Route::post('pengajuan-rekening/bukti-bayar', [PengajuanRekeningController::class, 'apiUploadBukti']);
});

// Produk pembiayaan (publik, untuk mobile)
Route::get('produk-pembiayaan', [DynamicLoanController::class, 'apiIndex'])
    ->name('api.produk-pembiayaan.index');

// Deposito / Simpanan Berjangka Syariah (akad Mudharabah Muthlaqah)
Route::prefix('deposito')->group(function () {
    /*
     * BACA — master produk & parameter akun. Tidak memuat data nasabah.
     * Tetap WAJIB terautentikasi: role `ao` & `dps` boleh membaca.
     */
    Route::middleware(['web', 'auth', 'izin:api.baca.operasional'])->group(function () {
        Route::get('products', [ApiDepositoController::class, 'products']);
        Route::get('coa-config', [ApiDepositoController::class, 'coaConfig']);
        Route::post('simulate', [ApiDepositoController::class, 'simulate']);
        Route::get('member/{memberId}', [ApiDepositoController::class, 'member'])->whereNumber('memberId');
    });

    /*
     * TULIS — pembukaan deposito memindahkan dana nasabah dari Simpanan
     * Sukarela ke Simpanan Berjangka dan membentuk jurnal.
     *
     * K-2 — ini TRANSAKSI LOKET yang MENGEKSEKUSI KAS. Tiga gerbang wajib:
     *   1. `izin:kas.loket.eksekusi` — hanya pemegang laci (teller) dan
     *      super_admin. `teller.loket` (dipakai grup baca di atas maupun
     *      jalur web) terlalu LEBAR di sini: isinya juga manajer/pengurus
     *      yang berwenang menyetujui, bukan mengeluarkan dana anggota.
     *      `admin`, `manajer`, `pengurus`, `dps`, dan `ao` DITOLAK — AO
     *      hanya berwenang atas analisis & pengajuan pembiayaan.
     *   2. `kas.terbuka` — dana anggota tidak boleh berpindah produk sebelum
     *      Buka Kas, agar angka fisik penutupan kas mencerminkan mutasi.
     *      Middleware ini hanya menegakkan aturan bagi pemegang laci;
     *      pembatasan peran sepenuhnya ditegakkan gerbang (1).
     * Otorisasi per-baris ditegakkan ulang di Api\DepositoController::store.
     */
    Route::middleware(['web', 'auth', 'izin:kas.loket.eksekusi', 'kas.terbuka'])
        ->post('store', [ApiDepositoController::class, 'store']);

    /*
     * Pencairan dini (break deposito) sebelum jatuh tempo.
     *
     * Sama seperti cetak bilyet, endpoint ini diakses dari halaman aplikasi
     * yang autentikasinya berbasis sesi, sehingga middleware `web`+`auth`
     * harus dipasang eksplisit (berkas ini tidak mendapat grup `web`).
     *
     * SEGREGATION OF DUTIES — HANYA `teller`.
     * Pencairan dini mengeluarkan dana koperasi secara tunai/transfer dan
     * mengubah saldo Simpanan Berjangka (COA 2-1003) secara permanen, sehingga
     * kewenangannya dibatasi pada petugas loket. Role `ao` DILARANG: AO hanya
     * berwenang atas analisis & pengajuan pembiayaan, bukan eksekusi kas.
     * Otorisasi per-baris ditegakkan ulang di
     * Api\DepositoController::earlyWithdrawal (defense-in-depth).
     * Gerbang rute memakai izin bernama `deposito.pencairan_dini`
     * (config/izin.php) yang isinya tepat `['teller']`.
     */
    Route::middleware(['web', 'auth', 'izin:deposito.pencairan_dini'])
        ->post('{id}/early-withdrawal', [ApiDepositoController::class, 'earlyWithdrawal'])
        ->whereNumber('id')
        ->name('deposito.early-withdrawal');

    /*
     * Cetak Bilyet PDF.
     *
     * Middleware `web` dipakai secara eksplisit karena berkas ini berada di
     * routes/api.php (tidak mendapat grup `web` dari bootstrap/app.php),
     * sedangkan autentikasi aplikasi ini berbasis sesi — bukan token Sanctum.
     * Urutan `auth` lalu `izin:` memastikan pengguna anonim diarahkan ke login
     * dan peran yang tidak berhak menerima 403. Otorisasi per-baris (mis.
     * pencocokan pemilik & penolakan status belum terverifikasi) ditegakkan
     * di DepositoController::generateBilyetPdf.
     *
     * Gerbang memakai izin bernama `deposito.cetak_bilyet` — pemindahan persis
     * daftar peran lama ditambah super_admin. Batas "hanya bilyet milik
     * sendiri" tetap di controller (kelompok `pencetak_bilyet`).
     */
    Route::middleware(['web', 'auth', 'izin:deposito.cetak_bilyet'])
        ->get('{id}/print-bilyet', [ApiDepositoController::class, 'generateBilyetPdf'])
        ->whereNumber('id')
        ->name('deposito.print-bilyet');

    /*
     * Distribusi Bagi Hasil dari Laba Riil (R8).
     *
     * Menghitung total pendapatan investasi riil periode (margin + bagi hasil
     * + ujroh dari angsuran yang benar-benar dibayar) lalu mengalokasikannya
     * secara PROPORSIONAL ke seluruh deposito aktif dan memposting jurnal.
     * Nominal pendapatan TIDAK diterima dari pengguna — dihitung sistem.
     *
     * SEGREGATION OF DUTIES — HANYA pengelola (`manajer,pengurus`).
     * Endpoint ini membentuk jurnal beban bagi hasil secara massal sehingga
     * berada di luar wewenang teller/AO (eksekusi transaksi harian).
     *
     * K-2 — `admin` DICABUT (sebelumnya `role:admin,manajer,pengurus`):
     * peran administratif tidak memutuskan/memposting distribusi laba.
     * Gerbang memakai izin bernama `pembiayaan.approve` yang pada matriks
     * berisi manajer + pengurus (super_admin dikecualikan: bukan pemutus).
     */
    Route::middleware(['web', 'auth', 'izin:pembiayaan.approve'])
        ->post('distribusi-bagi-hasil', [ApiDepositoController::class, 'distribusiBagiHasil'])
        ->name('deposito.distribusi-bagi-hasil');
});

/*
 * Transaksi dengan limit check (protected).
 *
 * Setor/tarik tunai menggerakkan uang fisik, sehingga aturan sesi kas yang
 * berlaku pada jalur web (routes/web.php → `kas.terbuka`) WAJIB berlaku juga
 * di sini. Tanpa guard ini, pemegang laci dapat memproses transaksi tunai
 * lewat API meski `kas_harian` belum dibuka, sehingga angka fisik pada
 * penutupan kas tidak lagi mencerminkan transaksi yang benar-benar terjadi.
 *
 * `PastikanKasTerbuka` hanya menegakkan aturan bagi pemegang laci (teller);
 * peran non-teller — termasuk anggota yang bertransaksi atas rekeningnya
 * sendiri — tetap diloloskan.
 */
Route::middleware(['auth:sanctum', 'token.menganggur'])->group(function () {
    Route::post('transaksi/setor', [TransaksiMobileController::class, 'setor'])
        ->middleware(['throttle:transaksi', 'kas.terbuka'])->name('api.transaksi.setor');
    Route::post('transaksi/tarik', [TransaksiMobileController::class, 'tarik'])
        ->middleware(['throttle:transaksi', 'kas.terbuka'])->name('api.transaksi.tarik');
    Route::get('approval/status', [ApprovalTransaksiController::class, 'apiStatus'])
        ->middleware('throttle:baca-api')->name('api.approval.status');
});
