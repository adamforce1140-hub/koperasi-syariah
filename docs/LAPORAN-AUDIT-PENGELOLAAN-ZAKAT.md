# LAPORAN AUDIT MENYELURUH — PENGELOLAAN ZAKAT, INFAK & SEDEKAH

**Sistem:** Koperasi Syariah (Laravel 12 / PHP 8.3 / MySQL 8.4)
**Modul yang diaudit:** Zakat, Infak & Sedekah (PSAK 109)
**Tanggal audit:** 2026-10-12
**Tanggal pembaruan (audit ulang):** 2026-10-12 — verifikasi pasca-perbaikan **P1** (Z-01, Z-02, Z-03), **P2** (Z-05, Z-04, Z-07, Z-09) & **P3** (Z-06, Z-08, Z-10, Z-11)
**Jenis audit:** Audit kepatuhan (compliance) + audit operasional + audit sistem informasi
**Siklus yang diaudit:** Pengumpulan → Pendistribusian → Pelaporan
**Metode:** Inspeksi kode sumber, penelusuran alur (walkthrough), pengujian otomatis, dan kontrol silang antar-komponen.

---

## Ringkasan Eksekutif

Modul zakat telah **memenuhi prinsip inti PSAK 109 dan batas syariah** — pemisahan dana zakat/infak dari dana koperasi, penyaluran terbatas pada 8 asnaf (QS At-Taubah 60), jurnal berimbang yang wajib terposting, sumber saldo dari mutasi jurnal, dan pengendalian akses berbasis peran (RBAC). Kepatuhan ini terbukti melalui pengujian otomatis khusus zakat dan **baseline penuh 1508 lulus / 6831 asersi / 5 dilewati / 0 gagal** (per 2026-10-12, setelah perbaikan P3).

Audit ulang per 2026-10-12 mengonfirmasi bahwa **ketiga temuan berisiko Tinggi (Z-01, Z-02, Z-03) kini TERTUTUP penuh** setelah perbaikan P1, **keempat temuan P2 (Z-04, Z-05, Z-07, Z-09) TERTUTUP** setelah perbaikan P2, dan **keempat temuan P3 (Z-06, Z-08, Z-10, Z-11) TERTUTUP** setelah perbaikan P3: paginasi buku besar, whitelist kategori penyaluran, tautan menu zakat untuk DPS, dan konsistensi berkas uji ke jalur HTTP. Perbaikan ini **tidak menurunkan baseline pengujian** dan mengikuti pola **P4 "Default Aman"** (kolom/parameter baru nullable; ambang `0` = dual control nonaktif sehingga perilaku lama tetap terjaga).

Saat ini **seluruh temuan (Z-01 s.d. Z-11) telah TERTUTUP**

| Tingkat | Jumlah | ID Temuan |
|---------|--------|-----------|
| Tinggi  | 0      | — (Z-01, Z-02, Z-03 tertutup) |
| Sedang  | 0      | — (Z-04, Z-05, Z-07, Z-09 tertutup) |
| Rendah  | 0      | — (Z-06, Z-08, Z-10, Z-11 tertutup) |

**Opini audit (diperbarui):** Sistem **layak secara pencatatan akuntansi DAN dari sisi pengendalian internal inti**. **Seluruh temuan (Tinggi, Sedang, maupun Rendah) telah tertutup**; tidak ada lagi temuan terbuka yang menghalangi penggunaan pada data produksi. Modul zakat dinilai **siap operasional** dengan pemantauan berkala atas indikator kepatuhan (hak amil, kelengkapan jejak jurnal) yang kini tersedia di UI.

---

## 1. Latar Belakang & Tujuan

Dana zakat, infak, dan sedekah adalah **dana titipan (amanah)** yang secara akuntansi **bukan pendapatan** koperasi dan tidak boleh memengaruhi Sisa Hasil Usaha (SHU). Karena itu modul ini menuntut standar pengendalian yang lebih ketat daripada transaksi komersial biasa: setiap rupiah harus dapat ditelusuri (traceable), setiap koreksi harus terekam (auditable), dan setiap penyaluran harus terotorisasi (authorized).

Tujuan audit ini:
1. Memverifikasi **alur kerja** pengumpulan, pendistribusian, dan pelaporan zakat.
2. Memverifikasi **pencatatan transaksi & jurnal** terhadap PSAK 109.
3. Memverifikasi **pembagian wewenang** di seluruh peran (role) yang terlibat.
4. Mengidentifikasi **potensi kesalahan, penyimpangan, dan celah risiko** pada setiap tahapan.
5. Menyusun **rekomendasi perbaikan terukur**.
6. **Mendokumentasikan** seluruh temuan dengan bukti pendukung yang valid.

---

## 2. Ruang Lingkup & Metodologi

### 2.1 Ruang lingkup
Seluruh komponen yang membentuk siklus pengelolaan zakat:
`ZakatController`, `ZakatService`, `ZakatTransaksi`, rute `web.php` (grup pengelola & DPS), view `zakat/index.blade.php` & `zakat/laporan.blade.php`, migrasi CoA & parameter kebijakan, `Setting`, `CoaResolver`, `AutoJournalingService`, serta `ReversalService` dan `DanaKebajikanService` sebagai **pembanding arsitektur internal**.

### 2.2 Teknik audit yang digunakan
| Teknik | Penerapan |
|--------|-----------|
| Inspeksi kode | Pembacaan penuh seluruh berkas siklus zakat |
| Walkthrough alur | Penelusuran dari rute → controller → service → jurnal |
| Uji kepatuhan otomatis | `php artisan test --filter=Zakat` |
| Kontrol silang | Perbandingan modul zakat vs modul dana sosial sejenis (Dana Kebajikan) |
| Uji negatif | Penelusuran jalur koreksi, pembatalan, dan pembatasan akses |
| Analisis kesenjangan | Perbandingan klaim dokumentasi vs implementasi nyata |

### 2.3 Bukti pengujian
```
php artisan optimize:clear ; php artisan test tests/Feature/ZakatPsak109Test.php tests/Feature/ZakatHisabKonfigurasiTest.php
→ Tests: 70 passed (292 assertions)

php artisan optimize:clear ; php artisan test tests/Feature/NavigasiWewenangPeranTest.php tests/Feature/ZakatAmilPorsiTest.php tests/Feature/ZakatLaporanCalkTest.php
→ Tests: 20 passed (51 assertions)

php artisan optimize:clear ; php artisan test
→ Tests: 5 skipped, 1508 passed (6831 assertions) — Duration: ~222s
```

---

## 3. Dasar Normatif

| Rujukan | Pokok ketentuan | Status kepatuhan |
|---------|-----------------|------------------|
| **PSAK 109** — Akuntansi Zakat, Infak & Sedekah | Dana zakat/infak adalah dana titipan; disajikan terpisah; tidak memengaruhi SHU | **Sesuai** (lihat §6.1) |
| **QS At-Taubah 60** | Zakat hanya untuk 8 asnaf | **Sesuai** (ditegakkan di service & validasi) |
| **Fatwa DSN-MUI No. 08/DSN-MUI/IV/2000** | Zakat, infak, sedekah; hak amil maksimum 1/8 (12,5%) | **Sesuai** (temuan Z-07 & Z-09 tertutup) |
| **Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000** | Denda/ta'zir disalurkan sebagai dana sosial | Di luar modul ini (Dana Kebajikan) |
| **Prinsip GCG OJK** (transparansi, akuntabilitas, tanggung jawab, independensi, kewajaran) | Pemisahan tugas, jejak audit, otorisasi berjenjang | **Sesuai** (Z-01, Z-02, Z-03 tertutup) |
| **Prinsip operasional koperasi syariah** | Pengelolaan dana amanah secara akuntabel & bebas penyimpangan | **Sesuai** (koreksi berjejak + dual control terpasang) |

---

## 4. Arsitektur & Peta Komponen

