<?php

use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AnggotaController;
use App\Http\Controllers\AO\KeringananTazirController;
use App\Http\Controllers\AOController;
use App\Http\Controllers\ApprovalTransaksiController;
use App\Http\Controllers\AuditLaporanKeuanganController;
use App\Http\Controllers\AutodebitController;
use App\Http\Controllers\CifController;
use App\Http\Controllers\CkpnController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\DanaCadanganController;
use App\Http\Controllers\Dashboard\AoDashboardController;
use App\Http\Controllers\Dashboard\DpsController;
use App\Http\Controllers\Dashboard\ManajerController;
use App\Http\Controllers\Dashboard\PengurusController;
use App\Http\Controllers\Dashboard\RiwayatPengujianController;
use App\Http\Controllers\Dashboard\SuperAdminController;
use App\Http\Controllers\Dashboard\TellerDashboardController;
use App\Http\Controllers\DenyutController;
use App\Http\Controllers\DepositoPenaltyReliefController;
use App\Http\Controllers\DokumenController;
use App\Http\Controllers\DpsAuditController;
use App\Http\Controllers\DynamicLoanController;
use App\Http\Controllers\HariLiburController;
use App\Http\Controllers\JenisSimpananController;
use App\Http\Controllers\KeringanTazirController;
use App\Http\Controllers\LaporanKeuanganSyariahController;
use App\Http\Controllers\Manajer\ApprovalTazirController;
use App\Http\Controllers\Manajer\ClosingApprovalController;
use App\Http\Controllers\MemberTerminationController;
use App\Http\Controllers\NasabahController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PencairanController;
use App\Http\Controllers\PengajuanPembiayaanController;
use App\Http\Controllers\PengajuanPembiayaanIntegController;
use App\Http\Controllers\PengajuanPublikController;
use App\Http\Controllers\PengajuanRekeningController;
use App\Http\Controllers\PengeluaranBebanController;
use App\Http\Controllers\Pengurus\AmlComplianceController;
use App\Http\Controllers\Pengurus\ShuController;
use App\Http\Controllers\ProdukAkadController;
use App\Http\Controllers\Teller\ClosingController;
use App\Http\Controllers\Teller\SimpananWajibController;
use App\Http\Controllers\TellerAnggotaController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\TellerPencairanController;
use App\Http\Controllers\WebAuthController;
use App\Http\Controllers\ZakatController;
use Illuminate\Support\Facades\Route;

// Halaman utama: pengguna yang sudah login diarahkan ke dashboard sesuai peran,
// tamu langsung diarahkan ke halaman login (tanpa halaman depan).
Route::get('/', function () {
    if (auth()->check()) {
        return app(WebAuthController::class)->redirectByRolePublic(auth()->user());
    }

    return redirect()->route('login');
});

// Form pengajuan rekening PUBLIK (tanpa login)
// Endpoint publik dibatasi lajunya untuk mencegah penyalahgunaan (spam
// pengajuan & enumerasi NIK). Lihat docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md.
Route::get('/buka-rekening', [PengajuanPublikController::class, 'form'])
    ->middleware('throttle:30,1')
    ->name('pengajuan-rekening.publik.form');
