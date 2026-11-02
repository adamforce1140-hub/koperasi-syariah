# Laporan Akhir Gelombang 3 (SEDANG/RENDAH) — Presentasi Stakeholder

**Sistem:** Koperasi Syariah — Modul Akuntansi, Simpanan, Pembiayaan & Kepatuhan PSAK/OJK
**Periode:** Gelombang 3 (Sedang/Rendah) — gelombang terakhir dari roadmap audit
**Status:** ✅ **SELESAI — SIAP UMPAN BALIK SEBELUM PELUNCURAN**
**Tanggal laporan:** 5 Oktober 2026
**Rujukan teknis:** `docs/AUDIT-MENYELURUH-PSAK-OJK.md` (bagian G, G.2, H)
**Prasyarat selesai:** Gelombang 1 (KRITIS) & Gelombang 2 (TINGGI) — ✅ selesai & terverifikasi.

---

## 1. Ringkasan Eksekutif

Gelombang 3 adalah **gelombang penutup** dari roadmap perbaikan hasil audit menyeluruh PSAK/OJK.
Seluruh **30 butir SEDANG/RENDAH** telah **diimplementasikan, didokumentasikan, dan diverifikasi**:

- **Keamanan aplikasi (S-01..S-06)** — 6 butir
- **Bug fungsional & integritas data (BUG-04..BUG-08)** — 5 butir
- **Akuntansi & PSAK (F-05..F-09)** — 5 butir
- **Kepatuhan OJK (OJK-04..OJK-09)** — 6 butir
- **Pengerasan & kerapian (R-01..R-04)** — 4 butir
- **Perbaikan ikutan (regresi)** — 4 akar masalah tambahan

**Hasil regresi: `1280 passed (5585 assertions)` — seluruh suite HIJAU tanpa regresi.**
(Naik dari baseline Gelombang 2: `1263 passed` — +17 test, termasuk 10 test baru OJK-08.)

Dengan selesainya gelombang ini, **seluruh 42 temuan audit (6 KRITIS + 11 TINGGI + 17 SEDANG + 8 RENDAH)
telah tertutup**, dan sistem memenuhi standar kualitas & spesifikasi yang ditetapkan pada audit awal.

---

## 2. Tinjauan Gelombang 1 & 2 (Prasyarat)

| Gelombang | Fokus | Hasil | Baseline uji |
|---|---|---|---|
| **Gelombang 1** | KRITIS — kerugian dana/regulasi (K-01..K-03, BUG-01, F-01, OJK-01) | ✅ Selesai | `1245 passed` |
| **Gelombang 2** | TINGGI — GCG/audit trail, integritas keuangan, hardening (F-02..F-04, BUG-02..BUG-03, T-01..T-04, OJK-02..OJK-03) | ✅ Selesai | `1263 passed` |
| **Gelombang 3** | SEDANG/RENDAH — 30 butir penutup | ✅ **Selesai** | **`1280 passed`** |

**Kekurangan yang mendasari Gelombang 3:** sesi tidak diinvalidasi saat password berubah, *dead code*
Kernel legacy, paginasi tanpa batas (DoS), CSP Report-Only + HSTS hardcoded, filter Zakat tanpa
validasi, SoD belum merata, inkonsistensi casing status, race pada RMW saldo, perbandingan float,
penomoran berbasis `count()+1`, ketergantungan akun CoA otomatis, batas tahun buku hardcode,
kebijakan retensi log & CDD/PEP/pemilik manfaat yang belum ada.

---

## 3. Hasil Gelombang 3 — 30 Butir SEDANG/RENDAH

### 3.1 Keamanan Aplikasi (S-01..S-06)

