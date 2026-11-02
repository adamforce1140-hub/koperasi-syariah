# TAHAP 12 — Analisis Menyeluruh Modul Simpanan Wajib
## Batasan Sistem & Dampaknya terhadap Kepatuhan PSAK Koperasi Syariah

**Status:** 📋 ANALISIS (Langkah 1 dari 6)
**Tanggal:** 2026-09-30
**Tahap:** 12 — lanjutan langsung Tahap 11 (Fleksibilitas Dinamis)
**Dasar syariah:** PSAK 101 (Penyajian Laporan Keuangan Syariah), PSAK 105 (Mudharabah),
PSAK 106 (Musyarakah), PSAK 107 (Ijarah), PSAK 108 (Transaksi Asuransi Syariah),
PSAK 109 (Zakat, Infak, Sedekah), PSAK 111 (Wakaf) · Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000
· Fatwa DSN-MUI No. 02/DSN-MUI/IV/2000 (Tabungan Wadiah)
**Dasar regulasi:** UU No. 25/1992 tentang Perkoperasian · PP No. 7/2021 (Kemudahan,
Perlindungan, Pemberdayaan KSP) · Permen KUKM No. 09/2018 (KSPPS) · POJK No. 76/2016
**Khalayak:** Pengurus, Manajer, Dewan Pengawas Syariah (DPS), Tim TI, Auditor Internal

---

## 1. Ringkasan Eksekutif

Modul Simpanan Wajib adalah **tulang punggung modal anggota** koperasi syariah. Sistem saat ini
sudah menjalankannya secara otomatis (penagihan bulanan, auto-debit, penandaan tunggakan,
penahanan SHU), namun hasil penelusuran kode menemukan **dua kelas masalah struktural** yang
membuat modul ini **kaku (tidak dapat dikonfigurasi tanpa mengubah kode)** dan **berpotensi tidak
konsisten secara akuntansi**.

| # | Temuan | Klasifikasi | Dampak PSAK |
|---|---|---|---|
| **P1** | **Dua akun CoA berbeda untuk Simpanan Wajib** (`2-1002` Kewajiban vs `3-1002` Ekuitas/Modal Anggota) dipakai bergantian tergantung jalur transaksi | 🔴 **Kritis — Integritas Akuntansi** | PSAK 101 ¶ PSAK 101:31 & KDPPLKS — klasifikasi pos laporan posisi keuangan tidak konsisten; saldo tidak dapat di-nol-kan saat anggota keluar |
| **P2** | **Penjadwalan & parameter operasional ditanam di kode** (tanggal jatuh tempo, tanggal penandaan overdue, periode tagihan selalu bulanan, jadwal otomatis, kebijakan penarikan) | 🟠 **Tinggi — Kekakuan (Zero-Hardcoding)** | PSAK 101 ¶ 45–47 (kebijakan akuntansi harus dapat disesuaikan & diungkapkan); Permen KUKM 09/2018 Pasal 24–26 (kewenangan Pengurus menetapkan ketentuan simpanan) |
| **P3** | **Belum ada jalur penarikan Simpanan Wajib** (tidak ada method HTTP, tidak ada pemanggil `jurnalTarikSimpananWajib()`) | 🟠 **Tinggi — Kelengkapan Siklus** | PSAK 101 — tidak ada mekanisme penghentian pengakuan modal anggota saat anggota keluar (keluar keanggotaan) |

**Kesimpulan Langkah 1:** Kedua permasalahan **berakar pada satu akar yang sama** — modul Simpanan
Wajib belum mengikuti pola *Zero-Hardcoding* yang sudah dibangun pada Tahap 11 (`App\Support\Setting`
+ `App\Services\CoaResolver` + `pemetaan_akad_coa`). Perbaikan harus dilakukan dengan **mengadopsi
pola yang sudah ada**, bukan menciptakan mekanisme baru.

---

## 2. Permasalahan 1 — Dua Akun CoA untuk Satu Jenis Simpanan

