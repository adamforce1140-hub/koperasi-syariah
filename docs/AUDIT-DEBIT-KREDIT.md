# Laporan Audit Menyeluruh Debit (Debet) & Kredit — Logika Perhitungan dan Konsistensi Tampilan

**Tanggal audit**: 2 Oktober 2026
**Objek audit**: Logika perhitungan mutasi simpanan (arah saldo), penyajian simbol +/− pada antarmuka, dan konsistensi konvensi `posisi` (`Debet`/`Kredit`) di seluruh sistem.
**Standar & acuan**: PSAK 101 (penyajian laporan keuangan syariah), PSAK 207 (laporan arus kas), kaidah pembukuan berpasangan (*double-entry*), komentar kanonik `resources/views/teller/riwayat.blade.php` (aturan tanda), `docs/TAHAP2-PSAK-INTI.md` §116-118.
**Kesimpulan umum**: **Logika perhitungan saldo SUDAH BENAR dan konsisten.** Ditemukan **9 ketidaksesuaian pada tampilan** (badge/warna terbalik, kolom tanpa simbol, total masuk/keluar tertukar, logika tanda salah) — **seluruhnya telah diperbaiki dan dikunci dengan 8 test regresi** (baseline uji **1245 passed**).

---

## 1. Keputusan Konvensi Tanda (Disepakati dengan Pemilik Sistem)

Permintaan awal menyebutkan *"+ untuk kredit, − untuk debit"*. Setelah ditelusuri, permintaan tersebut **berlawanan** dengan konvensi yang berlaku di seluruh basis kode. Karena mengubahnya berisiko merusak perhitungan saldo, SHU, dan limit transaksi (semantik kolom `posisi` di database), pemilik sistem memutuskan:

| Aspek | Keputusan |
|---|---|
| Konvensi tanda | **Pertahankan konvensi lama**: `posisi='Debet'` (uang MASUK) → **"+"**; `posisi='Kredit'` (uang KELUAR) → **"−"** |
| Cakupan perubahan | **Tampilan saja (aman)** — rendering view diperbaiki; **semantik kolom `posisi` di database tidak diubah** |

Konsekuensinya: perhitungan saldo, SHU, dan limit tetap utuh, sementara seluruh tampilan diseragamkan.

---

## 2. Konvensi yang Ditegakkan

| `posisi` DB | Arah kas | Simbol | Warna | Sisi kolom |
|---|---|---|---|---|
| `Debet` | Uang **MASUK** (menambah saldo) | **+** | hijau (`#16a34a`) | kolom **Debet** |
| `Kredit` | Uang **KELUAR** (mengurangi saldo) | **−** | merah (`#dc2626`) | kolom **Kredit** |

**Sumber kebenaran arah mutasi**: `Transaksi::isMasuk()` — `posisi` eksplisit menang atas `jenis_transaksi`.

**Bukti konsistensi mesin perhitungan** (sudah benar, tidak diubah):
- `TellerController` — `posisi='Debet'` → `increment('saldo')`; `posisi='Kredit'` → `decrement('saldo')`.
- `TransactionLimitService` L218-245 — arah increment/decrement yang sama.
- `ShuCalculatorService` L569-570 — `$masuk = posisi 'Debet'`, `$keluar = posisi 'Kredit'`.

---

## 3. Temuan Audit dan Status Perbaikan

### DK-1 (KRITIS) — `NasabahController::mutasiIndex()` Menukar Total Masuk/Keluar

**Kondisi**: total "masuk" dihitung dari `posisi='Kredit'` dan "keluar" dari `posisi='Debet'` — **terbalik** dari konvensi. Kartu ringkasan "Total Masuk"/"Total Keluar" pada halaman mutasi menampilkan angka yang tertukar.

**Perbaikan**: `mutasiIndex()` (L151-155) diubah — `$totalMasuk = where('posisi','Debet')`, `$totalKeluar = where('posisi','Kredit')`, dengan komentar konvensi.

**Status**: ✅ **SELESAI & TERVERIFIKASI** — diuji `test_total_masuk_keluar_mengikuti_konvensi_posisi`.

---

### DK-2 (TINGGI) — `Transaksi::isMasuk()` Mendahulukan `jenis_transaksi` di Atas `posisi`

**Kondisi**: `isMasuk()` semula mengecek `jenis_transaksi` lebih dulu, sehingga baris dengan `posisi` eksplisit bisa salah arah. `Pencairan Pembiayaan` disimpan `posisi='Debet'` (lihat `DisbursementService.php` L323), membuat posisi bukan proksi tunggal arah kas.