```
Rute (web.php)
├── role:pengurus,manajer → prefix pengelola
│   ├── GET  /zakat                → ZakatController@index      (zakat.index)
│   ├── GET  /zakat/laporan        → ZakatController@laporan    (zakat.laporan)
│   ├── GET  /zakat/ringkasan      → ZakatController@ringkasan  (zakat.ringkasan)
│   ├── POST /zakat/hitung         → ZakatController@hitung     (zakat.hitung)
│   ├── POST /zakat/hitung-penghasilan → @hitungPenghasilan
│   ├── POST /zakat/terima         → ZakatController@store      (zakat.store)
│   ├── POST /zakat/salurkan       → ZakatController@salurkan   (zakat.salurkan)
│   ├── POST /zakat/setujui        → ZakatController@setujui    (zakat.setujui)   ← Z-02
│   ├── POST /zakat/tolak          → ZakatController@tolak      (zakat.tolak)     ← Z-02
│   └── POST /zakat/batalkan       → ZakatController@batalkan   (zakat.batalkan)  ← Z-01
└── role:dps → prefix dps (READ-ONLY)
    ├── GET /dps/zakat             → zakat.index  (dps.zakat.index)
    └── GET /dps/zakat/laporan     → zakat.laporan (dps.zakat.laporan)

Controller → Service → Jurnal (sumber kebenaran saldo)
ZakatController → ZakatService → AutoJournalingService → Jurnal / jurnal_detail
                                 ↑
                        CoaResolver (account_dana_zakat_id / account_dana_infak_id)
                                 ↑
                        Setting (parameter kebijakan)
```

**Akun CoA terkait:**
- `2-3002` Dana Zakat (Kewajiban jangka pendek, saldo normal Kredit)
- `2-3004` Dana Infak & Sedekah (Kewajiban jangka pendek, saldo normal Kredit)
- `1-1001` Kas Teller & `1-1002` Bank Koperasi (akun kas likuid sumber/tujuan)

---

## 5. Alur Kerja End-to-End

### 5.1 Pengumpulan (Penerimaan)
`POST /zakat/terima` → validasi (dana, kategori, pihak/pihak_id, jumlah, tanggal, kode_kas) → `ZakatService::terima()` → `DB::transaction` → posting jurnal **DEBET kas/bank | KREDIT 2-3002/2-3004** → simpan `ZakatTransaksi` dengan `jurnal_id` dan `created_by`.

### 5.2 Pendistribusian (Penyaluran)
`POST /zakat/salurkan` → validasi → `ZakatService::salurkan()` → cek minimum penyaluran → cek 8 asnaf (khusus zakat) → **cabang dual control (Z-02):** bila nominal ≥ `Setting::zakatAmbangPersetujuan()` (dan ambang > 0), transaksi disimpan berstatus `menunggu_persetujuan` **tanpa posting jurnal**; bila di bawah ambang/ambang `0`, lanjut auto-post. Jalur auto-post: `DB::transaction` → **`lockForUpdate()` baris CoA dana (anti-TOCTOU)** → cek saldo → posting jurnal **DEBET 2-3002/2-3004 | KREDIT kas/bank** → simpan `ZakatTransaksi` (`status => posted`). Persetujuan (`POST /zakat/setujui`) oleh peran berbeda memposting jurnal dengan penguncian; penolakan (`POST /zakat/tolak`) tidak pernah memposting.

### 5.3 Pelaporan
`GET /zakat/laporan` → `ZakatService::ringkasanSemua()` (saldo awal + penerimaan − penyaluran dari **mutasi jurnal Posted**) → rincian per jenis zakat & per asnaf → **rekap hak amil** (porsi × penerimaan vs realisasi) → rincian mutasi periode. Penyajian juga masuk ke **CaLK 6.g** dan **Dana Investasi Terikat (DIT)** melalui `LaporanKeuanganSyariahService`.

### 5.4 Titik kendali yang sudah ada (kekuatan)
- ✅ Saldo **selalu** dari mutasi jurnal CoA, bukan dari tabel transaksi.
- ✅ `validasi()` melakukan kontrol silang buku besar vs jurnal, mendeteksi saldo negatif, baris tanpa jurnal, dan asnaf tidak sah.
- ✅ Anti-TOCTOU via `lockForUpdate()` pada penyaluran, persetujuan, dan pembatalan.
- ✅ Pembulatan konsisten (`Setting::pembulatanNominal`) sehingga buku besar identik dengan jurnal.
- ✅ **Koreksi berjejak (Z-01):** pembatalan menandai `void` tanpa menghapus + jurnal pembalik + `AuditLog`.
- ✅ **Dual control (Z-02):** penyaluran ber-ambang wajib persetujuan peran berbeda (pengaju ≠ pemutus).

---

## 6. Pemetaan Peran & Wewenang (RBAC)

| Peran | Hak pada modul zakat | Kendali |
|-------|----------------------|---------|
| **Pengurus** | Baca + tulis (penerimaan, penyaluran) + laporan | Middleware `role:pengurus,manajer` |
| **Manajer** | Baca + tulis (penerimaan, penyaluran) + laporan | Middleware `role:pengurus,manajer` |
| **DPS** | **Read-only** (buku besar + laporan) | Middleware `role:dps`; form disembunyikan via `$readOnly`; tidak ada rute POST di grup DPS |
| **Teller / AO** | Tidak berwenang | Diblokir (diuji: `assertForbidden`) |

**Kendali yang terbukti:** DPS tidak dapat menulis (`test_dps_hanya_dapat_membaca_buku_besar_zakat`); teller/AO diblokir (`test_teller_dan_ao_tidak_dapat_mengakses_modul_zakat`); menu peran tidak menautkan rute peran lain (`NavigasiWewenangPeranTest`).

**Pemisahan tugas (diperbarui pasca-P1):** Kini **ada** pemisahan **pengaju vs pemutus** pada siklus zakat:
- **Pembatalan (Z-01):** `PemisahanTugas::dilanggar($trx->created_by, $userId)` — pembuat transaksi tidak boleh membatalkan transaksinya sendiri (rute `zakat.batalkan`).
- **Penyaluran ber-ambang (Z-02):** pengaju tidak boleh menyetujui/menolak pengajuannya sendiri (rute `zakat.setujui`/`zakat.tolak`).

**Catatan pengawasan:** peran **DPS** kini memperoleh tautan menu ke modul zakat (baca-saja) — temuan **Z-10 tertutup** (lihat Z-10-verifikasi).

---

## 7. Hasil Uji Kepatuhan (Kontrol Positif)

Berikut kontrol yang **terverifikasi bekerja** dan tidak menjadi temuan:

| # | Kontrol | Bukti |
|---|---------|-------|
| P1 | Pemisahan akun dana zakat (2-3002) vs infak (2-3004) vs dana kebajikan (2-3001) | `ZakatPsak109Test::test_dana_zakat_dan_infak_sedekah_menempati_akun_terpisah` |
| P2 | Penyaluran zakat hanya ke 8 asnaf | `test_zakat_hanya_dapat_disalurkan_kepada_delapan_asnaf` |
| P3 | Penyaluran melebihi saldo ditolak | `test_penyaluran_melebihi_saldo_dana_ditolak` |
| P4 | Setiap transaksi terhubung jurnal Posted & berimbang | `test_setiap_transaksi_terhubung_ke_jurnal_posted_berimbang` |
| P5 | Saldo = saldo awal + penerimaan − penyaluran (dari jurnal) | `test_ringkasan_mengembalikan_empat_komponen_psak_109` |
| P6 | Minimum penyaluran dari parameter kebijakan | `test_penyaluran_di_bawah_batas_minimum_ditolak` |
| P7 | Pemetaan akun dana menyatukan buku besar & DIT | `test_setting_akun_dana_menyatukan_pencatatan_dan_dit` |
| P8 | Hak amil dari parameter (default 12,5%) | `ZakatAmilPorsiTest` |
| P9 | Hisab zakat maal & penghasilan (nishab, haul) | `ZakatHisabKonfigurasiTest`, `ZakatPenghasilanTest` |
| P10 | Pembatalan (void) berjejak: baris tak terhapus, saldo kembali, jurnal pembalik, AuditLog | `ZakatPsak109Test` (blok "PEMBATALAN (VOID) TERKENDALI") |
| P11 | Dual control penyaluran: pengaju ≠ penyetuju; ambang `0` mempertahankan auto-post | `ZakatPsak109Test` (blok dual control Z-02) |
| P12 | Keenam parameter hisab dapat dikonfigurasi via HTTP `pengurus.kebijakan.update` | `ZakatPsak109Test::test_pengurus_dapat_mengubah_parameter_hisab_zakat_via_ui` |

