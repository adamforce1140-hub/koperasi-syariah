# TAHAP 12 — R1: Infrastruktur Konfigurasi Dinamis

**Status:** ✅ SELESAI &amp; TERVERIFIKASI
**Tanggal:** 2026-09-30
**Tahap:** R1 (Langkah 3 dari 6)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R1
**Rujukan analisis:** `docs/TAHAP12-SIMPANAN-WAJIB-ANALISIS.md` (hardcode H1–H9, H11)

**Dasar syariah:** PSAK 101 ¶ 45–47 (kebijakan akuntansi entitas syariah dapat ditetapkan,
diubah, dan diungkapkan — karenanya **harus konfigurabel**, bukan dikunci di kode).
**Dasar regulasi:** Permen KUKM No. 09/2018 Pasal 24–26 (kewenangan Pengurus menetapkan
kebijakan operasional); UU No. 25/1992 Pasal 30 (kewenangan Rapat Anggota/Pengurus).

**Khalayak:** Tim TI, Pengurus, Dewan Pengawas Syariah (DPS), auditor eksternal.

---

## 1. Ringkasan untuk Pemangku Kepentingan

Sebelum R1, seluruh ketentuan operasional Simpanan Wajib **tertanam mati di dalam kode**
(*hardcode*). Mengubah tanggal jatuh tempo tagihan, mis. dari tanggal 10 menjadi tanggal 15,
**wajib menunggu rilis kode baru** — tidak dapat dilakukan oleh Pengurus sendiri.

Setelah R1, lima parameter kunci dipindahkan ke **panel Kebijakan Pengurus** dan langsung
berlaku pada transaksi **berikutnya**, tanpa deploy, tanpa henti operasional.

| Aspek | Sebelum R1 (kaku) | Sesudah R1 (dinamis) |
|---|---|---|
| Tanggal jatuh tempo tagihan | Tertanam `10` di `MandatorySavingsService` | Diatur Pengurus, rentang 1–28 |
| Kanal pembayaran default anggota baru | Ditentukan di controller pendaftaran | Parameter `simpanan_wajib_kanal_default` |
| Penarikan saldo Simpanan Wajib | Tidak ada saklar sama sekali | Saklar `simpanan_wajib_boleh_ditarik` (default **nonaktif**) |
| Akun CoA Simpanan Wajib | Tersebar sebagai konstanta | Pemetaan dinamis `account_simpanan_wajib_id` |
| Jejak audit perubahan kebijakan | Tidak ada | Otomatis tercatat (siapa, kapan, lama → baru) |

---

## 2. Parameter yang Dipindahkan ke Konfigurasi

Seluruh parameter terdaftar pada tiga titik di `App\Support\Setting` (`DEFAULTS`, `TIPE`,
`DESKRIPSI`) — inilah *satu sumber kebenaran* nilai default.

| Parameter | Tipe | Default | Rentang/Nilai Sah | Deskripsi |
|---|---|---|---|---|
| `simpanan_wajib_kanal_default` | string | `AUTO_DEBIT` | `AUTO_DEBIT` \| `CASH` \| `TRANSFER` | Kanal pembayaran default anggota baru |
| `simpanan_wajib_boleh_ditarik` | boolean | `false` | true/false | Saklar penarikan saldo Simpanan Wajib |
| `simpanan_wajib_hari_penarikan` | string | `SETELAH_KELUAR` | `SETELAH_KELUAR` \| `KAPAN_SAJA` | Waktu penarikan diizinkan |
| `account_simpanan_wajib_id` | integer (nullable) | `null` | ID CoA aktif | Akun CoA kanonik Simpanan Wajib |

**Bukti kode:**
- `app/Support/Setting.php` — `DEFAULTS` baris 58–62; `TIPE` baris 153–157;
  `DESKRIPSI` baris 213–217.
- `app/Support/Setting.php` — accessor `simpananWajibKanalDefault()` (baris 384, normalisasi
  nilai tak dikenal → `AUTO_DEBIT`), `simpananWajibKanalDefaultAutoDebit()` (baris 397),
  `simpananWajibBolehDitarik()` (baris 408), `simpananWajibHariPenarikan()` (baris 418),
  `akunSimpananWajibId()` (baris 432).
- `app/Models/KebijakanKoperasi.php` — `$fillable` baris 33 &amp; 35; casts baris 102–103;
  delegasi statis baris 610–636.

