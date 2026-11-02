# Audit Menyeluruh Sistem Koperasi Syariah — PSAK & Regulasi OJK

**Tanggal audit:** 2 Oktober 2026
**Lingkup:** Seluruh sistem (24 Service, 45+ Controller, 50+ Model, seluruh rute & migrasi)
**Metode:** Pemetaan arsitektur → 4 audit paralel (akuntansi/PSAK, keamanan, bug fungsional, kepatuhan OJK) → **verifikasi manual baris-per-baris** atas setiap temuan KRITIS/TINGGI.
**Baseline uji:** `1245 passed (5591 assertions)` — tidak ada regresi.

> Legenda keparahan: **KRITIS** = kerugian dana/data langsung & melanggar regulasi; **TINGGI** = integritas akuntansi/keamanan terancam; **SEDANG** = penyimpangan standar/operasional; **RENDAH** = pengerasan/kerapian.

---

## Ringkasan Eksekutif

| Kategori | KRITIS | TINGGI | SEDANG | RENDAH | Total |
|---|---|---|---|---|---|
| Akuntansi & PSAK | 1 | 3 | 3 | 2 | 9 |
| Keamanan | 3 | 4 | 6 | 4 | 17 |
| Bug Fungsional & Dinamika | 1 | 2 | 4 | 1 | 8 |
| Kepatuhan OJK | 1 | 2 | 4 | 1 | 8 |
| **Total** | **6** | **11** | **17** | **8** | **42** |

**6 temuan KRITIS yang harus diprioritaskan:** K-01, K-02, K-03, BUG-01, F-01, OJK-01.

**Kontrol yang sudah BENAR (dikonfirmasi):** transaksi Teller (`DB::transaction` + `lockForUpdate`), `approveTransaction()` (lock + guard saldo), `DisbursementService`/`AngsuranTransactionService`/`ReversalService`/`TazirService` (atomik), `PemisahanTugas` pada modul operasional, hash chain `LaporanKeuanganLog`, CSRF aktif (`$except = []`), tidak ada SQL injection (grep), enkripsi PII + blind index, guard command destruktif di produksi, RBAC rute berjenjang.

---

## A. Temuan Akuntansi & Kepatuhan PSAK

