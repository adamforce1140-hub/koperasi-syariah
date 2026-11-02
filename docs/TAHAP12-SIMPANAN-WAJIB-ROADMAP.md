# TAHAP 12 — Roadmap Implementasi Bertahap Modul Simpanan Wajib
## Milestone Terukur, Inkremental, Tanpa Mengganggu Operasional Berjalan

**Status:** 📋 ROADMAP (Langkah 2 dari 6) — **pelaksanaan R1–R6 telah dijalankan**
**Tanggal:** 2026-09-30
**Rujukan analisis:** `docs/TAHAP12-SIMPANAN-WAJIB-ANALISIS.md` (Langkah 1)
**Prinsip pelaksanaan:** *Incremental, Reversible, Non-Disruptive*

---

## 0. Status Pelaksanaan (Diperbarui 2026-09-30)

| Tahap | Status | Dokumen Bukti |
|---|---|---|
| **R1** — Infrastruktur Konfigurasi Dinamis | ✅ SELESAI | `docs/TAHAP12-R1-INFRASTRUKTUR-KONFIGURASI.md` |
| **R2** — Penyatuan Akun CoA Simpanan Wajib | 🟡 SELESAI PARSIAL (resolusi tunggal ✅; reklasifikasi saldo legacy ⏳ menunggu keputusan Pengurus/DPS) | `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` |
| **R3** — Siklus Penarikan Simpanan Wajib | ✅ SELESAI, lalu **DIKUNCI PERMANEN** — alur penarikan (service+rute+test) sudah diimplementasi, namun dibekukan total per PSAK 101 ¶31 (Simpanan Wajib = modal anggota); satu-satunya jalur sah = `member_terminations` | `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md` §2.2 + `tests/Feature/SimpananWajibPenarikanTest.php` |
| **R4** — Mekanisme Validasi Akuntansi PSAK | ✅ SELESAI | `docs/TAHAP12-R4-VALIDASI-AKUNTANSI-PSAK.md` |
| **R5** — Pengujian Fungsional &amp; Kepatuhan | ✅ SELESAI | `docs/TAHAP12-R5-PENGUJIAN-FUNGSIONAL-KEPATUHAN.md` |
| **R6** — Dokumentasi &amp; Bukti Kepatuhan | ✅ SELESAI | `docs/TAHAP12-R6-DOKUMENTASI-KEPATUHAN.md` + `docs/PANDUAN-SIMPANAN-WAJIB.md` |

**Catatan penamaan:** perintah gerbang kepatuhan direncanakan sebagai
`simpanan-wajib:audit-kepatuhan`, namun diimplementasikan sebagai
**`psak:validasi-simpanan-wajib`** agar konsisten dengan prefiks perintah kepatuhan lain.

### 0.1 Status Permasalahan 2 — Kesiapan Produksi

| Aspek | Status | Bukti |
|---|---|---|
| Regresi penuh (gerbang G1+G3) | ✅ HIJAU — **1280 passed (5585 assertions)**, exit code 0 (baseline terkini pasca-Gelombang 3) | `scripts\run-tests.ps1` |
| Audit kepatuhan PSAK (gerbang G3) | ✅ LULUS — 7 kategori = 0, exit code 0 | `php artisan psak:validasi-simpanan-wajib` |
| Panduan deploy produksi | ✅ Diperbarui (status akurat, variabel keamanan, gerbang kepatuhan, seeder aman, pembuatan akun, lembar tanda tangan) | `docs/DEPLOY-PRODUKSI.md` |
| Prasyarat go-live (keputusan Pengurus/DPS) | ⏳ MENUNGGU — reklasifikasi saldo legacy `2-1002` (keputusan akuntansi). **Status formal alur penarikan sudah tidak relevan**: alur penarikan rutin **dikunci permanen** (PSAK 101 ¶31), bukan sekadar "tertutup menunggu saklar" | `docs/DEPLOY-PRODUKSI.md` §0.1 |