---

## 8. Temuan Audit

### Ringkasan Temuan

| ID | Temuan | Severitas | Tahapan | Risiko utama | Status |
|----|--------|-----------|---------|--------------|--------|
| Z-01 | Tidak ada mekanisme pembatalan/koreksi (void/reversal) terkontrol untuk transaksi zakat | **Tinggi** | Pencatatan | Salah input tak dapat dikoreksi berjejak | **TERTUTUP** |
| Z-02 | Penyaluran dana amanah tanpa dual control (maker-checker) & tanpa guard pemisahan tugas | **Tinggi** | Pendistribusian | Penyimpangan sepihak | **TERTUTUP** |
| Z-03 | 6 parameter hisab zakat tidak dapat dikonfigurasi via UI (klaim dokumentasi tidak terpenuhi) | **Tinggi** | Seluruh siklus | Nilai hisab hanya dapat diubah via DB | **TERTUTUP** |
| Z-04 | Potensi race condition pada pembentukan `no_referensi` | Sedang | Pencatatan | Duplicate key / gagal simpan | **TERTUTUP** |
| Z-05 | `jurnal_id` & `created_by` tanpa foreign key (integritas referensial lemah) | Sedang | Pencatatan | Referensi yatim, jejak audit lemah | **TERTUTUP** |
| Z-06 | Buku besar `index()` dibatasi 200 baris tanpa paginasi/ekspor | Rendah | Pelaporan | Kelengkapan data terpotong | **TERTUTUP** |
| Z-07 | Hak amil bersifat *entitlement* tanpa kontrol realisasi | Sedang | Pelaporan | Realisasi amil dapat melampaui hak | **TERTUTUP** |
| Z-08 | Kategori penyaluran infak/sedekah tidak di-whitelist | Rendah | Pendistribusian | Pelaporan per kategori tidak konsisten | **TERTUTUP** |

**Temuan baru (hasil audit ulang):**

| ID | Temuan | Severitas | Tahapan | Risiko utama |
|----|--------|-----------|---------|--------------|
| Z-09 | Warna selisih hak amil pada laporan **terbalik** (merah saat aman, hijau saat terlampaui) | Sedang | Pelaporan | Salah baca → tidak terdeteksinya pelampauan hak amil | **TERTUTUP** |
| Z-10 | DPS (pengawas syariah) tidak memiliki tautan menu ke modul zakat (harus akses URL langsung) | Rendah | Pengawasan | Pengawasan syariah tidak efektif/terlewat | **TERTUTUP** |
| Z-11 | Berkas uji lama `ZakatHisabKonfigurasiTest` masih mengubah parameter via model langsung (jalur HTTP kini sudah ditutup di `ZakatPsak109Test`) | Rendah | Jaminan mutu | Uji ganda tidak konsisten (bukan risiko fungsional) | **TERTUTUP** |

---

### Z-01 — Tidak Ada Mekanisme Pembatalan/Koreksi (Void/Reversal) Terkontrol

**Severitas:** Tinggi
**Tahapan:** Pencatatan transaksi
**STATUS: ✅ TERTUTUP (P1, 2026-10-12)** — perbaikan terpasang & teruji (lihat Z-01-verifikasi).

**Kondisi (saat temuan):** `ZakatTransaksi` tidak memiliki kolom `deleted_at` maupun `updated_by`; tidak ada metode `batalkan()`/`void()` pada `ZakatService`; dan `ReversalService` **tidak memiliki cabang penanganan zakat sama sekali**.