Route::post('/buka-rekening', [PengajuanPublikController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('pengajuan-rekening.publik.store');

// Auth routes
Route::get('/login', [WebAuthController::class, 'loginForm'])->name('login')->middleware('guest');
Route::post('/login', [WebAuthController::class, 'login'])->name('login.post')->middleware(['guest', 'throttle:5,1']);
// 5g — logout mengakhiri sesi: hanya untuk pengguna yang sedang masuk.
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout')->middleware('auth');

/*
 |------------------------------------------------------------------
 | LUPA KATA SANDI (C1)
 |------------------------------------------------------------------
 | Rute tamu saja. Permintaan tautan dibatasi `throttle:5,1` agar
 | tidak dijadikan alat spam email maupun enumerasi akun; respons
 | yang dikembalikan selalu sama untuk email terdaftar/tidak.
 */
Route::middleware('guest')->group(function () {
    Route::get('/lupa-kata-sandi', [PasswordResetController::class, 'formLupa'])
        ->name('password.request');
    Route::post('/lupa-kata-sandi', [PasswordResetController::class, 'kirimTautan'])
        ->middleware('throttle:5,1')->name('password.email');
    Route::get('/atur-ulang-kata-sandi/{token}', [PasswordResetController::class, 'formReset'])
        ->name('password.reset');
    Route::post('/atur-ulang-kata-sandi', [PasswordResetController::class, 'simpan'])
        ->middleware('throttle:5,1')->name('password.update');
});

// Routes authenticated
Route::middleware(['auth'])->group(function () {

    /*
     |------------------------------------------------------------------
     | DENYUT SINKRONISASI REAL-TIME
     |------------------------------------------------------------------
     | Satu endpoint untuk semua peran terautentikasi. Endpoint ini TIDAK
     | menyentuh database — hanya membaca tanda versi dari cache — sehingga
     | aman untuk polling berkala maupun sambungan SSE/long-polling.
     |
     | Ditempatkan di luar grup RBAC per-modul karena hanya mengembalikan
     | sidik jari perubahan, bukan data bisnis.
     */
    Route::get('/denyut', [DenyutController::class, 'index'])
        ->name('denyut');

    /*
     |------------------------------------------------------------------
     | UNDUHAN DOKUMEN PRIVAT (PII)
     |------------------------------------------------------------------
     | Dokumen berisi data pribadi (KTP, selfie, dokumen pengajuan, lampiran
     | AML/DPS) disimpan pada disk privat `dokumen` yang tidak terekspos ke
     | web. Rute ini memverifikasi autentikasi (dari grup `auth`) sekaligus
     | peran pengguna sebelum melayani berkas. Lihat DokumenController.
     */
    Route::get('/berkas/{path}', [DokumenController::class, 'unduh'])
        ->where('path', '.*')
        ->name('berkas.unduh');

    // ============================================
    // DASHBOARD ROUTES (sesuai RBAC 6 role)
    // ============================================

    // Dashboard Teller
    Route::get('/teller/dashboard', [TellerDashboardController::class, 'index'])
        ->middleware('izin:dasbor.teller')->name('dashboard.teller');
    // Versi ringkas (HTML terpotong) untuk sinkronisasi real-time tanpa reload penuh.
    Route::get('/teller/dashboard/ringkas', [TellerDashboardController::class, 'ringkas'])
        ->middleware('izin:dasbor.teller')->name('dashboard.teller.ringkas');

    // Dashboard Account Officer (AO)
    Route::get('/ao/dashboard', [AoDashboardController::class, 'index'])
        ->middleware('izin:dasbor.ao')->name('dashboard.ao');
    Route::get('/ao/dashboard/ringkas', [AoDashboardController::class, 'ringkas'])
        ->middleware('izin:dasbor.ao')->name('dashboard.ao.ringkas');

    // ─── Modul AO ───
    Route::middleware('izin:ao.pembiayaan')->prefix('ao')->group(function () {
        // Pembiayaan & Survei
        Route::get('/pengajuan', [AOController::class, 'pengajuanIndex'])->name('ao.pengajuan.index');
        Route::get('/pengajuan/create', [AOController::class, 'pengajuanCreate'])->name('ao.pengajuan.create');
        Route::post('/pengajuan', [AOController::class, 'pengajuanStore'])->name('ao.pengajuan.store');
        Route::get('/pengajuan/{id}', [AOController::class, 'pengajuanShow'])->name('ao.pengajuan.show');
        Route::post('/pengajuan/{id}/approve', [AOController::class, 'pengajuanApprove'])->name('ao.pengajuan.approve');
        Route::post('/pengajuan/{id}/reject', [AOController::class, 'pengajuanReject'])->name('ao.pengajuan.reject');
        Route::post('/pengajuan/{id}/dokumen', [AOController::class, 'uploadDokumen'])->name('ao.pengajuan.dokumen.upload');
        Route::delete('/pengajuan/{id}/dokumen/{dokumenId}', [AOController::class, 'hapusDokumen'])->name('ao.pengajuan.dokumen.hapus');
        Route::get('/survei', [AOController::class, 'surveiIndex'])->name('ao.survei.index');
        Route::post('/survei/{id}', [AOController::class, 'surveiStore'])->name('ao.survei.store');
        Route::get('/tracking', [AOController::class, 'trackingIndex'])->name('ao.tracking.index');
        // Monitoring
        Route::get('/nasabah', [AOController::class, 'nasabahIndex'])->name('ao.nasabah.index');
        Route::get('/nasabah/{id}', [AOController::class, 'nasabahShow'])->name('ao.nasabah.show');
        Route::get('/tunggakan', [AOController::class, 'tunggakanIndex'])->name('ao.tunggakan.index');
        Route::post('/tunggakan/{id}/catatan', [AOController::class, 'tunggakanCatatan'])->name('ao.tunggakan.catatan');
        Route::get('/restrukturisasi', [AOController::class, 'restrukturisasiIndex'])->name('ao.restrukturisasi.index');
        Route::post('/restrukturisasi', [AOController::class, 'restrukturisasiStore'])->name('ao.restrukturisasi.store');
        // Tools
        Route::get('/simulasi', [AOController::class, 'simulasiIndex'])->name('ao.simulasi.index');

        // AJAX Tambah Anggota Baru langsung dari AO
        Route::post('/anggota/quick-store', [AOController::class, 'quickStoreAnggota'])->name('ao.anggota.quick-store');

        // AJAX: Search anggota (Tom Select autocomplete)
        Route::get('/anggota/search', [AOController::class, 'searchAnggota'])->name('ao.anggota.search');
        // AJAX: Info finansial anggota (saldo simpanan + deposito)
        Route::get('/anggota/{id}/finansial', [AOController::class, 'anggotaFinansial'])->name('ao.anggota.finansial');

        // Verifikasi Keringanan Ta'zir (alur: Teller -> AO -> Manajer)
        Route::get('/keringanan-tazir', [KeringananTazirController::class, 'index'])->name('ao.keringanan-tazir.index');
        Route::get('/keringanan-tazir/{relief}', [KeringananTazirController::class, 'show'])->name('ao.keringanan-tazir.show');
        Route::post('/keringanan-tazir/{relief}/rekomendasi', [KeringananTazirController::class, 'rekomendasi'])->name('ao.keringanan-tazir.rekomendasi');

        // Verifikasi Keringanan Penalti Deposito (alur: Teller -> AO -> Manajer)
        Route::get('/keringanan-penalti-deposito', [DepositoPenaltyReliefController::class, 'aoIndex'])->name('ao.keringanan-penalti.index');
        Route::post('/keringanan-penalti-deposito/{relief}/rekomendasi', [DepositoPenaltyReliefController::class, 'rekomendasi'])->name('ao.keringanan-penalti.rekomendasi');
    });

    // ─── Modul CIF Anggota ───
    // AO: CRUD Data CIF
    Route::middleware('izin:ao.cif')->prefix('ao')->group(function () {
        Route::get('/cif', [CifController::class, 'index'])->name('ao.cif.index');
        Route::get('/cif/create', [CifController::class, 'create'])->name('ao.cif.create');
        Route::post('/cif', [CifController::class, 'store'])->name('ao.cif.store');
        // Alias: klien yang mem-POST langsung ke URL halaman form
        // (`/ao/cif/create`) diarahkan ke handler simpan yang sama, sehingga
        // tidak lagi mengembalikan HTTP 405 Method Not Allowed.
        Route::post('/cif/create', [CifController::class, 'store'])->name('ao.cif.store.create');
        Route::get('/cif/{id}', [CifController::class, 'show'])->name('ao.cif.show')->whereNumber('id');
        Route::get('/cif/{id}/edit', [CifController::class, 'edit'])->name('ao.cif.edit')->whereNumber('id');
        Route::put('/cif/{id}', [CifController::class, 'update'])->name('ao.cif.update')->whereNumber('id');
        // Transaksi lanjutan: hanya anggota ACTIVE yang boleh membuka rekening baru.
        Route::post('/cif/{id}/buka-rekening', [CifController::class, 'bukaRekening'])
            ->middleware('anggota.aktif:id')
            ->name('ao.cif.buka-rekening')->whereNumber('id');
    });

    // Teller: Search/Read CIF
    Route::middleware('izin:cif.teller')->prefix('teller')->group(function () {
        Route::get('/cif', [CifController::class, 'search'])->name('teller.cif.index');
        Route::get('/cif/{id}', [CifController::class, 'show'])->name('teller.cif.show')->whereNumber('id');
    });

    // Manajer: Read/Edit CIF + Portofolio
    Route::middleware('izin:cif.manajer')->prefix('manajer')->group(function () {
        Route::get('/cif', [CifController::class, 'index'])->name('manajer.cif.index');
        Route::get('/cif/{id}', [CifController::class, 'show'])->name('manajer.cif.show')->whereNumber('id');
        Route::get('/cif/{id}/edit', [CifController::class, 'edit'])->name('manajer.cif.edit')->whereNumber('id');
        Route::put('/cif/{id}', [CifController::class, 'update'])->name('manajer.cif.update')->whereNumber('id');
        Route::get('/cif/export', [CifController::class, 'export'])->name('manajer.cif.export');
    });

    // Pengurus: Read/Edit/Export CIF
    Route::middleware('izin:cif.pengurus')->prefix('pengurus')->group(function () {
        Route::get('/cif', [CifController::class, 'index'])->name('pengurus.cif.index');
        Route::get('/cif/{id}', [CifController::class, 'show'])->name('pengurus.cif.show')->whereNumber('id');
        Route::get('/cif/{id}/edit', [CifController::class, 'edit'])->name('pengurus.cif.edit')->whereNumber('id');
        Route::put('/cif/{id}', [CifController::class, 'update'])->name('pengurus.cif.update')->whereNumber('id');
        Route::get('/cif/export', [CifController::class, 'export'])->name('pengurus.cif.export');
    });

    // ─── Pengeluaran Beban Operasional — Input Teller/Keuangan/Manajer (CoA Beban 5-XXXX) ───
    // Guard `izin:` dicantumkan di level rute sebagai deklarasi wewenang yang
    // dapat dibaca mesin (menu, audit, dan pengujian invariant semuanya membaca
    // middleware rute). Nilainya PERSIS sama dengan yang di
    // PengeluaranBebanController::__construct() — perilaku controller tidak
    // berubah, deklarasi wewenangnya saja yang ditegaskan di sini. AO sengaja
    // ikut daftar supaya ia tetap menerima redirect + pesan ramah dari
    // controller, bukan 403 telanjang dari middleware; peran lain
    // (dps/pengurus/admin/anggota) tetap 403.
    Route::middleware('izin:beban.operasional')->prefix('pengeluaran-beban')->group(function () {
        Route::get('/', [PengeluaranBebanController::class, 'index'])->name('pengeluaran-beban.index');
        Route::post('/', [PengeluaranBebanController::class, 'store'])->name('pengeluaran-beban.store');
    });

    // Dashboard Manager
    Route::get('/manajer/dashboard', [ManajerController::class, 'index'])
        ->middleware('izin:dasbor.manajer')->name('dashboard.manajer');
    // Versi ringkas (HTML terpotong) untuk sinkronisasi real-time tanpa reload penuh.
    Route::get('/manajer/dashboard/ringkas', [ManajerController::class, 'ringkas'])
        ->middleware('izin:dasbor.manajer')->name('dashboard.manajer.ringkas');

    // ─── Modul Manajer ───
    Route::middleware('izin:manajer.operasional')->prefix('manajer')->group(function () {
        // Verifikasi & Approval
        Route::get('/approval-pembiayaan', [ManajerController::class, 'approvalPembiayaan'])->name('manajer.approval-pembiayaan.index');
        Route::get('/approval-pembiayaan/{id}', [ManajerController::class, 'approvalPembiayaanShow'])->name('manajer.approval-pembiayaan.show');
        Route::post('/approval-pembiayaan/{id}/approve', [ManajerController::class, 'approvalPembiayaanApprove'])->name('manajer.approval-pembiayaan.approve');
        Route::post('/approval-pembiayaan/{id}/reject', [ManajerController::class, 'approvalPembiayaanReject'])->name('manajer.approval-pembiayaan.reject');
        Route::post('/approval-pembiayaan/{id}/revisi', [ManajerController::class, 'approvalPembiayaanRevisi'])->name('manajer.approval-pembiayaan.revisi');
        Route::post('/approval-pembiayaan/{id}/forward', [ManajerController::class, 'approvalPembiayaanForward'])->name('manajer.approval-pembiayaan.forward');
        Route::get('/approval-transaksi', [ManajerController::class, 'approvalTransaksi'])->name('manajer.approval-transaksi.index');
        Route::get('/approval-restrukturisasi', [ManajerController::class, 'approvalRestrukturisasi'])->name('manajer.approval-restrukturisasi.index');
        // Approval Pengeluaran Beban > Rp 1.000.000
        Route::get('/approval-beban', [PengeluaranBebanController::class, 'approvalIndex'])->name('manajer.beban.index');
        Route::post('/approval-beban/{pengeluaran_beban}/approve', [PengeluaranBebanController::class, 'approve'])->name('manajer.beban.approve');
        Route::post('/approval-beban/{pengeluaran_beban}/reject', [PengeluaranBebanController::class, 'reject'])->name('manajer.beban.reject');
        Route::post('/approval-restrukturisasi/{id}/approve', [ManajerController::class, 'approveRestrukturisasi'])->name('manajer.approval-restrukturisasi.approve');
        Route::post('/approval-restrukturisasi/{id}/reject', [ManajerController::class, 'rejectRestrukturisasi'])->name('manajer.approval-restrukturisasi.reject');
        Route::get('/approval-restrukturisasi/{id}/addendum', [ManajerController::class, 'addendumRestrukturisasi'])->name('manajer.approval-restrukturisasi.addendum');

        // Monitoring & Portofolio
        Route::get('/pembiayaan-aktif', [ManajerController::class, 'pembiayaanAktif'])->name('manajer.pembiayaan-aktif.index');
        Route::get('/pembiayaan/{id}', [ManajerController::class, 'pembiayaanShow'])->name('manajer.pembiayaan.show');
        Route::get('/npf', [ManajerController::class, 'npf'])->name('manajer.npf.index');
        Route::get('/kinerja-staf', [ManajerController::class, 'kinerjaStaf'])->name('manajer.kinerja-staf.index');

        // Laporan & Likuiditas
        Route::get('/kas', [ManajerController::class, 'kas'])->name('manajer.kas.index');
        Route::get('/bagi-hasil', [ManajerController::class, 'bagiHasil'])->name('manajer.bagi-hasil.index');

        // Rekam Jejak
        Route::get('/audit', [ManajerController::class, 'audit'])->name('manajer.audit.index');

        // Keringanan Ta'zir (Approval akhir Manajer — alur baru Teller -> AO -> Manajer)
        Route::get('/keringanan-tazir', [ApprovalTazirController::class, 'index'])->name('manajer.keringanan-tazir.index');
        Route::post('/keringanan-tazir/{relief}/approve', [ApprovalTazirController::class, 'approve'])->name('manajer.keringanan-tazir.approve');
        Route::post('/keringanan-tazir/{relief}/reject', [ApprovalTazirController::class, 'reject'])->name('manajer.keringanan-tazir.reject');

        // Keringanan Penalti Deposito (Approval akhir Manajer — alur Teller -> AO -> Manajer)
        Route::get('/keringanan-penalti-deposito', [DepositoPenaltyReliefController::class, 'manajerIndex'])->name('manajer.keringanan-penalti.index');
        Route::post('/keringanan-penalti-deposito/{relief}/approve', [DepositoPenaltyReliefController::class, 'approve'])->name('manajer.keringanan-penalti.approve');
        Route::post('/keringanan-penalti-deposito/{relief}/reject', [DepositoPenaltyReliefController::class, 'reject'])->name('manajer.keringanan-penalti.reject');

        // Approval Closing Kas Teller — terima kas & posting jurnal pemindahan ke brankas
        Route::get('/closing', [ClosingApprovalController::class, 'index'])->name('manajer.closing.index');
        Route::post('/closing/{opname}/approve', [ClosingApprovalController::class, 'approve'])->name('manajer.closing.approve')->whereNumber('opname');
        Route::post('/closing/{opname}/reject', [ClosingApprovalController::class, 'reject'])->name('manajer.closing.reject')->whereNumber('opname');

        // Alias nama rute (dipakai sebagian tautan/menu) — mengarah ke controller yang sama
        Route::get('/closing-kas', [ClosingApprovalController::class, 'index'])->name('manajer.closing-kas.index');
    });

    // Dashboard Pengurus
    Route::get('/pengurus/dashboard', [PengurusController::class, 'index'])
        ->middleware('izin:dasbor.pengurus')->name('dashboard.pengurus');

    // Potongan real-time (mesin denyut) — hanya HTML yang terdampak.
    Route::get('/pengurus/dashboard/ringkas', [PengurusController::class, 'ringkas'])
        ->middleware('izin:dasbor.pengurus')->name('dashboard.pengurus.ringkas');

    // ─── Modul Pengurus ───
    Route::middleware('izin:pengurus.operasional')->prefix('pengurus')->group(function () {
        // Strategis & Approval
        Route::get('/pembiayaan', [PengurusController::class, 'pembiayaan'])->name('pengurus.pembiayaan.index');
        Route::get('/pembiayaan/{id}', [PengurusController::class, 'pembiayaanShow'])->name('pengurus.pembiayaan.show');
        Route::post('/pembiayaan/{id}/approve', [PengurusController::class, 'pembiayaanApprove'])->name('pengurus.pembiayaan.approve');
        Route::post('/pembiayaan/{id}/reject', [PengurusController::class, 'pembiayaanReject'])->name('pengurus.pembiayaan.reject');
        Route::get('/pengesahan-shu', [PengurusController::class, 'pengesahanShu'])->name('pengurus.pengesahan-shu.index');
        // Approval Transaksi — antrean persetujuan transaksi (limit/dual control).
        // Ditambahkan agar tautan pada view pengurus.approval tidak lagi menunjuk
        // ke rute yang tidak terdaftar.
        Route::get('/approval-transaksi', [PengurusController::class, 'approval'])->name('pengurus.approval.index');

        // Keuangan & Monitoring Eksekutif
        Route::get('/laporan', [PengurusController::class, 'laporan'])->name('pengurus.laporan.index');
        Route::get('/kas', [PengurusController::class, 'kas'])->name('pengurus.kas.index');
        Route::get('/anggota', [AnggotaController::class,   'index'])->name('pengurus.anggota.index');
        Route::get('/simpanan', [PengurusController::class, 'simpanan'])->name('pengurus.simpanan.index');
    });

    // ─── Setoran Angsuran — EKSKLUSIF Teller/Super Admin ───
    // Operasional harian loket (penerimaan setoran angsuran dari anggota)
    // dicabut dari Pengurus sesuai Segregation of Duties: Pengurus adalah
    // penentu kebijakan & pemberi persetujuan, bukan pelaksana transaksi kas.
    // Super Admin tetap diberi akses sebagai administrator teknis sistem.
    Route::middleware('izin:angsuran.setoran')->prefix('pengurus')->group(function () {
        Route::get('/angsuran', [PengurusController::class, 'angsuran'])->name('pengurus.angsuran.index');
    });

    // ─── Pengaturan & Master Data — EKSKLUSIF Super Admin ───
    // Pengembangan (Super Admin) diberi wewenang mengelola master data
    // administratif: produk/akad, user, jenis simpanan, CoA, dan pemeliharaan
    // sistem (riwayat pengujian). Wewenang APPROVAL (pembiayaan, SHU, approval
    // transaksi) TETAP eksklusif Pengurus — Super Admin tidak masuk rantai
    // persetujuan (pemisahan tugas maker-checker tetap utuh).
    Route::middleware('izin:master.data')->prefix('pengurus')->group(function () {
        // Pengaturan & Master Data
        Route::get('/produk', [ProdukAkadController::class, 'index'])->name('pengurus.produk.index');
        Route::get('/produk/{id}/edit', [ProdukAkadController::class, 'edit'])->name('pengurus.produk.edit');
        Route::put('/produk/{id}', [ProdukAkadController::class, 'update'])->name('pengurus.produk.update');
        Route::get('/users', [PengurusController::class, 'users'])->name('pengurus.users.index');

        // Ubah PERAN seorang pengguna. Gerbang izin hanya pintu pertama:
        // `PengurusController::ubahPeran()` + penjaga model User menolak
        // perubahan atas peran puncak administratif oleh siapa pun selain
        // Super Admin (langkah #3 — anti-pencabutan hak akses).
        Route::put('/users/{user}/peran', [PengurusController::class, 'ubahPeran'])
            ->whereNumber('user')->name('pengurus.users.peran');

        // Tambah akun STAF baru dan reset kata sandi. Keduanya tulis dan
        // dijaga `master.data`; peran yang dapat DITETAPKAN tetap disaring
        // HierarkiRole::bolehMengubahPeran() di controller.
        Route::post('/users', [PengurusController::class, 'storeUser'])->name('pengurus.users.store');
        Route::put('/users/{user}/kata-sandi', [PengurusController::class, 'resetKataSandi'])
            ->whereNumber('user')->name('pengurus.users.kata-sandi');

        // Override batas operasional per peran (tabel limit_transaksi_role).
        // Hanya ANGKA (nominal, limit harian, kebutuhan approval) — daftar
        // wewenang tetap read-only dari config/izin.php, sehingga halaman ini
        // tidak dapat dipakai melonggarkan hak akses.
        Route::post('/users/limit', [PengurusController::class, 'storeLimit'])->name('pengurus.limit-role.store');
        Route::put('/users/limit/{limit_role}', [PengurusController::class, 'updateLimit'])->name('pengurus.limit-role.update');
        Route::delete('/users/limit/{limit_role}', [PengurusController::class, 'destroyLimit'])->name('pengurus.limit-role.destroy');

        // Riwayat Pengujian — verifikasi kualitas sistem langsung dari UI.
        Route::get('/riwayat-pengujian', [RiwayatPengujianController::class, 'index'])->name('pengurus.riwayat-pengujian.index');
        Route::post('/riwayat-pengujian/jalankan', [RiwayatPengujianController::class, 'jalankan'])->name('pengurus.riwayat-pengujian.jalankan');
        Route::delete('/riwayat-pengujian/{id}', [RiwayatPengujianController::class, 'hapus'])
            ->whereNumber('id')->name('pengurus.riwayat-pengujian.hapus');
    });

    // ─── Modul Zakat, Infak & Sedekah (PSAK 109) — Pengurus & Manajer ───
    // Dana zakat/infak adalah dana TITIPAN (amanah), dipisahkan dari pendapatan
    // koperasi sehingga tidak memengaruhi SHU. Pencatatan penuh untuk pengurus &
    // manajer; DPS mendapat jalur read-only di grup pengawasan di atas.
    Route::middleware('izin:zakat.kelola')->prefix('pengelola')->group(function () {
        Route::get('/zakat', [ZakatController::class, 'index'])->name('zakat.index');
        Route::get('/zakat/laporan', [ZakatController::class, 'laporan'])->name('zakat.laporan');
        Route::get('/zakat/ringkasan', [ZakatController::class, 'ringkasan'])->name('zakat.ringkasan');
        Route::post('/zakat/hitung', [ZakatController::class, 'hitung'])->name('zakat.hitung');
        Route::post('/zakat/hitung-penghasilan', [ZakatController::class, 'hitungPenghasilan'])->name('zakat.hitung.penghasilan');
        Route::post('/zakat/terima', [ZakatController::class, 'store'])->name('zakat.store');
        Route::post('/zakat/salurkan', [ZakatController::class, 'salurkan'])->name('zakat.salurkan');
        // Z-01 — pembatalan (void) transaksi terkendali: baris ditandai void &
        // jurnalnya dibalik (bukan dihapus); pemisahan tugas ditegakkan di service.
        Route::post('/zakat/{id}/batalkan', [ZakatController::class, 'batalkan'])->name('zakat.batalkan');
        // Z-02 — dual control (maker-checker) penyaluran dana amanah: pengajuan
        // di atas ambang wajib disetujui/ditolak pengurus/manajer LAIN sebelum
        // jurnal diposting. Pemisahan tugas ditegakkan di service.
        Route::post('/zakat/{id}/setujui', [ZakatController::class, 'setujui'])->name('zakat.setujui');
        Route::post('/zakat/{id}/tolak', [ZakatController::class, 'tolak'])->name('zakat.tolak');
    });

    // ─── CKPN Piutang Pembiayaan (PSAK 71) — Pengurus, Manajer & DPS ───
    // 'laporan.akses:neraca' diletakkan SEBELUM 'izin:' mengikuti kebijakan grup
    // Neraca: percobaan akses tidak sah tetap tercatat di log audit laporan
    // keuangan sebelum ditolak. Penyesuaian mengubah nilai neraca → RBAC neraca.
    Route::middleware(['laporan.akses:neraca', 'izin:ckpn.lihat'])->group(function () {
        Route::get('/laporan/ckpn', [CkpnController::class, 'index'])->name('laporan.ckpn');
    });

    // Posting penyesuaian hanya untuk PENGURUS, dan lewat modul tersendiri
    // 'penyesuaian_jurnal' — bukan lewat modul laporan. Matriks
    // LaporanKeuanganPermission memberi hak 'edit' modul penyesuaian HANYA
    // kepada pengurus, sementara modul laporan (neraca, buku besar, dst.)
    // bersifat hanya-baca bagi SEMUA peran. Inilah pemisahan tugas (R19/B11):
    // kewenangan menulis tidak lagi menempel pada kewenangan melihat laporan.
    // Karena itu `izin:ckpn.posting` di sini wajib sejalan dengan matriks — bila manajer
    // disertakan, permintaan POST-nya pasti ditolak 403 oleh middleware
    // laporan.akses sebelum sampai ke controller.
    Route::middleware(['laporan.akses:penyesuaian_jurnal', 'izin:ckpn.posting'])->group(function () {
        Route::post('/laporan/ckpn/posting', [CkpnController::class, 'posting'])->name('laporan.ckpn.posting');
    });

    Route::middleware('izin:kebijakan.koperasi')->prefix('pengurus')->group(function () {
        // Profil & Kebijakan Koperasi — kewenangan STRATEGIS Pengurus (AD/ART,
        // plafon pembiayaan, visi & misi). Berbeda dari CoA/parameter teknis
        // aplikasi (konfigurasi sistem, milik Super Admin), ini adalah
        // penetapan kebijakan tingkat eksekutif sehingga tetap di Pengurus.
        Route::get('/kebijakan', [PengurusController::class, 'kebijakan'])->name('pengurus.kebijakan.index');

        Route::match(['put', 'post'], '/kebijakan', [PengurusController::class, 'updateKebijakan'])->name('pengurus.kebijakan.update');

        // Pemetaan akad syariah → akun CoA (fleksibilitas PSAK, berbasis data).
        // Pengurus dapat menambah akad baru tanpa mengubah kode sumber.
        Route::post('/kebijakan/pemetaan-akad', [PengurusController::class, 'updatePemetaanAkad'])->name('pengurus.kebijakan.pemetaan-akad');

        // Kalender hari libur — parameter penjadwalan angsuran. Berada di grup
        // kebijakan karena isinya menggeser tanggal jatuh tempo pembiayaan.
        Route::get('/hari-libur', [HariLiburController::class, 'index'])->name('pengurus.hari-libur.index');
        Route::post('/hari-libur', [HariLiburController::class, 'store'])->name('pengurus.hari-libur.store');
        Route::put('/hari-libur/{hari_libur}', [HariLiburController::class, 'update'])->name('pengurus.hari-libur.update');
        Route::post('/hari-libur/{hari_libur}/toggle', [HariLiburController::class, 'toggle'])->name('pengurus.hari-libur.toggle');
        Route::delete('/hari-libur/{hari_libur}', [HariLiburController::class, 'destroy'])->name('pengurus.hari-libur.destroy');
    });

    // Master Parameter Jenis Simpanan (dinamis — tanpa ubah kode inti).
    // Dikelola Super Admin (administrator teknis sistem). Parameter produk
    // simpanan = konfigurasi sistem, bukan kewenangan kebijakan Pengurus.
    Route::middleware('izin:simpanan.jenis')->prefix('pengurus')->group(function () {
        Route::get('/jenis-simpanan', [JenisSimpananController::class, 'index'])->name('pengurus.jenis-simpanan.index');
        Route::post('/jenis-simpanan', [JenisSimpananController::class, 'store'])->name('pengurus.jenis-simpanan.store');
        Route::put('/jenis-simpanan/{jenis_simpanan}', [JenisSimpananController::class, 'update'])->name('pengurus.jenis-simpanan.update');
        Route::post('/jenis-simpanan/{jenis_simpanan}/toggle', [JenisSimpananController::class, 'toggle'])->name('pengurus.jenis-simpanan.toggle');
        Route::delete('/jenis-simpanan/{jenis_simpanan}', [JenisSimpananController::class, 'destroy'])->name('pengurus.jenis-simpanan.destroy');
    });

    // Chart of Accounts (CoA) & Parameter Teknis Aplikasi — EKSKLUSIF Super Admin.
    // Peta akun buku besar adalah konfigurasi teknis akuntansi (IT/administrator
    // sistem), bukan kewenangan kebijakan strategis Pengurus. Pengurus cukup
    // melihat hasilnya lewat laporan (Neraca, Buku Besar, PSAK 101).
    Route::middleware('izin:coa.kelola')->prefix('pengurus')->group(function () {
        Route::get('/coa', [CoaController::class, 'index'])->name('pengurus.coa.index');
        Route::post('/coa', [CoaController::class, 'store'])->name('pengurus.coa.store');
        Route::post('/coa/verify', [CoaController::class, 'verifyJournals'])->name('pengurus.coa.verify');
        Route::post('/coa/close-fiscal-year', [CoaController::class, 'closeFiscalYear'])->name('pengurus.coa.close-fiscal-year');
        Route::post('/coa/ikhtisar-shu/preview', [CoaController::class, 'previewIkhtisarShu'])->name('pengurus.coa.ikhtisar-shu.preview');
        Route::post('/coa/ikhtisar-shu/reset', [CoaController::class, 'resetIkhtisarShu'])->name('pengurus.coa.ikhtisar-shu.reset');
        Route::put('/coa/{coa}', [CoaController::class, 'update'])->name('pengurus.coa.update');
        Route::post('/coa/{coa}/toggle', [CoaController::class, 'toggle'])->name('pengurus.coa.toggle');
    });

    Route::middleware('izin:pengurus.keuangan')->prefix('pengurus')->group(function () {
        // Kepatuhan AML & Laporan Syariah DPS
        Route::get('/laporan-aml', [AmlComplianceController::class, 'index'])->name('pengurus.aml.index');
        // OJK-01 — ekspor laporan PPATK (LTKT / LTKM / STR) dalam format CSV.
        Route::get('/laporan-aml/export/{jenis}', [AmlComplianceController::class, 'export'])
            ->whereIn('jenis', ['LTKT', 'LTKM', 'STR'])->name('pengurus.aml.export');
        Route::post('/laporan-aml/{id}/verify', [AmlComplianceController::class, 'verify'])->name('pengurus.aml.verify');
        Route::post('/laporan-aml/{id}/tolak', [AmlComplianceController::class, 'tolak'])->name('pengurus.aml.tolak');
        Route::get('/laporan-syariah', [PengurusController::class, 'laporanSyariah'])->name('pengurus.laporan-syariah.index');
        Route::get('/laporan/dana-kebajikan', [PengurusController::class, 'laporanDanaKebajikan'])->name('pengurus.laporan.dana-kebajikan');

        // SHU — Perhitungan & Distribusi
        Route::get('/shu', [ShuController::class, 'index'])->name('pengurus.shu.index');
        Route::post('/shu', [ShuController::class, 'store'])->name('pengurus.shu.store');
        // Rincian per anggota (JSON) — untuk modal Detail di halaman indeks.
        // Didaftarkan SEBELUM '/shu/{shu}' agar tidak tertangkap oleh wildcard.
        Route::get('/shu/{shu}/detail-anggota', [ShuController::class, 'detailAnggota'])->name('pengurus.shu.detail-anggota');
        Route::get('/shu/{shu}', [ShuController::class, 'show'])->name('pengurus.shu.show');
        Route::post('/shu/{shu}/proses', [ShuController::class, 'proses'])->name('pengurus.shu.proses');
        Route::delete('/shu/{shu}', [ShuController::class, 'destroy'])->name('pengurus.shu.destroy');
        Route::get('/shu/{shu}/export-excel', [ShuController::class, 'exportExcel'])->name('pengurus.shu.export-excel');
        Route::post('/shu/preview', [ShuController::class, 'preview'])->name('pengurus.shu.preview');

        /* Pelepasan penahanan SHU (HOLD). Didaftarkan sebelum wildcard
         | '/shu/{shu}' agar 'lepas-hold' tidak tertangkap sebagai {shu}. */
        Route::post('/shu/lepas-hold', [ShuController::class, 'lepasPenahananOtomatis'])->name('pengurus.shu.lepas-hold');
        Route::post('/shu/{shu}/distribusi/{distribusi}/lepas-hold', [ShuController::class, 'lepaskanPenahanan'])
            ->whereNumber('shu')->whereNumber('distribusi')
            ->name('pengurus.shu.lepas-hold-anggota');
    });

    // ─── Pengaturan Kebijakan Koperasi (ZERO-HARDCODING POLICY) ───
    // Alias UI resmi /admin/settings untuk pengurus & admin. Menyimpan ke
    // baris kebijakan yang SAMA (single source of truth), sehingga tidak ada
    // dua jalur logika yang bisa berbeda.
    //
    // Wewenang MEMBACA halaman (`pengaturan.admin`) lebih luas daripada
    // wewenang MENGUBAH kebijakan (`kebijakan.koperasi`). Karena itu kedua
    // jalur dipisah: bila keduanya memakai `pengaturan.admin`, admin dan
    // super_admin dapat mengubah parameter bisnis lewat alias ini padahal
    // jalur resmi `/pengurus/kebijakan` hanya mengizinkan Pengurus —
    // pembatasan kebijakan menjadi bisa ditembus lewat pintu samping.
    Route::middleware('izin:pengaturan.admin')->prefix('admin')->group(function () {
        Route::get('/settings', [AdminSettingsController::class, 'index'])->name('admin.settings.index');
        Route::post('/settings/clear-cache', [AdminSettingsController::class, 'clearCache'])->name('admin.settings.clear-cache');
    });

    Route::middleware('izin:kebijakan.koperasi')->prefix('admin')->group(function () {
        Route::match(['put', 'post'], '/settings', [PengurusController::class, 'updateKebijakan'])->name('admin.settings.update');
    });

    // Dashboard DPS
    Route::middleware('izin:dps.pengawasan')->group(function () {
        Route::get('/dps/dashboard', [DpsController::class, 'index'])->name('dashboard.dps');
        // Potongan HTML real-time (mesin denyut) untuk dashboard DPS.
        Route::get('/dps/dashboard/ringkas', [DpsController::class, 'ringkas'])->name('dashboard.dps.ringkas');
        Route::get('/dps/audit-akad', [DpsController::class, 'auditAkad'])->name('dps.audit-akad');
        Route::get('/dps/review-produk', [DpsController::class, 'reviewProduk'])->name('dps.review-produk.index');
        Route::post('/dps/review-produk/{id}/approve', [DpsController::class, 'reviewProdukApprove'])->name('dps.review-produk.approve');
        Route::get('/dps/dana-kebajikan', [DpsController::class, 'danaKebajikan'])->name('dps.dana-kebajikan');
        Route::get('/dps/dana-kebajikan/ringkasan', [DpsController::class, 'ringkasanDanaKebajikan'])->name('dps.dana-kebajikan.ringkasan');
        Route::get('/dps/jurnal-umum', [CoaController::class, 'jurnalUmum'])->name('dps.jurnal-umum');
        Route::get('/dps/audit-beban', [PengeluaranBebanController::class, 'auditIndex'])->name('dps.beban.index');
        Route::get('/dps/laporan-keuangan', [DpsController::class, 'laporanKeuangan'])->name('dps.laporan-keuangan');
        Route::get('/dps/laporan-keuangan/dana-kebajikan', [DpsController::class, 'ringkasanKebajikanLaporanKeuangan'])->name('dps.laporan-keuangan.dana-kebajikan');
        Route::get('/dps/opini-catatan', [DpsController::class, 'opiniCatatan'])->name('dps.opini-catatan');
        Route::post('/dps/opini-catatan', [DpsController::class, 'opiniCatatanStore'])->name('dps.opini-catatan.store');

        // Modul Zakat & Infak/Sedekah (PSAK 109) — DPS: pengawasan syariah, READ-ONLY.
        // Form pencatatan disembunyikan (ZakatController menandai $readOnly untuk role dps).
        Route::get('/dps/zakat', [ZakatController::class, 'index'])->name('dps.zakat.index');
        Route::get('/dps/zakat/laporan', [ZakatController::class, 'laporan'])->name('dps.zakat.laporan');

        // Pengesahan Akad — DPS approval 4 tahap
        Route::get('/dps/pengesahan-akad', [DpsAuditController::class, 'index'])->name('dps.pengesahan-akad');
        Route::get('/dps/pengesahan-akad/{id}/detail', [DpsAuditController::class, 'getDetail'])->name('dps.pengesahan-akad.detail');
        Route::post('/dps/pengesahan-akad/{id}/approve', [DpsAuditController::class, 'approve'])->name('dps.pengesahan-akad.approve');
        Route::post('/dps/pengesahan-akad/{id}/reject', [DpsAuditController::class, 'reject'])->name('dps.pengesahan-akad.reject');
    });

    // Dashboard Super Admin — administrator sistem (hierarki tertinggi di
    // antara peran administratif). Menyajikan ringkasan real-time seluruh
    // koperasi, visualisasi, dan antrean kegiatan yang menunggu persetujuan.
    Route::middleware('izin:super_admin.administrasi')->prefix('super-admin')->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('dashboard.super-admin');
        // Potongan HTML real-time (mesin denyut) untuk dashboard Super Admin.
        Route::get('/dashboard/ringkas', [SuperAdminController::class, 'ringkas'])->name('dashboard.super-admin.ringkas');

        // Laporan Kinerja Seluruh Unit/Cabang — diturunkan dari data yang ada
        // (produk, account officer, jenis simpanan) tanpa tabel cabang baru.
        Route::get('/kinerja-unit', [SuperAdminController::class, 'kinerjaUnit'])->name('super-admin.kinerja-unit');
    });

    // Modul pengajuan rekening (pengelola) — hanya Teller, Manajer, Pengurus
    Route::middleware('izin:pengajuan.rekening')->group(function () {
        Route::get('/pengelola/pengajuan-rekening',
            [PengajuanRekeningController::class, 'index'])->name('pengelola.pengajuan-rekening.index');
        Route::get('/pengelola/pengajuan-rekening/{pengajuan}',
            [PengajuanRekeningController::class, 'show'])->name('pengelola.pengajuan-rekening.show');
        Route::post('/pengelola/pengajuan-rekening/{pengajuan}/setujui',
            [PengajuanRekeningController::class, 'setujui'])->name('pengelola.pengajuan-rekening.setujui');
        Route::post('/pengelola/pengajuan-rekening/{pengajuan}/tolak',
            [PengajuanRekeningController::class, 'tolak'])->name('pengelola.pengajuan-rekening.tolak');
        Route::post('/pengelola/pengajuan-rekening/{pengajuan}/konfirmasi-pembayaran',
            [PengajuanRekeningController::class, 'konfirmasiPembayaran'])->name('pengelola.pengajuan-rekening.konfirmasi-pembayaran');
        Route::post('/pengelola/pengajuan-rekening/{pengajuan}/tolak-pembayaran',
            [PengajuanRekeningController::class, 'tolakPembayaran'])->name('pengelola.pengajuan-rekening.tolak-pembayaran');
    });

    // ─── Approval Transaksi (Manajer & Pengurus saja) ───
    Route::middleware('izin:approval.transaksi')->group(function () {
        Route::get('/pengelola/approval', [ApprovalTransaksiController::class, 'index'])->name('pengelola.approval.index');
        Route::get('/pengelola/approval/{approval}', [ApprovalTransaksiController::class, 'show'])->name('pengelola.approval.show');
        Route::post('/pengelola/approval/{approval}/approve', [ApprovalTransaksiController::class, 'approve'])->name('pengelola.approval.approve');
        Route::post('/pengelola/approval/{approval}/reject', [ApprovalTransaksiController::class, 'reject'])->name('pengelola.approval.reject');
    });

    // ─── Teller — Operasional Loket (Diperketat RBAC Segregation of Duties) ───
    Route::middleware('izin:teller.loket')->prefix('teller')->group(function () {
        // Operasional Harian
        // Guard sesi kas (`kas.terbuka`): seluruh transaksi yang menggerakkan
        // uang tunai fisik wajib memiliki sesi Buka Kas berstatus OPEN pada
        // tanggal bisnis berjalan. Rute non-tunai (jadwal, konfirmasi,
        // reschedule, halaman baca) sengaja TIDAK dijaga.
        Route::get('/setor', [TellerController::class, 'setorForm'])->name('teller.setor');
        Route::post('/setor', [TellerController::class, 'setorStore'])
            ->middleware('kas.terbuka')->name('teller.setor.store');
        Route::get('/tarik', [TellerController::class, 'tarikForm'])->name('teller.tarik');
        // Penarikan simpanan: anggota PENDING/SUSPENDED/INACTIVE/Keluar diblokir.
        Route::post('/tarik', [TellerController::class, 'tarikStore'])
            ->middleware(['anggota.aktif', 'kas.terbuka'])->name('teller.tarik.store');
        Route::get('/deposito', [TellerController::class, 'depositoForm'])->name('teller.deposito');
        // Pembukaan rekening berjangka baru juga tergolong transaksi lanjutan.
        Route::post('/deposito', [TellerController::class, 'depositoStore'])
            ->middleware(['anggota.aktif', 'kas.terbuka'])->name('teller.deposito.store');
        Route::get('/deposito/daftar', [TellerController::class, 'depositoIndex'])->name('teller.deposito.index');
        Route::get('/deposito/{rekening}', [TellerController::class, 'depositoShow'])->name('teller.deposito.show');
        Route::post('/deposito/{rekening}/cair', [TellerController::class, 'depositoCair'])
            ->middleware('kas.terbuka')->name('teller.deposito.cair');
        Route::get('/angsuran', [TellerController::class, 'angsuranForm'])->name('teller.angsuran');
        Route::get('/angsuran/get-pembiayaan/{anggota_id}', [TellerController::class, 'getPembiayaanAngsuran'])->name('teller.angsuran.get-pembiayaan');
        // K-02: pembentukan jadwal angsuran dipisah ke POST (GET tetap idempoten).
        Route::post('/angsuran/generate-jadwal/{pembiayaan_id}', [TellerController::class, 'generateJadwalAngsuran'])
            ->whereNumber('pembiayaan_id')->name('teller.angsuran.generate-jadwal');
        Route::post('/angsuran/{angsuran}', [TellerController::class, 'angsuranStore'])
            ->middleware('kas.terbuka')->name('teller.angsuran.store');

        // Simpanan Wajib Bulanan — tagihan yang tidak terpotong auto-debit
        // (anggota `is_auto_debit = false`) dilunasi manual di loket.
        // Jurnal PSAK 101/108 diposting oleh MandatorySavingsService.
        Route::get('/simpanan-wajib', [SimpananWajibController::class, 'index'])->name('teller.simpanan-wajib.index');
        Route::post('/simpanan-wajib/{tagihan}/bayar', [SimpananWajibController::class, 'bayar'])
            ->whereNumber('tagihan')->middleware('kas.terbuka')->name('teller.simpanan-wajib.bayar');
        // Konfirmasi manual setoran yang DITANGGUHKAN (BUG-03): setoran otomatis
        // yang verifikasi status pembayarannya gagal tidak langsung dipotong —
        // petugas memutuskan lanjut (konfirmasi) atau tolak.
        Route::post('/simpanan-wajib/{tagihan}/konfirmasi', [SimpananWajibController::class, 'konfirmasi'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.konfirmasi');
        Route::post('/simpanan-wajib/{tagihan}/tolak', [SimpananWajibController::class, 'tolak'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.tolak');
        // Penarikan saldo Simpanan Wajib (PSAK 101 ¶ 31 — pengurangan Modal Anggota).
        // TIDAK memakai `anggota.aktif`: justru anggota berstatus Keluar yang
        // menjadi sasaran utama penarikan. Gembok kebijakan & status ditegakkan
        // di dalam service (`alasanPenarikanDitolak`).
        Route::post('/simpanan-wajib/{anggota}/tarik', [SimpananWajibController::class, 'tarik'])
            ->whereNumber('anggota')->middleware('kas.terbuka')->name('teller.simpanan-wajib.tarik');
        // Penanganan DINAMIS tunggakan (koperasi syariah — tanpa denda):
        //  - jadwal: pembayaran bertahap sesuai kemampuan anggota
        //  - cicilan: potong cicilan dari saldo Simpanan Sukarela
        //  - reschedule: geser tenggat TANPA denda (QS. Al-Baqarah 2:280)
        //  - riwayat: audit trail seluruh pemotongan & penjadwalan ulang
        Route::post('/simpanan-wajib/{tagihan}/jadwal', [SimpananWajibController::class, 'jadwalkan'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.jadwal');
        Route::post('/simpanan-wajib/{tagihan}/cicilan', [SimpananWajibController::class, 'bayarCicilan'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.cicilan');
        Route::post('/simpanan-wajib/{tagihan}/reschedule', [SimpananWajibController::class, 'reschedule'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.reschedule');
        Route::get('/simpanan-wajib/riwayat/{tagihan}', [SimpananWajibController::class, 'riwayat'])
            ->whereNumber('tagihan')->name('teller.simpanan-wajib.riwayat');
        Route::post('/tazir/proses', [TellerController::class, 'prosesTazir'])
            ->middleware('kas.terbuka')->name('teller.tazir.proses');
        // Keringanan Ta'zir — alur baru: pengajuan dibuat dari form Bayar Angsuran
        // (TellerController@angsuranStore), halaman ini hanya menampilkan riwayat status.
        Route::get('/keringanan-tazir', [KeringanTazirController::class, 'riwayatTeller'])->name('teller.keringanan-tazir.index');

        // Keringanan Penalti Deposito — alur berjenjang Teller -> AO -> Manajer.
        // Penalti pencairan dini deposito adalah ta'zir (Dana Kebajikan); anggota
        // dapat mengajukan keringanan sebelum pencairan dini dieksekusi.
        Route::get('/keringanan-penalti-deposito', [DepositoPenaltyReliefController::class, 'create'])->name('teller.keringanan-penalti.create');
        Route::post('/keringanan-penalti-deposito', [DepositoPenaltyReliefController::class, 'store'])->name('teller.keringanan-penalti.store');
        Route::get('/keringanan-penalti-deposito/riwayat', [DepositoPenaltyReliefController::class, 'riwayat'])->name('teller.keringanan-penalti.index');
        Route::get('/keringanan-penalti-deposito/anggota/{anggota}', [DepositoPenaltyReliefController::class, 'infoAnggota'])->name('teller.keringanan-penalti.anggota');

        // Pencairan Pembiayaan
        Route::get('/pencairan', [PencairanController::class, 'index'])->name('teller.pencairan.index');
        Route::post('/pencairan/{id}', [PencairanController::class, 'prosesPencairan'])
            ->middleware('kas.terbuka')->name('teller.pencairan.proses');
        // Jalur alternatif: pencairan pembiayaan berstatus DISETUJUI berdasarkan tgl_pencairan.
        // Controller ini sebelumnya tidak terhubung route apa pun sehingga jurnal
        // pencairannya tidak pernah terbentuk; kini didaftarkan (dengan otomatisasi jurnal).
        Route::get('/pencairan-disetujui', [TellerPencairanController::class, 'index'])->name('teller.pencairan-disetujui.index');
        Route::post('/pencairan-disetujui/{id}/cairkan', [TellerPencairanController::class, 'cairkan'])
            ->whereNumber('id')->middleware('kas.terbuka')->name('teller.pencairan.cairkan');
        Route::get('/rekening-by-anggota', [TellerController::class, 'getRekeningByAnggota'])->name('teller.rekening-by-anggota');
        Route::get('/nota/{transaksi}', [TellerController::class, 'notaShow'])->name('teller.nota');
        Route::get('/riwayat', [TellerController::class, 'riwayat'])->name('teller.riwayat');
        Route::get('/slip/{transaksi}', [TellerController::class, 'slipShow'])->name('teller.slip');
        Route::post('/void/{transaksi}', [TellerController::class, 'voidRequest'])->name('teller.void');

        // Buka Kas / Opening
        Route::get('/kas/opening', [TellerController::class, 'openingForm'])->name('teller.kas.opening');
        Route::post('/kas/opening', [TellerController::class, 'openingStore'])->name('teller.kas.opening.store');
        Route::post('/kas/tambah-modal', [TellerController::class, 'tambahModal'])->name('teller.kas.tambah-modal');
        Route::post('/kas/setor-brankas', [TellerController::class, 'setorKeBrankas'])->name('teller.kas.setor-brankas');

        // Kas Opname & Closing — teller mengajukan, Manajer yang menyetujui.
        // (Alur lama TellerController::rekapStore sudah digantikan ClosingController::storeOpname
        //  agar kas tidak dapat ditutup tanpa verifikasi Manajer.)
        Route::get('/kas/closing', [ClosingController::class, 'index'])->name('teller.kas.closing');
        Route::post('/kas/closing', [ClosingController::class, 'storeOpname'])->name('teller.kas.closing.store');
        Route::post('/kas/closing/{opname}/batal', [ClosingController::class, 'batalOpname'])->name('teller.kas.closing.batal')->whereNumber('opname');
        Route::get('/rekap', [TellerController::class, 'rekapIndex'])->name('teller.rekap');

        // Kelola Anggota (Read Only untuk Teller — pendaftaran anggota ada di role AO)
        Route::get('/anggota', [TellerAnggotaController::class, 'index'])->name('teller.anggota.index');
        // whereNumber wajib agar /anggota/create tidak tertangkap sebagai {id}
        Route::get('/anggota/{id}', [TellerAnggotaController::class, 'show'])->name('teller.anggota.show')->whereNumber('id');
        Route::get('/anggota/{id}/kartu', [TellerAnggotaController::class, 'card'])->name('teller.anggota.card')->whereNumber('id');
    });

    // ─── Edit Data Anggota (dipakai Teller — pendaftaran anggota baru ada di role AO) ───
    Route::middleware('izin:anggota.edit')->prefix('teller')->group(function () {
        Route::get('/anggota/{id}/edit', [TellerAnggotaController::class, 'edit'])->name('teller.anggota.edit');
        Route::put('/anggota/{id}', [TellerAnggotaController::class, 'update'])->name('teller.anggota.update');
    });

    // ─── Pengunduran Diri / Exit Keanggotaan (`member_terminations`) ───
    // SATU-SATUNYA pintu sah pengembalian dana Simpanan Pokok (3-1001) &
    // Simpanan Wajib (3-1002) — keduanya Modal/Ekuitas Koperasi (PSAK 101 ¶ 31).
    // Penarikan rutin kedua akun diblokir total oleh GuardSimpananModal.
    // Segregation of Duties: pengajuan/pemantauan boleh oleh loket & pengelola;
    // EKSEKUSI pengembalian dana (posting jurnal PSAK 108 + set TERMINATED)
    // dilakukan Teller/Manajer/Pengurus sesuai keputusan modul.
    Route::middleware('izin:terminasi.pengajuan')->prefix('member-terminations')->group(function () {
        Route::get('/', [MemberTerminationController::class, 'index'])->name('member-terminations.index');
        Route::get('/create', [MemberTerminationController::class, 'create'])->name('member-terminations.create');
        // WAJIB didaftarkan SEBELUM `/{terminasi}` agar tidak tertangkap
        // parameter dinamis (whereNumber membuatnya 404).
        Route::get('/cari-anggota', [MemberTerminationController::class, 'cariAnggota'])->name('member-terminations.cari-anggota');
        Route::post('/', [MemberTerminationController::class, 'store'])->name('member-terminations.store');
        // Dokumen bukti penutupan (hanya untuk terminasi yang sudah diposting).
        Route::get('/{terminasi}/bukti-pdf', [MemberTerminationController::class, 'buktiPdf'])->whereNumber('terminasi')->name('member-terminations.bukti-pdf');
        Route::get('/{terminasi}/bukti-thermal', [MemberTerminationController::class, 'buktiThermal'])->whereNumber('terminasi')->name('member-terminations.bukti-thermal');
        Route::get('/{terminasi}', [MemberTerminationController::class, 'show'])->whereNumber('terminasi')->name('member-terminations.show');
    });

    Route::middleware('izin:terminasi.eksekusi')->prefix('member-terminations')->group(function () {
        Route::post('/{terminasi}/eksekusi', [MemberTerminationController::class, 'eksekusi'])->whereNumber('terminasi')->name('member-terminations.eksekusi');
        Route::post('/{terminasi}/tolak', [MemberTerminationController::class, 'tolak'])->whereNumber('terminasi')->name('member-terminations.tolak');
        Route::post('/{terminasi}/batalkan', [MemberTerminationController::class, 'batalkan'])->whereNumber('terminasi')->name('member-terminations.batalkan');
    });

    // Persetujuan Manajer untuk pencairan yang melampaui
    // `high_value_withdrawal_limit` (parameter kebijakan koperasi).
    Route::middleware('izin:terminasi.approval')->prefix('member-terminations')->group(function () {
        Route::post('/{terminasi}/setujui', [MemberTerminationController::class, 'setujui'])->whereNumber('terminasi')->name('member-terminations.setujui');
        Route::post('/{terminasi}/tolak-approval', [MemberTerminationController::class, 'tolakApproval'])->whereNumber('terminasi')->name('member-terminations.tolak-approval');
    });

    // ─── Pusat Konfirmasi Auto-Debit (Manajer, Super Admin, Admin) ───
    // SATU-SATUNYA pintu tempat pemotongan auto-debit dieksekusi. Scheduler
    // (Simpanan Wajib, Angsuran, Ta'zir) hanya MENGUSULKAN ke sini; dana baru
    // dipotong setelah pengguna berwenang menekan tombol konfirmasi. Tanpa
    // konfirmasi di sini, saldo anggota TIDAK tersentuh.
    //
    // PEMISAHAN TUGAS (Segregation of Duties): konfirmasi/eksekusi auto-debit
    // adalah fungsi back-office, BUKAN tugas kasir. Role `teller` sengaja
    // dicabut — menunya juga tidak didaftarkan pada sidebar Teller. Percobaan
    // akses URL langsung oleh teller akan ditolak middleware `izin:` (403).
    Route::middleware('izin:autodebit.konfirmasi')->prefix('autodebit')->group(function () {
        Route::get('/', [AutodebitController::class, 'index'])->name('autodebit.index');
        Route::post('/{usulan}/konfirmasi', [AutodebitController::class, 'konfirmasi'])->whereNumber('usulan')->name('autodebit.konfirmasi');
        Route::post('/{usulan}/tolak', [AutodebitController::class, 'tolak'])->whereNumber('usulan')->name('autodebit.tolak');
    });

    // ─── Pengajuan Pinjaman Pengelola (AO & Manajer) ───
    Route::middleware('izin:pembiayaan.pengajuan')->group(function () {
        Route::get('/pengelola/pengajuan-pinjaman', [PengajuanPembiayaanController::class, 'index'])->name('pengelola.pengajuan-pinjaman.index');
        Route::post('/pengelola/pengajuan-pinjaman', [PengajuanPembiayaanController::class, 'store'])->name('pengelola.pengajuan-pinjaman.store');
        Route::get('/pengelola/pengajuan-pinjaman/{pengajuan}', [PengajuanPembiayaanController::class, 'show'])->name('pengelola.pengajuan-pinjaman.show');
    });

    // ─── Approve/Tolak Pengajuan (Manajer & Pengurus saja) ───
    Route::middleware('izin:pembiayaan.approve')->group(function () {
        Route::patch('/pengelola/pengajuan-pinjaman/{pengajuan}/review', [PengajuanPembiayaanController::class, 'review'])->name('pengelola.pengajuan-pinjaman.review');
        Route::patch('/pengelola/pengajuan-pinjaman/{pengajuan}/approve', [PengajuanPembiayaanController::class, 'approve'])->name('pengelola.pengajuan-pinjaman.approve');
        Route::patch('/pengelola/pengajuan-pinjaman/{pengajuan}/tolak', [PengajuanPembiayaanController::class, 'tolak'])->name('pengelola.pengajuan-pinjaman.tolak');
    });

    // Produk pembiayaan dinamis — hanya Pengurus (CRUD penuh termasuk delete)
    Route::middleware('izin:produk.pembiayaan')->group(function () {
        Route::get('/pengelola/produk-pembiayaan', [DynamicLoanController::class, 'index'])->name('pengelola.produk-pembiayaan.index');
        Route::get('/pengelola/produk-pembiayaan/create', [DynamicLoanController::class, 'create'])->name('pengelola.produk-pembiayaan.create');
        Route::post('/pengelola/produk-pembiayaan', [DynamicLoanController::class, 'store'])->name('pengelola.produk-pembiayaan.store');
        Route::get('/pengelola/produk-pembiayaan/{produk}/edit', [DynamicLoanController::class, 'edit'])->name('pengelola.produk-pembiayaan.edit');
        Route::put('/pengelola/produk-pembiayaan/{produk}', [DynamicLoanController::class, 'update'])->name('pengelola.produk-pembiayaan.update');
        Route::delete('/pengelola/produk-pembiayaan/{produk}', [DynamicLoanController::class, 'destroy'])->name('pengelola.produk-pembiayaan.destroy');
    });

    // ─── Modul Nasabah / Anggota (izin: nasabah.swalayan) ───
    // Peran anggota memakai satu-satunya rute masuk via middleware `anggota.aktif`
    // (tanpa itu pengguna berperan anggota dapat melihat data anggota yang tidak
    // terhubung dengan dirinya). Lihat juga: users.role VARCHAR memuat 'anggota'.
    Route::middleware('izin:nasabah.swalayan')->prefix('nasabah')->group(function () {
        Route::get('/dashboard', [NasabahController::class, 'dashboard'])->name('dashboard.nasabah');
        // Potongan HTML real-time (mesin denyut) untuk dashboard nasabah.
        Route::get('/dashboard/ringkas', [NasabahController::class, 'dashboardRingkas'])->name('nasabah.dashboard.ringkas');
        Route::get('/simpanan', [NasabahController::class, 'simpananIndex'])->name('nasabah.simpanan.index');
        Route::get('/rekening', [NasabahController::class, 'rekeningIndex'])->name('nasabah.rekening.index');
        Route::get('/mutasi', [NasabahController::class, 'mutasiIndex'])->name('nasabah.mutasi.index');
        // Buku tabungan (passbook) satu rekening: saldo awal, mutasi kronologis,
        // dan saldo berjalan. Kepemilikan rekening diperiksa di controller.
        Route::get('/buku-tabungan/{rekening}', [NasabahController::class, 'bukuTabungan'])->name('nasabah.buku-tabungan.index');
        // Transparansi nisbah & rincian bagi hasil deposito (Mudharabah Muthlaqah)
        Route::get('/deposito', [NasabahController::class, 'depositoIndex'])->name('nasabah.deposito.index');
        Route::get('/deposito/{deposito}/bagi-hasil', [NasabahController::class, 'depositoBagiHasil'])->name('nasabah.deposito.bagi-hasil');
        Route::get('/pembiayaan', [NasabahController::class, 'pembiayaanIndex'])->name('nasabah.pembiayaan.index');
        // Pengajuan sederhana (1 step)
        Route::get('/pengajuan', [NasabahController::class, 'pengajuanIndex'])->name('nasabah.pengajuan.index');
        Route::get('/pengajuan/create', [NasabahController::class, 'pengajuanCreate'])->name('nasabah.pengajuan.create');
        Route::post('/pengajuan', [NasabahController::class, 'pengajuanStore'])->name('nasabah.pengajuan.store');
        // Pengajuan wizard multi-step (view pengajuan-pinjaman.blade.php)
        Route::get('/pengajuan-pinjaman', [NasabahController::class, 'pengajuanPinjamanIndex'])->name('nasabah.pengajuan-pinjaman.index');
        Route::post('/pengajuan-pinjaman', [NasabahController::class, 'pengajuanStore'])->name('nasabah.pengajuan-pinjaman.store');
        // Pengajuan wizard baru 3-step (view pengajuan-pinjaman-baru.blade.php)
        Route::get('/pengajuan-pinjaman/baru', [NasabahController::class, 'pengajuanPinjamanBaru'])->name('nasabah.pengajuan-pinjaman.baru');
        Route::post('/pengajuan-pinjaman/baru', [NasabahController::class, 'pengajuanPinjamanStoreBaru'])->name('nasabah.pengajuan-pinjaman.store.baru');
        // Status pengajuan (view status-pengajuan.blade.php)
        Route::get('/status-pengajuan', [NasabahController::class, 'statusPengajuan'])->name('nasabah.status-pengajuan.index');
        Route::get('/angsuran', [NasabahController::class, 'angsuranIndex'])->name('nasabah.angsuran.index');
        Route::get('/profil', [NasabahController::class, 'profilIndex'])->name('nasabah.profil.index');
        Route::put('/profil', [NasabahController::class, 'profilUpdate'])->name('nasabah.profil.update');
    });

    // ─── Dana Cadangan & Modal Sendiri ───
    // Pengurus: CRUD alokasi SHU ke Cadangan
    Route::middleware('izin:dana.cadangan')->prefix('akuntansi')->group(function () {
        Route::get('/cadangan', [DanaCadanganController::class, 'index'])->name('pengurus.cadangan.index');
        // Alokasi SHU mengubah data yang mendasari Neraca. Sesuai pemisahan
        // tugas (R19/B11) ia TIDAK dijaga modul 'neraca' — sebab modul laporan
        // hanya-baca — melainkan modul tulis tersendiri 'penyesuaian_jurnal',
        // dan setiap pemostingannya tetap tercatat di log audit laporan.
        Route::post('/alokasi-shu', [DanaCadanganController::class, 'alokasiShu'])
            ->middleware('laporan.akses:penyesuaian_jurnal')
            ->name('pengurus.cadangan.alokasi');
    });

    // Neraca — Pengurus (penuh), Manajer & DPS (lihat/ekspor sesuai matriks RBAC).
    // 'laporan.akses' sengaja diletakkan SEBELUM 'izin:' supaya percobaan akses
    // tidak sah tetap tercatat di log audit Laporan Keuangan sebelum ditolak.
    Route::middleware(['laporan.akses:neraca', 'izin:laporan.neraca'])->group(function () {
        Route::get('/laporan/neraca', [DanaCadanganController::class, 'neraca'])->name('laporan.neraca');
    });

    // Buku Besar (read-only; DPS dibatasi ke akun Ekuitas & Kewajiban di controller)
    Route::middleware(['laporan.akses:buku_besar', 'izin:laporan.buku_besar'])->group(function () {
        Route::get('/laporan/buku-besar', [DanaCadanganController::class, 'bukuBesar'])->name('laporan.buku-besar');
    });

    // Laporan Keuangan Syariah lengkap — PSAK 101: kelima komponen laporan
    // (Posisi Keuangan, Laba Rugi, Perubahan Ekuitas, Arus Kas, Perubahan Dana
    // Investasi Terikat) beserta Catatan atas Laporan Keuangan, semuanya
    // dihasilkan dari satu sumber kebenaran (mutasi jurnal) sehingga saling
    // rekonsiliasi. Ekspor Excel lewat `?export=excel` pada rute yang sama,
    // sehingga middleware menilainya sebagai hak `ekspor`, bukan `lihat`.
    // Urutan 'laporan.akses' SEBELUM 'izin:' menjaga jejak audit percobaan
    // akses tidak sah (lihat catatan pada rute Neraca di atas).
    Route::middleware(['laporan.akses:laporan_psak101', 'izin:laporan.psak101'])->group(function () {
        Route::get('/laporan/keuangan-syariah', [LaporanKeuanganSyariahController::class, 'index'])->name('laporan.psak101');
    });

    // Laporan Operasional Manajer.
    // Didaftarkan di LUAR grup manajer dengan sengaja: kalau ia berada di
    // dalam grup itu, CekIzin akan menolak lebih dulu dan percobaan akses
    // tidak sah (mis. Teller/AO mencoba membuka) tidak akan pernah tercatat.
    // Di sini urutan 'laporan.akses' lalu 'izin:' menjamin dua-duanya tercatat.
    Route::middleware(['laporan.akses:laporan_manajer', 'izin:laporan.manajer'])
        ->get('/manajer/laporan', [ManajerController::class, 'laporan'])
        ->name('manajer.laporan.index');

    // ─── Audit Laporan Keuangan — EKSKLUSIF Pengawas/Auditor Internal (DPS/SPI) ───
    // Riwayat aktivitas modul Laporan Keuangan adalah alat pengawasan independen.
    // Sesuai Segregation of Duties, fungsi ini TIDAK boleh dipegang oleh pihak
    // yang diawasi (Pengurus, Manajer) maupun administrator sistem (Super Admin,
    // Admin) — cukup DPS sebagai pengawas independen.
    Route::middleware(['laporan.akses:audit_laporan', 'izin:laporan.audit'])
        ->prefix('laporan/audit')
        ->name('audit.laporan.')
        ->group(function () {
            Route::get('/', [AuditLaporanKeuanganController::class, 'index'])->name('index');
            Route::get('/matriks', [AuditLaporanKeuanganController::class, 'matriks'])->name('matriks');
            Route::get('/verifikasi', [AuditLaporanKeuanganController::class, 'verifikasiIntegritas'])->name('verifikasi');
            Route::get('/ekspor', [AuditLaporanKeuanganController::class, 'ekspor'])->name('ekspor');
        });

    // =========================================================================
    // MODUL PENGAJUAN PEMBIAYAAN SYARIAH INTEGRATED
    // Route: /pengajuan-pembiayaan
    // Akses: AO (input), Manajer (review/tolak), Pengurus (approve/tolak)
    // =========================================================================
    Route::middleware('izin:pembiayaan.pengajuan')->prefix('pengajuan-pembiayaan')
        ->name('pengajuan-pembiayaan.')
        ->group(function () {
            // Index + Form Input (AO). 5h — menulis pengajuan dijaga izin yang
            // sama dengan penampil form: `pengurus` hanya memantau.
            Route::get('/', [PengajuanPembiayaanIntegController::class, 'index'])->name('index');
            Route::post('/', [PengajuanPembiayaanIntegController::class, 'store'])
                ->middleware('izin:pembiayaan.pengajuan.masukan')
                ->name('store');
            Route::get('/{id}', [PengajuanPembiayaanIntegController::class, 'show'])->name('show')->whereNumber('id');

            // AJAX: Eligibility Check CIF
            Route::get('/api/eligibility-check', [PengajuanPembiayaanIntegController::class, 'eligibilityCheck'])
                ->name('eligibility-check');

            // Manajer: Review & Tolak
            Route::middleware('izin:pembiayaan.approve')->group(function () {
                Route::post('/{id}/review', [PengajuanPembiayaanIntegController::class, 'review'])->name('review')->whereNumber('id');
                Route::post('/{id}/tolak-manajer', [PengajuanPembiayaanIntegController::class, 'tolakManajer'])->name('tolak-manajer')->whereNumber('id');
            });

            // Pengurus: Approve Final + Pencairan & Tolak
            Route::middleware('izin:pembiayaan.persetujuan')->group(function () {
                Route::post('/{id}/approve', [PengajuanPembiayaanIntegController::class, 'approvePengurus'])->name('approve')->whereNumber('id');
                Route::post('/{id}/cairkan', [PengajuanPembiayaanIntegController::class, 'cairkan'])->name('cairkan')->whereNumber('id');
                Route::post('/{id}/tolak-pengurus', [PengajuanPembiayaanIntegController::class, 'tolakPengurus'])->name('tolak-pengurus')->whereNumber('id');
            });
        });
});