> **Catatan kejujuran:** deploy ke server produksi **belum dijalankan** karena
> memerlukan akses ke server target (VPS/cPanel) yang tidak tersedia di
> lingkungan ini. Yang telah diselesaikan adalah **kesiapan rilis**: dokumen
> panduan akurat, gerbang kepatuhan otomatis terpasang, dan **satu** prasyarat
> keputusan yang harus disetujui Pengurus/DPS sebelum go-live (reklasifikasi
> saldo legacy `2-1002`).

---

## 1. Prinsip & Aturan Main Roadmap

| Prinsip | Penerapan Konkret |
|---|---|
| **P1 — Incremental (bertahap)** | Setiap tahap (R1–R6) **dapat di-deploy sendiri** dan meninggalkan sistem dalam kondisi **konsisten & berjalan**. Tidak ada tahap yang "setengah jalan". |
| **P2 — Reversible (dapat digulirkan)** | Setiap tahap **tidak mengubah data historis** dan **tidak mengubah perilaku jurnal yang sudah berjalan**. Rollback cukup mengganti kode + menghapus baris konfigurasi baru. |
| **P3 — Non-Disruptive (tanpa henti operasi)** | Tidak ada *maintenance window*. Scheduler `mandatory-savings:process-daily` tidak dihentikan. Migrasi hanya **menambah kolom/tabel** (additive), tidak mengubah atau menghapus kolom lama. |
| **P4 — Default Aman (safe default)** | Setiap parameter baru **default = perilaku sistem saat ini**. Bila parameter kosong/belum diisi, sistem berjalan **persis seperti sebelum perubahan**. Ini jaminan utama "tidak mengganggu operasional". |
| **P5 — Satu Sumber Kebenaran** | Setelah seluruh tahap selesai, akun Simpanan Wajib ditentukan **hanya** oleh satu titik resolusi. Konstanta `App\Support\Coa` tetap dipertahankan sebagai **registri standar** (jaring pengaman), mengikuti pola Tahap 11. |
| **P6 — Teruji setiap tahap** | Setiap tahap wajib lolos **3 gerbang**: (a) `scripts\run-tests.ps1` hijau, (b) test baru khusus tahap tersebut, (c) pemeriksaan kepatuhan PSAK. |

---

## 2. Peta Tahap (Overview)

```
                 TAHAP 12 — ROADMAP SIMPANAN WAJIB
                 
  R1 ──► R2 ──► R3 ──► R4 ──► R5 ──► R6
   │      │      │      │      │      │
   │      │      │      │      │      └─ Dokumentasi & bukti kepatuhan
   │      │      │      │      └──────── Pengujian fungsional + kepatuhan
   │      │      │      └─────────────── Validasi akuntansi PSAK
   │      │      └────────────────────── Siklus penarikan (P3)
   │      └───────────────────────────── Penyatuan akun CoA (P1)
   └──────────────────────────────────── Infrastruktur konfigurasi (P2)

  Setiap blok = 1 deploy mandiri. Tidak ada ketergantungan mundur.
```

---

## 3. Rincian Tahap & Milestone

### R1 — Infrastruktur Konfigurasi Dinamis
**Permasalahan yang disasar:** P2 (H1–H9, H11)
**Dasar PSAK:** PSAK 101 ¶ 45–47 (kebijakan akuntansi dapat ditetapkan & diungkapkan)
**Risiko:** 🟢 Rendah — aditif murni, default = perilaku sekarang

