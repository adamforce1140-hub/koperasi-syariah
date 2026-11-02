# TAHAP 12 — R6: Dokumentasi &amp; Bukti Kepatuhan

**Status:** ✅ SELESAI
**Tanggal:** 2026-09-30
**Tahap:** R6 (Langkah 6 dari 6 — penutup)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R6
**Dokumen pendamping:** `docs/PANDUAN-SIMPANAN-WAJIB.md`

**Khalayak:** Pengurus, Dewan Pengawas Syariah (DPS), auditor internal &amp; eksternal, Tim TI.

---

## 1. Ringkasan Eksekutif

Tahap 12 menyelesaikan **permasalahan pertama**: inkonsistensi akun CoA Simpanan Wajib
(`2-1002` kewajiban vs `3-1002` modal anggota) yang melanggar **PSAK 101 ¶ 31**, sekaligus
menghapus kekakuan sistem dengan memindahkan ketentuan operasional Simpanan Wajib ke
**konfigurasi yang dapat diubah Pengurus tanpa deploy**.

**Hasil terukur:**

| Indikator | Sebelum Tahap 12 | Sesudah Tahap 12 |
|---|---|---|
| Akun Simpanan Wajib | Dua akun bercampur (`2-1002` &amp; `3-1002`) | **Satu titik resolusi tunggal** → `3-1002` (Modal Anggota) |
| Perubahan kebijakan | Wajib ubah kode + rilis ulang | **Ubah dari panel Pengurus**, tanpa deploy |
| Gerbang kepatuhan otomatis | Tidak ada | `psak:validasi-simpanan-wajib` (7 kategori, exit code) |
| Regresi pengujian | 1110 passed | **1120 passed (5139 assertions)**, 0 failure |
| Panduan untuk Pengurus | Tidak ada | `docs/PANDUAN-SIMPANAN-WAJIB.md` |
| Audit kepatuhan data demo | — | **LULUS** (exit `0`, 7 kategori `0`) |

---

## 2. Matriks Kepatuhan: Kriteria → Implementasi → Bukti

### 2.1 PSAK 101 ¶ 31 — Simpanan Wajib disajikan sebagai Modal Anggota

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Simpanan Wajib diklasifikasikan sebagai **Ekuitas → Modal Anggota**, bukan kewajiban | Titik resolusi tunggal `MandatorySavingsService::akunSimpananWajib()` mengembalikan `3-1002` (Ekuitas) | `app/Services/MandatorySavingsService.php:70–96`; `app/Support/Coa.php:97` (`SIMPANAN_WAJIB_MODAL = '3-1002'`) |
| Konstanta akun kewajiban legacy tidak lagi dipakai untuk transaksi baru | `AutoJournalingService::SIMPANAN_WAJIB` ditandai `@deprecated`, hanya untuk data historis | `app/Services/AutoJournalingService.php:36–43` |
| Seluruh jalur transaksi konsisten ke akun yang sama | Auto-debit, teller, bundling CIF, API deposito, *reversal* → semuanya lewat titik resolusi tunggal | `MandatorySavingsService.php:437,531`; `ReversalService.php:340–354`; `Api/DepositoController.php:192` |
| Pelanggaran penyajian **terdeteksi otomatis** | Kategori `AKUN_SIMPANAN_WAJIB_KEWAJIBAN` + `SALDO_MODAL_NEGATIF` | `ValidasiKepatuhanSimpananWajib.php:22–34,63,69`; `ValidasiKepatuhanSimpananWajibTest::test_mendeteksi_kredit_ke_akun_kewajiban` |
| Data historis tidak diubah untuk memaksa kepatuhan | Koreksi hanya melalui jurnal penyesuaian (Gerbang 4) | `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` §2.3, §5 |

**Status:** ✅ Patuh untuk seluruh transaksi **baru**. ⏳ Saldo legacy Rp 500.000 di `2-1002`
**menunggu jurnal reklasifikasi** (lihat §4.1).