### 2.1 Bukti Kode (Ground Truth)

Ada **DUA konstanta** di `App\Services\AutoJournalingService` yang keduanya diberi nama
"Simpanan Wajib" dengan makna akuntansi yang berbeda:

```php
// app/Support/Coa.php
public const SIMPANAN_WAJIB        = '2-1002';   // Kewajiban Jangka Pendek
public const SIMPANAN_WAJIB_MODAL  = '3-1002';   // Ekuitas — Modal Anggota
```

```php
// app/Services/AutoJournalingService.php
const SIMPANAN_WAJIB        = \App\Support\Coa::SIMPANAN_WAJIB;        // baris 36  → 2-1002
const SIMPANAN_WAJIB_MODAL  = \App\Support\Coa::SIMPANAN_WAJIB_MODAL;  // baris 139 → 3-1002
```

**Jalur transaksi yang memakai `2-1002` (Kewajiban):**

| Lokasi | Konteks | Pemanggil |
|---|---|---|
| `AutoJournalingService::jurnalSetorSimpananWajib()` baris 639 | DEBET 1-1001 / **KREDIT 2-1002** | `TransactionLimitService:277`, `DemoDataSeeder:315`, `SimulasiTransaksiHariIni:397` |
| `AutoJournalingService::jurnalTarikSimpananWajib()` baris 793 | **DEBET 2-1002** / KREDIT 1-1001 | ⚠️ **TIDAK ADA PEMANGGIL** (dead code) |
| `AutoJournalingService::jurnalSetorSimpanan()` baris 689 (fallback) | DEBET 1-1001 / **KREDIT 2-1002** | `CifController:735` bila `jenis_simpanans.kode_akun` kosong |
| `Api\DepositoController:188` | Daftar akun untuk API | — |
| `database/seeders/JenisSimpananSeeder.php:36` | Master produk `jenis_simpanans.kode_akun` | — |
| `database/seeders/DemoDataSeeder.php:289–317` | Data demo | — |

**Jalur transaksi yang memakai `3-1002` (Ekuitas — Modal Anggota):**

| Lokasi | Konteks | Pemanggil |
|---|---|---|
| `AutoJournalingService::jurnalSimpananWajibTeller()` baris 712 | DEBET 1-1001 / **KREDIT 3-1002** | `MandatorySavingsService::bayarViaTeller()` (loket Teller) |
| `AutoJournalingService::jurnalSimpananWajibAutoDebit()` baris 782 | DEBET 2-1001 / **KREDIT 3-1002** | `MandatorySavingsService::autoDebit()` (scheduler) |
| `AutoJournalingService::jurnalBundlingSetoranAwal()` baris 750 | **KREDIT 3-1002** porsi wajib | `CifController:227` (setoran awal pendaftaran) |
| `CifController:223` | Prasyarat CoA saat daftar CIF | — |
| `DepositoProduct:33` | Pemetaan default tipe produk | — |
| `database/seeders/AdamRenataSeeder.php:50` | Data demo | — |

### 2.2 Analisis Dampak Akuntansi (PSAK)

**a) Pelanggaran konsistensi klasifikasi (PSAK 101 ¶ 31 & KDPPLKS)**

PSAK 101 mengadopsi kerangka konseptual pelaporan syariah: pos-pos harus diklasifikasikan secara
**konsisten antarperiode**. Saat ini, satu anggota dapat memiliki saldo Simpanan Wajib yang
**terpecah di dua pos** laporan posisi keuangan:

- Setoran bulanan via **auto-debit/teller/bundling** → menambah **Ekuitas (3-1002)**
- Setoran yang disetujui lewat **`TransactionLimitService`** (jalur approval) → menambah **Kewajiban (2-1002)**

Konsekuensi: **total modal anggota pada laporan posisi keuangan UNDERSTATED** untuk anggota yang
menyetor lewat jalur approval, dan **rasio kewajiban terhadap ekuitas menjadi bias**. Ini bukan
sekadar salah tampil — ia mengubah angka yang dipakai untuk menghitung **porsi SHU jasa modal**
(`ShuCalculatorService`) dan **rasio kesehatan koperasi**.