#### Ruang Lingkup
1. **Migrasi aditif** penambahan kolom pada `kebijakan_koperasis`:
   - ~~`simpanan_wajib_overdue_day` (integer, default 10)~~ → **dibatalkan 2026-10-13**: jatuh tempo dibuat **otomatis per anggota**, kolom dihapus kembali
   - `simpanan_wajib_kanal_default` (string, default `AUTO_DEBIT`)
   - `simpanan_wajib_boleh_ditarik` (boolean, default false)
   - `simpanan_wajib_hari_penarikan` (string, default `SETELAH_KELUAR`)
   - `account_simpanan_wajib_id` (unsignedBigInteger nullable)
   - `account_simpanan_sukarela_id` — **sudah ada** di Setting/DESKRIPSI; pastikan kolomnya ada
   - `account_kas_teller_id` — idem
2. **Registrasi parameter** di `App\Support\Setting`: tambahkan ke `DEFAULTS`, `TIPE`, `DESKRIPSI`.
3. **Accessor** di `Setting`: `simpananWajibKanalDefault()`,
   `simpananWajibBolehDitarik()`, `simpananWajibAkunId()`.
4. **Delegasi** di `KebijakanKoperasi` (mengikuti pola `simpananWajibBulanan()`).
5. **Penambahan `$fillable`** di `KebijakanKoperasi` + whitelist `AdminSettingsController`.
6. **UI Pengurus**: panel baru **"Ketentuan Simpanan Wajib"** di `pengurus/kebijakan.blade.php`
   (kanal default, kebijakan penarikan, akun tujuan) + validasi. *(Jadwal jatuh tempo dihapus
   2026-10-13 — kini otomatis per anggota.)*
7. **Audit trail**: seluruh perubahan parameter tercatat (pola Tahap 11).

#### Milestone Terukur
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M1.1** | Kolom baru tersedia | `php artisan migrate` sukses; `Schema::hasColumn` benar untuk kolom baru |
| **M1.2** | Parameter terbaca | `Setting::simpananWajibKanalDefault()` → `AUTO_DEBIT`; `Setting::get('simpanan_wajib_kanal_default')` tidak `null` |
| **M1.3** | Default aman | Dengan kolom **NULL**, seluruh method mengembalikan nilai default = perilaku lama |
| **M1.4** | UI berfungsi | Halaman `pengurus.kebijakan.index` menampilkan panel baru; submit → tersimpan; validasi menolak nilai tak sah |
| **M1.5** | Audit tercatat | Perubahan parameter menghasilkan entri audit (siapa, kapan, lama → baru) |
| **M1.6** | Regresi nihil | `scripts\run-tests.ps1` **seluruhnya hijau** (0 failure) |

**Bukti:** `docs/TAHAP12-R1-INFRASTRUKTUR-KONFIGURASI.md`

---

### R2 — Penyatuan Akun CoA Simpanan Wajib
**Permasalahan yang disasar:** P1 (H7–H9)
**Dasar PSAK:** PSAK 101 ¶ 31 (konsistensi klasifikasi pos)
**Risiko:** 🔴 **Tinggi** — menyentuh inti posting jurnal → **wajib paling hati-hati**

#### Strategi Penyatuan (Aman & Bertahap)
1. **Titik resolusi tunggal.** Buat method di `MandatorySavingsService`:
   `akunSimpananWajib(): string` yang meresolusi dengan rantai:
   > `Setting::akunId('account_simpanan_wajib_id')` → `JenisSimpanan::coaKode('Simpanan Wajib')`
   > → registri standar `App\Support\Coa::SIMPANAN_WAJIB_MODAL` (`3-1002`)
2. **Semua jalur memakai titik resolusi ini** — termasuk `jurnalSetorSimpananWajib()` dan
   `jurnalTarikSimpananWajib()` (yang saat ini `2-1002`) **dan** `TransactionLimitService:277`.
3. **Pilihan akun kanonik:** `3-1002` (Ekuitas — Modal Anggota), sesuai **PSAK 101** dan konsisten
   dengan mayoritas jalur (auto-debit, teller, bundling CIF, master `jenis_simpanans` seharusnya
   diarahkan ke sini) serta penegasan `CoaSeeder:94–99`.
