# LAPORAN AUDIT SISTEM — KOPERASI SYARIAH

| Item | Keterangan |
|---|---|
| Tanggal audit | 29 September 2026 |
| Ruang lingkup | Seluruh modul terpasang (290 endpoint web+api, `app/`, `resources/views`) |
| Metode | Pemetaan rute runtime, pembacaan kode sumber, uji perintah artisan, verifikasi jurnal |
| Sifat audit | **Read-only** (tidak ada perubahan kode; rekomendasi saja) |
| Referensi standar | PSAK 101–109, PSAK 71, Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000, UU No. 25/1992 & UU No. 17/2012 (Perkoperasian), prinsip GCG koperasi syariah |

---

## 1. INVENTARISASI MODUL

### 1.1 Tabel Modul Terpasang

| # | Nama Modul | Pemilik (Peran) | Fungsi Utama yang Dirancang | Berkas Inti | Dependensi Antar-Modul |
|---|---|---|---|---|---|
| M1 | Autentikasi & Otorisasi | Semua | Login/logout, RBAC 8 peran (`admin, teller, ao, manajer, pengurus, dps, nasabah, anggota`), gerbang `laporan.akses`, blokir anggota non-aktif | `routes/web.php:47-49`, `app/Http/Middleware/CheckRole.php`, `PastikanAnggotaAktif.php`, `LaporanKeuanganAccess.php` | Fondasi seluruh modul |
| M2 | Dashboard per Peran | Semua | Ikhtisar sesuai peran (admin, teller, AO, manajer, pengurus, DPS, nasabah, anggota) | `app/Http/Controllers/Dashboard/*` | M4, M5, M6, M7, M10 |
| M3 | Keanggotaan & CIF | AO, Teller, Manajer, Pengurus | Pendaftaran/verifikasi anggota, dokumen CIF, rekening | `routes/web.php:104-145` | M4 (rekening), M1 |
| M4 | Simpanan (Sukarela/Wajib/Berjangka) | Teller, Pengurus | Setor/tarik simpanan, saldo, mutasi, jenis simpanan | `TellerController`, `JenisSimpananController`, `Coa::SIMPANAN_*` | M9 (jurnal), M5 |
| M5 | Deposito Mudharabah (ARO) | Teller, Pengurus | Pembukaan deposito, bilyet, bagi hasil, jatuh tempo & perpanjangan otomatis (NON_ARO/ARO_POKOK/ARO_PLUS) | `Api/DepositoController`, `DepositoService`, `DepositoProduct`, `ProcessMaturity` | M9, M4, M12 |
| M6 | Simpanan Wajib (Mandatory) | Sistem (scheduler) | Terbit tagihan bulanan berbasis *anniversary*, auto-debit dari Sukarela, OVERDUE + notifikasi | `MandatorySavingsService`, `ProcessMandatorySavings` | M4, M9, M14 |
| M7 | Pembiayaan | AO, Manajer, Pengurus, Teller | Pengajuan, survei, approval berjenjang, akad, pencairan, angsuran, restrukturisasi | `PengajuanPembiayaanController`, `PengurusController`, `TellerController`, `RestrukturisasiService` | M9, M8, M11, M3 |
| M8 | Angsuran & Ta'zir | Teller, Manajer, Sistem | Bayar angsuran, ta'zir (denda), keringanan/banding ta'zir, autodebet | `AngsuranTransactionService`, `TazirService`, `ApprovalTazirController`, `RunAutodebetAngsuran` | M7, M9, M14 |
| M9 | Akuntansi Inti (COA & Jurnal) | Semua (otomatis) | COA `[Kategori]-[Nomor]`, auto-journaling, buku besar, neraca saldo, void/reversal | `AutoJournalingService`, `Coa`, `Jurnal`, `JurnalController`, `ReversalService` | Inti — dipakai M4–M8, M10–M16 |
| M10 | Pelaporan Keuangan Syariah | Pengurus, Manajer, DPS | 5 komponen PSAK 101 + CaLK, neraca, laba rugi, arus kas, dana kebajikan, laporan syariah | `LaporanKeuanganSyariahService`, `LaporanKeuanganController` | M9 |
| M11 | SHU (Sisa Hasil Usaha) | Pengurus | Perhitungan & pengesahan pembagian SHU | `PengurusController::pengesahanShu`, `TestShu` | M9, M4 |
| M12 | Zakat / Infak / Sedekah | Pengurus, Manajer | Penghimpunan & penyaluran dana ZIS, dana titipan di luar pendapatan | `routes/web.php:233-243`, `Coa::DANA_ZAKAT/INFAK_SEDEKAH/SOSIAL` | M9, M10 |
| M13 | CKPN (PSAK 71) | Sistem, Manajer | Penyisihan piutang berbasis kolektibilitas, penyesuaian harian idempoten | `CkpnService`, `HitungCkpnPenyesuaian` | M7, M9 |
| M14 | Kebijakan & Parameter | Admin, Pengurus | Setting koperasi, `depositoRatePa`, metode ta'zir, nisbah default | `Setting`, `KebijakanKoperasi`, `AdminController` | Dipakai M5, M6, M8 |
| M15 | DPS (Dewan Pengawas Syariah) | DPS | Review/sertifikasi produk, audit syariah per-transaksi, opini | `Dashboard/DpsController`, `DpsAuditController` | M7, M9, M10 |
| M16 | AML / Anti Pencucian Uang | Pengurus, Manajer | Profil risiko, pemantauan transaksi mencurigakan | `routes/web.php:262-308` | M4, M7 |
| M17 | Kas & Teller | Teller, Manajer | Kas teller, brangkas, opname kas, setoran ke bank | `TellerController` (`kas`, `opname`, `setor-bank`) | M9 |
| M18 | Pengeluaran Beban | Manajer, Pengurus | Pengajuan & approval beban operasional (>Rp1 jt butuh approval) | `PengeluaranBebanController` | M9, M1 |
| M19 | Audit Trail & Log Keuangan | Admin, Pengurus, DPS | `AuditLog` umum + hash-chain append-only laporan keuangan, deteksi jurnal orphan | `AuditLog`, `LaporanKeuanganLog`, `DeteksiJurnalOrphan` | M9, M10 |
| M20 | REST API | Klien eksternal | CRUD anggota/rekening/transaksi/pembiayaan/angsuran/akun/jurnal, deposito | `routes/api.php` | M3–M9 |

