# LAPORAN AUDIT — PARAMETER MODUL TELLER (LIMIT SETOR & TARIK TUNAI)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal:** 2026-10-14
**Lingkup:** Modul Teller — parameter limit transaksi & kontrol kas
**Gejala yang dilaporkan:** *"belum ada parameter modul untuk mengatur limit dari teller."*

---

## 1. Ringkasan Eksekutif

Kolom parameter limit teller (`limit_penarikan_teller`, `limit_setoran_teller`,
`teller_max_cash_limit`, `minimum_transaksi_teller`) **sudah ada** di basis data, model,
`Setting`, dan sudah **dipakai** oleh `HierarkiRole` untuk menentukan kapan transaksi
teller butuh approval Manajer. **Namun dua di antaranya — `limit_penarikan_teller` dan
`limit_setoran_teller` — tidak pernah dapat diatur**: tidak ada input di form kebijakan
Pengurus, tidak tervalidasi, dan tidak tersimpan oleh `PengurusController`.

Akibatnya kontrol limit teller bersifat **mati/tidak dapat dikonfigurasi** dari aplikasi,
padahal komentar kode di `HierarkiRole` sudah mengklaim kolom ini *"sudah dapat diubah
Pengurus dari UI"*. Perbaikan menutup kesenjangan tersebut (zero-hardcoding policy).

---

## 2. Audit Basis Kode (Langkah 1)

Pencarian menyeluruh atas `limit_penarikan_teller` / `limit_setoran_teller`:

| Lapisan | Lokasi | Sebelum perbaikan |
|---|---|---|
| Basis data | `kebijakan_koperasis` (kolom sudah ada) | ✅ Ada |
| Model | `KebijakanKoperasi` (`$fillable`, `$casts`, label) | ✅ Ada |
| Resolver | `Setting::limitPenarikanTeller()` / `limitSetoranTeller()` | ✅ Ada |
| Pemakai logika | `HierarkiRole::kebijakanTellerNominal()` | ✅ Ada (limit per transaksi teller) |
| Whitelist admin | `AdminSettingsController` | ✅ Ada |
| **Form kebijakan Pengurus** | `resources/views/pengurus/kebijakan.blade.php` | ❌ **TIDAK ADA** |
| **Validasi & simpan** | `PengurusController::update()` | ❌ **TIDAK ADA** |

**Kesimpulan:** akar masalah tunggal = **lapisan form + controller Pengurus tidak
mengekspos field ini.** Dua parameter lain pada section yang sama
(`teller_max_cash_limit`, `minimum_transaksi_teller`) sudah diekspos, sehingga pengguna
melihat section "Limit Kas & Transaksi Teller" **tanpa** pengaturan limit setor/tarik —
persis keluhan yang dilaporkan.

---

## 3. Analisis Akar Penyebab (Langkah 2)

Section **"Limit Kas & Transaksi Teller"** di halaman kebijakan Pengurus hanya memuat 2
dari 4 parameter teller yang relevan. Dua parameter limit per-transaksi (setor & tarik)
**terlewat** saat section ini dibuat, meski:

- kolomnya sudah dimigrasikan,
- resoler `Setting` sudah tersedia,
- dan `HierarkiRole` **sudah membacanya** untuk keputusan approval.

Karena tidak ada input, tidak ada `validate()`, dan tidak ada assignment di controller,
nilai kolom tersebut **hanya bisa berubah lewat akses DB langsung** — bukan melalui
kebijakan operasional yang dapat diubah Pengurus. Ini melanggar prinsip zero-hardcoding
dan membuat kontrol risiko limit teller tidak efektif.

---

## 4. Implementasi Perbaikan (Langkah 3)

Perbaikan konsisten mengikuti pola yang sudah ada (tanpa hardcode, guard `filled()`,
label & pesan Bahasa Indonesia):

### 4.1 UI — `resources/views/pengurus/kebijakan.blade.php`
Menambahkan 2 field pada section "Limit Kas & Transaksi Teller":
- **Limit Penarikan Tunai Teller (Rp)** → `name="limit_penarikan_teller"` (required)
- **Limit Setoran Tunai Teller (Rp)** → `name="limit_setoran_teller"` (required)