4. **Rekonsiliasi saldo lama (TANPA mengubah jurnal historis).** Saldo mengendap di `2-1002`
   direklasifikasi melalui **jurnal penyesuaian** yang disetujui Pengurus:
   > `DEBET 2-1002` → `KREDIT 3-1002`
   Dilakukan **sekali**, melalui **fitur reklasifikasi dengan pratinjau + persetujuan**, bukan
   skrip ad-hoc, dengan audit trail lengkap.
5. **Perbaikan `ReversalService::kodeSimpananUntukJenis()`** agar tidak lagi bergantung pada
   heuristik jalur (`dariTagihanBerjadwal`) — cukup memanggil satu titik resolusi.

#### Milestone Terukur
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M2.1** | Resolver tunggal ada | `akunSimpananWajib()` mengembalikan `3-1002` pada default; mengikuti `Setting` bila diisi |
| **M2.2** | Semua jalur konsisten | **Test lintas-jalur**: setor via auto-debit, teller, bundling CIF, dan approval → **keempatnya KREDIT akun yang SAMA** |
| **M2.3** | Penarikan menolkan saldo | DEBET jalur penarikan menyentuh akun yang **sama** dengan jalur setoran → saldo kembali **0,00** setelah penuh |
| **M2.4** | Reversal konsisten | `ReversalService` membalik ke akun asal untuk **setiap** jalur |
| **M2.5** | Reklasifikasi aman | Pratinjau `2-1002 → 3-1002` menghasilkan jurnal **seimbang**; setelah posting, saldo `2-1002` = `0,00` |
| **M2.6** | Jurnal historis utuh | Jumlah & nilai jurnal sebelum vs sesudah **identik** (kecuali jurnal penyesuaian baru) |
| **M2.7** | Regresi nihil | `scripts\run-tests.ps1` hijau, **termasuk** `JenisSimpananParameterTest` & `TellerSimpananWajibTest` |

**Bukti:** `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md`

---

### R3 — Siklus Penarikan Simpanan Wajib (Kelengkapan PSAK 101)
**Permasalahan yang disasar:** P3 (H10)
**Dasar PSAK:** PSAK 101 (penghentian pengakuan modal anggota); UU 25/1992 (anggota keluar)
**Risiko:** 🟠 Sedang — fitur **baru**, tidak menyentuh alur lama

#### Ruang Lingkup (✅ terlaksana)
1. **Service**: `tarikSimpananWajib(Anggota, ?float $nominal, string $metode, ?Carbon, ?int $userId): array`
   dengan guard kebijakan `simpanan_wajib_boleh_ditarik` via `alasanPenarikanDitolak()`.
2. **Jurnal** memakai `jurnalTarikSimpananWajib()` yang **sudah ada** — memakai titik resolusi
   tunggal R2 (akun kanonik `3-1002`).
3. **Kondisi penarikan** yang divalidasi: (a) anggota berstatus `Keluar`, atau (b) kebijakan
   `KAPAN_SAJA` mengizinkan penarikan kapan saja — parameter `simpanan_wajib_hari_penarikan`.
4. **HTTP layer**: `SimpananWajibController::tarik()` + rute
   `POST /teller/simpanan-wajib/{anggota}/tarik`. **Catatan pelaksanaan:** rute **tidak** memakai
   middleware `anggota.aktif` (middleware itu menolak anggota non-`ACTIVE`, padahal justru anggota
   berstatus **Keluar** yang menjadi sasaran penarikan); gerbang status ditegakkan di service.
5. **Idempotensi**: penarikan kedua atas saldo `0` **ditolak** (tanpa jurnal baru).
6. **Kanal pembayaran keluar**: tunai (`CASH`) atau transfer bank (`TRANSFER`) — keduanya
   mengkredit **Kas Teller / Bank** (`1-1001`). *Pindah buku langsung ke Simpanan Sukarela
   (`account_simpanan_sukarela_id`) tidak diimplementasikan pada R3;* jalur ini tetap dapat
   dilakukan teller sebagai dua transaksi terpisah (tarik tunai → setor sukarela) dan dapat
   ditambahkan kemudian tanpa mengubah kode inti.