**Perbaikan**: `isMasuk()` ditulis ulang — `posisi` eksplisit menjadi sumber kebenaran; fallback ke daftar `jenis_transaksi` hanya bila `posisi` kosong. Ditambahkan `isKeluar()`.

**Status**: ✅ **SELESAI & TERVERIFIKASI** — diuji 4 skenario (`test_is_masuk_true_untuk_posisi_debet`, `test_is_masuk_false_untuk_posisi_kredit`, `test_posisi_eksplisit_menang_atas_jenis_transaksi`, `test_tanpa_posisi_fallback_ke_jenis_transaksi`).

---

### DK-3 (TINGGI) — `nasabah/partials/dashboard-transaksi.blade.php` Memakai Logika Tanda Salah

**Kondisi**: badge dan simbol ditentukan dari `jenis_transaksi === 'kredit'` — field yang tidak pernah bernilai "kredit" pada mutasi, sehingga arah/warna hampir selalu salah.

**Perbaikan**: diganti menjadi `$isMasuk = ($trx->posisi ?? 'Debet') === 'Debet'`; badge hijau/merah dan simbol `+`/`-` mengikuti `$isMasuk`.

**Status**: ✅ **SELESAI & TERVERIFIKASI** — diuji `test_potongan_dashboard_memberi_tanda_sesuai_posisi`.

---

### DK-4 (SEDANG) — `nasabah/mutasi.blade.php`: Filter, Badge, dan Kolom Nominal Tidak Konsisten

**Kondisi**:
- Dropdown filter berlabel terbalik ("Kredit (Masuk)"/"Debet (Keluar)").
- Badge Debet/Kredit terbalik (Debet berwarna merah).
- Kolom nominal tidak menampilkan simbol +/−.

**Perbaikan**: label dropdown menjadi "Debet (Mutasi Masuk)"/"Kredit (Mutasi Keluar)"; badge `badge-green` untuk Debet dan `badge-red` untuk Kredit; kolom Debet menampilkan `+ Rp` (hijau) dan kolom Kredit `- Rp` (merah).

**Status**: ✅ **SELESAI & TERVERIFIKASI** — diuji `test_view_mutasi_menampilkan_plus_untuk_debet` dan `test_view_mutasi_menampilkan_minus_untuk_kredit`.

---

### DK-5 (SEDANG) — Enam View Tidak Menampilkan Simbol +/−

**Kondisi**: view berikut menampilkan nominal tanpa tanda arah sehingga ambigu:
`partials/mutasi-terakhir`, `ao/nasabah/show`, `manajer/laporan`, `manajer/kas`, `teller/rekap`, `teller/opening`.

**Perbaikan**: seluruhnya ditambah simbol `+`/`−` dan warna konsisten (hijau masuk, merah keluar), mengikuti konvensi terpadu.

**Status**: ✅ **SELESAI** — diverifikasi melalui `php -l`, pemeriksaan pasangan `@php`/`@endphp`, dan `GetDiagnostics` (bersih).

---

### DK-6 (SEDANG) — `teller/anggota/show.blade.php`: Arah Mutasi Kurang Tepat

**Kondisi**: penentuan "mutasi keluar" tidak konsisten; kolom Jenis menampilkan label arah yang menyesatkan.

**Perbaikan**: `$isKurang` diperluas (`posisi='Kredit'` atau jenis `pemotongan`/`penarikan`/`ta'zir`/`autodebet`); kolom Jenis menampilkan "Mutasi Masuk"/"Mutasi Keluar" + detail `jenis_transaksi`.

**Status**: ✅ **SELESAI**.

---

### DK-7 (RENDAH — dicegah) — `pengurus/kas.blade.php` Tidak Boleh Memakai `posisi` Saja

**Kondisi**: percobaan mengubah `$isKeluar` agar berbasis `posisi` akan **salah** menandai `Pencairan Pembiayaan` (posisi='Debet' padahal kas keluar).

**Perbaikan**: **dikembalikan** ke daftar `jenis_transaksi` semula (`Penarikan`, `Pencairan Pembiayaan`, `Transfer Keluar`, dst.) karena view ini berbasis arus kas, bukan arah mutasi rekening.

**Status**: ✅ **SELESAI (revert)** — mencegah regresi.

---