Nilai default diambil dari `Setting::limitPenarikanTeller()` /
`Setting::limitSetoranTeller()` sehingga responsif terhadap kebijakan yang berlaku.

### 4.2 Controller — `app/Http/Controllers/Dashboard/PengurusController.php`
- **Sanitasi nominal**: menambahkan kedua field ke daftar `$fieldNominal` (mencegah input
  sampah menjadi 0).
- **Validasi**: `nullable|numeric|min:0|max:99999999999.99` untuk keduanya.
- **Pesan error spesifik**: `.numeric` & `.min` untuk label Bahasa Indonesia.
- **Penyimpanan**: assignment dengan guard `filled()`, fallback ke nilai `Setting` yang
  berlaku (pembaruan sebagian aman/tidak menghapus nilai).

Tidak ada perubahan pada `AdminSettingsController` (sudah lengkap) maupun pada logika
`HierarkiRole` — cukup menutup lapisan input.

---

## 5. Pengujian Fungsional (Langkah 4)

### 5.1 Tes otomatis baru — `tests/Feature/LimitTellerParameterTest.php` (9 tes, 27 assertion)

| # | Tes | Fokus |
|---|---|---|
| 1 | `test_kolom_parameter_limit_teller_tersedia_di_tabel_kebijakan` | 4 kolom ada di DB |
| 2 | `test_setting_mengembalikan_default_saat_kebijakan_belum_diatur` | default 5 jt / 50 jt |
| 3 | `test_setting_mengikuti_nilai_kebijakan_yang_sedang_berlaku` | sumber kebenaran tunggal |
| 4 | `test_endpoint_pengurus_menyimpan_parameter_limit_teller` | simpan via HTTP |
| 5 | `test_endpoint_pengurus_menolak_limit_negatif` | validasi `min:0` |
| 6 | `test_endpoint_pengurus_menolak_limit_bukan_angka` | validasi `numeric` |
| 7 | `test_halaman_kebijakan_menampilkan_field_limit_teller` | field tampil di UI |
| 8 | `test_perubahan_limit_penarikan_berlaku_pada_logika_teller` | efek ke `HierarkiRole` |
| 9 | `test_perubahan_limit_setoran_berlaku_pada_logika_teller` | efek ke `HierarkiRole` |

**Hasil:** `9 passed (27 assertions)`.

### 5.2 Verifikasi end-to-end di aplikasi berjalan
- Halaman `/pengurus/kebijakan` menampilkan 4 field teller dengan nilai benar:
  penarikan `5.000.000`, setoran `50.000.000`, kas laci `25.000.000`, minimum `1.000`.
- Simpan dari UI berhasil; verifikasi DB: `8000000.00 | 45000000.00` (persistensi OK).
- Nilai diuji dikembalikan ke default (`5000000 | 50000000`) setelah verifikasi.

---

## 6. Verifikasi Regresi (Langkah 5)

Suite penuh via `scripts\run-tests.ps1` (setelah `optimize:clear`):

```
Tests:    5 skipped, 1547 passed (8714 assertions)
Duration: 236.00s
[OK] Pengujian LULUS.
```

Baseline sebelumnya **1538 passed** → kini **1547 passed** (+9 = tepat jumlah tes baru).
**Tidak ada regresi**, tidak ada masalah baru pada modul lain.

---

## 7. Dokumentasi & Pelacakan (Langkah 6)

| Item | Keterangan |
|---|---|
| **Modul teridentifikasi** | Modul Teller — parameter limit setor & tarik tunai |
| **Jenis masalah** | Kesenjangan UI/controller: kolom parameter ada di DB & dipakai logika, tetapi tidak dapat diatur Pengurus |
| **Perubahan** | `pengurus/kebijakan.blade.php` (2 field baru), `PengurusController` (sanitasi + validasi + pesan + simpan) |
| **Bukti uji** | 9 tes baru lulus; verifikasi UI end-to-end; suite penuh 1547 passed / 0 failed |
| **Pencegahan** | Setiap menambah section parameter baru, pastikan **4 lapisan** sinkron: kolom DB → model/`Setting` → form → controller (validasi & simpan). Bila kolom dipakai logika tetapi tidak ada inputnya, kontrol tersebut efektif mati |