**b) Saldo tidak dapat dinolkan saat anggota keluar**

`jurnalTarikSimpananWajib()` (penarikan/penutupan) mendebit **`2-1002`**, sementara mayoritas
setoran mengkredit **`3-1002`**. Ketika anggota keluar:

```
DEBET  2-1002   → saldo 2-1002 menjadi MINUS (tidak pernah dikredit jalur ini)
KREDIT 1-1001   → kas berkurang (benar)
```

Saldo **`3-1002` tetap menggantung** di ekuitas. Secara PSAK 101, ini berarti **penghentian
pengakuan modal anggota tidak pernah terjadi** — anggota yang sudah keluar masih tercatat sebagai
pemilik modal. Inilah bukti paling keras bahwa inkonsistensi ini adalah **risiko kepatuhan**, bukan
sekadar preferensi desain. *(`ReversalService::kodeSimpananUntukJenis()` baris 335–360 sudah
mendokumentasikan inkonsistensi ini secara eksplisit sebagai "PENTING (konsistensi akun)".)*

**c) Ambiguitas pengakuan pendapatan/kerugian revaluasi**

Bila kelak Pengurus memindahkan akun, tidak ada satu titik kendali. Pemindahan harus dilakukan di
**6+ lokasi kode** + 2 seeder — mustahil dilakukan DPS/Pengurus tanpa Tim TI.

### 2.3 Batasan Sistem yang Menyebabkan Kekakuan

1. **Tidak ada satu sumber kebenaran (single source of truth).** Akun Simpanan Wajib ditentukan
   oleh **konstanta kelas di dalam kode**, bukan oleh data. `App\Support\Setting::DESKRIPSI` bahkan
   **belum memiliki** entri `account_simpanan_wajib_id` — pemetaan akun simpanan hanya tersedia untuk
   Simpanan Sukarela (`account_simpanan_sukarela_id`, baris 213).
2. **Master produk `jenis_simpanans` sudah ada tetapi DIABAIKAN.** `JenisSimpanan::coaKode('Simpanan Wajib')`
   → `2-1002` (via `FALLBACK` baris 61), sedangkan `MandatorySavingsService` **melewati** resolver ini
   dan memakai konstanta `COA_SIMPANAN_WAJIB_MODAL` langsung (baris 39). Sistem punya resolver
   dinamis yang tidak dipakai oleh modul yang paling membutuhkannya.
3. **`CoaResolver` hanya melayani modul Pencairan.** Doc-block kelasnya menyatakan lingkupnya
   "modul Pencairan Pembiayaan" — tidak mencakup akun simpanan/kewajiban.
4. **Komentar seeder mengabadikan inkonsistensi.** `CoaSeeder:35` dan `JenisSimpananSeeder:16`
   menuliskan `2-1002` sebagai akun operasional seolah final, tanpa mekanisme rekonsiliasi.
5. **Tidak ada validasi lintas-jalur.** Tidak ada test yang memverifikasi bahwa **semua jalur
   setoran Simpanan Wajib mendarat di akun yang sama**.

---

## 3. Permasalahan 2 — Penjadwalan & Parameter Operasional Ditahan di Kode

### 3.1 Inventaris Hardcode (Ground Truth)