### F-01 — KRITIS — Jurnal "hantu" tanpa `coa_id` (hilang dari laporan)
- **Status: ✅ SELESAI (terverifikasi ulang — kode saat ini sudah aman)**
- **Lokasi:** [JurnalDetailController.php](../app/Http/Controllers/JurnalDetailController.php#L46-L110).
- **Jenis:** Integritas data akuntansi / ketidaksesuaian skema.
- **Bukti:** Jalur ini menulis kolom warisan `akun_id` (nullable) tetapi **tidak menulis `coa_id`** — kolom kanonik yang dipakai seluruh laporan via `INNER JOIN coas`. `AutoJournalingService::post()` menulis `'akun_id' => null, 'coa_id' => $coa->id`.
- **Dampak:** Baris jurnal yang dibuat melalui API detail **tidak muncul di buku besar, neraca, laba-rugi, dan laporan sumber-penggunaan dana** → laporan keuangan tidak lengkap. Melanggar **PSAK 101** (penyajian laporan keuangan syariah) dan prinsip *completeness*.
- **Perbaikan (kode saat ini):** `store()` menerjemahkan **setiap** baris ke `coa_id` lewat `resolveCoaId()` SEBELUM menghapus baris lama; `akun_id` hanyalah masukan fallback (pemetaan kode legacy → kode CoA → kecocokan nama akun). Bila satu baris tidak terpetakan, seluruh permintaan ditolak HTTP 422 dengan pesan edukatif — jurnal lama tetap utuh (tidak ada jurnal hantu). Penulisan selalu `'coa_id' => $item['coa_id']` dan `'akun_id' => null`. Penulisan `akun_id` sudah dihapus seluruhnya dari jalur ini.

### F-02 — TINGGI — Toleransi keseimbangan jurnal tidak konsisten (hardcoded `0.01`)
- **Status: ✅ SELESAI (terverifikasi ulang — temuan usang)**
- **Lokasi:** [Jurnal.php](../app/Models/Jurnal.php#L118) (observer `saving`), L144 (`pastikanBalance()`), L199 (`hitungBalance()`), [JurnalDetailController.php](../app/Http/Controllers/JurnalDetailController.php#L121).
- **Jenis:** Konsistensi aturan bisnis.
- **Bukti kode saat ini:** seluruh perbandingan keseimbangan jurnal **sudah memakai titik aturan tunggal** `Setting::toleransiSelisihJurnal()` — tidak ada lagi literal `0.01`. Perbaikan ini sudah diterapkan sebelum audit Gelombang 2 sehingga tidak perlu dikerjakan ulang; cukup dikoreksi statusnya di dokumen.
- **Dampak (bila temuan masih aktif):** Jurnal bisa "lolos" di satu jalur dan "gagal" di jalur lain → inkonsistensi keputusan balance. Melanggar **PSAK 108** (kerangka transaksi syariah) & prinsip konsistensi.

### F-03 — TINGGI — `JurnalController::void()` tanpa jurnal pembalik & tanpa SoD
- **Lokasi:** [JurnalController.php](../app/Http/Controllers/JurnalController.php#L216-L234).
- **Jenis:** Integritas akuntansi + GCG.
- **Bukti:** Hanya `$jurnal->update(['status' => 'Void'])`. Tidak ada jurnal pembalik (reversal), tidak ada `PemisahanTugas`, tidak ada alasan void.
- **Dampak:** Saldo akun tidak dikembalikan; jejak audit tidak lengkap. **PSAK 101/108** mensyaratkan pembatalan transaksi melalui jurnal pembalik, bukan sekadar ubah status. Melanggar prinsip GCG (maker-checker).
- **Rekomendasi:** Wajibkan jurnal pembalik otomatis + `PemisahanTugas` + alasan void wajib.

### F-04 — TINGGI — Gerbang cut-off periode hanya di `AutoJournalingService`
- **Lokasi:** [AutoJournalingService.php](../app/Services/AutoJournalingService.php) (`pastikanDapatDiposting()` ~L1754); jalur yang melewatinya: `IkhtisarShuService`, `ShuCalculatorService::cairkanShuDitahan`, `JurnalController::store/post`.
- **Jenis:** Kontrol periode akuntansi.
- **Dampak:** Jurnal dapat diposting ke periode yang sudah ditutup melalui jalur manual → **laporan periode tertutup berubah retroaktif**. Melanggar **PSAK 101** & prinsip *periodicity/cut-off*.
- **Rekomendasi:** Pasang gerbang cut-off di model/observer agar berlaku universal.

### F-05 — SEDANG — Nomor jurnal/transaksi memakai `date('Ymd')` (bukan sequence periode)
- **Status: ✅ SELESAI (aspek tabrakan)** — sufiks urut kini diambil dari **nilai numerik terbesar** (`MAX(CAST(SUBSTRING(...) AS UNSIGNED))`) bukan `substr($maxNo, -4)` yang memotong urutan >9999, ditambah **pencarian kandidat bebas** sampai nomor tidak terpakai. Format tetap (aman bagi test `DEP-\d{8}-\d{4}`). *Aspek "nomor mencerminkan periode akuntansi" sengaja tidak diubah agar kontrak format API/test tidak rusak.*
- **Lokasi:** [AutoJournalingService.php](../app/Services/AutoJournalingService.php#L1794-L1820) (`JRN-YYYYMMDD-`), [DepositoService.php](../app/Services/DepositoService.php#L1399-L1457) (`DEP-`/`TRX-`), [PengajuanPembiayaan.php](../app/Models/PengajuanPembiayaan.php#L495-L520) (`PMB-YYYYMM-`).
- **Catatan desain:** penomoran tetap harian (bukan `YYMM`) demi **kompatibilitas format** dengan kontrak API & test yang ada; yang diperbaiki adalah **keamanan urutan** (tidak duplikat saat volume tinggi / ada record terhapus).

### F-06 — SEDANG — `Coa::firstOrCreate` membuat akun otomatis
- **Status: ✅ SELESAI (Gelombang 3)** — `Coa::firstOrCreate` pada modul SHU telah diganti **resolusi dinamis** `App\Support\Coa::terdaftar()` + persetujuan pengurus; tidak ada lagi akun CoA yang tercipta otomatis dari jalur transaksi.
- **Lokasi perbaikan:** [ShuCalculatorService.php](../app/Services/ShuCalculatorService.php), [ShuController.php](../app/Http/Controllers/Pengurus/ShuController.php) — kini mengimpor `App\Support\Coa` (bukan `App\Models\Coa`) dan memakai `Coa::terdaftar()`/`namaAkun()`.
- **Catatan:** sisa `firstOrCreate` di `ShuController` hanya untuk model `Rekening` (bukan CoA) dan tidak menciptakan akun otomatis.

### F-07 — SEDANG — Asumsi tahun buku Januari hardcode (`12`)
- **Status: ✅ SELESAI** — seluruh batas 12 bulan diganti **`TahunBukuService::jumlahBulan()`**; kalkulasi prorate memakai `bulanAntara()` + `bulanKe()` sehingga tahun buku non-kalender (mis. Juli–Juni) & tahun buku pendek sahih.
- **Lokasi:** [TestShu.php](../app/Console/Commands/TestShu.php#L111-L135), [DistribusiBagiHasilDeposito.php](../app/Console/Commands/DistribusiBagiHasilDeposito.php#L43-L80), [DepositoService.php](../app/Services/DepositoService.php#L1091-L1230), [PendapatanService.php](../app/Services/PendapatanService.php#L243-L267).

### F-08 — RENDAH — Fallback akun `Akun::query()->value('id')`
- **Status: ✅ SELESAI** — fallback akun sembarang dihapus; resolusi memakai `Coa::akunLegacyId()` dengan **fail-fast** (exception) bila pemetaan tidak ada.
- **Lokasi:** [DepositoService.php](../app/Services/DepositoService.php#L1459-L1474), [PengajuanPembiayaanIntegController.php](../app/Http/Controllers/PengajuanPembiayaanIntegController.php).

### F-09 — RENDAH — Alokasi bagi hasil tanpa largest-remainder
- **Status: ✅ SELESAI (Gelombang 3)** — alokasi porsi bagi hasil memakai metode **largest remainder**; sisa pembulatan Rp 1 dialokasikan penuh sehingga tidak ada dana yang hilang.
- **Lokasi:** [DepositoService.php](../app/Services/DepositoService.php#L1263-L1264) (`alokasiPorsiBagiHasil()`).
- **Dampak sebelum perbaikan:** Pembulatan dapat menyisakan selisih Rp 1 yang tidak dialokasikan.

---

## B. Temuan Keamanan

### K-01 — KRITIS — IDOR pada `TransaksiMobileController::setor/tarik`
- **Status: ✅ SELESAI (Gelombang 1 KRITIS)**
- **Lokasi:** [TransaksiMobileController.php](../app/Http/Controllers/TransaksiMobileController.php) — validasi hanya `'rekening_id' => 'required|exists:rekening,id'`, **tanpa cek kepemilikan** (`anggota.user_id === Auth::id()`).
- **Jenis:** Broken Access Control (OWASP A01).
- **Dampak:** Anggota terautentikasi dapat menyetor/menarik dari **rekening milik anggota lain** dengan menebak/mengubah `rekening_id` → kerugian dana langsung.
- **Perbaikan:** ditambahkan guard `rekeningMilikPengguna()` pada `setor()` & `tarik()`; `rekening_id` diambil dari objek hasil resolusi (bukan input mentah). Aturan: (1) `anggota.user_id === user->id`; (2) cadangan `strcasecmp` email untuk data lama yang `user_id`-nya kosong; (3) role operasional `teller|admin|manajer|pengurus` boleh melayani rekening mana pun. Rekening bukan milik pemanggil → HTTP 403, tidak ditemukan → 404.

### K-02 — KRITIS — IDOR + efek samping di jalur GET `TellerController::getPembiayaanAngsuran`
- **Status: ✅ SELESAI (Gelombang 1 KRITIS)**
- **Lokasi:** [TellerController.php](../app/Http/Controllers/TellerController.php#L469-L579) (L493-516 auto-generate jadwal `Angsuran::create` di dalam GET).
- **Jenis:** Broken Access Control + pelanggaran idempotensi HTTP.
- **Dampak:** (a) Query pembiayaan by `anggota_id` tanpa cek kepemilikan → kebocoran data pembiayaan anggota lain; (b) GET yang mengubah state (membuat jadwal angsuran) dapat dipicu berulang/oleh crawler → data ganda & tidak idempoten.
- **Perbaikan:** (a) Endpoint `getPembiayaanAngsuran` kini murni baca — mengembalikan penanda `perlu_generate_jadwal` (`true` bila jadwal kosong) tanpa `Angsuran::create`; (b) pembentukan jadwal dipindah ke endpoint POST baru `teller.angsuran.generate-jadwal` (`generateJadwalAngsuran()`, dibungkus `DB::transaction`, idempoten: tidak menambah baris bila jadwal sudah ada); (c) UI `teller/angsuran.blade.php` memicu POST sekali lalu memuat ulang data.

### K-03 — KRITIS — Mass assignment & mutasi saldo/status tanpa jurnal
- **Status: ✅ SELESAI (Gelombang 1 KRITIS)**
- **Lokasi:**
  - [User.php](../app/Models/User.php#L18-L24): `$fillable` termasuk `role`, `is_active`.
  - [Rekening.php](../app/Models/Rekening.php#L17-L34): termasuk `saldo`.
  - [Transaksi.php](../app/Models/Transaksi.php#L17-L35): termasuk `status_transaksi`, `status`, `approved_by`, `user_id`.
  - [Angsuran.php](../app/Models/Angsuran.php#L18-L35): termasuk `sisa_pokok`, `status_angsuran`, `denda`, `user_id`.
  - Tidak ada `$guarded` pada model mana pun.
  - **Jalur eksploitasi nyata:** [RekeningController.php](../app/Http/Controllers/RekeningController.php#L125) memvalidasi `'saldo' => 'nullable|numeric|min:0'` lalu L143 `$rekening->update($validated)`; [TransaksiController.php](../app/Http/Controllers/TransaksiController.php#L148) memvalidasi `status_transaksi` lalu L155 `$transaksi->update($validated)`.
- **Jenis:** Mass assignment / integritas keuangan.
- **Dampak:** Melalui rute `role:admin,manajer,pengurus,teller` ([routes/api.php](../routes/api.php#L63-L78)), saldo rekening dapat diubah langsung **tanpa jurnal & tanpa audit**; status transaksi dapat dimanipulasi. Melanggar prinsip *audit trail* **PSAK 101**.
- **Perbaikan:** (a) `saldo` **dihapus** dari validasi `RekeningController::update()` — saldo adalah turunan buku besar (PSAK 101), hanya berubah lewat transaksi berjurnal; (b) `status_transaksi` **dihapus** dari validasi `TransaksiController::update()` — hanya berubah lewat alur approval/void terkendali; (c) ditambahkan sabuk pengaman `unset()` atas kunci sensitif tersisa (`saldo`, `status_transaksi`, `status`, `approved_by`) agar tidak lolos bila `$validated` diperluas di kemudian hari. `saldo` tetap diterima pada `store()` sebagai **saldo awal rekening** (pembukaan rekening) — perilaku lama dipertahankan.

### T-01 — TINGGI — `TrustProxies::$proxies = '*'` hardcoded (komentar menyesatkan)
- **Lokasi:** [TrustProxies.php](../app/Http/Middleware/TrustProxies.php#L16).
- **Jenis:** Konfigurasi keamanan / spoofing.
- **Bukti:** Komentar menyatakan "diambil dari env `TRUSTED_PROXIES`", tetapi nilainya **hardcoded `'*'`**.
- **Dampak:** Bila aplikasi tidak di belakang LB terkelola, klien dapat memalsukan `X-Forwarded-For` → mem-bypass rate-limit/log IP/geo-restriction.

### T-02 — TINGGI — Upload PII (KTP/selfie) ke disk `public`
- **Lokasi:** [AOController.php](../app/Http/Controllers/AOController.php#L273) (ktp), [PengajuanPublikController.php](../app/Http/Controllers/PengajuanPublikController.php#L42-L43), [PengajuanRekeningController.php](../app/Http/Controllers/PengajuanRekeningController.php#L220-L223), [NasabahController.php](../app/Http/Controllers/NasabahController.php#L412), [PengajuanPembiayaanController.php](../app/Http/Controllers/PengajuanPembiayaanController.php#L198), [DpsController.php](../app/Http/Controllers/Dashboard/DpsController.php#L277), [AmlComplianceController.php](../app/Http/Controllers/Pengurus/AmlComplianceController.php#L96).
- **Jenis:** Perlindungan data pribadi.
- **Dampak:** Dokumen identitas dapat diakses via URL publik bila tahu path. Melanggar **UU 27/2022 (PDP)** Pasal 35 & **POJK 27/2022** (perlindungan data nasabah).

### T-03 — TINGGI — Sesi tidak terenkripsi & cookie `secure` null
- **Lokasi:** [config/session.php](../config/session.php#L11) (`'encrypt' => env('SESSION_ENCRYPT', false)`), L23 (`'secure' => env('SESSION_SECURE_COOKIE')` default null).
- **Jenis:** Manajemen sesi.
- **Dampak:** Payload sesi tersimpan plaintext; cookie bisa terkirim via HTTP → risiko session hijacking.

### T-04 — TINGGI — Token Sanctum tidak kedaluwarsa
- **Lokasi:** [config/sanctum.php](../config/sanctum.php#L12) (`'expiration' => null`).
- **Jenis:** Manajemen token.
- **Dampak:** Token API berlaku selamanya; bila bocor, akses tidak dapat dibatasi waktu.

### S-01 — SEDANG — `authenticateSessions()` tidak terpasang
- **Lokasi:** `bootstrap/app.php`.
- **Dampak:** Sesi tidak otomatis diinvalidasi saat password berubah.

### S-02 — SEDANG — `app/Http/Kernel.php` legacy (dead code)
- **Dampak:** Kebingungan konfigurasi middleware; risiko perubahan tidak berefek.

### S-03 — SEDANG — `paginate($request->per_page)` tanpa validasi (DoS)
- **Lokasi:** [RekeningController.php](../app/Http/Controllers/RekeningController.php), [AngsuranController.php](../app/Http/Controllers/AngsuranController.php#L51-L53), [TransaksiController.php](../app/Http/Controllers/TransaksiController.php).
- **Dampak:** `per_page` sangat besar → query berat / DoS.

### S-04 — SEDANG — CSP Report-Only + `unsafe-inline`; HSTS hardcoded di `.htaccess`
- **Dampak:** XSS tidak benar-benar diblokir; HSTS tidak fleksibel per-environment.

### S-05 — SEDANG — Filter tanggal Zakat tanpa `validate()`
- **Dampak:** Input tanggal tidak tervalidasi → error/inkonsistensi laporan.

### S-06 — SEDANG — `KeringanTazirController` legacy tanpa SoD (tidak ter-route)
- **Dampak:** Risiko residu bila rute diaktifkan kembali.

### R-01..R-04 — RENDAH
- `PiiHasher` fallback tanpa log; `{!! json_encode !!}` rapuh; alias middleware duplikat; race `count()+1` pada penomoran.

---

## C. Temuan Bug Fungsional & Dinamika Operasional

### BUG-01 — KRITIS — API mobile selalu mengembalikan HTTP 422
- **Status: ✅ SELESAI (Gelombang 1 KRITIS)**
- **Lokasi:** [TransaksiMobileController.php](../app/Http/Controllers/TransaksiMobileController.php#L40) & L82: `response()->json($result, $result['success'] ? 200 : 422)`.
- **Bukti:** Service mengembalikan kunci **`status`** (mis. `'SUCCESS'`/`'success'`/`'PENDING_APPROVAL'`), **bukan `success`**. `$result['success']` selalu `null` → selalu 422.
- **Dampak:** Aplikasi mobile menganggap **semua** setor/tarik gagal meski dana sudah diproses → risiko double-submit (user mengulang) & saldo tidak sinkron dengan UI.
- **Perbaikan:** ditambahkan helper `responsHasil()` yang membaca kunci **`status`** (case-insensitive) dan memetakannya ke HTTP: `SUCCESS` → 200 (`success=true`), `PENDING_APPROVAL` → 202, lainnya → 422. Kedua `return` di `setor()`/`tarik()` memakai helper ini.

### BUG-02 — TINGGI — Race condition auto-debit Simpanan Wajib
- **Lokasi:** [MandatorySavingsService.php](../app/Services/MandatorySavingsService.php#L486-L517).
- **Bukti:** Cek saldo L489 (`(float) $rekening->saldo < $nominal`) dilakukan **di luar** `DB::transaction` (L495), lalu L516-517 memakai `$rekening->saldo` in-memory tanpa `lockForUpdate`.
- **Dampak:** Dua proses paralel dapat men-debit saldo yang sama → **saldo minus / double-debit**.

### BUG-03 — TINGGI — `AngsuranController::update/destroy` memutasi keuangan tanpa jurnal
- **Lokasi:** [AngsuranController.php](../app/Http/Controllers/AngsuranController.php#L103-L141) (L103-131 `$angsuran->update($validated)` langsung; L133-141 `$angsuran->delete()` tanpa membalik `pembiayaan.sisa_pembiayaan`).
- **Jenis:** Integritas akuntansi.
- **Dampak:** Mengubah `sisa_pokok`/`status_angsuran`/`denda` atau menghapus angsuran tanpa jejak jurnal & tanpa penyesuaian sisa pembiayaan → laporan tidak konsisten (melanggar **PSAK 102** murabahah).

### BUG-04 — SEDANG — Casing status tidak konsisten (`'SUCCESS'` vs `'success'`)
- **Lokasi:** [TransactionLimitService.php](../app/Services/TransactionLimitService.php#L149-L153) (`'SUCCESS'`) vs L142 (`'success'`), L340/L411/L421 (`'success'`).
- **Dampak:** Pengecekan `if ($result['status'] !== 'success')` di pemanggil dapat salah menganggap sukses sebagai gagal.

### BUG-05 — SEDANG — RMW saldo tanpa `lockForUpdate`
- **Lokasi:** [ShuController.php](../app/Http/Controllers/Pengurus/ShuController.php#L642-L643) (dan L896-897).
- **Dampak:** Pembagian SHU paralel dapat menimpa saldo (lost update).

### BUG-06 — SEDANG — Perbandingan float `$selisih == 0`
- **Lokasi:** `TellerController::rekapStore()`.
- **Dampak:** Selisih pembulatan kecil dianggap tidak balance.

### BUG-07 — SEDANG — Kolom `transaksi.status` tidak konsisten (`'success'` vs `'Sukses'`)
- **Dampak:** Query/filter status bisa melewatkan data.

### BUG-08 — RENDAH — View teller tanpa `@can` (defense-in-depth)
- **Dampak:** Tidak ada lapisan otorisasi di view.

---

## D. Temuan Kepatuhan Regulasi OJK

### OJK-01 — KRITIS — Tidak ada pelaporan PPATK (LTKM/LTKT/STR)
- **Status: ✅ SELESAI (Gelombang 1 KRITIS)**
- **Lokasi:** [AmlComplianceController.php](../app/Http/Controllers/Pengurus/AmlComplianceController.php) — hanya verifikasi internal transaksi high-value (threshold `Setting::amlThreshold()`), tanpa ekspor/API ke PPATK, tanpa pembentukan STR.
- **Jenis:** Kepatuhan APU-PPT.
- **Dampak:** Melanggar **UU 8/2010** Pasal 23 & **POJK 27/2022** → sanksi administratif/pencabutan izin.
- **Perbaikan:**
  - **Service baru** [PpatkReportService.php](../app/Services/PpatkReportService.php) — menyusun tiga jenis laporan: **LTKT** (transaksi tunai ≥ ambang), **LTKM** (transaksi bertanda AML / bernilai besar), **STR** (rekap transaksi AML yang telah diverifikasi/ditolak), lengkap dengan kop identitas pelapor & konversi CSV siap unggah goAML.
  - **Endpoint ekspor** `GET /pengurus/laporan-aml/export/{jenis}` (`LTKT|LTKM|STR`) dengan filter periode `dari`/`sampai`, mengunduh berkas CSV bernama `PPATK-<JENIS>-<dari>_<sampai>.csv`, dan mencatat aksi ke `Log`.
  - **UI** halaman AML ditambah panel ekspor + tombol per jenis laporan.
  - **ZERO-HARDCODING:** ambang tunai (`ltkt_threshold`, default Rp 500 juta), kategori transaksi tunai (`ppatk_jenis_tunai`), dan identitas pelapor (`ppatk_nama_pelapor`, `ppatk_kode_pelapor`, `npwp_koperasi`) menjadi parameter kebijakan — dapat diubah pengurus tanpa mengubah kode. Kolom baru ditambahkan lewat migrasi [2026_10_02_000300](../database/migrations/2026_10_02_000300_add_ppatk_reporting_parameters_to_kebijakan_koperasis.php).

### OJK-02 — TINGGI — Jurnal manual tanpa maker-checker
- **Lokasi:** [JurnalController.php](../app/Http/Controllers/JurnalController.php) (store/update/post/void) tidak memakai `PemisahanTugas`.
- **Dampak:** Satu orang dapat membuat & memposting jurnal → melanggar prinsip GCG/POJK.

### OJK-03 — TINGGI — `audit_logs` tidak append-only & tanpa hash chain
- **Lokasi:** [AuditLog.php](../app/Models/AuditLog.php) vs [LaporanKeuanganLog.php](../app/Models/LaporanKeuanganLog.php#L111-L120) (yang sudah benar).
- **Dampak:** Log audit dapat diubah/dihapus tanpa terdeteksi → jejak audit tidak sah.

### OJK-04 — SEDANG — Tidak ada kebijakan retensi log
- **Dampak:** Melanggar **UU 27/2022 (PDP)** Pasal 16 & 35.

### OJK-05 — SEDANG — Klaim dokumen bertentangan dengan kode
- **Lokasi:** `docs/AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md` (klaim R-01/R-11) vs [DpsAuditController.php](../app/Http/Controllers/DpsAuditController.php#L86-L127) (DPS gating sudah ada).
- **Jenis:** Dokumentasi, bukan bug kode.

### OJK-06 — SEDANG — Peran `nasabah` tidak ada di `config/role_hierarki.php`
- **Lokasi:** `config/role_hierarki.php` (hanya 7 peran).
- **Dampak:** Ketidaksesuaian dokumentasi vs implementasi otorisasi.

### OJK-07 — RENDAH — `maks_transaksi_per_hari` & `maks_baris_ekspor` default `null`
- **Dampak:** Tidak ada batas default → risiko ekspor/transaksi tak terbatas.

### OJK-08 — SEDANG — Tidak ada CDD/PEP/pemilik manfaat (APU-PPT onboarding)
- **Dampak:** Melanggar **POJK 27/2022** (identifikasi nasabah & beneficial owner).

### OJK-09 — SEDANG — `is_active` tidak ditegakkan saat autentikasi
- **Dampak:** Akun nonaktif tetap dapat login.

---

## E. Matriks Kesesuaian PSAK

| PSAK | Aspek | Status | Catatan |
|---|---|---|---|
| 101 | Penyajian laporan keuangan syariah | ⚠️ Sebagian | F-01 ✅; sisa F-03 (void tanpa pembalik), F-04 (cut-off) |
| 102 | Murabahah | ⚠️ Sebagian | BUG-03 (mutasi angsuran tanpa jurnal) |
| 105 | Mudharabah | ✅ | Bagi hasil deposito terpisah & proporsional |
| 106 | Musyarakah | ✅ | Pembiayaan & jurnal terpisah |
| 107 | Ijarah | ✅ | Ujroh tercatat terpisah |
| 108 | Transaksi syariah | ⚠️ Sebagian | F-02 (toleransi balance tidak konsisten) |
| 109 | Zakat/infak/sedekah | ✅ | Akun terpisah, 8 asnaf, uji lengkap |
| 71/413/414 | CKPN | ✅ | Akun kontra & verifikasi ganda teruji |

## F. Matriks Kesesuaian OJK

| Regulasi | Aspek | Status | Catatan |
|---|---|---|---|
| UU 8/2010 & POJK 27/2022 | APU-PPT (pelaporan PPATK) | ✅ | OJK-01 selesai — ekspor LTKT/LTKM/STR (ambang & kategori tunai dinamis) |
| POJK 27/2022 | CDD/PEP/beneficial owner | ❌ | OJK-08 |
| POJK 27/2022 | Perlindungan data nasabah | ⚠️ | T-02 (PII di disk publik) |
| POJK 24/2024 | Kualitas aset/CKPN | ✅ | CKPN & verifikasi ganda teruji |
| POJK 12/2023 | Penyelenggaraan koperasi | ⚠️ | OJK-02, OJK-03 (GCG/audit trail) |
| UU 27/2022 (PDP) | Retensi & keamanan data | ⚠️ | OJK-04, T-02, T-03 |
| GCG/maker-checker | Pemisahan tugas | ⚠️ | OJK-02, F-03 (modul jurnal) |

---

## G. Prioritas Perbaikan (Roadmap)

**Gelombang 1 — KRITIS (kerugian dana/regulasi):**
1. ✅ K-01 & K-02 — Cek kepemilikan endpoint mobile & teller; auto-generate jadwal dipindah ke jalur POST (GET idempoten).
2. ✅ BUG-01 — Pembacaan kunci `status` (bukan `success`) di `TransaksiMobileController`.
3. ✅ K-03 — Larang `saldo` & `status_transaksi` via update API (guard di batas request; `$fillable` internal dipertahankan agar mutasi berjurnal tetap berfungsi).
4. ✅ F-01 — `coa_id` diwajibkan pada `JurnalDetailController` (terverifikasi: baris tak terpetakan ditolak 422; `akun_id` legacy hanya masukan).
5. ✅ OJK-01 — Modul ekspor/pelaporan PPATK (LTKT/LTKM/STR) + parameter dinamis.

**Gelombang 2 — TINGGI:**
6. ✅ F-02, F-03, F-04, BUG-02, BUG-03, T-01..T-04, OJK-02, OJK-03.

**Gelombang 3 — SEDANG/RENDAH:**
7. ✅ S-01..S-06, BUG-04..BUG-08, F-05..F-09, OJK-04..OJK-09, R-01..R-04. (Lihat §G.2 — SELESAI.)

---

## G.1 Status Pelaksanaan Gelombang 2 (TINGGI) — SELESAI

Seluruh butir Gelombang 2 telah diimplementasikan, didokumentasikan, dan diverifikasi. Baseline regresi **hijau: 1263 passed (5521 assertions)**.

| Butir | Status | Inti perbaikan & berkas kunci |
|---|---|---|
| F-02 | ✅ (temuan usang) | Toleransi keseimbangan jurnal sudah terpusat (bukan `0.01` tersebar) — tidak ada perubahan lanjutan diperlukan. |
| F-03 | ✅ | `JurnalController::void()` membuat **jurnal pembalik** berpasangan, wajib alasan, dibungkus `DB::transaction`, dan menegakkan pemisahan tugas. |
| F-04 | ✅ | Gerbang cut-off periode akuntansi dipindah ke **observer model `Jurnal` (`creating`)** sehingga semua jalur tulis (controller, service, seeder) tunduk aturan yang sama; `AutoJournalingService` tetap memanggil pemeriksaan eksplisit. |
| BUG-02 | ✅ | Auto-debit Simpanan Wajib: pembacaan saldo & mutasi kini di dalam transaksi dengan `lockForUpdate()` — menghilangkan race condition (double-debit / saldo minus). |
| BUG-03 | ✅ | `AngsuranController::update()` & `destroy()` menolak mutasi kolom keuangan pada angsuran yang sudah terhubung transaksi/jurnal; pembatalan harus lewat void transaksi (ReversalService) agar sisa pembiayaan & jurnal ikut terbalik. |
| T-01 | ✅ | `TrustProxies` membaca `TRUSTED_PROXIES` dari env — tidak lagi hardcode `'*'`. |
| T-02 | ✅ | Upload PII (KTP/selfie) dipindah ke **disk privat** + rute unduh terproteksi (otorisasi peran) + view diperbarui. |
| T-03 | ✅ | Sesi dienkripsi & cookie `secure` aktif secara bawaan. |
| T-04 | ✅ | Masa berlaku token Sanctum ditetapkan (`expiration`). |
| OJK-02 | ✅ | Jurnal manual: **maker-checker** (`PemisahanTugas`) pada store/update/post — pembuat tidak boleh memposting jurnalnya sendiri. |
| OJK-03 | ✅ | `audit_logs` menjadi **append-only** dengan **rantai hash SHA-256** (`hash`, `hash_sebelumnya`, `hash_sebelumnya_id`), model menolak `update`/`delete`, dan `verifyChain()` mendeteksi penyuntingan/penghapusan baris. |

**Perbaikan akar masalah ikutan (ditemukan saat verifikasi regresi Gelombang 2):**

1. **Seeder kebijakan singleton** — tabel `kebijakan_koperasis` (satu baris, banyak kolom) sebelumnya tidak pernah terisi saat seeding sehingga 18 test bergantung pada default dinamis gagal. Ditambahkan `Database\Seeders\KebijakanKoperasiSeeder` (sumber nilai = `Setting::DEFAULTS`, idempoten, difilter ke kolom nyata) dan didaftarkan **pertama** di `DatabaseSeeder` karena seeder lain membaca nominal dinamis dari sini.
2. **Isolasi test** — `PengerasanKonfigurasiDanDokumenTest` beralih dari `RefreshDatabase` ke `DatabaseTransactions` (konsisten dengan 70 kelas lain); `RefreshDatabase` di tengah suite menghapus DB bersama dan merusak seluruh test sesudahnya.
3. **Generator nomor anggota** (`CifController`) — sufiks non-numerik (mis. `3E15`) diurai PHP sebagai notasi ilmiah (`3.0E+15`) sehingga nomor melampaui panjang kolom (`Data too long`). Kini hanya digit di depan sufiks yang dihitung.
4. **Test rantai audit** (`AuditLogAppendOnlyTest`) dibuat tahan terhadap baris audit pra-seed: ujung rantai diambil via `AuditLog::ujungRantai()` alih-alih mengasumsikan tabel kosong.

---

## G.2 Status Pelaksanaan Gelombang 3 (SEDANG/RENDAH) — SELESAI

Seluruh **30 butir Gelombang 3** telah diimplementasikan, didokumentasikan, dan diverifikasi.
Baseline regresi **hijau: 1280 passed (5585 assertions)** (naik dari Gelombang 2: `1263 passed`).
Laporan stakeholder: `docs/LAPORAN-GELOMBANG-3-STAKEHOLDER.md`.

| Butir | Status | Inti perbaikan & berkas kunci |
|---|---|---|
| S-01 | ✅ | `$middleware->authenticateSessions()` aktif (`bootstrap/app.php`) — sesi diinvalidasi saat password berubah. |
| S-02 | ✅ | `app/Http/Kernel.php` legacy dihapus; middleware terpusat di `bootstrap/app.php`. |
| S-03 | ✅ | Helper `Controller::perPage()` memvalidasi `per_page` (`integer`, `min:1`, `max`) pada `AngsuranController`, `RekeningController`, `TransaksiController` — menutup DoS. |
| S-04 | ✅ | Header keamanan (CSP/HSTS) dipindah dari `.htaccess` ke middleware `KeamananHeader` + `config/keamanan.php`; CSP di-**enforce** di produksi (Report-Only hanya local/testing), HSTS per-environment, mode ketat (nonce) tersedia. |
| S-05 | ✅ | `ZakatController` memvalidasi filter `dari`/`sampai` (`date`, `after_or_equal`). |
| S-06 | ✅ | `KeringanTazirController::approve()` & `reject()` menegakkan `PemisahanTugas` (maker ≠ checker). |
| BUG-04 | ✅ | Casing status diseragamkan di `TransactionLimitService` (menghapus `'SUCCESS'` yang salah klasifikasi). |
| BUG-05 | ✅ | `ShuController` & `ShuCalculatorService` memakai `lockForUpdate()` pada RMW saldo — mencegah *lost update*. |
| BUG-06 | ✅ | `TellerController::rekapStore()` memakai toleransi `abs($selisih) < 0.005`. |
| BUG-07 | ✅ | Kamus status terpusat di model `Transaksi` (`STATUS_TRANSAKSI_SUKSES='Sukses'`, `STATUS_TEKNIS_SUCCESS='success'`) + helper. |
| BUG-08 | ✅ | `TellerPolicy` + `@can` pada view teller (defense-in-depth). |
| F-05 | ✅ | Urutan nomor (`JRN`/`DEP`/`TRX`/`PMB`) dari `MAX(CAST(SUBSTRING(...) AS UNSIGNED))` + kandidat bebas; format tetap. |
| F-06 | ✅ | `Coa::firstOrCreate` diganti `App\Support\Coa::terdaftar()` + persetujuan pengurus (modul SHU). |
| F-07 | ✅ | Batas bulan & prorate mengikuti `TahunBukuService` (`jumlahBulan()`/`bulanKe()`/`bulanAntara()`). |
| F-08 | ✅ | Fallback `Akun::query()->value('id')` dihapus → `Coa::akunLegacyId()` fail-fast. |
| F-09 | ✅ | `DepositoService::alokasiPorsiBagiHasil()` memakai largest-remainder. |
| OJK-04 | ✅ | Kebijakan retensi (`config/keamanan.php`) + command `log:purge-kedaluwarsa` (`PurgeLogKedaluwarsa`); diuji `PurgeLogKedaluwarsaTest`. |
| OJK-05 | ✅ | Klarifikasi dokumen DPS vs kode (`AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md` L207–209). |
| OJK-06 | ✅ | Peran `nasabah` ditambahkan ke `config/role_hierarki.php` (non-anggota, POJK 76/2016). |
| OJK-07 | ✅ | Batas default non-null (`maks_transaksi_per_hari`, `maks_baris_ekspor`) per peran. |
| OJK-08 | ✅ | CDD/PEP/pemilik manfaat + `CddService::terapkan()` di-wire ke `CifController`, `AOController`, `PengajuanRekeningController`; migrasi `2026_10_05_000100_*`; diuji `CddPepOnboardingTest` (10 test). |
| OJK-09 | ✅ | `WebAuthController` menolak login & logout paksa bila `is_active` false. |
| R-01 | ✅ | `PiiHasher` mencatat `Log::warning` saat jalur fallback terpakai. |
| R-02 | ✅ | `{!! json_encode(..., JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT) !!}` pada `manajer/closing/index.blade.php`. |
| R-03 | ✅ | Alias middleware duplikat dibersihkan & terpusat di `bootstrap/app.php`. |
| R-04 | ✅ | Penomoran dokumen berbasis `MAX` sufiks numerik (bukan `count()+1`) di seluruh controller terkait. |

**Perbaikan akar masalah ikutan (ditemukan saat verifikasi regresi Gelombang 3):**

1. **Regresi `authenticateSessions()` (S-01)** — middleware baru menolak permintaan saat hash password di sesi ≠ hash user aktif; `actingAs()` bawaan tidak memperbarui sesi → 69 kegagalan. Diperbaiki via override `actingAs()` di `tests/TestCase.php` (menulis `password_hash_web` + `migrate(true)`); menghilangkan 59 dari 69 kegagalan.
2. **Import kelas `Coa` salah (F-06)** — `App\Models\Coa` dipakai padahal `terdaftar()` milik `App\Support\Coa`; diperbaiki di `ShuCalculatorService` & `ShuController` (5 kegagalan ShuHold).
3. **Menu navigasi per peran** — `getMenu('admin')` kosong (tak ada cabang `match()`) & `menuPengurus()` menautkan rute ber-guard `role:dps`; diperbaiki dengan `menuAdmin()` baru + penghapusan entri `dps.zakat.*`.
4. **JSON modal closing (R-02)** — `Js::from()` menghasilkan ekspresi JS, bukan JSON; diganti `json_encode(..., JSON_HEX_*)`.

**Hasil akhir:** 69 kegagalan regresi → **0**; regresi penuh **HIJAU `1280 passed (5585 assertions)`**.

---

## H. Catatan Metodologi & Verifikasi

- Seluruh temuan KRITIS/TINGGI di atas **diverifikasi manual** dengan membaca kode sumber langsung (bukan hanya hasil pencarian otomatis).
- Baseline uji regresi pasca-audit (Gelombang 1): **1245 passed (5591 assertions)**.
- Baseline uji regresi pasca-Gelombang 2: **1263 passed (5521 assertions)** — seluruh suite hijau, tanpa regresi.
- Baseline uji regresi pasca-Gelombang 3: **1280 passed (5585 assertions)** — seluruh suite hijau, tanpa regresi (seluruh 42 temuan audit tertutup).
- Audit ini bersifat *point-in-time*; temuan bersifat rekomendasi dan memerlukan keputusan pemilik sistem sebelum perbaikan.

### H.1 Catatan Perbaikan (Gelombang Fleksibilitas — PSAK/OJK)

Perbaikan di bawah berprinsip **zero-hardcoding**: titik kaku diganti resolusi dinamis dari infrastruktur yang sudah ada (`Setting`, `CoaResolver`, `TahunBukuService`, registri `Coa`, `PemetaanAkadCoa`, `JenisSimpanan`) sehingga pengurus dapat menyesuaikan tanpa mengubah kode, sementara kepatuhan PSAK/OJK terjaga (nilai bawaan = perilaku lama).

| Temuan | Status | Inti perbaikan |
|---|---|---|
| F-05 | ✅ (tabrakan) | Urutan nomor (`JRN`/`DEP`/`TRX`/`PMB`) dari nilai numerik terbesar + pencarian kandidat bebas (anti-duplikat); **format tetap** agar kontrak API/test tidak berubah |
| F-07 | ✅ | Batas bulan & prorate mengikuti `TahunBukuService` (`jumlahBulan()`, `bulanKe()`, `bulanAntara()`) — mendukung tahun buku non-kalender |
| F-08 | ✅ | Fallback `Akun::query()->value('id')` dihapus → `Coa::akunLegacyId()` **fail-fast** |
| F-06 | ✅ | `Coa::firstOrCreate` diganti resolusi dinamis `App\Support\Coa::terdaftar()` + persetujuan pengurus (modul SHU) — tidak ada akun CoA tercipta otomatis |
| F-09 | ✅ | Largest-remainder alokasi bagi hasil (`DepositoService::alokasiPorsiBagiHasil()`) — sisa pembulatan Rp 1 teralokasi penuh |

Titik kaku lain yang turut dibersihkan: konstanta akun DIT & piutang di `LaporanKeuanganSyariahService` → resolver dinamis (`akunDit()`, `akunPiutang()` via `CoaResolver`); 5 parameter COA baru (`account_dana_*_id`, `account_titipan_selisih_id`) ditambahkan ke `Setting` dengan nilai bawaan `null` (tidak mengubah perilaku).