| Butir | Status | Hasil & Bukti |
|---|---|---|
| **S-01** `authenticateSessions()` tidak terpasang | ✅ | `$middleware->authenticateSessions()` aktif di [bootstrap/app.php](../bootstrap/app.php#L31-L34) — sesi lama otomatis diinvalidasi saat password berubah. |
| **S-02** `app/Http/Kernel.php` legacy (dead code) | ✅ | File legacy dihapus; konfigurasi middleware terpusat di `bootstrap/app.php` (Laravel 12 style). |
| **S-03** `paginate($request->per_page)` tanpa validasi (DoS) | ✅ | Helper `Controller::perPage()` memvalidasi `['integer','min:1','max:'.$maks]` — dipakai di `AngsuranController`, `RekeningController`, `TransaksiController`. |
| **S-04** CSP Report-Only + `unsafe-inline`; HSTS hardcoded di `.htaccess` | ✅ | Header keamanan dipindah ke middleware [KeamananHeader](../app/Http/Middleware/KeamananHeader.php) + [config/keamanan.php](../config/keamanan.php#L31-L57). CSP **di-enforce di produksi** (Report-Only hanya di local/testing), mode ketat (nonce) tersedia, HSTS per-environment. |
| **S-05** Filter tanggal Zakat tanpa `validate()` | ✅ | [ZakatController](../app/Http/Controllers/ZakatController.php#L30-L33) memvalidasi `dari`/`sampai` (`date`, `after_or_equal`). |
| **S-06** `KeringanTazirController` legacy tanpa SoD | ✅ | `PemisahanTugas::dilanggar()` ditegakkan pada `approve()` & `reject()` — pengaju tidak boleh memutus pengajuannya sendiri (maker ≠ checker). |

### 3.2 Bug Fungsional & Integritas Data (BUG-04..BUG-08)

| Butir | Status | Hasil & Bukti |
|---|---|---|
| **BUG-04** Casing status tidak konsisten | ✅ | [TransactionLimitService](../app/Services/TransactionLimitService.php) — seluruh literal status diseragamkan (`'success'`), menghilangkan `'SUCCESS'` yang menyebabkan salah klasifikasi sukses/gagal. |
| **BUG-05** RMW saldo tanpa `lockForUpdate` | ✅ | [ShuController](../app/Http/Controllers/Pengurus/ShuController.php#L628) & `ShuCalculatorService` memakai `lockForUpdate()` — mencegah *lost update* pada pembagian SHU paralel. |
| **BUG-06** Perbandingan float `$selisih == 0` | ✅ | [TellerController::rekapStore()](../app/Http/Controllers/TellerController.php#L1749-L1754) memakai toleransi `abs($selisih) < 0.005` — selisih pembulatan kecil tidak lagi salah dianggap tidak balance. |
| **BUG-07** Kolom `transaksi.status` tidak konsisten | ✅ | [Transaksi](../app/Models/Transaksi.php#L19-L50) memusatkan kamus status (`STATUS_TRANSAKSI_SUKSES='Sukses'`, `STATUS_TEKNIS_SUCCESS='success'`) + helper `sukses()`/`menungguOtorisasi()` — query/filter status seragam. |
| **BUG-08** View teller tanpa `@can` (defense-in-depth) | ✅ | [TellerPolicy](../app/Policies/TellerPolicy.php) terdaftar di `AuthServiceProvider`; `@can` diterapkan pada view teller (`deposito-show`, `kas/closing`, `simpanan-wajib`). |

### 3.3 Akuntansi & PSAK (F-05..F-09)

| Butir | Status | Hasil & Bukti |
|---|---|---|
| **F-05** Penomoran `date('Ymd')` (aspek tabrakan) | ✅ | Urutan diambil dari **nilai numerik terbesar** (`MAX(CAST(SUBSTRING(...) AS UNSIGNED))`) + pencarian kandidat bebas di `AutoJournalingService`, `DepositoService`, `PengajuanPembiayaan`. Format tetap (aman bagi kontrak API/test). |
| **F-06** `Coa::firstOrCreate` membuat akun otomatis | ✅ | Diganti resolusi dinamis `App\Support\Coa::terdaftar()` + persetujuan pengurus di `ShuCalculatorService` & `ShuController` — tidak ada lagi akun CoA yang tercipta otomatis. |
| **F-07** Asumsi tahun buku Januari hardcode (`12`) | ✅ | Batas bulan & prorate mengikuti `TahunBukuService::jumlahBulan()`/`bulanKe()`/`bulanAntara()` — mendukung tahun buku non-kalender & tahun buku pendek. |
| **F-08** Fallback akun `Akun::query()->value('id')` | ✅ | Fallback akun sembarang dihapus → `Coa::akunLegacyId()` **fail-fast** (exception) bila pemetaan tidak ada. |
| **F-09** Alokasi bagi hasil tanpa largest-remainder | ✅ | `DepositoService::alokasiPorsiBagiHasil()` memakai metode **largest remainder** — sisa pembulatan Rp 1 teralokasi penuh (tidak ada dana hilang). |

### 3.4 Kepatuhan Regulasi OJK (OJK-04..OJK-09)

| Butir | Status | Hasil & Bukti |
|---|---|---|
| **OJK-04** Tidak ada kebijakan retensi log | ✅ | Kebijakan retensi di [config/keamanan.php](../config/keamanan.php#L67-L100) (audit 1095 hari, PII 365 hari, `0` = tanpa batas) + command `log:purge-kedaluwarsa` ([PurgeLogKedaluwarsa](../app/Console/Commands/PurgeLogKedaluwarsa.php)). Diuji `PurgeLogKedaluwarsaTest`. |
| **OJK-05** Klaim dokumen bertentangan dengan kode | ✅ | Klarifikasi pada [AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md](../docs/AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md#L207-L209) — DPS *gating* pada akad sudah berjalan; temuan R-01/R-11 dikoreksi. |
| **OJK-06** Peran `nasabah` tidak ada di `role_hierarki.php` | ✅ | Peran `nasabah` ditambahkan ([role_hierarki.php](../config/role_hierarki.php#L95-L109)) sebagai peran non-anggota (calon anggota), selaras POJK 76/2016. |
| **OJK-07** `maks_transaksi_per_hari` & `maks_baris_ekspor` default `null` | ✅ | Batas default non-null ditetapkan per peran pada `role_hierarki.php` (frekuensi & akses_data) — tidak ada lagi ekspor/transaksi tak terbatas. |
| **OJK-08** Tidak ada CDD/PEP/pemilik manfaat | ✅ | Field CDD/PEP/pemilik manfaat + `CddService::terapkan()` di-wire ke `CifController`, `AOController`, `PengajuanRekeningController`; migrasi [2026_10_05_000100](../database/migrations/2026_10_05_000100_add_cdd_pep_pemilik_manfaat_to_anggota_pengajuan.php). Diuji `CddPepOnboardingTest` (10 test). |
| **OJK-09** `is_active` tidak ditegakkan saat autentikasi | ✅ | [WebAuthController](../app/Http/Controllers/WebAuthController.php#L34-L51) memaksa logout & menolak login bila `is_active` false — akun nonaktif tidak dapat masuk. |

### 3.5 Pengerasan & Kerapian (R-01..R-04)

| Butir | Status | Hasil & Bukti |
|---|---|---|
| **R-01** `PiiHasher` fallback tanpa log | ✅ | [PiiHasher](../app/Support/PiiHasher.php#L104-L127) mencatat `Log::warning` saat jalur fallback terpakai (sekali per proses) — fallback tidak lagi senyap. |
| **R-02** `{!! json_encode !!}` rapuh | ✅ | Data modal ditulis dengan flag `JSON_HEX_TAG\|JSON_HEX_AMP\|JSON_HEX_APOS\|JSON_HEX_QUOT` ([manajer/closing/index.blade.php](../resources/views/manajer/closing/index.blade.php#L168-L201)) — catatan pengguna tidak dapat memutus blok `<script>`. |
| **R-03** Alias middleware duplikat | ✅ | Alias middleware dibersihkan & terpusat di [bootstrap/app.php](../bootstrap/app.php#L24-L30) (`role`, `laporan.akses`, `anggota.aktif`). |
| **R-04** Race `count()+1` pada penomoran | ✅ | Seluruh penomoran dokumen berbasis `MAX` sufiks numerik (bukan `count()+1`) di `AOController`, `PengajuanPembiayaanController`, `PengajuanRekeningController`, `Anggota`, `RestrukturisasiService`, `IkhtisarShuService`, `PengajuanPembiayaanIntegController`. |

---

## 4. Perbaikan Akar Masalah Ikutan (ditemukan saat verifikasi regresi)

Selama pengujian menyeluruh Gelombang 3, ditemukan & diperbaiki 4 akar masalah yang mencegah regresi:

1. **Regresi `authenticateSessions()` (S-01) pada suite uji** — middleware baru menolak permintaan
   ketika hash password di sesi ≠ hash user aktif; `actingAs()` bawaan Laravel tidak memperbarui sesi,
   sehingga panggilan berulang (loop per-peran) memicu redirect `/login`. Diperbaiki dengan override
   `actingAs()` di [tests/TestCase.php](../tests/TestCase.php) (menulis `password_hash_web` +
   `migrate(true)`). **Menghilangkan 59 dari 69 kegagalan.**
2. **Import kelas `Coa` yang salah (F-06)** — `App\Models\Coa` dipakai padahal `terdaftar()` milik
   `App\Support\Coa`, menyebabkan fatal error pada modul SHU. Diperbaiki di `ShuCalculatorService`
   & `ShuController` → **5 kegagalan ShuHold hilang.**
3. **Menu navigasi per peran (S-01/S-06 ikutan)** — `getMenu('admin')` kosong (tak ada cabang
   `match()`) & `menuPengurus()` menautkan rute ber-guard `role:dps` (pelanggaran wewenang).
   Diperbaiki: cabang `menuAdmin()` baru + penghapusan 2 entri `dps.zakat.*`.
4. **JSON modal pada halaman closing (R-02)** — `Js::from()` menghasilkan ekspresi JS
   (`JSON.parse('…')`, slash ter-double-escape) padahal runtime & test membutuhkan JSON mentah
   di `<script type="application/json">`. Diganti `json_encode(..., JSON_HEX_*)`.

**Hasil: 69 kegagalan regresi → 0.** Regresi penuh kembali **HIJAU** dan naik dari baseline.

---

## 5. Bukti Pengujian (Pengujian Menyeluruh)

| Jenis | Cakupan | Hasil |
|---|---|---|
| **Regresi penuh** | Seluruh suite | **`1280 passed (5585 assertions)`** — HIJAU |
| **Kepatuhan OJK (baru)** | `CddPepOnboardingTest` (OJK-08) | ✅ 10 passed |
| **Retensi log** | `PurgeLogKedaluwarsaTest` (OJK-04) | ✅ Hijau |
| **Navigasi & wewenang** | `NavigasiAdminMenuTest`, `NavigasiWewenangPeranTest` | ✅ 12 passed (19 assertions) |
| **Persetujuan closing** | `ManajerClosingApprovalMenuTest` | ✅ 17 passed (109 assertions) |
| **SHU & CoA** | `ShuHoldNotifikasiTest`, `ShuModalDetailTest`, `ShuProratedMasaKeanggotaanTest` | ✅ Hijau |
| **Keamanan header & PII** | `KeamananHeaderTest`, `KeamananPiiNikTest`, `AuditPerubahanPiiTest` | ✅ Hijau |
| **Zakat PSAK 109** | `ZakatPsak109Test` | ✅ 15 passed |
| **Anti-regresi** | Gelombang 1 `1245` → Gelombang 2 `1263` → Gelombang 3 **`1280 passed`** | ✅ Naik, tanpa regresi |

> Catatan operasional: `php artisan optimize:clear` dijalankan sebelum `php artisan test`.
> Uji berjalan pada DB dev ter-seed (tidak ada `.env.testing` terpisah). Durasi ±180 detik.

---

## 6. Status Matriks Kesesuaian (pasca-Gelombang 3)

### 6.1 PSAK

| PSAK | Aspek | Status | Catatan |
|---|---|---|---|
| 101 | Penyajian laporan keuangan syariah | ✅ | F-01, F-03, F-04, F-05, F-06 tuntas |
| 102 | Murabahah | ✅ | BUG-03, F-07 tuntas |
| 105 | Mudharabah | ✅ | Bagi hasil deposito proporsional + F-09 largest-remainder |
| 106 | Musyarakah | ✅ | Pembiayaan & jurnal terpisah |
| 107 | Ijarah | ✅ | Ujroh tercatat terpisah |
| 108 | Transaksi syariah | ✅ | F-02, F-04, F-05 tuntas |
| 109 | Zakat/infak/sedekah | ✅ | Akun terpisah, 8 asnaf, filter tervalidasi (S-05) |
| 71/413/414 | CKPN | ✅ | Akun kontra & verifikasi ganda teruji |

### 6.2 OJK / Regulasi

| Regulasi | Aspek | Status | Catatan |
|---|---|---|---|
| UU 8/2010 & POJK 27/2022 | APU-PPT (pelaporan PPATK) | ✅ | OJK-01 tuntas (LTKT/LTKM/STR dinamis) |
| POJK 27/2022 | CDD/PEP/beneficial owner | ✅ | OJK-08 tuntas (CDD, PEP, pemilik manfaat) |
| POJK 27/2022 | Perlindungan data nasabah | ✅ | T-02, T-03 tuntas |
| POJK 24/2024 | Kualitas aset/CKPN | ✅ | CKPN & verifikasi ganda teruji |
| POJK 12/2023 | Penyelenggaraan koperasi | ✅ | OJK-02, OJK-03 tuntas |
| UU 27/2022 (PDP) | Retensi & keamanan data | ✅ | OJK-04 (retensi + purge), T-02, T-03 tuntas |
| GCG/maker-checker | Pemisahan tugas | ✅ | OJK-02, F-03, S-06 tuntas |
| POJK 76/2016 | Pembedaan anggota vs nasabah | ✅ | OJK-06 tuntas |

---

## 7. Rekomendasi untuk Kelancaran Peluncuran Gelombang 3

1. **Produksi — aktifkan CSP enforce & HSTS.** Pastikan `KEAMANAN_CSP_ENFORCE=true` dan
   `KEAMANAN_HSTS_AKTIF=true` di `.env` produksi (default sudah enforce di luar local/testing;
   verifikasi eksplisit sebelum go-live). Pertimbangkan `KEAMANAN_CSP_STRICT=true` setelah seluruh
   `<script>`/`<style>` inline diberi `nonce="{{ csp_nonce() }}"`.
2. **Produksi — jadwalkan `log:purge-kedaluwarsa`.** Daftarkan command pada scheduler (mis. bulanan)
   agar retensi OJK-04 berjalan otomatis; sesuaikan `KEAMANAN_RETENSI_*` dengan kebijakan internal
   (default: audit 3 tahun, PII 1 tahun).
3. **Validasi ambang OJK-07 & parameter PPATK.** Konfirmasi `maks_transaksi_per_hari`,
   `maks_baris_ekspor`, serta ambang LTKT (`ltkt_threshold`) & identitas pelapor PPATK terhadap
   kebijakan koperasi sebelum pelaporan goAML pertama.
4. **Uji terima pengguna (UAT).** Libatkan teller, AO, manajer, pengurus, dan DPS pada skenario
   end-to-end: onboarding CDD/PEP, keringanan ta'zir (alur teller→AO→manajer), closing kas,
   distribusi bagi hasil, serta ekspor PPATK.
5. **Backup & rollback plan.** Siapkan snapshot DB sebelum migrasi produksi
   (`2026_10_05_000100_*` menambah kolom CDD/PEP/pemilik manfaat pada `anggota` & `pengajuan_rekening`).
6. **Sign-off kepatuhan.** Mintakan persetujuan tertulis pengurus/DPS atas pemenuhan PSAK 101–109
   dan POJK (APU-PPT, CDD, PDP, GCG) sebagai prasyarat peluncuran.

---

## 8. Permintaan Umpan Balik Stakeholder (sebelum peluncuran)

1. **Sasaran peluncuran** — apakah ketiga gelombang (42 temuan) sudah cukup untuk *go-live* penuh,
   atau perlu masa *pilot* terbatas pada 1–2 kantor terlebih dahulu?
2. **Kebijakan retensi (OJK-04)** — validasi durasi default (audit 1095 hari, PII 365 hari) &
   apakah perlu arsip terpisah untuk auditor eksternal.
3. **Mode CSP ketat** — apakah `csp_strict` (nonce, tanpa `unsafe-inline`) akan diaktifkan pada
   peluncuran atau bertahap.
4. **Jadwal UAT** dengan pengurus/teller/AO/manajer/DPS sebagai prasyarat *sign-off* produksi.

---

**Kesimpulan:** Gelombang 3 **selesai & terverifikasi** — seluruh **30 butir SEDANG/RENDAH** tuntas,
**4 akar masalah regresi** diperbaiki, dan regresi penuh **HIJAU: `1280 passed (5585 assertions)`**.
Dengan tuntasnya gelombang ini, **seluruh 42 temuan audit telah tertutup**. Sistem siap memasuki
tahap peluncuran, menunggu umpan balik stakeholder pada Bagian 8.