| # | Lokasi (baris) | Nilai yang ditanam | Seharusnya |
|---|---|---|---|
| H1 | `MandatorySavingsService:189` | `min(10, ...)` — tanggal 10 sebagai batas penandaan OVERDUE | ~~Parameter `simpanan_wajib_overdue_day`~~ → **diselesaikan 2026-10-13: tanggal jatuh tempo dibuat otomatis per anggota** (`tanggalJatuhTempo()`), parameter dihapus menyeluruh |
| H2 | `MandatorySavingsBill::periodDari()` | `format('Y-m')` — periode **selalu bulanan** | Parameter `simpanan_wajib_periode` (`BULANAN`/`MINGGUAN`/`TRIWULANAN`) |
| H3 | `MandatorySavingsService:tanggalJatuhTempo():252` | `isEndOfMonth()` — perlakuan anggota bergabung tgl 29/30/31 | Parameter `simpanan_wajib_eom_strategy` |
| H4 | `MandatorySavingsService:257–260` | `min($mulai->day, $hariMaksimal)` — clamp ke hari terakhir bulan | Parameter `simpanan_wajib_clamp_strategy` |
| H5 | `Anggota::is_auto_debit` (boolean per anggota) | Pemilihan kanal **hanya per-anggota**, tidak dapat ditetapkan sebagai kebijakan kolektif | Parameter `simpanan_wajib_kanal_default` |
| H6 | `SimpananWajibController::bayar()` validasi `in:CASH,TRANSFER` | Kanal pelunasan loket dibatasi 2 metode | Parameter `simpanan_wajib_metode_loket` |
| H7 | `MandatorySavingsService:39` | `COA_SIMPANAN_WAJIB_MODAL` — akun ditanam (lihat Permasalahan 1) | Parameter `account_simpanan_wajib_id` |
| H8 | `MandatorySavingsService:36` | `COA_SIMPANAN_SUKARELA` — akun sumber auto-debit ditanam | Parameter `account_simpanan_sukarela_id` **(sudah ada di Setting, belum dipakai!)** — baris 182 |
| H9 | `MandatorySavingsService:42` | `COA_KAS_TELLER` — akun kas ditanam | Parameter `account_kas_teller_id` **(sudah ada di Setting, belum dipakai!)** — baris 171 |
| H10 | Tidak ada method penarikan sama sekali | Anggota keluar / penutupan rekening tidak punya jalur | Fitur penarikan + kebijakan `simpanan_wajib_boleh_ditarik` |
| H11 | `KebijakanKoperasi::$fillable` | Tidak ada kolom untuk parameter di atas | Kolom baru di `kebijakan_koperasis` |

> **Catatan penting:** H8 dan H9 adalah temuan yang sangat menggembirakan — **infrastruktur
> konfigurasi akun sudah ada** (`account_simpanan_sukarela_id`, `account_kas_teller_id` beserta
> deskripsinya di `Setting::DESKRIPSI` baris 202 & 213), tetapi `MandatorySavingsService` **masih
> memakai konstanta**. Artinya perbaikan Permasalahan 1 **tidak memerlukan skema baru** — cukup
> menyambungkan modul ke infrastruktur yang sudah terbukti pada Tahap 11.

### 3.2 Analisis Dampak Kepatuhan

**a) PSAK 101 ¶ 45–47 — Kebijakan akuntansi & pengungkapannya**

Standar mensyaratkan entitas mengungkapkan **kebijakan akuntansi** (dasar pengukuran, metode
pengakuan) dan bahwa kebijakan tersebut **diterapkan secara konsisten**. Bila tanggal jatuh tempo,
periode penagihan, dan akun tujuan ditanam di kode:

- Pengurus **tidak dapat** menyesuaikan periode penagihan (mis. dari bulanan → mingguan) ketika
  Rapat Anggota memutuskan perubahan — padahal kewenangan ini ada di Pengurus (UU 25/1992 Pasal 30,
  Permen KUKM 09/2018 Pasal 24).
- **Nota kalibrasi: Periode tagihan yang berbeda = dasar pengakuan pendapatan/penerimaan yang
  berbeda** → penyajian arus kas & ekuitas antarperiode menjadi tidak dapat dibandingkan.

**b) Sharia compliance — kepastian akad**

Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 menetapkan sanksi atas anggota mampu yang menunda pembayaran
**harus berbentuk denda yang masuk ke Dana Sosial**, bukan pendapatan koperasi. Sistem sudah benar
(ta'zir → Dana Kebajikan `2-3001`). Namun **tanggal kapan tunggakan diakui** (`H1`, tanggal 10)
adalah **titik penentuan** apakah sanksi ta'zir sah dikenakan. Tanggal ini **wajib dapat
ditetapkan Pengurus dan diungkapkan** ke anggota di **akad Simpanan Wajib**.

**c) Permen KUKM No. 09/2018 — kewenangan Pengurus**