> **Pembaruan (2026-10-13):** parameter **`simpanan_wajib_overdue_day` dan accessor
> `simpananWajibOverdueDay()` telah dihapus** — tanggal jatuh tempo kini otomatis per anggota
> (`MandatorySavingsService::tanggalJatuhTempo()`), lihat
> `database/migrations/2026_10_13_000100_drop_simpanan_wajib_overdue_day_from_kebijakan_koperasis.php`.

---

## 3. Pemenuhan Milestone

| ID | Milestone | Kriteria Lulus | Status | Bukti |
|---|---|---|---|---|
| **M1.1** | Kolom baru tersedia | `php artisan migrate` sukses; `Schema::hasColumn` benar untuk 5 kolom | ✅ | `database/migrations/2026_09_30_004000_add_simpanan_wajib_parameters_to_kebijakan_koperasis.php` (guard `Schema::hasColumn`, penempatan `->after(...)`) — *catatan 2026-10-13: kolom `simpanan_wajib_overdue_day` kemudian dihapus* |
| **M1.2** | Parameter terbaca | `Setting::simpananWajibKanalDefault()` → `AUTO_DEBIT`; `Setting::get('simpanan_wajib_kanal_default')` tidak `null` | ✅ | `Setting.php`; `DEFAULTS` |
| **M1.3** | Default aman | Dengan kolom **NULL**, seluruh method mengembalikan nilai default = perilaku lama | ✅ | Accessor memakai `static::get($kunci, $default)` dengan default eksplisit (P4 Default Aman) |
| **M1.4** | UI berfungsi | Panel baru muncul; submit tersimpan; validasi menolak nilai tak sah | ✅ | `resources/views/pengurus/kebijakan.blade.php` baris 163–165 (panel "Ketentuan Simpanan Wajib"); `PengurusController.php:754` (`nullable\|integer\|min:1\|max:28`), `:756` (`nullable\|boolean`); whitelist `AdminSettingsController.php:49,51` |
| **M1.5** | Audit tercatat | Perubahan parameter menghasilkan entri audit (siapa, kapan, lama → baru) | ✅ | `PengurusController.php:938–960` (penugasan) + `:1049–1051` (ringkasan audit) |
| **M1.6** | Regresi nihil | `scripts\run-tests.ps1` **seluruhnya hijau** | ✅ | **1120 passed (5139 assertions)**, durasi 162,34s, exit code 0 |

Tambahan bukti uji khusus R1: `tests/Feature/SimpananWajibKonfigurasiDinamisTest.php`
(prefix `CFGWJB`) — membuktikan default aman, rantai resolusi akun, validasi tak sah,
persistensi, dan jalur teller.

---

## 4. Prinsip P4 (Default Aman) — Jaminan Non-Disruptif

Setiap accessor **selalu** menyuplai nilai default eksplisit. Konsekuensinya:

> Basis data yang **belum diisi** (`NULL`) menghasilkan perilaku **identik** dengan sistem
> sebelum Tahap 12. Tidak ada satu pun transaksi berjalan yang berubah akibat migrasi R1.

Migrasi bersifat **aditif murni**: hanya `ADD COLUMN`, tanpa `MODIFY`/`DROP`, dan dijaga
`Schema::hasColumn` sehingga aman dijalankan berulang. Scheduler `mandatory-savings:process-daily`
tidak perlu dihentikan — **tidak ada maintenance window**.

---

## 5. Rollback

1. Jalankan *down* migrasi `2026_09_30_004000_add_simpanan_wajib_parameters_to_kebijakan_koperasis`
   (menghapus 5 kolom baru).
2. Hapus 5 entri terkait dari `DEFAULTS`, `TIPE`, `DESKRIPSI` di `app/Support/Setting.php`.
3. Hapus accessor & delegasi terkait.
4. `php artisan optimize:clear`.

Rollback **tidak** menyentuh data transaksi/jurnal — R1 murni konfigurasi.

**Catatan penting:** rollback akan mengembalikan `account_simpanan_wajib_id` ke hilang, sehingga
titik resolusi tunggal jatuh ke rantai berikutnya (master `jenis_simpanans` → registri standar
`3-1002`). Karena default juga `3-1002`, **akun efektif tidak berubah**.

---

*Dokumen ini adalah bukti Milestone R1. Lanjut ke `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md`.*