#### Milestone Terukur (✅ terpenuhi)
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M3.1** | Guard kebijakan | Bila `simpanan_wajib_boleh_ditarik = false` → penarikan **ditolak** dengan pesan jelas |
| **M3.2** | Jurnal benar | DEBET akun Simpanan Wajib / KREDIT 1-1001, `is_balance = true` |
| **M3.3** | Saldo kembali nol | Setelah penarikan penuh, saldo Simpanan Wajib anggota = `0,00` |
| **M3.4** | Idempotensi | Penarikan kedua → **ditolak**, **tidak ada** jurnal baru |
| **M3.5** | Kanal tunai &amp; non-tunai | Penarikan tunai dan transfer bank keduanya memakai akun kanonik; metode divalidasi `in:CASH,TRANSFER` |
| **M3.6** | Hak akses | Role `anggota` **tidak boleh** mengakses rute penarikan |
| **M3.7** | Regresi nihil | `scripts\run-tests.ps1` hijau — **1135 passed (5177 assertions)** |

**Bukti:** `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md` (✅ SELESAI &amp; TERVERIFIKASI)

---

### R4 — Mekanisme Validasi Akuntansi PSAK
**Permasalahan yang disasar:** jaminan kepatuhan berkelanjutan (Langkah 4)
**Dasar PSAK:** PSAK 101 ¶ 31 & 45–47; Fatwa DSN-MUI 17/2000 (ta'zir → dana sosial)
**Risiko:** 🟢 Rendah — lapisan **pemeriksa**, tidak mengubah alur transaksi

#### Ruang Lingkup
1. **Validator lintas-jalur** (`SimpananWajibComplianceService`):
   - **V1 — Keseragaman akun**: seluruh jurnal bersumber `Setoran Simpanan Wajib*` /
     `Simpanan Wajib Auto-Debit` wajib mengkredit **satu akun** yang sama.
   - **V2 — Keseimbangan**: setiap jurnal Simpanan Wajib wajib `is_balance = true` (memanfaatkan
     `AutoJournalingService::pastikanBalance()` yang sudah ada).
   - **V3 — Kelengkapan tagihan**: setiap tagihan `PAID` wajib punya `transaksi_id` **dan**
     `jurnal_id` tidak null (double-entry lengkap).
   - **V4 — Konsistensi nominal**: nominal tagihan = nominal baris jurnal.
   - **V5 — Kepatuhan ta'zir**: denda keterlambatan (bila aktif) **tidak boleh** masuk akun
     pendapatan koperasi — wajib `akun_tazir_id` (Dana Kebajikan).
   - **V6 — Penahanan SHU**: anggota `OVERDUE` **tidak boleh** berstatus SHU dilepas.
2. **Command**: `php artisan simpanan-wajib:audit-kepatuhan` → laporan tabular + exit code
   (0 = patuh, 1 = ada temuan) agar dapat dipakai gerbang audit otomatis.
3. **Integrasi pelaporan**: temuan ditampilkan pada panel Pengurus/DPS.

#### Milestone Terukur
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M4.1** | V1 mendeteksi penyimpangan | Test menanam jurnal ke akun berbeda → validator **melaporkan temuan** |
| **M4.2** | V1 lolos pada data sehat | Data normal → **0 temuan** |
| **M4.3** | V3 & V4 berfungsi | Tagihan PAID tanpa jurnal → terdeteksi; nominal beda → terdeteksi |
| **M4.4** | V5 kepatuhan syariah | Ta'zir ke akun pendapatan → terdeteksi (pelanggaran Fatwa 17/2000) |
| **M4.5** | Exit code benar | `audit-kepatuhan` mengembalikan exit code `0`/`1` sesuai kondisi |
| **M4.6** | Regresi nihil | `scripts\run-tests.ps1` hijau |

**Bukti:** `docs/TAHAP12-R4-VALIDASI-AKUNTANSI-PSAK.md`

---

### R5 — Pengujian Fungsional & Pengujian Kepatuhan
**Permasalahan yang disasar:** verifikasi menyeluruh (Langkah 5)
**Risiko:** 🟢 Rendah — pengujian, tidak mengubah kode produksi

#### Ruang Lingkup
1. **Test fungsional baru** (per tahap): `SimpananWajibKonfigurasiTest`, `SimpananWajibAkunTest`,
   `SimpananWajibPenarikanTest`, `SimpananWajibKepatuhanTest`.
2. **Test lintas-jalur (regression guard)**: satu test yang membuktikan **semua 4 jalur setoran**
   mendarat di akun yang sama — *test ini adalah penjaga permanen P1*.
3. **Test default aman**: dengan parameter kosong/NULL, perilaku identik dengan sebelum Tahap 12.
4. **Test penggalian (edge case)**: anggota bergabung tgl 29/30/31; periode lintas tahun
   (Desember → Januari); nominal `0`; anggota `SUSPENDED`; penarikan berulang; tunggakan
   berlapis-lapis.
5. **Uji end-to-end data demo**: jalankan seeder demo → audit kepatuhan → **0 temuan**.
6. **Uji skala**: 1.000 anggota × 12 periode → ukur waktu proses `process-daily`
   (tanpa regresi performa).
7. **Gerbang akhir**: `scripts\run-tests.ps1` **seluruhnya hijau + test baru hijau**.

#### Milestone Terukur
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M5.1** | Semua test lama hijau | `scripts\run-tests.ps1` → **0 failure, 0 error** |
| **M5.2** | Test baru hijau | ≥ 4 berkas test baru, seluruh assertion lulus |
| **M5.3** | Penjaga lintas-jalur | Test lintas-jalur ada & lulus |
| **M5.4** | Default aman terbukti | Test membuktikan parameter NULL ⇒ perilaku lama |
| **M5.5** | Audit data demo bersih | `simpanan-wajib:audit-kepatuhan` → exit code `0` |
| **M5.6** | Skala wajar | 1.000 anggota × 12 periode selesai tanpa error & waktu wajar |

**Bukti:** `docs/TAHAP12-R5-PENGUJIAN-FUNGSIONAL-KEPATUHAN.md`

---

### R6 — Dokumentasi & Bukti Kepatuhan
**Permasalahan yang disasar:** Langkah 6
**Risiko:** 🟢 Rendah

#### Ruang Lingkup
1. **Panduan konfigurasi Simpanan Wajib** untuk Pengurus (menutup pertanyaan tertunda user):
   - identifikasi modul & lokasi pengaturan;
   - parameter batas nominal, jadwal, aturan akumulasi, batasan akses;
   - panduan aturan bisnis (kapan mengubah apa, dan dampaknya);
   - langkah pengujian mandiri oleh Pengurus/DPS;
   - pemeliharaan & pemantauan rutin.
2. **Laporan Validasi Kepatuhan**: matriks **kriteria → implementasi → bukti** untuk setiap
   klaim PSAK (101 ¶ 31, ¶ 45–47; Fatwa DSN-MUI 17/2000; Permen KUKM 09/2018 Pasal 24–26).
3. **Ringkasan perubahan** untuk audit: berkas yang diubah, alasan, hasil uji.
4. **Catatan migrasi & rollback** per tahap.

#### Milestone Terukur
| ID | Milestone | Kriteria Lulus (Verifikasi) |
|---|---|---|
| **M6.1** | Panduan konfigurasi lengkap | Dokumen memuat 5 bagian sesuai permintaan user, dapat diikuti tanpa Tim TI |
| **M6.2** | Matriks kepatuhan lengkap | Setiap klaim PSAK punya bukti (berkas test / log / laporan) |
| **M6.3** | Jejak perubahan lengkap | Daftar berkas diubah + alasan + hasil uji |
| **M6.4** | Prosedur rollback ada | Setiap tahap R1–R5 punya langkah rollback tertulis |
| **M6.5** | Dapat diserahterimakan | Pengurus/DPS dapat memverifikasi klaim kepatuhan secara mandiri dari dokumen |

**Bukti:** `docs/TAHAP12-R6-DOKUMENTASI-KEPATUHAN.md` + `docs/PANDUAN-SIMPANAN-WAJIB.md`

---

## 4. Urutan, Ketergantungan & Titik Aman

| Tahap | Dapat di-deploy sendiri? | Bergantung pada | Rollback |
|---|---|---|---|
| **R1** | ✅ Ya | — | Drop 5 kolom baru + hapus entri Setting |
| **R2** | ✅ Ya | R1 (untuk `account_simpanan_wajib_id`) | Kembalikan ke konstanta; jurnal penyesuaian reklasifikasi dibalik |
| **R3** | ✅ Ya | R2 (akun benar) | Nonaktifkan parameter `simpanan_wajib_boleh_ditarik` + hapus rute |
| **R4** | ✅ Ya | R2, R3 | Hapus command & service validator |
| **R5** | ✅ Ya | R1–R4 | Hapus berkas test baru |
| **R6** | ✅ Ya | R1–R5 | — (dokumentasi) |

### Titik Aman Wajib (Safety Gates)

1. **Gerbang 1 — Sebelum setiap deploy:** `php artisan optimize:clear` **wajib** dijalankan sebelum
   `php artisan test` (mencegah `config:cache` membekukan `APP_KEY` → 419 CSRF massal).
2. **Gerbang 2 — Sebelum R2 (penyatuan akun):** lakukan **backup basis data** dan catat
   saldo `2-1002` & `3-1002` per anggota sebagai *baseline* rekonsiliasi.
3. **Gerbang 3 — Sesudah setiap tahap:** jalankan `scripts\run-tests.ps1` + audit kepatuhan
   (R4) — bila **ada temuan**, deploy tahap berikutnya **ditahan**.
4. **Gerbang 4 — Prinsip data historis:** dalam kondisi apa pun, **dilarang** `UPDATE`/`DELETE`
   pada baris `jurnals`/`jurnal_details` historis. Koreksi **hanya** via jurnal penyesuaian.

---

## 5. Matriks Keterlacakan: Permasalahan → Tahap → Milestone

| Permasalahan | Titik Kritis | Tahap | Milestone Kunci |
|---|---|---|---|
| **P1** — Akun CoA ganda | H7, H8, H9 | **R2** | M2.1, M2.2, M2.3, M2.4, M2.5 |
| **P2** — Parameter kaku | H1 | R1 | M1.2, M1.3 |
| | H2 | R1 | M1.2, M1.3 |
| | H3, H4 | R1 | M1.3 |
| | H5, H6 | R1 | M1.4 |
| | H11 | R1 | M1.1, M1.4, M1.5 |
| **P3** — Tidak ada penarikan | H10 | **R3** | M3.1–M3.6 |
| **Jaminan kepatuhan** | — | R4 | M4.1–M4.5 |
| **Verifikasi** | — | R5 | M5.1–M5.6 |
| **Bukti** | — | R6 | M6.1–M6.5 |

---

## 6. Definisi Selesai (Definition of Done) Tahap 12

Tahap 12 dinyatakan **selesai** bila **seluruh** kondisi berikut terpenuhi:

1. ✅ **Nol hardcode akun** — tidak ada nomor akun Simpanan Wajib yang ditanam di Service/Controller;
   semua melalui titik resolusi tunggal.
2. ✅ **Nol jalur tak terkonsisten** — seluruh 4 jalur setoran mendarat di akun yang sama,
   dibuktikan test permanen.
3. ✅ **Saldo modal terjaga** — alur penarikan (service + rute + aksi teller + panel loket + test)
   **sudah diimplementasi**, namun sejak audit kepatuhan Gelombang 3 alur penarikan rutin
   **DIKUNCI PERMANEN** (PSAK 101 ¶31: Simpanan Wajib = modal/ekuitas anggota). Tidak ada saklar
   kebijakan yang dapat membukanya; satu-satunya jalur sah = modul `member_terminations`
   (`ExitKeanggotaanService`). Lihat `tests/Feature/SimpananWajibPenarikanTest.php`.
4. ✅ **Parameter dinamis** — jadwal jatuh tempo, kanal, kebijakan penarikan, dan akun tujuan
   **dapat diubah dari UI Pengurus tanpa deploy**.
5. ✅ **Default aman** — dengan parameter kosong, perilaku identik dengan sebelum Tahap 12.
6. ✅ **Audit kepatuhan hijau** — `psak:validasi-simpanan-wajib` exit code `0` pada data demo.
7. ✅ **Regresi nihil** — `scripts\run-tests.ps1` hijau seluruhnya (**1280 passed / 5585 assertions**, baseline pasca-Gelombang 3).
8. ✅ **Dokumentasi & bukti lengkap** — panduan pengurus + matriks kepatuhan + catatan rollback.
9. ✅ **Tidak ada data historis yang diubah** — seluruh koreksi melalui jurnal penyesuaian
   (bug seeder + 1 baris tagihan #274 dikoreksi pada tabel tagihan, bukan jurnal).

**Dua item terbuka (dilaporkan tanpa disamarkan):**

| Item | Status | Rujukan |
|---|---|---|
| Reklasifikasi saldo legacy `2-1002` (Rp 500.000) → `3-1002` | ⏳ Menunggu persetujuan Pengurus/DPS | `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` §2.3 |
| Alur penarikan Simpanan Wajib (service + rute + test) | ✅ **Sudah diimplementasi**, lalu **DIKUNCI PERMANEN** (PSAK 101 ¶31). Penarikan rutin diblokir total; satu-satunya jalur sah = modul `member_terminations` (`ExitKeanggotaanService`) | `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md` §2.2 + `tests/Feature/SimpananWajibPenarikanTest.php` |

---

## 7. Perkiraan Dampak & Mitigasi Risiko

| Risiko | Kemungkinan | Dampak | Mitigasi |
|---|---|---|---|
| Perubahan pada R2 mengubah nilai jurnal berjalan | Sedang | Laporan bergeser tak terduga | Default aman (M1.3) + test lintas-jalur (M2.2) + baseline sebelum deploy (Gerbang 2) |
| Reklasifikasi `2-1002 → 3-1002` salah hitung | Rendah | Neraca tidak seimbang | Jurnal penyesuaian via `post()` yang **sudah** memvalidasi keseimbangan + pratinjau wajib disetujui |
| Scheduler berhenti saat deploy | Sangat rendah | Tagihan tidak terbit | Migrasi aditif; `process-daily` tetap berjalan; tidak ada maintenance window |
| `config:cache` membekukan `APP_KEY` | Sedang | 419 CSRF massal | Gerbang 1: `optimize:clear` wajib sebelum test |
| Test lama menuntut `2-1002` | **Tinggi** | Deploy R2 gagal | Rencana pembaruan `JenisSimpananParameterTest`, `DemoDataSeeder`, `Api\DepositoController` **bersamaan** dengan R2 (perubahan penegasan, bukan pelonggaran) |

---

*Dokumen ini adalah Langkah 2 dari 6. Pelaksanaan teknis dimulai pada Langkah 3 (Tahap R1).*