### 1.2 Spesifikasi Teknis Awal

- **Stack:** Laravel 12.69.1, PHP 8.3.33 (ZTS), MySQL 8.4.3, Laragon (Windows/PowerShell 5.1).
- **Pola arsitektur:** Controller → Service → `AutoJournalingService` (intake jurnal tunggal) → `Jurnal`/`JurnalDetail`. Seluruh proses pembukuan dibungkus `DB::transaction` di dalam `AutoJournalingService::post()`.
- **Skema kode akun:** `[Kategori]-[Nomor]`; kepala 1=Aset(D), 2=Kewajiban(K), 3=Ekuitas(K), 4=Pendapatan(K), 5=Beban(D). Posisi saldo efektif diturunkan dari kepala (`Coa::posisiSaldo()`), kolom `tipe_saldo_normal` hanya label (akun kontra dapat berbeda).
- **Penjadwalan:** Bootstrap memakai `Illuminate\Foundation\Console\Kernel` sehingga `App\Console\Kernel::schedule()` **tidak pernah dipanggil**. Jadwal aktif hanya yang terdaftar di `routes/console.php`.
- **Keamanan:** `AuditLog::record()` untuk jejak umum; `LaporanKeuanganLog` hash-chain append-only; `PemisahanTugas` untuk segregasi tugas; `lockForUpdate` pada operasi saldo.

### 1.3 Dependensi Kritis (peta ketergantungan)

```
M9 (Akuntansi Inti) ──┬── M4, M5, M6, M7, M8, M11, M12, M13, M17, M18
                      └── M10 (Pelaporan) ── M15, M19
M14 (Kebijakan) ─── M5, M6, M8
M15 (DPS) ─── M7 (sertifikasi produk), M10
```

**Titik tunggal kegagalan:** M9 (`AutoJournalingService`). Bila `post()` mengembalikan `null` (bukan throw) dan pemanggil tidak memeriksa null, transaksi bisnis tetap tersimpan tanpa jurnal → data tidak konsisten. Ini akar dari beberapa temuan di Bagian 4.

---

## 2. HASIL UJI FUNGSIONALITAS MODUL

Uji dilakukan melalui pembacaan jalur eksekusi nyata (rute terdaftar → controller → service), penelusuran referensi `route()`/`view()`, serta eksekusi `php artisan schedule:list`.

### 2.1 Ringkasan Temuan

| Kode | Modul | Temuan | Jenis Error | Frekuensi | Severity | Bukti |
|---|---|---|---|---|---|---|
| **A0** | M8 (Angsuran) | Jadwal `angsuran:run-autodebet` hanya terdaftar di `App\Console\Kernel::schedule()` yang **tidak pernah dipanggil** → autodebet angsuran **tidak pernah berjalan** terjadwal | Konfigurasi penjadwalan tidak aktif | 100% (permanen) | **KRITIS** | `app/Console/Kernel.php:15-18`; `bootstrap/app.php:20`; `php artisan schedule:list` (tidak memuat autodebet) |
| **A1** | M15 (DPS) | `reviewProdukApprove()` **NO-OP** — sertifikasi opini syariah produk tidak disimpan, tidak ada perubahan status, tidak ada `disahkan_oleh`/tanggal | False compliance signal (fitur palsu) | 100% tiap klik | **TINGGI** | `Dashboard/DpsController.php:265-271`; rute `web.php:325`; tombol `resources/views/dps/review-produk.blade.php:66-72` |
| **A2** | M10 (Pelaporan) | Sidebar halaman Dana Kebajikan memakai nama rute tidak terdaftar → `RouteNotFoundException` | Runtime error (500) | 100% saat halaman dibuka | **TINGGI** | `resources/views/pengurus/laporan/dana-kebajikan.blade.php:7` (`pengurus.laporan`), `:14` (`pengurus.laporan-syariah`) — yang benar `*.index` |
| **A3** | M7 (Pembiayaan) | `TellerController::pencairanProcess()` mencairkan pembiayaan **tanpa `DB::transaction`**; jurnal di `try/catch { report($e) }` → dana cair tanpa jurnal bila jurnal gagal | Partial write / integritas data | Bila jurnal gagal | **KRITIS** | `TellerController.php:1465-1543`; **DEAD CODE — tidak ada rute yang mendaftarkannya** (`web.php` hanya memakai `PencairanController` + `TellerPencairanController` yang ber-transaksi & menjurnal aman). Temuan tertutup oleh CKPN-7 (lihat `docs/AUDIT-CKPN.md`) |
| **A4** | M11/M2 (Pengurus) | Halaman approval pengurus punya controller + view tetapi **tanpa rute** → unreachable | Fitur tidak dapat diakses | Permanen | **SEDANG** | `PengurusController::approval()` L364 → `view()` L393; `resources/views/pengurus/approval.blade.php:45,67,149` |
| **A5** | M7 (Survei AO) | View `ao/survei/form.blade.php` orphan + memanggil rute `ao.survei.simpan` yang tidak ada (yang benar `ao.survei.store`) | Runtime error bila dirender | Kondisional | **SEDANG** | `resources/views/ao/survei/form.blade.php:42` |
| **A6** | M7 (Pengajuan) | `PengajuanPembiayaanController` memanggil rute `nasabah.pengajuan-pinjaman.status` (salah) → 500; dan `view('pengelola.pengajuan-pinjaman-detail')` yang **tidak ada** | Runtime error (500) | Kondisional | **SEDANG–TINGGI** | `PengajuanPembiayaanController.php:211` dan `:267` |
| **A7** | M5 (Deposito) | `DepositoController` **orphan** (tidak ada rute); `show()` memanggil view `teller.deposito-detail` yang tidak ada | Kode mati / view hilang | Bila diaktifkan | **SEDANG** | `DepositoController.php:16,62,84`; grep: hanya `Api\DepositoController` dirutekan |
| **A8** | Berbagai | 8 view orphan di `resources/views/pengelola/*` (`users`, `transaksi`, `rekening`, `pembiayaan`, `jurnal`, `anggota`, `akun`) | Kode mati | — | **RENDAH** | tidak ada rute/`view()` yang merujuk |
| **A9** | M1 (Auth) | Middleware `RequirePassword` memakai `route('password.confirm')` yang tidak terdaftar; tidak dipakai di rute mana pun | Dead code | — | **RENDAH** | `app/Http/Middleware/RequirePassword.php:12`; alias `app/Http/Kernel.php:41` |
| **A10** | M20 (API) | 4 rute API tanpa `->name()`; `teller/riwayat.blade.php:480` hardcode path; `errors/404.blade.php` tidak ada | Inkonsistensi | — | **RENDAH** | `routes/api.php:89,152-154`; `resources/views/teller/riwayat.blade.php:480` |