Pasal 24–26 menetapkan bahwa **besaran dan ketentuan simpanan wajib ditetapkan oleh Pengurus**
(dalam batas anggaran dasar), bukan oleh penyedia sistem. Saat ini yang dinamis baru
**nominalnya** (`mandatory_monthly_savings`, sudah dapat diubah dari UI). **Ketentuannya**
(jatuh tempo, periode, cara bayar, boleh/tidak ditarik) **masih kaku** → sistem belum sepenuhnya
mewakili kewenangan Pengurus.

### 3.3 Batasan Sistem yang Menyebabkan Kekakuan

1. **Parameter operasional tidak dimodelkan sebagai data.** Hanya *nominal* yang dinamis;
   *ketentuan* (rule kebijakan) masih kode.
2. **Periode tagihan terkunci pada bulanan** oleh `periodDari()` di **level model**, sehingga
   seluruh sistem (index unik `member_id + bill_period`, kolom `bill_period VARCHAR(7)`,
   `getNamaPeriodeAttribute()`) mengasumsikan panjang periode 7 karakter `YYYY-MM`.
3. **Belum ada UI untuk pengaturan Simpanan Wajib.** `resources/views/pengurus/kebijakan.blade.php`
   hanya menyediakan **dua input** terkait Simpanan Wajib: `mandatory_principal_savings` (baris 62)
   dan `mandatory_monthly_savings` (baris 77) + satu checkbox `shu_hold_on_overdue` (baris 104).
   Tidak ada panel untuk jadwal, kanal, atau akun tujuan.
4. **Belum ada audit trail kunci untuk parameter ini.** Tahap 11 sudah membangun pola audit
   konfigurasi; parameter Simpanan Wajib baru harus mengikutinya agar kepatuhan **dapat diaudit DPS**.

---

## 4. Peta Keterkaitan: Mengapa Kedua Masalah Harus Diselesaikan Bersamaan

```
                  ┌──────────────────────────────────────────────┐
                  │  BUKU BESAR SIMPANAN WAJIB (3-1002 Ekuitas)  │
                  └──────────────────────────────────────────────┘
                       ▲                        ▲
        (jalur benar)  │                        │  (jalur SALAH)
                       │                        │
   ┌───────────────────┴──────────┐   ┌─────────┴──────────────────┐
   │ Auto-debit / Teller /        │   │ TransactionLimitService    │
   │ Bundling CIF / Approval      │   │ (approval jurnal manual)   │
   │  → 3-1002 ✅                 │   │  → 2-1002 ❌ (Kewajiban)   │
   └──────────────────────────────┘   └────────────────────────────┘
              ▲                                     ▲
              └──────────┬──────────────────────────┘
                         │
        ╔════════════════┴════════════════════════════════════════╗
        ║  AKAR MASALAH: akun ditentukan KONSTANTA KODE,          ║
        ║  bukan DATA yang dikelola Pengurus.                     ║
        ║  → Tidak ada satu titik untuk mengubahnya (kaku)        ║
        ║  → Tidak ada validasi bahwa semua jalur konsisten (P1)  ║
        ╚═════════════════════════════════════════════════════════╝
```

**Kesimpulan keterkaitan:** Memperbaiki P1 (menyatukan akun) **tanpa** memperbaiki P2 (pola
konfigurasi) hanya memindahkan titik keras dari konstanta ke konstanta lain — akan muncul masalah
yang sama pada perubahan aturan berikutnya. Sebaliknya, memperbaiki P2 **tanpa** menyelesaikan P1
akan menyediakan panel yang mengonfigurasi akun, sementara jalur approval tetap menulis ke akun
lain. Karena itu keduanya **wajib diselesaikan dalam satu rangkaian tahap** dengan urutan:
**P2-infrastruktur dahulu → P1-penyatuan → P3-penarikan → validasi → pengujian**.