### 2.2 PSAK 101 ¶ 45–47 — Kebijakan Akuntansi Ditetapkan &amp; Diungkapkan

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Kebijakan akuntansi bersifat **dapat ditetapkan** entitas (bukan dipaku di kode) | 5 parameter Simpanan Wajib terdaftar di `Setting` (`DEFAULTS`, `TIPE`, `DESKRIPSI`) | `app/Support/Setting.php:58–62,153–157,213–217` |
| Kebijakan dapat **diubah** oleh organ yang berwenang | Panel "Ketentuan Simpanan Wajib" di UI Pengurus | `resources/views/pengurus/kebijakan.blade.php:163–165`; `PengurusController.php:754,756,938–960` |
| Perubahan kebijakan **terdokumentasi** (jejak audit) | Pencatatan otomatis siapa/kapan/lama→baru | `PengurusController.php:1049–1051` |
| Kebijakan berlaku **konsisten** pada seluruh jalur | Satu titik resolusi dipakai semua jalur | `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` §2.2 |
| Nilai tak sah **ditolak** agar kebijakan tetap sahih | Validasi rentang 1–28, kanal tertutup, akun harus aktif | `PengurusController.php:754–756`; `Setting.php:369–423` |
| Kebijakan dapat **diungkapkan** (dibaca ulang) untuk pelaporan | `DESKRIPSI` menjadi teks baku; `PANDUAN-SIMPANAN-WAJIB.md` untuk non-teknis | `Setting.php:207–217`; `docs/PANDUAN-SIMPANAN-WAJIB.md` Bagian 2 |

**Status:** ✅ Patuh.

### 2.3 Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000 — Denda/*Ta'zir* ke Dana Kebajikan

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Denda keterlambatan **tidak boleh** menjadi pendapatan koperasi | Dana ta'zir dipetakan ke akun **Dana Kebajikan**, terpisah dari akun pendapatan | `app/Support/Coa.php` (`DANA_KEBAJIKAN`); `AutoJournalingService.php:45–59` (pemisahan absolut PSAK 108/109) |
| Saklar denda eksplisit (0 = nonaktif) | `financing_late_fee_per_day` default `0.00` = fitur nonaktif | `Setting.php:76–78` |
| Penyimpangan **terdeteksi otomatis** | Gerbang kepatuhan menandai akun salah kategori | `ValidasiKepatuhanSimpananWajibTest` (kategori akun kewajiban) |
| Dana sosial tidak boleh bertukar akun dengan pendapatan | Konstanta `SUMBER_PENYALURAN_DANA_KEBAJIKAN` + penegasan pemisahan | `AutoJournalingService.php:48–59` |

**Status:** ✅ Patuh. Modul Zakat/Infak/Sedekah (PSAK 109) juga terverifikasi terpisah:
`tests/Feature/ZakatPsak109Test.php` — 15 test lulus.

### 2.4 Permen KUKM No. 09/2018 Pasal 24–26 — Kewenangan &amp; Pertanggungjawaban Pengurus

| Kriteria | Implementasi | Bukti |
|---|---|---|
| Pengurus berwenang menetapkan kebijakan operasional | Panel Kebijakan khusus Pengurus | `PengurusController.php`; `resources/views/pengurus/kebijakan.blade.php` |
| Kebijakan dapat **disesuaikan** dengan kondisi koperasi | 5 parameter dinamis + nominal simpanan | `docs/PANDUAN-SIMPANAN-WAJIB.md` Bagian 3 |
| Pertanggungjawaban melalui **jejak audit** | Setiap perubahan parameter tercatat | `PengurusController.php:1049–1051` |
| Pengawasan oleh **DPS** | DPS dapat membaca hasil gerbang kepatuhan; dilarang mengubah jurnal | `tests/Feature/Tahap1StabilisasiKritisTest` (`peran dps dilarang menulis jurnal`) |
| Pemisahan tugas (*segregation of duties*) | Peran Pengurus / Teller / Anggota / DPS dibedakan tegas | `docs/PANDUAN-SIMPANAN-WAJIB.md` §2.5 |
| Prinsip kehati-hatian (POJK 76/2016) | Default aman: penarikan tertutup; denda nonaktif; akun kanonik aman | `Setting.php:60,76–78` |