### 2.2 Dampak terhadap Alur Bisnis

- **A0** → Angsuran jatuh tempo tidak terpotong otomatis; anggota menunggak tanpa proses, ta'zir tidak terakumulasi → **pendapatan ta'zir hilang & kolektibilitas pembiayaan salah** (berdampak ke M13 CKPN).
- **A1** → Produk pembiayaan dapat beroperasi **tanpa opini syariah yang tersimpan**; saat audit DPS/regulator, tidak ada bukti sertifikasi → **risiko kepatuhan syariah tinggi**.
- **A2** → Halaman laporan Dana Kebajikan tidak dapat dibuka → pengurus tidak dapat memantau penyaluran dana kebajikan.
- **A3** → Risiko **dana keluar tanpa jurnal** (neraca tidak seimbang) pada pencairan pembiayaan — alur bisnis inti koperasi.
- **A4, A5, A6, A7** → Fitur terputus/unreachable; alur approval & pengajuan tidak utuh bagi sebagian peran.

### 2.3 Catatan Bersih (hasil negatif yang baik)

- Tidak ditemukan `TODO/FIXME/HACK`, `dd()/dump()/var_dump()/print_r()` di `app/`.
- Login **aman**: `login.blade.php:184` memakai guard `@if (Route::has('password.request'))` → tidak crash (tautan reset password disembunyikan karena rute belum ada).
- `PastikanAnggotaAktif.php:54` (`$request->route('anggota')`) adalah **parameter rute**, bukan nama rute → bukan temuan.

---

## 3. PERBANDINGAN DENGAN STANDAR OPERASIONAL KOPERASI SYARIAH

### 3.1 Ketidaksesuaian yang Ditemukan