---

## 5. Matriks Risiko & Dampak

| Temuan | Probabilitas | Dampak | Risiko | Prioritas |
|---|---|---|---|---|
| P1 — Akun ganda (`2-1002` vs `3-1002`) | **Sudah terjadi** (realtime) | Laporan posisi keuangan salah saji; SHU jasa modal bias; saldo tidak dapat dinolkan | 🔴 Kritis | **1** |
| P2-H1 — Tanggal overdue kaku (tgl 10) | Tinggi (setiap perubahan AD/ART) | Sanksi ta'zir tidak sah; pelanggaran akad | 🟠 Tinggi | **2** |
| P2-H2 — Periode selalu bulanan | Sedang | Sistem menolak keputusan Rapat Anggota | 🟠 Tinggi | **3** |
| P3 — Tidak ada penarikan | Tinggi (setiap anggota keluar) | Modal anggota menggantung di ekuitas | 🟠 Tinggi | **4** |
| P2-H3/H4 — EOM & clamp kaku | Rendah | Ketidakadilan perlakuan anggota tgl 29–31 | 🟡 Sedang | **5** |
| P2-H5/H6 — Kanal kaku | Sedang | Operasional loket tidak fleksibel | 🟡 Sedang | **6** |

---

## 6. Ruang Lingkup & Batasan Analisis (Out of Scope)

Agar fokus dan tidak mengganggu operasional berjalan (amanat Langkah 2), analisis ini
**tidak menyentuh**:

- **Perubahan numerik pada jurnal yang sudah diposting.** Sesuai jaminan Tahap 11, jurnal historis
  **tidak boleh diubah**. Rekonsiliasi selisih jalur approval dilakukan melalui **jurnal penyesuaian
  yang disetujui Pengurus**, bukan UPDATE data historis.
- **Perubahan skema `mandatory_savings_bills.bill_period`.** Kolom tetap `VARCHAR(7)`; fleksibilitas
  periode dilakukan dengan **strategi penyimpanan yang kompatibel** (bukan mengubah tipe kolom),
  sehingga sistem berjalan tetap aman tanpa migrasi merusak.
- **Modul pembiayaan, deposito, dan SHU.** Hanya **titik sentuh** yang dipakai bersama
  (mis. `ShuCalculatorService::lepasPenahananAnggota()`) yang diperiksa, tanpa diubah.

---

## 7. Kesimpulan Langkah 1

1. Modul Simpanan Wajib **secara fungsional sudah berjalan** (penagihan, auto-debit, tunggakan,
   penahanan SHU) dan **secara konsep sudah memakai PSAK 101/108**.
2. Namun modul ini **belum mengikuti pola Zero-Hardcoding Tahap 11**: akun CoA, jadwal, dan
   ketentuan operasional masih ditanam di kode → **sistem kaku** dan **berpotensi salah saji**.
3. **Terdapat DUA permasalahan** yang harus diselesaikan, dengan **6 titik kritis** (P1, P2-H1…H6,
   P3) dan **11 inventaris hardcode** (H1–H11).
4. Infrastruktur untuk perbaikan **sudah tersedia dan terbukti** (`Setting`, `CoaResolver`,
   `jenis_simpanans`, pola audit Tahap 11) → perbaikan bersifat **menyambungkan & menyatukan**,
   bukan membangun ulang → **risiko terhadap operasional berjalan rendah**.
5. Langkah 2 menyusun roadmap bertahap dengan urutan: **infrastruktur konfigurasi → penyatuan akun
   → penarikan → validasi PSAK → pengujian → dokumentasi**, dengan setiap tahap **dapat
   di-deploy sendiri (incremental)** dan **dapat digulirkan (rollback) sendiri**.

---

*Dokumen ini adalah Langkah 1 dari 6. Langkah 2 (Roadmap) dibahas pada
`docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md`.*