**Status:** ✅ Patuh.

---

## 3. Ringkasan Perubahan (Jejak Audit Teknis)

### 3.1 Berkas Baru

| Berkas | Tahap | Alasan |
|---|---|---|
| `database/migrations/2026_09_30_004000_add_simpanan_wajib_parameters_to_kebijakan_koperasis.php` | R1 | Menambah 5 kolom parameter (aditif, guard `hasColumn`) |
| `app/Console/Commands/ValidasiKepatuhanSimpananWajib.php` | R4 | Gerbang kepatuhan PSAK 101/108 (7 kategori, exit code) |
| `tests/Feature/ValidasiKepatuhanSimpananWajibTest.php` | R5 | 10 test / 18 assertion untuk gerbang kepatuhan |
| `docs/PANDUAN-SIMPANAN-WAJIB.md` | R6 | Panduan konfigurasi untuk Pengurus/DPS |
| `docs/TAHAP12-R1..R6-*.md` (6 dokumen) | R6 | Bukti kepatuhan per tahap |

### 3.2 Berkas yang Dimodifikasi

| Berkas | Tahap | Perubahan | Hasil Uji |
|---|---|---|---|
| `app/Support/Setting.php` | R1 | +5 entri `DEFAULTS`/`TIPE`/`DESKRIPSI`; +6 accessor (dengan *clamp* &amp; normalisasi) | ✅ |
| `app/Models/KebijakanKoperasi.php` | R1 | +`$fillable`, +casts, +delegasi statis | ✅ |
| `app/Http/Controllers/Dashboard/PengurusController.php` | R1 | +validasi, +penugasan, +ringkasan audit | ✅ |
| `app/Http/Controllers/AdminSettingsController.php` | R1 | +whitelist 2 parameter | ✅ |
| `resources/views/pengurus/kebijakan.blade.php` | R1 | +panel "Ketentuan Simpanan Wajib" | ✅ |
| `app/Services/MandatorySavingsService.php` | R1/R2 | +titik resolusi tunggal `akunSimpananWajib()`; +`penarikanDiizinkan()`. *(Catatan 2026-10-13: method `jatuhTempoHari()` dan parameter `simpanan_wajib_overdue_day` telah dihapus — jatuh tempo kini otomatis via `tanggalJatuhTempo()`.)* | ✅ |
| `app/Services/AutoJournalingService.php` | R2 | `SIMPANAN_WAJIB` ditandai `@deprecated`; jurnal memakai akun kanonik | ✅ |
| `app/Services/ReversalService.php` | R2 | Heuristik jalur diganti titik resolusi tunggal | ✅ |
| `database/seeders/AdamRenataSeeder.php` | R4 | **Perbaikan bug**: `amount` tagihan bundling = porsi wajib (bukan total) | ✅ |

**Perbaikan bug yang ditemukan oleh gerbang R4:**

| Item | Sebelum | Sesudah |
|---|---|---|
| `AdamRenataSeeder::simulasikanPelunasanSetoranAwal()` (2 tempat) | `'amount' => $jumlah` (150.000) | `'amount' => $wajib` (50.000) |
| Baris data historis **bill #274** (anggota #5, periode 2026-01) | `amount = 150.000,00` | `amount = 50.000,00` |

Kedua koreksi menyentuh **kolom `amount` pada tabel tagihan**, **bukan** `jurnal`/`jurnal_detail`
— patuh Gerbang 4.

### 3.3 Hasil Pengujian Akhir

```
Tests:    1120 passed (5139 assertions)
Duration: 162.34s
Exit code: 0
```