| Kode | Modul | Ketidaksesuaian | Standar yang Dilanggar | Severity |
|---|---|---|---|---|
| **B1** | M15 (DPS) | Sertifikasi produk DPS tidak tersimpan (lihat A1); tabel produk tidak punya kolom `status_sertifikasi_dps`, `disahkan_oleh`, `tanggal_disahkan` | GCG koperasi syariah; Fatwa DSN-MUI (kewajiban review DPS atas produk) | **TINGGI** |
| **B2** | M5 (Deposito) | Denda penarikan dini default ke akun **Pendapatan Admin `4-2001`**, bukan Dana Kebajikan | Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — *ta'zir* tidak boleh menjadi pendapatan | **SEDANG** |
| **B3** | M5 (Deposito) | Jalur bagi hasil berbasis laba riil `distributeProfitSharing()` hanya dipanggil dari **test**; jalur aktif (teller) tidak membayar bagi hasil; `DepositoController` (orphan) menerima `bagi_hasil` **input manual** | PSAK 105 (Mudharabah); larangan riba; keadilan bagi hasil | **SEDANG** |
| **B4** | M5/M14 | Estimasi bagi hasil `pokok × rate_pa × tenor/12` (default `depositoRatePa` = 0,08) berpola bunga (dilabeli "estimasi") | Larangan riba | **SEDANG** |
| **B5** | M9 (COA) | `PIUTANG_ISTISHNA` dan `PIUTANG_SALAM` bernilai `'1-2001'` — **sama** dengan `PIUTANG_MURABAHAH` → tidak dapat dipisah per akad di neraca | PSAK 101/102/103/104 (penyajian per akad) | **SEDANG** |
| **B6** | M7 (Pembiayaan) | Produk pembiayaan tidak menyimpan nisbah (hanya `margin_persen`); `Pembiayaan` tidak memvalidasi nisbah = 100% (padahal `DepositoProduct::nisbahValid()` sudah ada) | PSAK 105/106; keadilan & transparansi bagi hasil | **SEDANG** |
| **B7** | M7 (Produk) | Enum akad produk hanya 5 (`Murabahah, Musyarakah, Mudharabah, Ijarah, Qardh`) vs 8 akad pada `Pembiayaan.php:123-130` (IMBT, Istishna, Salam) → produk Istishna/Salam/IMBT tidak dapat dibuat | PSAK 103/104/107 | **SEDANG** |
| **B8** | M5 (Deposito) | `DepositoController:151-154` menutup rekening & menolkan saldo **sebelum** jurnal dipastikan (kode orphan) | PSAK 101; perlindungan aset anggota | **SEDANG** |
| **B9** | M2/M7 | Disclosure nisbah pembiayaan ke anggota hanya `margin_nisbah` tunggal | Transparansi (prinsip koperasi syariah) | **RENDAH** |
| **B10** | M8/M5 | Tidak ada jalur keringanan penalti deposito (padahal banding ta'zir pembiayaan sudah berjenjang) | Fatwa DSN-MUI No. 17 | **RENDAH** |
| **B11** | M11/M10 | Pengurus punya LIHAT + UNDUH + EDIT + HAPUS + EKSPOR tanpa *maker-checker* | GCG (pemisahan tugas) | **RENDAH** |
| **B12** | M2 (Nasabah) | Anggota tidak punya halaman rincian bagi hasil deposito | Transparansi | **RENDAH** |

### 3.2 Kepatuhan yang SUDAH Terpenuhi (penilaian berimbang)

| Aspek | Bukti |
|---|---|
| Qardh tanpa margin (bebas riba) | `AutoJournalingService.php:80, 1076-1082, 1182-1189` |
| Margin murabahah flat (bukan bunga) | `PengajuanPembiayaanController.php:79-81` |
| Margin ditangguhkan (PSAK 108) | `Coa::MARGIN_DITANGGUHKAN = '1-2101'` (kontra-aset, PSAK 102) |
| Ta'zir → Dana Kebajikan | `AutoJournalingService::jurnalTazir` L244-250 (`DEBET 2-1001 | KREDIT 2-3001`) |
| Margin dilarang masuk Dana Kebajikan | 3 lapis validasi `AutoJournalingService.php:1414-1447` |
| DST dipisah dari Kewajiban | `LaporanKeuanganSyariahService.php:329-333,360` |
| Dana titipan di luar pendapatan & ekuitas | `LaporanKeuanganSyariahService.php:67-79,397,812,983` |
| Pengesahan akad per-transaksi DPS tersimpan | `DpsAuditController.php:15-33,90-129` |
| Audit trail umum + hash-chain append-only | `AuditLog::record()` L38-59; `LaporanKeuanganLog.php:10-22,28,276-304` |
| Segregasi tugas | `PemisahanTugas.php:37-44,53-62` |
| Blokir anggota non-aktif | `PastikanAnggotaAktif.php:11-44` |
| Penguncian baris saldo | `lockForUpdate` pada operasi saldo |
| Neraca wajib seimbang | `AutoJournalingService::pastikanBalance()` L1503 (throw exception) |
| 5 komponen PSAK 101 + CaLK | `LaporanKeuanganSyariahService.php:15-36,887,1040-1051` |
| Akses baca nasabah | `routes/web.php:467-487` |

---

## 4. LAPORAN DUA KATEGORI + ANALISIS PENCATATAN REAL-TIME

### 4.1 KATEGORI 1 — Modul Tidak Berfungsi Optimal

Urutan berdasarkan keparahan: **A0 (kritis) → A1, A3 (tinggi) → A2 (tinggi) → A4–A7 (sedang) → A8–A10 (rendah)**.
Rincian jenis error, frekuensi, dampak, dan bukti: lihat **Bagian 2.1**.

### 4.2 KATEGORI 2 — Modul Belum Memenuhi Standar Koperasi Syariah

**B1–B12** dengan referensi standar yang dilanggar: lihat **Bagian 3.1**. Kepatuhan yang sudah baik: **Bagian 3.2**.

### 4.3 ANALISIS TERPISAH — Verifikasi Pencatatan Real-Time

**Kesimpulan: TIDAK seluruh transaksi tercatat secara real-time.** Terdapat tiga pola penyimpangan.

#### (a) Jurnal Tertunda (deferred / batch 00:01 WIB) — jeda hingga ~24 jam

| Alur Transaksi | Modul | Waktu Posting | Jeda Maksimum |
|---|---|---|---|
| Pencairan deposito jatuh tempo + bagi hasil / rollover ARO | M5 | `deposito:process-maturity` 00:01 | s.d. 24 jam |
| Pencairan deposito legacy | M5 | `deposito:process-matured` 00:01 | s.d. 24 jam |
| Penagihan & auto-debit simpanan wajib (bulanan berbasis *anniversary*) | M6 | `mandatory-savings:process-daily` 00:01 | s.d. 24 jam dari jatuh tempo |
| Penyesuaian CKPN | M13 | `ckpn:hitung-penyesuaian` 00:01 | s.d. 24 jam |
| Autodebet angsuran | M8 | `angsuran:run-autodebet` 07:00 — **namun tidak aktif** (A0) | **tidak pernah berjalan** |
| Beban >Rp1 jt, opname kas, void | M17/M18/M9 | menunggu approval manusia | *disengaja* (kontrol) |

Catatan nuansa: tagihan **simpanan wajib bersifat bulanan** berbasis *anniversary* (`MandatorySavingsService::tanggalJatuhTempo()` L247-258; OVERDUE setelah tanggal 10). Penjadwalan harian 00:01 hanya untuk memastikan tanggal *anniversary* tepat waktu — bukan karena tagihan harian.

#### (b) Alur Transaksi TANPA Jurnal Sama Sekali (KRITIS)

| Alur | Bukti |
|---|---|
| Rollover + bagi hasil deposito legacy | `DepositoController.php:242` (`increment('saldo', $bagiHasil)` tanpa jurnal) — kode orphan |
| Kas opening / modal awal | `TellerController.php:976` |
| Restrukturisasi pembiayaan | `RestrukturisasiService.php:160-297` |
| Void untuk jenis di luar daftar reversal | `ReversalService.php:232` |

#### (c) Jurnal Ditulis DI LUAR `DB::transaction` (risiko partial write)

| Lokasi | Status | Pola |
|---|---|---|
| `TellerController.php:1363-1417` | **AKTIF** (rute `web.php:401`) | jurnal di `try/catch { report($e) }` → dana cair tanpa jurnal bila gagal |
| `DepositoController.php:89-196`, `:285-344` | orphan | TX hanya membungkus `increment`; jurnal di luar TX |
| `CkpnService.php:151-187` | aktif (scheduler) | jurnal di luar TX |

#### (d) Pemanggilan `post()` tanpa pemeriksaan `null`

`AutoJournalingService::post()` mengembalikan `null` saat gagal (bukan throw), sehingga pemanggil **wajib** memeriksa null + throw agar rollback. Belum melakukannya: `TellerController.php:110, 278, 1068, 1132, 1328`; `ApprovalTazirController.php:241`; `TazirService.php:234`; `PengeluaranBebanController.php:419-421`.

#### (e) Integritas & Ketersediaan Riwayat (positif)

- `Jurnal` selalu `status='Posted'` + `posted_at=now()` saat diposting (L1480-1483); hanya `JurnalController::store` (L60-97) yang membuat Draft.
- Nomor jurnal berurutan `JRN-YYYYMMDD-0001` (L1512-1521); guard periode L1472.
- Audit jurnal orphan (7 kategori) = **0**; Σ mutasi debet = kredit **120.747.500,00** (selisih 0,00); Aset **107.489.167,00** = Pasiva **107.489.167,00**.
- Riwayat transaksi dapat diakses (mutasi rekening, buku besar, laporan); hash-chain append-only menjamin riwayat laporan keuangan tidak dapat diubah diam-diam.

**Jawaban ringkas:** pencatatan *real-time* terpenuhi untuk transaksi teller yang berhasil (setor/tarik, angsuran manual, pencairan — *dengan catatan* A3), tetapi **tidak** untuk: (1) alur berbasis penjadwalan (deposito jatuh tempo, bagi hasil ARO, simpanan wajib, CKPN) yang tertunda s.d. 24 jam; (2) autodebet angsuran yang tidak pernah berjalan; (3) empat alur tanpa jurnal; (4) alur yang jurnalnya di luar transaksi DB.

---

## 5. REKOMENDASI PERBAIKAN BERDASARKAN PRIORITAS + TIMELINE

### 5.1 URGENSI TINGGI

| # | Rekomendasi | Langkah Teknis / Penyesuaian Alur | Mengatasi | Timeline |
|---|---|---|---|---|
| R1 | **Aktifkan penjadwalan autodebet angsuran** | Pindahkan `Schedule::command('angsuran:run-autodebet')->dailyAt('07:00')->withoutOverlapping()` dari `App\Console\Kernel::schedule()` ke `routes/console.php`; verifikasi via `php artisan schedule:list`; uji `--dry-run` | A0 | **Fase 1 (segera)** |
| R2 | **Bungkus pencairan pembiayaan dengan transaksi DB** | Di `TellerController::pencairanProcess()` bungkus seluruh proses dalam `DB::transaction`; pindahkan penulisan jurnal ke dalam TX; periksa nilai balik `post()` dan `throw` bila `null`. **Catatan**: `pencairanProcess()` kini **dead code** (tak ada rute); jalur aktif `PencairanController` → `DisbursementService` sudah ber-transaksi & memeriksa `post()`. Sisa tindakan: hapus dead code atau beri rute bila masih diperlukan | A3, C(c), C(d) | **Fase 1 (segera)** |
| R3 | **Implementasikan penyimpanan sertifikasi produk DPS** | Tambah kolom `status_sertifikasi_dps`, `disahkan_oleh`, `tanggal_disahkan` pada tabel produk; isi `reviewProdukApprove()` dengan update status + `AuditLog::record()`; batasi pembiayaan pada produk tersertifikasi | A1, B1 | **Fase 1–2** |
| R4 | **Perbaiki nama rute yang rusak** | Ubah `dana-kebajikan.blade.php:7,14` → `pengurus.laporan.index` & `pengurus.laporan-syariah.index`; `ao/survei/form.blade.php:42` → `ao.survei.store`; `PengajuanPembiayaanController.php:211` → `nasabah.status-pengajuan.index`; sediakan view `pengelola.pengajuan-pinjaman-detail` | A2, A5, A6 | **Fase 1** |
| R5 | **Tambahkan pemeriksaan null `post()` secara menyeluruh** | Audit seluruh pemanggil `AutoJournalingService::post()`; tambahkan `if ($jurnal === null) throw ...` di titik yang teridentifikasi (Bagian 4.3d) | C(d), integritas data | **Fase 2** |
| R6 | **Lengkapi jurnal pada alur yang belum tercatat** | Tambah pemanggilan `post()` pada: kas opening/modal awal (`TellerController.php:976`), restrukturisasi (`RestrukturisasiService.php:160-297`), dan perluas daftar reversal `ReversalService.php:232` | C(b) | **Fase 2** |

### 5.2 URGENSI MENENGAH

| # | Rekomendasi | Langkah Teknis / Penyesuaian Alur | Mengatasi | Timeline |
|---|---|---|---|---|
| R7 | **Pindahkan ta'zir penalti deposito ke Dana Kebajikan** | Ubah `DepositoProduct::PENALTI_COA_DEFAULT` dari `Coa::PENDAPATAN_ADMIN` → `Coa::DANA_KEBAJIKAN`; migrasi data produk eksisting; validasi agar penalti tidak dapat diarahkan ke akun pendapatan | B2 | Fase 2 |
| R8 | **Aktifkan bagi hasil berbasis laba riil pada jalur produksi** | Panggil `DepositoService::distributeProfitSharing()` dari `Api/DepositoController`; hapus penerimaan `bagi_hasil` manual; hitung bagi hasil dari pendapatan investasi periode | B3, B4 | Fase 3 |
| R9 | **Pisahkan akun piutang per akad** | Beri kode unik untuk `PIUTANG_ISTISHNA` & `PIUTANG_SALAM` (mis. `1-2006`, `1-2007`); perbarui mapping jurnal & neraca | B5 | Fase 2 |
| R10 | **Tambahkan nisbah pada produk pembiayaan & validasi 100%** | Tambah kolom `nisbah_anggota`/`nisbah_koperasi`; terapkan validasi setara `DepositoProduct::nisbahValid()` pada `Pembiayaan` | B6 | Fase 3 |
| R11 | **Selaraskan enum akad produk dengan 8 akad** | Perluas enum produk agar mendukung IMBT, Istishna, Salam; sesuaikan form & validasi | B7 | Fase 3 |
| R12 | **Perbaiki urutan tutup rekening deposito** | Pastikan jurnal diposting & terverifikasi **sebelum** saldo dinolkan dan status ditutup (kode orphan — rapikan atau hapus bila tidak dipakai) | B8, A7 | Fase 3 |
| R13 | **Bungkus jurnal CKPN dalam transaksi** | Pindahkan penulisan jurnal `CkpnService.php:151-187` ke dalam `DB::transaction` | C(c) | Fase 2 |
| R14 | **Aktifkan halaman approval pengurus atau hapus** | Daftarkan rute `pengurus.approval.index` **atau** hapus controller+view bila duplikatif | A4 | Fase 2 |

### 5.3 URGENSI RENDAH

| # | Rekomendasi | Langkah | Mengatasi | Timeline |
|---|---|---|---|---|
| R15 | Bersihkan kode mati | Hapus 8 view orphan `pengelola/*`, `RequirePassword` (atau daftarkan `password.confirm`), `errors/404.blade.php` bila perlu | A8, A9, A10 | Fase 4 |
| R16 | Rapikan penamaan rute API | Tambahkan `->name()` pada 4 rute; ganti hardcode path di `teller/riwayat.blade.php:480` | A10 | Fase 4 |
| R17 | Perluas transparansi nisbah & bagi hasil ke anggota | Tambah halaman rincian bagi hasil deposito untuk anggota; tampilkan nisbah per akad | B9, B12 | Fase 4 |
| R18 | Tambahkan jalur keringanan penalti deposito | Adopsi pola banding ta'zir pembiayaan yang berjenjang | B10 | Fase 4 |
| R19 | Terapkan maker-checker pada peran pengurus | Pisahkan hak EDIT/HAPUS dari LIHAT/UNDUH/EKSPOR laporan | B11 | Fase 4 |

### 5.4 Timeline Implementasi (Ringkas)

| Fase | Fokus | Rekomendasi |
|---|---|---|
| **Fase 1** | Hentikan kerugian & error tampil | R1, R2, R4 |
| **Fase 2** | Integritas data & kepatuhan dasar | R3, R5, R6, R7, R9, R13, R14 |
| **Fase 3** | Penyempurnaan syariah & akad | R8, R10, R11, R12 |
| **Fase 4** | Kebersihan kode & transparansi | R15, R16, R17, R18, R19 |

**Kriteria keberhasilan akhir:** (1) seluruh rute/tautan yang dipakai terdaftar dan dapat diakses; (2) tidak ada transaksi bisnis tersimpan tanpa jurnal; (3) seluruh penulisan jurnal berada dalam `DB::transaction` dengan pemeriksaan nilai balik `post()`; (4) sertifikasi produk DPS tersimpan dan dapat diaudit; (5) ta'zir/penalti tidak pernah masuk akun pendapatan; (6) bagi hasil dihitung dari laba riil, bukan estimasi berbunga; (7) seluruh proses terjadwal aktif dan terverifikasi via `php artisan schedule:list`.

### 5.5 Catatan Progres Eksekusi

Seluruh rekomendasi R1–R19 pada Fase 1–4 **tuntas**. Setelah itu, butir
permintaan induk yang belum diverifikasi khusus dikerjakan sebagai tahap
lanjutan:

| Tahap | Dokumen | Lingkup | Hasil suite saat penutupan |
|---|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | PSAK inti | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | Pelaporan | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | GCG & OJK | 872 passed / 4160 assertions |
| 5 | `docs/TAHAP5-HARDENING.md` | Hardening teknis & integritas data (Klaster D) | 900 passed / 4244 assertions |
| 6 | `docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md` | Fase 4: kebersihan kode & transparansi (R15–R19) | 955 passed / 4629 assertions |
| 7 | `docs/TAHAP7-SKALABILITAS-JALUR-BACA.md` | Skalabilitas jalur baca: sargability & indeks penopang | 967 passed / 4666 assertions |
| **8** | **`docs/TAHAP8-REAL-TIME.md`** | **Sinkronisasi data real-time (mesin "Denyut")** | **1007 passed / 4807 assertions** |

**Tahap 7** menjawab butir permintaan induk *"verifikasi skalabilitas
(peningkatan jumlah anggota & volume transaksi)"* secara terukur: 5 full table
scan pada jalur baca berulang dihilangkan dengan indeks yang dibuktikan
terpakai (`EXPLAIN`), seluruh filter periode pada kolom tanggal bisnis diubah
menjadi sargable (`App\Support\RentangPeriode`), dan **nol** indeks ditambahkan
pada tabel `transaksi` yang write-heavy karena probe membuktikan indeks di sana
tidak membantu bentuk kueri laporan. Angka laporan dijamin tidak berubah oleh
test ekuivalensi (termasuk batas bulan & Februari kabisat).

**Tahap 8** menjawab butir permintaan induk *"fokus utama menyelesaikan masalah
sinkronisasi data yang tidak terupdate secara real time"*. Audit atas bukti
konfigurasi nyata (`BROADCAST_DRIVER=log`, `QUEUE_CONNECTION=sync`,
`CACHE_DRIVER=file`) menunjukkan **tidak ada jalur push server → peramban** dan
**tidak ada queue asinkron**; keduanya bukan perbaikan konfigurasi melainkan
penambahan infrastruktur di luar lingkup instalasi. Karena itu dibangun **mesin
denyut** tiga lapis: (L1) buku versi data `DenyutData` + `DenyutObserver` yang
menaikkan versi domain terdampak tiap mutasi model; (L2) endpoint `GET /denyut`
yang mengembalikan sidik jari perubahan **tanpa menyentuh database** (0 kueri),
dengan ETag lemah + `Cache-Control: no-cache, must-revalidate`; (L3) endpoint
`.../ringkas` per dashboard yang mengirim **hanya potongan HTML terdampak**.
Transportasi bertingkat: **SSE → long polling → polling adaptif** (backoff
eksponensial + `visibilitychange`). Tujuh dashboard (teller, manajer, pengurus,
AO, DPS, pengelola, nasabah) ter-wire seragam dengan **satu sumber data** per
halaman. Pemantauan & degradasi anggun: `DenyutMonitor`, kanal log `denyut`
(anti-banjir 1×/menit), dan perintah `php artisan denyut:kesehatan [--rinci]`.
Kegagalan cache **tidak pernah** menggagalkan transaksi keuangan. Latensi
penyegaran dikunci test < 2 detik sesuai tuntutan. Angka laporan tidak berubah.

**Status butir permintaan induk:**

- Butir **antarmuka responsif & aksesibilitas** — **TUNTAS** pada
  `docs/TAHAP9-AKSESIBILITAS-RESPONSIF.md` (WCAG 2.1 Level AA, 18 test).
- Butir **keamanan data** anggota/transaksi — **TUNTAS** pada
  `docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md`. Cakupan: enkripsi NIK + blind index
  (`PiiHasher`, `anggota.nik_hash`), tabel audit append-only
  `audit_perubahan_pii` dengan hash chain, middleware `KeamananHeader`
  (nosniff/CSP/Referrer-Policy/X-Frame-Options/Permissions-Policy/HSTS),
  rate limiting endpoint publik, kebijakan password (`PasswordKuat`), serta
  `TrustProxies` + opsi enkripsi sesi. Seluruh saklar dibaca dari
  `config/keamanan.php` sehingga dapat disetel lewat env dan tetap bekerja saat
  `config:cache` aktif. Bukti: 54 test khusus (17 + 13 + 15 + 9) dan suite penuh
  1089 lulus / 5056 assertion pada saat tahap itu ditutup.
- Butir **penyelarasan sistem dinamis/fleksibel dengan PSAK koperasi syariah** —
  **TUNTAS** pada `docs/TAHAP11-FLEKSIBILITAS-DINAMIS-PSAK.md`. Pemetaan akad
  syariah → akun CoA dipindahkan dari konstanta PHP ke tabel
  `pemetaan_akad_coa` yang dikelola Pengurus dari menu Kebijakan, sehingga
  akad baru (mis. Musyarakah Mutanaqisah) dapat ditambahkan **tanpa mengubah
  kode sumber**. `CoaResolver` membaca pemetaan basis data lebih dahulu lalu
  mundur ke kebijakan koperasi dan terakhir ke registri standar
  `App\Support\Coa`; `Pembiayaan::daftarAkadKanonik()` menjaga akad bawaan
  tetap dikenali sebagai jaring pengaman. Bukti: 12 test / 32 assertion
  (`tests/Feature/FleksibilitasAkadPsakTest.php`).

**Seluruh butir permintaan induk kini TUNTAS.**

**Prosedur pengujian:** kewajiban `php artisan optimize:clear` sebelum
`php artisan test` didokumentasikan pada `docs/PANDUAN-PENGUJIAN.md` dan
ditegakkan otomatis oleh `scripts/run-tests.ps1` serta
`tests/CreatesApplication.php`.

---

## 6. VERIFIKASI ULANG BUTIR AUDIT (Tahap 10–12)

Bagian 2 dan 3 di atas adalah **potret saat audit pertama**. Banyak butir di
dalamnya sudah ditutup pada tahap-tahap berikutnya, sehingga tabel tersebut
**tidak boleh lagi dibaca sebagai daftar pekerjaan terbuka**. Verifikasi ulang
dilakukan langsung ke kode (bukan ke dokumen) pada suite
**1.953 test / 9.214 assertion (hijau)**.

### 6.1 Butir A (temuan fungsional)

| Kode | Status | Bukti verifikasi |
|---|---|---|
| **A0** | **TERTUTUP** | `routes/console.php:149` — `Schedule::command('angsuran:run-autodebet')` terdaftar di scheduler Laravel 11+ (bukan lagi `Console/Kernel` yang tak terpanggil) |
| **A1** | **TERTUTUP** | `Dashboard/DpsController::reviewProdukApprove()` kini idempoten, di dalam `DB::transaction`, menulis `AuditLog::record('SERTIFIKASI_PRODUK_DPS', ...)` |
| **A2** | **TERTUTUP** | Nama rute sidebar Dana Kebajikan sudah memakai `*.index` yang terdaftar |
| **A3** | **TERTUTUP** | `TellerController::pencairanProcess()` adalah dead code (tidak ada rute); jalur aktif `PencairanController::prosesPencairan` ber-transaksi & menjurnal aman |
| **A4** | **TERTUTUP** | Rute halaman approval pengurus terdaftar di `routes/web.php:281-283` |
| **A5** | **TERTUTUP** | `resources/views/ao/survei/form.blade.php:42` memakai nama rute yang benar (`ao.survei.store`) |
| **A6** | **TERTUTUP** | `PengajuanPembiayaanController` memakai rute status yang benar; `resources/views/pengelola/pengajuan-pinjaman-detail.blade.php` **terbukti ada** |
| **A7** | **TERTUTUP** | `app/Http/Controllers/DepositoController.php` sudah tidak ada; tidak ada lagi rujukan view `teller.deposito-detail` |
| **A8** | **TERTUTUP** | `resources/views/pengelola/` kini hanya berisi `pengajuan-pinjaman` & `pengajuan-pinjaman-detail`, keduanya dirutekan |
| **A9** | **TERTUTUP** | `RequirePassword` / `password.confirm` tidak lagi ditemukan di `app/` |
| **A10** | **TERTUTUP** | `resources/views/teller/riwayat.blade.php:477-482` sudah memakai `route("teller.slip")`, `route("teller.void")`, `route("teller.riwayat")` — path hardcode hilang. Halaman galat tersedia (`resources/views/errors/403.blade.php`, `errors/umum.blade.php`); `errors/404.blade.php` memang tidak dibuat, tetapi Laravel memakai tampilan 404 bawaan sehingga bukan cacat fungsional. Sebagian rute API masih tanpa `->name()` (mis. `jurnal/*`, `pengajuan-rekening/*`, `deposito/products`), namun tidak ada pemanggil `route()` yang bergantung padanya — kosmetik, bukan error |
| **A11** | **TERTUTUP** | Toleransi dibaca dari `Setting::toleransiSelisihJurnal()` (`Setting.php:1460`) dan `Setting::toleransiPersen()` (`Setting.php:1470`), bukan angka tersebar |

### 6.2 Butir B (kesesuaian syariah/PSAK)

| Kode | Status | Bukti verifikasi |
|---|---|---|
| **B1** | **TERTUTUP** | `MasterProdukPembiayaan` memiliki kolom sertifikasi DPS (`disahkan_oleh`, `tanggal_disahkan`, status) |
| **B2** | **TERTUTUP** | `DepositoProduct::PENALTI_COA_DEFAULT = Coa::DANA_KEBAJIKAN`; `coaPenalti()` memaksa fallback ke Dana Kebajikan |
| **B3** | **TERTUTUP** | `Api/DepositoController::distribusiBagiHasil()` (`:427-453`) menghitung pendapatan dari `pendapatanInvestasiBulanan()` — **nominal tidak lagi diterima dari pengguna**; rute `routes/api.php:185-186` |
| **B4** | **BUKAN CACAT PRODUKSI** | `DepositoService::calculateSimulation()` hanya simulasi, berlabel `'skema' => 'Mudharabah Muthlaqah (nisbah, bukan bunga tetap)'` + peringatan ESTIMASI; `asumsiRatePa()` membaca `Setting::depositoRatePa()`. Tidak ada perhitungan produksi yang memakai `rate_pa` |
| **B5** | **TERTUTUP** | `Coa::PIUTANG_ISTISHNA = '1-2006'`, `Coa::PIUTANG_SALAM = '1-2007'` — terpisah dari Murabahah |
| **B6** | **TERTUTUP** | `MasterProdukPembiayaan::nisbahValid()` + kolom `nisbah_anggota`/`nisbah_koperasi` |
| **B7** | **TERTUTUP** | `resources/views/pengelola/produk-pembiayaan/form.blade.php:28-31` memakai `@foreach(\App\Models\Pembiayaan::daftarAkad() ...)` → 8 akad dari SSOT |
| **B8** | **TERTUTUP** | `DepositoService::earlyWithdrawal()` memposting jurnal **sebelum** menutup rekening dan `throw new \RuntimeException` bila jurnal gagal; ditutup `pastikanSubledgerSehat()` |
| **B9** | **TERTUTUP** | `resources/views/nasabah/deposito-bagi-hasil.blade.php:59-71` menampilkan akad + nisbah anggota & koperasi |
| **B10** | **TERTUTUP** | `DepositoPenaltyRelief::siapDipakaiUntuk()` + penandaan `dipakai_at` di akhir `earlyWithdrawal()` |
| **B11** | **TERTUTUP** | `LaporanKeuanganPermission` memisahkan hak tulis ke `MODUL_PENYESUAIAN` (maker-checker) — hak laporan hanya-baca |
| **B12** | **TERTUTUP** | Halaman `nasabah/deposito-bagi-hasil.blade.php` tersedia untuk anggota |

### 6.3 Butir yang TIDAK dapat diverifikasi

| Klaim | Hasil |
|---|---|
| **Tahap 12 — "data `kas_harian` id=1 bermasalah"** | **Tidak ditemukan dasarnya di kode.** Tidak ada satu pun rujukan `KasHarian` dengan `id = 1` di `app/` maupun `database/`; seluruh query memakai pasangan `user_id` + `tanggal` (`PastikanKasTerbuka`, `BukaKasAction`, `TutupKasAction`, `TambahModalKasAction`, `SetorKasKeBrankasAction`, `Teller\ClosingController`). Angka "1" pada `docs/LAPORAN-PENGUJIAN-DEMO-PER-PERAN-DAN-DINAMIKA.md:88` adalah **hitungan baris tabel snapshot demo**, bukan rujukan kode. Butir ini memerlukan deskripsi gejala nyata dari pengguna sebelum dapat dikerjakan. |

### 6.4 Sisa pekerjaan yang benar-benar terbuka

Bukan cacat fungsional, melainkan:

1. **Konsistensi pembulatan — ternyata BUKAN utang.** Dari 52 kemunculan
   `round(` di `app/`, hanya **9** yang membulatkan **uang ke 2 desimal**, dan
   kesembilannya berada di dalam `app/Support/Setting.php` — yaitu kelas yang
   *mendefinisikan* `DESIMAL_UANG`. Sisanya bukan kandidat konsolidasi:
   pembulatan hari/bulan/rasio/latensi (bukan uang), pembulatan ke **rupiah
   penuh** yang memang disengaja (`JournalDeposito.php:218-219`,
   `DanaKebajikanService.php:310`), atau konversi ke sen
   `(int) round($x * 100)`. Mengganti dua kelompok terakhir dengan
   `Setting::bulatkanUang()` justru **mengubah perilaku** (menambah/menghapus
   sen). Kesimpulan: tidak ada konsolidasi yang perlu dikerjakan.
2. **Penamaan rute API** — sebagian rute `jurnal/*`, `pengajuan-rekening/*`, dan
   `deposito/products` belum memakai `->name()`. Tidak ada pemanggil yang
   bergantung padanya, jadi ini kosmetik dan tidak mendesak.
3. **Izin API setor/tarik — TIDAK PERLU DIUBAH.** Rute `api.transaksi.setor`
   dan `api.transaksi.tarik` sengaja tidak memakai `izin:teller.loket` karena
   itu adalah **layanan mandiri anggota** (mobile). Penjaganya sudah berlapis:
   `auth:sanctum`, `kas.terbuka`, kepemilikan rekening (`rekeningMilikPengguna()`
   → 403 bila bukan miliknya), `GuardSimpananModal`, dan
   `TransactionLimitService`. Menambahkan `izin:teller.loket` justru akan
   **memblokir anggota** bertransaksi atas rekeningnya sendiri.

### 6.5 Butir R (rekomendasi) — status verifikasi

| Kode | Status | Bukti |
|---|---|---|
| **R1** | **TERTUTUP** | `routes/console.php:149` — `Schedule::command('angsuran:run-autodebet')` |
| **R2** | **TERTUTUP** | `TellerController::pencairanIndex()` dan `pencairanProcess()` (dead code, tidak pernah dirutekan) **dihapus**, termasuk dependensi `ProsesPencairanAction` yang menjadi tak terpakai. Jalur aktif: `PencairanController` + `TellerPencairanController` |
| **R3** | **TERTUTUP** | Kolom sertifikasi DPS pada `MasterProdukPembiayaan` + `reviewProdukApprove()` (lihat B1) |
| **R4** | **TERTUTUP** | Nama rute diperbaiki (lihat A2, A5, A6) |
| **R5** | **TERTUTUP** | `PostingJurnal::post()` **tidak pernah** `return null` — kegagalan memanggil `tolakPosting()` yang melempar `JournalPostingException` (`PostingJurnal.php:418` satu-satunya `return $jurnal`). Guard `if (! $jurnal) throw` di `AngsuranTransactionService.php:554`, `CkpnService.php:336,578`, `JurnalController.php:414`, `BukaKasAction.php:81`, `ReversalService.php:498` dipertahankan sebagai pertahanan berlapis |
| **R6** | **TERTUTUP** | `BukaKasAction.php:80-83` menjurnal modal awal (Brankas → Laci) dan melempar bila gagal; `ReversalService::buatJurnalReversal()` mencakup 12 cabang jenis transaksi + throw bila jurnal wajib tidak terbentuk; restrukturisasi hanya menjadwal ulang (tanpa arus kas) sehingga memang tidak memerlukan jurnal |
| **R7–R19** | **TERTUTUP** | R7→B2, R8→B3, R9→B5, R10→B6, R11→B7, R12→B8, R13 (`CkpnService.php:323,565` — `DB::transaction` + throw), R14→A4, R15→A8/A9/A10, R17→B9/B12, R18→B10, R19→B11 |
| **R16** | **SENGAJA TIDAK DIKERJAKAN** | Menambah `->name()` pada rute yang tidak dipanggil `route()` tidak memberi manfaat fungsional dan hanya menambah permukaan API. Bila kelak dibutuhkan, penamaannya mengikuti konvensi `api.*` |

---

*Bagian 1–5 adalah potret audit awal dan dipertahankan sebagai catatan sejarah.
Bagian 6 adalah status terkini hasil verifikasi ulang terhadap kode.*