**Bukti:**
- `app/Services/ReversalService.php` — penelusuran `zakat` pada berkas ini: **0 kecocokan**. Cabang yang ada hanya: Pencairan Pembiayaan, Injeksi Kas, Simpanan, Deposito, Angsuran, dan Dana Kebajikan (ta'zir).
- `database/migrations/2026_09_27_000200_create_zakat_transaksis_table.php` — kolom hanya `id, no_referensi, jenis, dana, kategori, pihak, jumlah, keterangan, tanggal, jurnal_id, created_by, timestamps`. **Tidak ada `updated_by`/`deleted_at`**.
- `app/Models/ZakatTransaksi.php` — tidak memakai `SoftDeletes`.
- **Pembanding internal:** `app/Services/DanaKebajikanService.php` (baris ~852) **memiliki** `batalkanPencatatanTransaksi(Transaksi, ?int $userId)` yang menandai status `Void`/`Dibatalkan` (baris ~408, 863) **tanpa menghapus** baris — jejak audit terjaga. Modul zakat **tidak memiliki padanan ini**.

**Risiko:** Kesalahan input nominal, pihak, kategori/asnaf, atau tanggal tidak dapat dikoreksi melalui alur terkontrol. Koreksi hanya mungkin lewat jurnal manual di luar modul zakat, yang **tidak terekam** sebagai koreksi transaksi zakat dan berpotensi disalahgunakan. Laporan sumber & penggunaan dana dapat menjadi tidak akurat tanpa jejak koreksi.

**Rekomendasi (terukur):**
1. Tambahkan kolom `status` (`posted`/`void`) dan/atau `updated_by` pada `zakat_transaksis` (nullable, P4 Default Aman).
2. Implementasikan `ZakatService::batalkan(int $id, ?int $userId)` yang: (a) membalik jurnal terkait (posting jurnal reversal), (b) menandai baris `void` **tanpa menghapus**, (c) mencatat `AuditLog::record()`.
3. Terapkan guard `PemisahanTugas::dilanggar($trx->created_by, $userId)` — pembuat tidak boleh membatalkan transaksinya sendiri.
4. **Kriteria penerimaan:** ada uji yang membuktikan (i) pembatalan tidak menghapus baris, (ii) saldo kembali ke nilai sebelum transaksi, (iii) pembuat sendiri ditolak, (iv) aksi tercatat di audit log.

**✅ Z-01-verifikasi (2026-10-12):**
- **Migrasi:** `database/migrations/2026_10_12_000100_add_void_columns_to_zakat_transaksis.php` menambah `status`, `void_reason`, `void_at`, `void_by` (nullable, P4).
- **Service:** `ZakatService::batalkan()` (`app/Services/ZakatService.php` baris 345–411) — menandai status `void` **tanpa menghapus** baris, memposting **jurnal pembalik** via `postJurnalPembalik()` (baris 594–651, tukar debet↔kredit), mengunci baris CoA dana (`lockForUpdate()` baris 368–371), menerapkan guard `PemisahanTugas::dilanggar()` (baris 358–360), dan mencatat `AuditLog::record()`.
- **Controller & rute:** `ZakatController::batalkan()` (baris 306–324), rute `zakat.batalkan` (grup `role:pengurus,manajer`); DPS & pembuat diblokir.
- **Uji (lulus):** `pembatalan menandai void tanpa menghapus dan mengembalikan saldo`, `pembuat transaksi tidak dapat membatalkan transaksinya sendiri`, `pembatalan tercatat di audit log`, `transaksi void tidak dapat dibatalkan dua kali`, `validasi tidak melaporkan selisih setelah pembatalan`, `pembatalan via http oleh pengurus lain berhasil`, `pembatalan via http oleh pembuat ditolak`, `dps tidak dapat membatalkan transaksi`.

---

### Z-02 — Penyaluran Dana Amanah Tanpa Dual Control & Tanpa Guard Pemisahan Tugas

**Severitas:** Tinggi
**Tahapan:** Pendistribusian
**STATUS: ✅ TERTUTUP (P1, 2026-10-12)** — dual control terpasang & teruji (lihat Z-02-verifikasi).

**Kondisi (saat temuan):** Penyaluran zakat/infak dilakukan **sepihak** oleh pengurus atau manajer. Tidak ada alur persetujuan (approval), tidak ada penanda penyetuju, dan `PemisahanTugas` tidak diterapkan di siklus zakat.

**Bukti:**
- `app/Http/Controllers/ZakatController.php` — tidak ada referensi `ApprovalTransaksi` (penelusuran: 0 kecocokan).
- `routes/web.php` (baris 301–302) — `POST /zakat/terima` dan `POST /zakat/salurkan` langsung di dalam grup `role:pengurus,manajer` tanpa middleware approval.
- Penelusuran `PemisahanTugas::` di seluruh `app/` menemukan **54 pemakaian** (Policies, ReversalService, TransactionLimitService, JurnalController, PengeluaranBebanController, dll.) — **tidak satu pun** di `ZakatController`/`ZakatService`.
- `app/Models/ApprovalTransaksi.php` sudah tersedia (maker-checker: `diajukan_oleh`, `disetujui_oleh`, `role_approver`) namun tidak dipakai modul zakat.

**Risiko:** Dana titipan (amanah) dapat disalurkan tanpa otorisasi kedua, membuka celah penyaluran fiktif, penyaluran ke pihak yang tidak berhak, atau penggelapan yang tidak terdeteksi. Bertentangan dengan prinsip pemisahan tugas dan tata kelola yang baik.

**Rekomendasi (terukur):**
1. Terapkan **maker-checker** untuk penyaluran: ajukan → setujui oleh peran berbeda (mis. pengurus mengajukan, manajer/pengurus lain menyetujui) sebelum jurnal diposting.
2. Minimal (bila tanpa alur penuh): simpan `disetujui_oleh` pada `zakat_transaksis` dan terapkan `PemisahanTugas::dilanggar()` agar pembuat ≠ penyetuju.
3. Tetapkan **ambang nominal** (parameter kebijakan) yang mewajibkan persetujuan; di bawah ambang dapat auto-post dengan jejak.
4. **Kriteria penerimaan:** uji yang membuktikan penyaluran di atas ambang tidak terposting tanpa persetujuan, dan penyaluran oleh orang yang sama dengan pengaju ditolak.

**✅ Z-02-verifikasi (2026-10-12):**
- **Migrasi:** `database/migrations/2026_10_12_000200_add_approval_columns_to_zakat_transaksis.php` menambah `disetujui_oleh`, `disetujui_pada`, `alasan_tolak`, `kode_kas` (nullable, P4); `status` (dari Z-01) menampung `menunggu_persetujuan` & `ditolak`.
- **Service:** `ZakatService::salurkan()` (baris 189–317) — bercabang pada `Setting::zakatAmbangPersetujuan()`: bila nominal ≥ ambang, transaksi disimpan berstatus `menunggu_persetujuan` **tanpa** posting jurnal; `setujui()` (baris 433–521) memposting jurnal di dalam `DB::transaction` dengan `lockForUpdate()` baris CoA; `tolak()` (baris 533–582) tidak pernah memposting. `pastikanSaldoCukup()` (baris 931–943) memberi umpan balik awal saat pengajuan.
- **Guard pemisahan tugas:** `PemisahanTugas::dilanggar($trx->created_by, $userId)` diterapkan pada `setujui()`/`tolak()` — pengaju tidak boleh memutus pengajuannya sendiri.
- **Controller & rute:** `ZakatController::salurkan()/setujui()/tolak()` (baris 220–297); rute `zakat.setujui` & `zakat.tolak` (grup `role:pengurus,manajer`); UI `zakat/index.blade.php` menampilkan badge status + tombol Setujui/Tolak.
- **Default Aman:** ambang `0` = dual control **nonaktif** → perilaku auto-post lama tetap terjaga.
- **Uji (lulus):** `penyaluran di atas ambang belum diposting sebelum disetujui`, `pengaju tidak dapat menyetujui pengajuannya sendiri`, `penyetuju lain dapat menyetujui dan jurnal diposting`, `penolakan tidak pernah memposting jurnal`, `pengaju tidak dapat menolak pengajuannya sendiri`, `pengajuan yang sudah diputus tidak dapat disetujui lagi`, `setujui via http oleh pengurus lain`, `tolak via http oleh pengurus lain`, `dps tidak dapat menyetujui penyaluran`, `ambang nol mempertahankan perilaku auto post`, `penyaluran di bawah ambang tetap auto post`.

---

### Z-03 — Parameter Hisab Zakat Tidak Dapat Dikonfigurasi (Klaim Dokumentasi Tidak Terpenuhi)

**Severitas:** Tinggi
**Tahapan:** Seluruh siklus (menentukan nishab, kadar, minimum penyaluran, haul)
**STATUS: ✅ TERTUTUP (P1, 2026-10-12)** — keenam parameter kini dapat dikonfigurasi via UI (lihat Z-03-verifikasi).

**Kondisi (saat temuan):** Enam parameter — `zakat_rate`, `zakat_nisab_gram`, `harga_emas_per_gram`, `zakat_minimal_tarik`, `zakat_haul_aktif`, `zakat_haul_hari` — **ada** di skema migrasi, `$fillable`/`$casts`/`$labels` model, dan dibaca `Setting`, tetapi **tidak dapat diubah melalui antarmuka**: tidak ada di aturan validasi controller, tidak ada di logika simpan, dan tidak ada di view kebijakan.

**Bukti:**
- Migrasi mengklaim dapat dikonfigurasi:
  - `database/migrations/2026_10_05_000500_add_zakat_parameters_to_kebijakan_koperasis.php` (rate, nisab gram, harga emas, minimal tarik)
  - `database/migrations/2026_10_09_000600_add_zakat_haul_parameters_to_kebijakan_koperasis.php` (haul aktif, haul hari)
- Namun `app/Http/Controllers/Dashboard/PengurusController.php`:
  - Blok `validate()` (baris 1001–1005) **hanya** memuat `account_dana_zakat_id`, `account_dana_infak_id`, `zakat_porsi_amil_persen`.
  - Blok pesan validasi (baris 1088–1094) hanya untuk ketiga field itu.
  - Logika simpan (baris 1292–1318) **hanya** menetapkan ketiga field itu; **tidak ada** penugasan untuk keenam parameter hisab — sehingga nilai apa pun yang dikirim akan **diabaikan secara diam-diam**.
- `resources/views/pengurus/kebijakan.blade.php` — hanya memuat `account_dana_zakat_id` (baris 828), `account_dana_infak_id` (baris 848), dan `zakat_porsi_amil_persen` (baris 871). **Keenam field hisab tidak ada di UI.**
- `ZakatHisabKonfigurasiTest` mengubah parameter **langsung via model** (`$kebijakan->zakat_rate = 5; $kebijakan->save();`), sehingga **tidak menangkap** celah UI ini.

**Risiko:** Kadar zakat (default 2,5%), nishab (85 gram emas), harga emas, batas minimum penyaluran, dan saklar/panjang haul hanya dapat diubah melalui **akses langsung ke basis data** — melanggar prinsip *zero-hardcode* / "Default Aman" (P4) yang diklaim, memutus jalur tata kelola perubahan parameter, dan berisiko salah nilai tanpa jejak. Pengurus tidak dapat menyesuaikan hisab sesuai fatwa/harga emas terkini.

**Rekomendasi (terukur):**
1. Tambahkan keenam field pada: (a) aturan `validate()` + pesan validasi, (b) logika simpan di `updateKebijakan()`, dan (c) form `pengurus/kebijakan.blade.php` (dengan tipe input yang tepat: numeric/boolean).
2. Terapkan penjepitan rentang (clamp) selaras `Setting` (mis. porsi 0–100, kadar 0–100, nisab > 0).
3. **Kriteria penerimaan:** uji jalur UI/HTTP yang menyimpan keenam parameter lewat `route('pengurus.kebijakan.update')`, lalu membuktikan `Setting` membacanya (bukan sekadar mengubah model langsung).

**✅ Z-03-verifikasi (2026-10-12):**
- **Validasi:** `PengurusController` kini memuat keenam parameter hisab + `zakat_ambang_persetujuan` (semua `nullable`, P4) pada blok `validate()` (baris 1011–1018) beserta pesan validasi (baris 1105–1124).
- **Logika simpan:** `updateKebijakan()` (baris 1357–1379) menerapkan pola P4 `$request->filled(...) ? ... : Setting::...()` — nilai kosong tidak menimpa kebijakan lama.
- **UI:** `resources/views/pengurus/kebijakan.blade.php` memuat `zakat_porsi_amil_persen` (baris 871), `zakat_rate` (895), `zakat_nisab_gram` (907), `harga_emas_per_gram` (919), `zakat_minimal_tarik` (931), `zakat_ambang_persetujuan` (943), `zakat_haul_hari` (955), `zakat_haul_aktif` (966–970).
- **Uji (lulus):** `pengurus dapat mengubah parameter hisab zakat via ui`, `validasi menolak parameter hisab tidak sah`, `pengurus dapat memindahkan akun dana zakat via ui`.
- **Catatan (lihat Z-11):** uji tersebut berjalan pada jalur HTTP `pengurus.kebijakan.update`; sebagian skenario lain masih mengubah model secara langsung.

---

### Z-04 — Potensi Race Condition pada Pembentukan `no_referensi`

**STATUS: ✅ TERTUTUP (P2, 2026-10-12)** — retry anti-race terpasang & teruji (lihat Z-04-verifikasi).

**Severitas:** Sedang
**Tahapan:** Pencatatan transaksi
**Kondisi:** `ZakatService::nomorReferensi()` menghitung nomor urut berikutnya dengan `max('no_referensi')` **tanpa penguncian**.

**Bukti:** `app/Services/ZakatService.php` baris 893–900:
```php
$terakhir = (string) ZakatTransaksi::where('no_referensi', 'like', $prefix . '%')->max('no_referensi');
$urut = $terakhir !== '' ? ((int) substr($terakhir, -4)) + 1 : 1;
```
Kolom `no_referensi` bersifat `unique` (migrasi baris 27).

**Risiko:** Dua penerimaan/penyaluran paralel pada tanggal yang sama dapat menghasilkan nomor urut identik → pelanggaran *unique constraint* → salah satu transaksi gagal disimpan. Meski `DB::transaction` melindungi konsistensi, kegagalan dapat menimbulkan galat pengguna yang membingungkan.

**Rekomendasi (terukur):**
1. Bungkus pembentukan nomor di dalam transaksi dengan `lockForUpdate()` pada baris terakhir, **atau** tangani `UniqueConstraintViolation` dengan retry otomatis (mis. 3×).
2. **Kriteria penerimaan:** uji yang menyimulasikan dua pembuatan nomor berurutan cepat tetap menghasilkan nomor unik dan berurutan.

**✅ Z-04-verifikasi (2026-10-12):**
- **Service:** `ZakatService::withRetryReferensi()` (baris 926–953) menjalankan generator nomor dengan **retry maksimum 5×** bila terjadi pelanggaran keunikan; `galatDuplikatReferensi()` (baris 956–962) membedakan galat duplikat `no_referensi` dari galat lain (diteruskan apa adanya). Setiap percobaan ulang menghitung ulang `MAX` sehingga nomor pemenang lomba konkuren tidak dipakai lagi, dengan jeda mikro acak (`usleep(random_int(1000,5000))`).
- **Jalur terima/salur:** `terima()` (baris 140–174) dan `salurkan()` (jalur dual control baris 249–264 & jalur auto-post baris 267–323) dibungkus `withRetryReferensi(...)`; nomor eksplisit pemanggil dihormati apa adanya.
- **P4 Default Aman:** bila `no_referensi` tidak diberikan, perilaku penomoran tetap sama seperti sebelumnya; hanya ditambah ketahanan terhadap tabrakan.
- **Uji (lulus):** `pembuatan beruntun membentuk nomor referensi unik`, `nomor referensi eksplisit dihormati`.

---

### Z-05 — Integritas Referensial Lemah: `jurnal_id` & `created_by` Tanpa Foreign Key

**STATUS: ✅ TERTUTUP (P2, 2026-10-12)** — FK terpasang & teruji (lihat Z-05-verifikasi).

**Severitas:** Sedang
**Tahapan:** Pencatatan transaksi
**Kondisi:** Kolom `jurnal_id` dan `created_by` dibuat tanpa *foreign key constraint*.

**Bukti:** `database/migrations/2026_09_27_000200_create_zakat_transaksis_table.php` baris 46–48:
```php
$table->unsignedBigInteger('jurnal_id')->nullable();
$table->unsignedBigInteger('created_by')->nullable();
```
(Bandingkan: migrasi `2026_10_09_000900_add_pihak_id_to_zakat_transaksis.php` **memberi** foreign key untuk `pihak_id` → `anggota` dengan `nullOnDelete`.)

**Risiko:** `jurnal_id` dapat menunjuk jurnal yang tidak ada (referensi yatim) tanpa dicegah di level basis data; `created_by` dapat menunjuk pengguna yang telah dihapus. Hal ini melemahkan jaminan jejak audit, padahal `validasi()` justru bergantung pada keterhubungan ke jurnal.

**Rekomendasi (terukur):**
1. Tambahkan FK `jurnal_id` → `jurnal.id` (`nullOnDelete`) dan `created_by` → `users.id` (`nullOnDelete`).
2. Sediakan migrasi penambahan yang aman untuk data lama (bersihkan/validasi referensi yatim terlebih dahulu).
3. **Kriteria penerimaan:** migrasi berhasil pada data yang ada; uji `validasi()` tidak lagi menemukan baris tanpa jurnal valid.

**✅ Z-05-verifikasi (2026-10-12):**
- **Migrasi:** `database/migrations/2026_10_12_000400_add_foreign_keys_to_zakat_transaksis.php` — menambahkan FK `jurnal_id` → `jurnal.id` dan `created_by` → `users.id`, keduanya `nullOnDelete` (relasi JEJAK HISTORIS, baris tetap utuh bila induk dihapus). Migrasi **defensif**: `bersihkanYatim()` menolkan referensi yatim lebih dulu, penentuan FK dihitung di luar closure `Schema::table()`, dan `namaForeign()` membaca `information_schema.KEY_COLUMN_USAGE` agar idempoten.
- **Model:** relasi `ZakatTransaksi::pencatat()` (`belongsTo(User::class, 'created_by')`) ditambahkan (baris 114–117).
- **Terpasang & berjalan:** `php artisan migrate` → `2026_10_12_000400 ... DONE`.
- **Uji (lulus):** `kolom jejak buku besar memiliki foreign key`, `relasi pencatat tertaut dan tetap utuh bila user dihapus`.

---

### Z-06 — Buku Besar `index()` Dibatasi 200 Baris Tanpa Paginasi/Ekspor

**Severitas:** Rendah
**Tahapan:** Pelaporan/Penelusuran
**STATUS: ✅ TERTUTUP (P3, 2026-10-12)** — buku besar dipaginasi & teruji (lihat Z-06-verifikasi).
**Kondisi:** `ZakatController::index()` memuat maksimum 200 baris tanpa paginasi.

**Bukti:** `app/Http/Controllers/ZakatController.php` baris 42–49 (`.limit(200)`).

**Risiko:** Pada periode dengan transaksi padat, baris lama tidak tampil sehingga penelusuran jejak audit di UI terpotong. **Catatan:** laporan (`laporan()`) **tidak** terkena batas ini karena memuat seluruh mutasi periode, sehingga akurasi laporan tetap terjaga.

**Rekomendasi (terukur):**
1. Terapkan paginasi (`paginate()`) atau tombol ekspor (CSV/Excel) pada buku besar.
2. **Kriteria penerimaan:** seluruh transaksi dalam periode dapat diakses/di-ekspor tanpa terpotong.

**✅ Z-06-verifikasi (2026-10-12):**
- **Controller:** `ZakatController::index()` (`app/Http/Controllers/ZakatController.php` baris 45–52) — `limit(200)->get()` diganti `->paginate(50)->withQueryString()`, sehingga **seluruh baris periode** dapat dijangkau halaman demi halaman, bukan hanya 200 baris pertama.
- **View:** `resources/views/zakat/index.blade.php` — badge jumlah baris memakai `$transaksi->total()` (bukan `count()` halaman) dan blok `@if($transaksi->hasPages()) ... {{ $transaksi->links() }} @endif` ditambahkan di bawah tabel.
- **P4 Default Aman:** struktur data berubah dari koleksi menjadi paginator; tidak ada perubahan perilaku pencatatan/jurnal — murni penyajian.
- **Uji (lulus):** `buku besar dipaginasi sehingga seluruh baris periode dapat diakses` (memverifikasi `LengthAwarePaginator`, `perPage() = 50`, dan `total()` = jumlah seluruh baris periode).

---

### Z-07 — Hak Amil Bersifat *Entitlement* Tanpa Kontrol Realisasi

**STATUS: ✅ TERTUTUP (P2, 2026-10-12)** — peringatan realisasi amil terpasang & teruji (lihat Z-07-verifikasi).

**Severitas:** Sedang
**Tahapan:** Pelaporan
**Kondisi:** Laporan menghitung **hak amil** (porsi × penerimaan zakat) dan menyandingkannya dengan **realisasi** penyaluran ke asnaf `amil`, tetapi **tidak ada validasi/peringatan** bila realisasi melampaui hak.

**Bukti:**
- `app/Http/Controllers/ZakatController.php` baris 379–401 (`$dasarHakAmil`, `$hakAmil`, `$realisasiAmil`) — hanya disajikan sebagai informasi.
- `ZakatAmilPorsiTest` memverifikasi penyajian rekap, bukan pembatasan.
- Fatwa DSN-MUI 08 menetapkan hak amil umumnya **maksimum 1/8 (12,5%)**; porsi dapat dikonfigurasi (`zakat_porsi_amil_persen`).
- **Diperluas (audit ulang) — temuan Z-09:** pada `resources/views/zakat/laporan.blade.php` baris 157, logika warna selisih **terbalik**:
  ```
  color:{{ ($hakAmil - $realisasiAmil) > 0 ? '#991b1b' : '#065f46' }}
  ```
  Selisih positif (hak > realisasi = **aman**) justru diberi warna **merah**, sedangkan selisih ≤ 0 (realisasi ≥ hak = **pelanggaran**) diberi warna **hijau**. Tidak ada teks peringatan eksplisit.

**Risiko:** Penyaluran ke asnaf `amil` dapat melebihi hak yang wajar (mis. melampaui 12,5%) tanpa peringatan, berpotensi melanggar ketentuan syariah dan mengurangi hak asnaf lain. Warna terbalik (Z-09) berpotensi membuat pengawas **salah membaca** kondisi kepatuhan.

**Rekomendasi (terukur):**
1. Tambahkan **indikator/peringatan** pada laporan bila `realisasiAmil > hakAmil` (selisih ditonjolkan).
2. **Perbaiki logika warna (Z-09):** hijau bila `hakAmil ≥ realisasiAmil`, merah + label peringatan bila terlampaui.
3. Opsional: validasi lunak pada penyaluran ke kategori `amil` bila kumulatif melampaui porsi.
4. **Kriteria penerimaan:** uji yang membuktikan peringatan muncul ketika realisasi amil melampaui hak, dan warna benar saat aman.

**✅ Z-07-verifikasi (2026-10-12):**
- **Controller:** `ZakatController::laporan()` menghitung flag `$hakAmilTerlampaui = $realisasiAmil > $hakAmil` (baris 386–390) dan meneruskannya ke view.
- **View:** `resources/views/zakat/laporan.blade.php` menampilkan **peringatan eksplisit** (`role="alert"`, blok merah) berisi nominal realisasi, hak amil, dan kelebihannya bila `$hakAmilTerlampaui`, disertai rujukan Fatwa DSN-MUI No. 08 (maksimum 1/8 = 12,5%).
- **Sifat:** tetap **informatif** (tidak memotong/memblokir jurnal penyaluran) — murni indikator kepatuhan bagi Pengurus/DPS.
- **Uji (lulus):** `laporan memberi peringatan saat realisasi amil melampaui hak`.

---

### Z-08 — Kategori Penyaluran Infak/Sedekah Tidak Di-Whitelist

**Severitas:** Rendah
**Tahapan:** Pendistribusian
**STATUS: ✅ TERTUTUP (P3, 2026-10-12)** — kategori penyaluran di-whitelist & teruji (lihat Z-08-verifikasi).
**Kondisi:** Penyaluran **zakat** divalidasi ketat terhadap 8 asnaf, namun penyaluran **infak/sedekah** hanya divalidasi `max:40` (teks bebas) pada level controller; tidak ada whitelist kategori.

**Bukti:**
- `app/Http/Controllers/ZakatController.php` baris 224 (`'kategori' => ['required','string','max:40']`) untuk `salurkan()`.
- `app/Services/ZakatService.php` baris 229 — pembatasan 8 asnaf hanya berlaku bila `$dana === DANA_ZAKAT`.
- `ZakatTransaksi::KATEGORI_PENYALURAN` sudah memuat kategori infak (`kegiatan_sosial`, `bantuan_pendidikan`, `bantuan_kesehatan`) tetapi tidak dipakai untuk memvalidasi input.

**Risiko:** Kategori penyaluran infak/sedekah dapat tidak konsisten (variasi ejaan/nama), sehingga pelaporan per kategori tidak teragregasi dengan baik.

**Rekomendasi (terukur):**
1. Validasi `kategori` penyaluran infak/sedekah terhadap `KATEGORI_PENYALURAN` (dengan opsi kategori "lain-lain" terkontrol).
2. **Kriteria penerimaan:** uji yang menolak kategori di luar daftar sah.

**✅ Z-08-verifikasi (2026-10-12):**
- **Controller:** `ZakatController::salurkan()` (`app/Http/Controllers/ZakatController.php` baris 231) — aturan `kategori` ditambah `Rule::in(array_keys(ZakatTransaksi::KATEGORI_PENYALURAN))`, sehingga kategori bebas ditolak di level validasi HTTP.
- **Lingkup:** whitelist mencakup 8 asnaf **dan** kategori infak/sedekah (`kegiatan_sosial`, `bantuan_pendidikan`, `bantuan_kesehatan`); batas khusus 8 asnaf untuk dana zakat tetap ditegakkan di `ZakatService::salurkan()`.
- **P4 Default Aman:** seluruh kategori yang sebelumnya sah (8 asnaf + kategori infak) tetap diterima; hanya kategori di luar daftar yang kini ditolak.
- **Uji (lulus):** `kategori penyaluran di luar daftar sah ditolak` (`assertSessionHasErrors('kategori')` + baris tidak tercatat), `kategori penyaluran sah tetap diterima`.

---

### Z-09 — Warna Selisih Hak Amil Terbalik pada Laporan

**STATUS: ✅ TERTUTUP (P2, 2026-10-12)** — logika warna dikoreksi & teruji (lihat Z-09-verifikasi).

**Severitas:** Sedang
**Tahapan:** Pelaporan
**Kondisi:** Pada `resources/views/zakat/laporan.blade.php` baris 157, warna selisih `(hakAmil − realisasiAmil)` dihitung **terbalik** dari makna kepatuhan.

**Bukti:**
- `resources/views/zakat/laporan.blade.php` baris 157:
  ```
  color:{{ ($hakAmil - $realisasiAmil) > 0 ? '#991b1b' : '#065f46' }}
  ```
  `#991b1b` (merah) diberikan saat selisih > 0 (hak > realisasi = **aman**); `#065f46` (hijau) diberikan saat selisih ≤ 0 (realisasi ≥ hak = **berpotensi melanggar** Fatwa DSN-MUI 08).
- Tidak ada teks/label peringatan eksplisit ketika realisasi melampaui hak.

**Risiko:** Pengurus/DPS dapat **salah menyimpulkan** status kepatuhan hak amil — kondisi pelanggaran (realisasi > hak) tampil hijau (seolah aman), sedangkan kondisi sehat tampil merah (seolah bermasalah). Mengikis keandalan pengawasan berbasis laporan.

**Rekomendasi (terukur):**
1. Balik logika warna: hijau (`#065f46`) bila `hakAmil ≥ realisasiAmil`, merah (`#991b1b`) + label "Terlampaui" bila `realisasiAmil > hakAmil`.
2. **Kriteria penerimaan:** uji render yang memverifikasi warna hijau saat aman dan merah + label saat terlampaui.

**✅ Z-09-verifikasi (2026-10-12):**
- **View:** `resources/views/zakat/laporan.blade.php` baris 158–168 — logika warna **dibalik**: `color:{{ $hakAmilTerlampaui ? '#991b1b' : '#065f46' }}`. Sel selisih kini menampilkan badge **"Terlampaui"** (merah) saat `realisasiAmil > hakAmil` dan badge **"Aman"** (hijau) saat sebaliknya.
- **Uji (lulus):** `laporan aman saat realisasi amil dalam batas hak` (memverifikasi `#065f46` + absennya peringatan), `laporan memberi peringatan saat realisasi amil melampaui hak` (memverifikasi label "Terlampaui" + blok peringatan).

---

### Z-10 — DPS Tidak Memiliki Tautan Menu ke Modul Zakat

**Severitas:** Rendah
**Tahapan:** Pengawasan
**STATUS: ✅ TERTUTUP (P3, 2026-10-12)** — tautan menu zakat DPS terpasang & teruji (lihat Z-10-verifikasi).
**Kondisi:** Menu navigasi hanya menautkan modul zakat untuk **Manajer** dan **Pengurus**; peran **DPS** tidak memperoleh tautan meski memiliki rute baca `dps.zakat.index`/`dps.zakat.laporan`.

**Bukti:**
- `app/Services/NavigationService.php` — tautan zakat hanya untuk Manajer (baris 209–211) & Pengurus (baris 321–329); **tidak ada** untuk DPS.
- Rute DPS `role:dps` (`routes/web.php` baris 400, rute 418–419) tetap dapat diakses bila URL diketik langsung (`test_dps_hanya_dapat_membaca_buku_besar_zakat`).

**Risiko:** Pengawasan syariah (fungsi utama DPS) menjadi tidak efektif karena akses bergantung pada pengetahuan URL manual; pengawasan berpotensi terlewat.

**Rekomendasi (terukur):**
1. Tambahkan tautan menu modul zakat (baca-saja) pada navigasi peran DPS.
2. **Kriteria penerimaan:** uji navigasi yang membuktikan tautan tampil untuk DPS dan mengarah ke `dps.zakat.index`.

**✅ Z-10-verifikasi (2026-10-12):**
- **Menu:** `NavigationService::menuDPS()` (`app/Services/NavigationService.php` baris 525–544) — blok baru "ZAKAT, INFAK & SEDEKAH (READ-ONLY)" dengan dua tautan: `dps.zakat.index` ("Buku Besar Zakat (Read-Only)") dan `dps.zakat.laporan` ("Laporan Sumber & Penggunaan Zakat").
- **Wewenang:** kedua rute ber-guard `role:dps`, sehingga tautan konsisten dengan hak akses (invariant `NavigasiWewenangPeranTest` otomatis mencakupnya).
- **Uji (lulus):** `menu dps memuat tautan zakat read only` (memverifikasi kedua nama rute muncul pada menu DPS), `dps dapat membuka tautan zakat pada menunya` (kedua rute → `assertOk`, tanpa 403).

---

### Z-11 — Berkas Uji Lama Masih Mengubah Parameter via Model Langsung

**Severitas:** Rendah
**Tahapan:** Jaminan mutu
**STATUS: ✅ TERTUTUP (P3, 2026-10-12)** — uji dimigrasikan ke jalur HTTP & teruji (lihat Z-11-verifikasi).
**Kondisi:** `tests/Feature/ZakatHisabKonfigurasiTest.php` menyetel parameter hisab **langsung via model** (`$kebijakan->zakat_rate = 5; $kebijakan->save();`), sehingga tidak menguji jalur HTTP. Jalur HTTP kini telah ditutup oleh `tests/Feature/ZakatPsak109Test.php` (`test_pengurus_dapat_mengubah_parameter_hisab_zakat_via_ui`, `test_validasi_menolak_parameter_hisab_tidak_sah`).

**Bukti:**
- `tests/Feature/ZakatHisabKonfigurasiTest.php` baris 50–53 (`setHaul()` menetapkan `zakat_haul_aktif`/`zakat_haul_hari` via model).
- `tests/Feature/ZakatPsak109Test.php` baris 788–853 (jalur `route('pengurus.kebijakan.update')`).

**Risiko:** Bukan risiko fungsional (kriteria Z-03 sudah tertutup), namun menyisakan **dua pola uji berbeda** untuk objek yang sama sehingga pemeliharaan kurang konsisten.

**Rekomendasi (terukur):**
1. Migrasikan skenario parametrik di `ZakatHisabKonfigurasiTest` ke jalur HTTP, atau tambahkan komentar penunjuk bahwa jalur HTTP diuji di `ZakatPsak109Test`.
2. **Kriteria penerimaan:** tidak ada lagi penyetelan parameter via model untuk menguji jalur kebijakan.

**✅ Z-11-verifikasi (2026-10-12):**
- **Uji:** `tests/Feature/ZakatHisabKonfigurasiTest.php` ditulis ulang — seluruh penyetelan parameter kini melalui helper `setHisabViaHttp(array $parameter)` yang memanggil `PUT route('pengurus.kebijakan.update')` (sumber kebenaran yang sama dipakai aplikasi), lalu `Setting::flush()`. Helper `payloadKebijakan()` mempertahankan nilai berjalan agar pembaruan sebagian tidak mengosongkan parameter lain.
- **Cakupan:** 12 skenario (default kadar/nishab, bawah nishab, 2,5%, endpoint kalkulator, kadar ubah, haul nonaktif/aktif, fail-closed, panjang haul, endpoint haul) tetap utuh — hanya jalur penyetelannya yang berubah dari model langsung ke HTTP.
- **Uji (lulus):** seluruh 12 test `ZakatHisabKonfigurasiTest` hijau melalui jalur HTTP `pengurus.kebijakan.update`.

---

## 9. Rekomendasi Perbaikan Terukur (Prioritas)

| Prioritas | ID | Perbaikan | Dampak | Kriteria Penerimaan (uji) | Status |
|-----------|----|-----------|--------|---------------------------|--------|
| **P1** | Z-01 | Void/reversal terkontrol + audit log + guard pemisahan tugas | Koreksi berjejak, jejak audit utuh | Baris tidak terhapus, saldo kembali, pembuat≠pembatal, tercatat di audit log | ✅ **Selesai** |
| **P1** | Z-02 | Maker-checker penyaluran + `PemisahanTugas` | Mencegah penyimpangan sepihak | Penyaluran di atas ambang tanpa persetujuan ditolak; pengaju≠penyetuju | ✅ **Selesai** |
| **P1** | Z-03 | Lengkapi validasi + simpan + UI keenam parameter hisab | Tata kelola parameter pulih; zero-hardcode | Simpan via endpoint kebijakan → `Setting` membaca nilai baru | ✅ **Selesai** |
| **P2** | Z-05 | Tambah FK `jurnal_id` & `created_by` | Integritas referensial | Migrasi aman + `validasi()` bersih | ✅ **Selesai** |
| **P2** | Z-04 | Kunci/retry pembentukan `no_referensi` | Ketahanan konkurensi | Nomor tetap unik pada pembuatan cepat | ✅ **Selesai** |
| **P2** | Z-07 | Peringatan realisasi amil > hak amil | Kepatuhan Fatwa DSN-MUI 08 | Peringatan tampil saat terlampaui | ✅ **Selesai** |
| **P2** | Z-09 | Perbaiki logika warna selisih hak amil (terbalik) | Akurasi pembacaan kepatuhan | Hijau saat aman, merah + label saat terlampaui | ✅ **Selesai** |
| **P3** | Z-06 | Paginasi/ekspor buku besar | Kelengkapan penelusuran | Semua baris periode dapat diakses | ✅ **Selesai** |
| **P3** | Z-08 | Whitelist kategori penyaluran infak | Konsistensi pelaporan | Kategori luar daftar ditolak | ✅ **Selesai** |
| **P3** | Z-10 | Tautan menu zakat untuk DPS | Efektivitas pengawasan syariah | Tautan tampil untuk DPS | ✅ **Selesai** |
| **P3** | Z-11 | Migrasikan uji lama ke jalur HTTP | Konsistensi jaminan mutu | Tidak ada penyetelan via model untuk jalur kebijakan | ✅ **Selesai** |

**Catatan pelaksanaan:** Semua perbaikan harus mengikuti pola **P4 "Default Aman"** (kolom/parameter baru nullable/opsional, tidak mengubah perilaku lama) dan **tidak menurunkan baseline pengujian** (saat ini `php artisan test` hijau: **1508 passed, 6831 assertions, 5 skipped, 0 gagal**). Setiap perbaikan wajib disertai uji regresi.

---

## 10. Matriks Risiko

| ID | Kemungkinan | Dampak | Skor Risiko | Level | Status |
|----|-------------|--------|-------------|-------|--------|
| Z-01 | Sedang | Tinggi | 12 | ~~Tinggi~~ | **Tertutup** |
| Z-02 | Sedang | Tinggi | 12 | ~~Tinggi~~ | **Tertutup** |
| Z-03 | Tinggi | Sedang | 12 | ~~Tinggi~~ | **Tertutup** |
| Z-04 | Rendah | Sedang | 6 | ~~Sedang~~ | **Tertutup** |
| Z-05 | Rendah | Sedang | 6 | ~~Sedang~~ | **Tertutup** |
| Z-07 | Sedang | Sedang | 9 | ~~Sedang~~ | **Tertutup** |
| Z-09 | Sedang | Sedang | 9 | ~~Sedang~~ | **Tertutup** |
| Z-06 | Rendah | Rendah | 3 | ~~Rendah~~ | **Tertutup** |
| Z-08 | Rendah | Rendah | 3 | ~~Rendah~~ | **Tertutup** |
| Z-10 | Rendah | Rendah | 3 | ~~Rendah~~ | **Tertutup** |
| Z-11 | Rendah | Rendah | 3 | ~~Rendah~~ | **Tertutup** |

*(Skala 1–5 per dimensi; skor = Kemungkinan × Dampak.)*

---

## 11. Bukti Pendukung

| # | Bukti | Lokasi |
|---|-------|--------|
| E1 | Uji zakat lulus | `php artisan test tests/Feature/ZakatPsak109Test.php tests/Feature/ZakatHisabKonfigurasiTest.php` → **70 passed (292 assertions)** |
| E1b | Baseline penuh hijau | `php artisan test` → **1508 passed (6831 assertions), 5 skipped, 0 gagal** |
| E2 | Perbaikan Z-01 (void) | `2026_10_12_000100_add_void_columns_to_zakat_transaksis.php`; `ZakatService::batalkan()` baris 345–411; `postJurnalPembalik()` baris 594–651 |
| E3 | Perbaikan Z-02 (dual control) | `2026_10_12_000200_add_approval_columns_to_zakat_transaksis.php`; `ZakatService::salurkan()/setujui()/tolak()` baris 189–582 |
| E4 | Perbaikan Z-03 (parameter UI) | `PengurusController` baris 1011–1018 & 1357–1379; `kebijakan.blade.php` baris 871–970; uji `ZakatPsak109Test` baris 788–853 |
| E5 | Retry anti-race `no_referensi` (Z-04) | `app/Services/ZakatService.php` `withRetryReferensi()` baris 926–953, `galatDuplikatReferensi()` baris 956–962; `terima()`/`salurkan()` dibungkus retry |
| E6 | FK `jurnal_id`/`created_by` (Z-05) | `database/migrations/2026_10_12_000400_add_foreign_keys_to_zakat_transaksis.php`; relasi `pencatat()` di `ZakatTransaksi.php` baris 114–117 |
| E7 | Paginasi buku besar (Z-06) | `app/Http/Controllers/ZakatController.php` baris 45–52 (`paginate(50)->withQueryString()`); `resources/views/zakat/index.blade.php` (`->total()` + `->links()`) |
| E8 | Peringatan realisasi hak amil (Z-07) | `ZakatController::laporan()` flag `$hakAmilTerlampaui`; blok `role="alert"` di `laporan.blade.php` |
| E9 | Warna selisih hak amil (Z-09) | `resources/views/zakat/laporan.blade.php` baris 154–186 (`$hakAmilTerlampaui ? '#991b1b' : '#065f46'` + badge) |
| E10 | Tautan menu zakat DPS (Z-10) | `app/Services/NavigationService.php` baris 525–544 (blok "ZAKAT, INFAK & SEDEKAH (READ-ONLY)" pada `menuDPS()`) |
| E11 | Uji jalur HTTP (Z-11) | `tests/Feature/ZakatHisabKonfigurasiTest.php` (`setHisabViaHttp()` + `payloadKebijakan()` via `route('pengurus.kebijakan.update')`) |
| E13 | Whitelist kategori penyaluran (Z-08) | `app/Http/Controllers/ZakatController.php` baris 231 (`Rule::in(array_keys(ZakatTransaksi::KATEGORI_PENYALURAN))`) |
| E12 | RBAC terverifikasi | `routes/web.php` grup `role:pengurus,manajer` (295) & `role:dps` (400); `ZakatPsak109Test` (DPS/teller/AO ditolak) |

---

## 12. Kesimpulan & Opini Audit

1. **Kepatuhan akuntansi (PSAK 109):** **Sesuai.** Pemisahan dana titipan, jurnal berimbang wajib terposting, sumber saldo dari mutasi jurnal, penyajian dalam DIT/CaLK, dan kontrol silang telah terbukti. Tidak ada temuan pada dimensi ini.

2. **Kepatuhan syariah:** **Sesuai** untuk batas 8 asnaf (QS At-Taubah 60). Kontrol realisasi hak amil kini diperkuat: laporan menampilkan **peringatan eksplisit** saat penyaluran ke amil melampaui hak (Z-07) dan indikator warna selisih telah dikoreksi (Z-09). Kedua temuan P2 ini **tertutup**.

3. **Pengendalian internal & tata kelola:** **Sesuai (diperbarui).** Ketiga temuan berisiko Tinggi **telah ditutup** pada perbaikan P1: koreksi berjejak (Z-01), dual control atas penyaluran dana amanah (Z-02), dan tata kelola parameter hisab via antarmuka (Z-03). Pemisahan tugas (maker ≠ checker) kini diterapkan pada pembatalan dan pemutusan penyaluran. Perbaikan P3 menambah: whitelist kategori penyaluran (Z-08) dan tautan menu pengawasan syariah untuk DPS (Z-10). Tidak ada lagi temuan terbuka.

4. **Integritas data & jejak audit:** **Sesuai (diperbarui).** Jejak jurnal kuat dan koreksi berjejak (AuditLog + jurnal pembalik). Integritas referensial kini terjamin melalui FK `jurnal_id`/`created_by` (Z-05, `nullOnDelete`) dan celah konkurensi penomoran ditutup dengan retry anti-race (Z-04). Kelengkapan penelusuran buku besar kini terjamin melalui paginasi (Z-06), dan konsistensi berkas uji dirapikan ke jalur HTTP (Z-11). Tidak ada lagi temuan terbuka.

**Rekomendasi akhir (diperbarui):** Seluruh prioritas **P1** (Z-01, Z-02, Z-03), **P2** (Z-05, Z-04, Z-07, Z-09), dan **P3** (Z-06, Z-08, Z-10, Z-11) **selesai & terverifikasi**. Seluruh 11 temuan audit (Z-01 s.d. Z-11) telah tertutup dengan uji regresi. Baseline pengujian tetap hijau (acuan saat ini: **1508 passed / 0 gagal**). Tidak ada tindak lanjut temuan yang tersisa; pemeliharaan cukup berupa pemantauan berkala atas indikator kepatuhan yang telah tersedia di UI (peringatan hak amil, kelengkapan jejak jurnal).

---

*Dokumen ini disusun berdasarkan inspeksi kode sumber dan pengujian otomatis pada repositori `koperasi_syariah` per 2026-10-12, dan diperbarui pada 2026-10-12 setelah verifikasi perbaikan P1 (Z-01, Z-02, Z-03), P2 (Z-05, Z-04, Z-07, Z-09) dan P3 (Z-06, Z-08, Z-10, Z-11). Seluruh temuan telah diverifikasi terhadap berkas nyata (bukti E1–E13) dan dapat direproduksi.*