Bukti lengkap: `docs/TAHAP12-R5-PENGUJIAN-FUNGSIONAL-KEPATUHAN.md`.

---

## 4. Keterbukaan: Item yang Belum Selesai

Dokumen ini **tidak mengklaim** lebih dari yang terbukti.

### 4.1 Reklasifikasi Saldo Legacy `2-1002 → 3-1002` (M2.5)

| Aspek | Keterangan |
|---|---|
| **Kondisi** | Saldo `2-1002` = **Rp 500.000,00** (5 baris jurnal 2026-09-22, sumber "Setoran Simpanan Wajib") |
| **Kenapa belum** | Menunggu pratinjau + persetujuan resmi Pengurus/DPS (prinsip P2 Reversible &amp; Gerbang 4) |
| **Rencana** | Posting **satu** jurnal penyesuaian: `DEBET 2-1002 Rp 500.000` → `KREDIT 3-1002 Rp 500.000` |
| **Dampak sesudah** | Saldo `2-1002` = `0,00`; saldo `3-1002` = `1.550.000,00`; jurnal historis utuh |
| **Dampak selama belum** | Neraca **masih memisahkan** dua pos; total ekuitas ter-*understate* Rp 500.000 |
| **Catatan** | Gerbang kepatuhan **belum** memeriksa saldo legacy ini (hanya memeriksa tagihan/jurnal jalur modul) |

### 4.2 Alur Penarikan Simpanan Wajib (R3) — ✅ TERSEDIA

| Aspek | Keterangan |
|---|---|
| **Yang tersedia** | Gerbang kebijakan `penarikanDiizinkan()`/`alasanPenarikanDitolak()`, `saldoSimpananWajib()`, `tarikSimpananWajib()` di service; rute + aksi `SimpananWajibController@tarik`; panel penarikan di loket Teller; serta `tests/Feature/SimpananWajibPenarikanTest.php` (15 test) |
| **Saklar** | `simpanan_wajib_boleh_ditarik` — **default `false`** (tetap nonaktif sampai Pengurus mengaktifkan) dan `simpanan_wajib_hari_penarikan` (`SETELAH_KELUAR` default / `KAPAN_SAJA`) |
| **Konsisten dengan P4?** | ✅ Ya — default `false` **tepat** mencerminkan perilaku sistem saat ini (penarikan tertutup); fitur baru tidak mengubah perilaku apa pun sampai saklar dibuka |
| **Bukti teknis** | `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md` (✅ SELESAI & TERVERIFIKASI) |

### 4.3 Uji Skala 1.000 Anggota × 12 Periode (M5.6)

Belum dijalankan. Skalabilitas jalur baca sudah diverifikasi terpisah pada
`tests/Feature/Tahap7SkalabilitasTest.php` (12 test lulus, indeks terpasang &amp; terpakai).

---

## 5. Catatan Migrasi &amp; Rollback per Tahap

### 5.1 Migrasi

| Tahap | Migrasi | Sifat | Aman diulang? |
|---|---|---|---|
| R1 | `2026_09_30_004000_add_simpanan_wajib_parameters_to_kebijakan_koperasis` | **Aditif** — hanya `ADD COLUMN` | ✅ Ya (guard `Schema::hasColumn`) |
| R2 | Tidak ada migrasi skema (murni kode + jurnal penyesuaian) | — | — |
| R3 | Tidak ada | — | — |
| R4 | Tidak ada (command) | — | — |
| R5 | Tidak ada (test) | — | — |

**Perintah migrasi:**
```bash
php artisan migrate
```

### 5.2 Rollback per Tahap