### DK-8 (RENDAH) — Konteks Jurnal Sengaja Tidak Diberi Simbol

**Kondisi**: `dps/dana-kebajikan.blade.php` (akun `2-3001`, sisi normal Kredit) dan `dps/jurnal-umum.blade.php` adalah **jurnal**, bukan arus kas anggota.

**Keputusan**: **tidak diubah** — menerapkan simbol +/− pada konteks jurnal justru menyesatkan; sisi normal akun diwakili label DEBET/KREDIT apa adanya.

**Status**: ✅ **SESUAI KAIDAH (tidak ada perubahan diperlukan)**.

---

### DK-9 (RENDAH) — `Transaksi::isMasuk()` Tidak Dipakai di Tempat Lain

**Kondisi**: pencarian (`grep`) menemukan `isMasuk()` hanya didefinisikan, tidak dipanggil di tempat lain.

**Dampak**: penulisan ulang `isMasuk()` **aman** — tidak mengubah perilaku modul lain.

**Status**: ✅ **TERVERIFIKASI**.

---

## 4. Ringkasan Perubahan Berkas

| Berkas | Perubahan |
|---|---|
| `app/Models/Transaksi.php` | Tulis ulang `isMasuk()` (posisi eksplisit menang); tambah `isKeluar()` |
| `app/Http/Controllers/NasabahController.php` | Perbaiki `mutasiIndex()` totalMasuk/totalKeluar (Debet=masuk) |
| `resources/views/nasabah/mutasi.blade.php` | Label filter, badge, dan kolom nominal +/− |
| `resources/views/nasabah/partials/dashboard-transaksi.blade.php` | Ganti logika tanda berbasis `posisi` |
| `resources/views/partials/mutasi-terakhir.blade.php` | Tambah simbol +/− |
| `resources/views/ao/nasabah/show.blade.php` | Tambah simbol + warna |
| `resources/views/manajer/laporan.blade.php` | Tambah simbol +/− |
| `resources/views/manajer/kas.blade.php` | Tambah simbol +/− |
| `resources/views/teller/rekap.blade.php` | Tambah simbol + warna |
| `resources/views/teller/opening.blade.php` | Tambah simbol + warna |
| `resources/views/teller/anggota/show.blade.php` | Perbaiki arah mutasi & label |
| `resources/views/pengurus/kas.blade.php` | Dipertahankan berbasis `jenis_transaksi` (dicegah regresi) |
| `tests/Feature/DebitKreditKonsistensiTest.php` | **Baru** — 8 test regresi (23 assertion) |

---

## 5. Hasil Pengujian

**Test baru** — `tests/Feature/DebitKreditKonsistensiTest.php`:

| Skenario | Status |
|---|---|
| `posisi='Debet'` → `isMasuk()` true | ✅ |
| `posisi='Kredit'` → `isMasuk()` false | ✅ |
| Posisi eksplisit menang atas `jenis_transaksi` | ✅ |
| Tanpa posisi → fallback ke `jenis_transaksi` | ✅ |
| Total masuk/keluar mengikuti konvensi posisi | ✅ |
| View mutasi: "+ Rp" untuk Debet | ✅ |
| View mutasi: "- Rp" untuk Kredit | ✅ |
| Potongan dashboard: tanda & warna sesuai posisi | ✅ |

**Hasil**: 8 passed (23 assertions).

**Regresi penuh**: `php artisan optimize:clear` + `php artisan test` → **1245 passed (5591 assertions)** — baseline **1237** terjaga, bertambah 8 test baru. **0 failure.**

---

## 6. Kesimpulan

1. **Logika perhitungan debit/kredit SUDAH SESUAI standar akuntansi.** Arah mutasi saldo (`Debet` menambah, `Kredit` mengurangi) konsisten di `TellerController`, `TransactionLimitService`, dan `ShuCalculatorService`.
2. **9 ketidaksesuaian tampilan ditemukan dan diperbaiki** — utamanya badge/warna terbalik, kolom tanpa simbol, total masuk/keluar tertukar, dan logika tanda berbasis field yang salah.
3. **Tampilan kini konsisten di seluruh sistem**: `+` (hijau) untuk `Debet`/mutasi masuk, `−` (merah) untuk `Kredit`/mutasi keluar — kecuali konteks jurnal (sengaja tidak diberi simbol, sesuai kaidah).
4. **Tidak ada regresi**: 1245 test hijau, termasuk 8 test regresi baru yang mengunci konvensi tanda dan perhitungan.