| Tahap | Langkah Rollback | Risiko |
|---|---|---|
| **R1** | `migrate:rollback` (hapus 5 kolom) + hapus 5 entri `Setting` + accessor + delegasi + panel UI | 🟢 Rendah — akun efektif tetap `3-1002` |
| **R2** | Kosongkan `account_simpanan_wajib_id` (resolusi turun ke `3-1002`). Bila jurnal reklasifikasi sudah diposting: posting **jurnal penyesuaian kebalikannya** — **jangan** hapus | 🟢 Rendah |
| **R3** | Tidak ada (fitur belum aktif; saklar tetap `false`) | 🟢 Nihil |
| **R4** | Hapus command + berkas test | 🟢 Nihil (perintah hanya-baca) |
| **R5** | Hapus berkas test baru | 🟢 Nihil |
| **R6** | Tidak ada (dokumentasi) | 🟢 Nihil |

### 5.3 Gerbang Keselamatan (Wajib Dipatuhi)

| # | Gerbang | Aturan |
|---|---|---|
| **G1** | Sebelum pengujian | `php artisan optimize:clear` **wajib** sebelum `php artisan test` (mencegah `config:cache` membekukan `APP_KEY` → 419 CSRF massal). Gunakan `scripts\run-tests.ps1` |
| **G2** | Sebelum R2 | Backup basis data + catat *baseline* saldo `2-1002` &amp; `3-1002` |
| **G3** | Sesudah setiap tahap | Jalankan `run-tests.ps1` + `psak:validasi-simpanan-wajib` — bila **ada temuan**, tahan deploy berikutnya |
| **G4** | Selamanya | **DILARANG** `UPDATE`/`DELETE` baris `jurnal`/`jurnal_detail` historis. Koreksi **hanya** via jurnal penyesuaian |

---

## 6. Pemenuhan Milestone R6

| ID | Milestone | Kriteria Lulus | Status | Bukti |
|---|---|---|---|---|
| **M6.1** | Panduan konfigurasi lengkap | Memuat 5 bagian sesuai permintaan, dapat diikuti tanpa Tim TI | ✅ | `docs/PANDUAN-SIMPANAN-WAJIB.md` (Bagian 1–5) |
| **M6.2** | Matriks kepatuhan lengkap | Setiap klaim PSAK punya bukti (berkas test / log / laporan) | ✅ | §2 dokumen ini (4 standar, 22 baris kriteria) |
| **M6.3** | Jejak perubahan lengkap | Daftar berkas diubah + alasan + hasil uji | ✅ | §3 dokumen ini |
| **M6.4** | Prosedur rollback ada | Setiap tahap R1–R5 punya langkah rollback tertulis | ✅ | §5.2 dokumen ini |
| **M6.5** | Dapat diserahterimakan | Pengurus/DPS dapat memverifikasi klaim secara mandiri | ✅ | Panduan §4 (uji mandiri) + perintah gerbang `psak:validasi-simpanan-wajib` |

---

## 7. Penutup

Tahap 12 menutup **permasalahan pertama** secara jujur dan terukur:

1. ✅ **Kepatuhan PSAK 101 ¶ 31** untuk seluruh transaksi baru — Simpanan Wajib disajikan
   sebagai Modal Anggota (`3-1002`) melalui **satu titik resolusi tunggal**.
2. ✅ **Sistem tidak lagi kaku** — 5 parameter operasional dapat diubah Pengurus tanpa deploy.
3. ✅ **Gerbang kepatuhan otomatis** tersedia dan sudah membuktikan nilainya dengan menangkap
   bug nyata pada seeder.
4. ✅ **Tidak ada regresi** — 1120 test lulus.
5. ✅ **Dokumentasi lengkap** — panduan Pengurus + matriks kepatuhan + catatan rollback.
6. ⏳ **Dua item terbuka** dilaporkan terbuka tanpa disamarkan: reklasifikasi saldo legacy
   Rp 500.000 dan alur penarikan Simpanan Wajib.

**Langkah berikutnya (permasalahan kedua):** deploy ke produksi — acuan
`docs/DEPLOY-PRODUKSI.md`.

---

*Dokumen ini adalah bukti penutup Milestone R6 dan Langkah 6 dari 6.*
