# Laporan Verifikasi Modul Exit Keanggotaan (Pengunduran Diri & Pencairan Aset)

**Sistem:** Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4)
**Tanggal verifikasi:** 19 Oktober 2026
**Ruang lingkup:** Modul pengunduran diri anggota — `MemberTerminationController`, `ExitKeanggotaanService`, model `MemberTermination` / `Anggota` / `Rekening` / `Deposito`, notifikasi approval, dokumen cetak (PDF A4 & Thermal 80mm), route/RBAC, dan parameter `kebijakan_koperasis` yang dipakai.
**Metode:** Pembacaan kode (static), penelusuran pemakaian antar-lapisan (controller → service → jurnal → ledger → CoA), pengujian end-to-end pada basis data sungguhan (`DatabaseTransactions`), dan eksekusi suite regresi penuh.

---

## 1. Ringkasan Eksekutif

Dua skenario utama **berjalan sesuai spesifikasi** dan seluruh temuan audit **telah diperbaiki serta dikunci dengan test regresi**:

| Aspek | Hasil |
|---|---|
| Temuan audit | 9 temuan; 1 dibatalkan (false positive), **8 diperbaiki** |
| Skenario 1 — pengajuan & keluar dari organisasi | **LULUS** |
| Skenario 2 — pencairan seluruh aset pasca-keluar | **LULUS** |
| Uji modul exit (`-Filter "Exit"`) | **47 test lulus, 357 assertion** |
| Suite regresi penuh | **1763 test lulus, 5 skipped, 8358 assertion** (tanpa kegagalan) |
| Integritas jurnal | Debet = Kredit = 384.600,00 (seimbang, PSAK 108) |
| Idempotensi | Pencairan ulang tidak menggandakan jurnal maupun ledger |

**Kesimpulan:** fitur pengunduran diri anggota beserta pencairan seluruh asetnya **stabil dan aman dijalankan**. Tidak ada data yang hilang, tidak ada komponen aset yang tidak terproses, dan mekanisme otorisasi (RBAC), validasi, approval, serta jejak audit berfungsi.

---

## 2. Temuan Audit & Status Perbaikan

| # | Temuan | Dampak bila tidak diperbaiki | Status |
|---|---|---|---|
| 1 | `butuh_approval` tidak tersimpan saat pengajuan | Approval bisa terlewati | **Dibatalkan** — field sudah disimpan sejak awal (false positive saat audit) |
| 2 | `tolakApproval()` tidak memeriksa `approved_at` | Dokumen yang sudah disetujui bisa ditolak | **Diperbaiki** — guard `sudah disetujui pada …` |
| 3 | `store()` mengunci `status: DIAJUKAN` secara hardcode | Status `MENUNGGU_APPROVAL` tidak pernah terbentuk dari UI | **Diperbaiki** — status mengikuti hasil kalkulasi service |
| 4 | `tolak()` / `batalkan()` tanpa guard status terminal, audit, dan transaksi | Dokumen terminal bisa diubah; jejak audit hilang | **Diperbaiki** — guard terminal + `AuditLog` + `DB::transaction` |
| 5 | `show()` mengirim `'total' => pokok + wajib` berlabel "Total Dana Dikembalikan" | Anggota melihat angka pengembalian yang menyesatkan (tanpa sukarela, deposito, akrual, zakat, biaya) | **Diperbaiki** — dilabeli "Subtotal Pokok + Wajib (estimasi)" |
| 6 | Rekening/bank tujuan disimpan di luar `DB::transaction` | Tujuan transfer hilang bila transaksi jurnal gagal | **Diperbaiki** — `fill()` dipindah ke dalam transaksi |
| 7 | `kirimPermintaanApproval()` meng-hardcode peran `'manajer'` | Perubahan kewenangan di SSOT tidak diikuti notifikasi | **Diperbaiki** — memakai `Izin::peran('terminasi.approval')` |
| 8 | `keteranganTujuan()` membaca kolom DB yang belum di-`fill()` | Nomor rekening tujuan tidak tercatat di `transaksi.catatan` | **Diperbaiki** — menerima parameter langsung dari input |
| 9 | `closure_admin_fee` & `high_value_withdrawal_limit` tidak dapat diubah lewat UI (melanggar kontrak DYN-08) | Parameter exit hanya bisa diubah via basis data | **Diperbaiki** — form kebijakan, validasi, dan penyimpanan |

### 2.1 Rincian perbaikan temuan #7 dan #8

- **#7 (SSOT RBAC):** penerima notifikasi approval kini diambil dari `config/izin.php` → `Izin::peran('terminasi.approval')` (`manajer`, `pengurus`). Bila SSOT kosong, sistem menulis `Log::warning('EXIT_APPROVAL_TANPA_PERAN')` alih-alih diam.
- **#8 (keterangan tujuan):** `keteranganTujuan(string $metode, ?string $nomorRekening, ?string $namaBank)` kini menerima nilai input langsung, sehingga `transaksi.catatan` untuk transfer bank memuat `ke rekening <nomor> (<bank>)`.

### 2.2 Rincian perbaikan temuan #9 (form kebijakan)

Tiga titik perubahan agar parameter exit patuh kontrak DYN-08:

1. `resources/views/pengurus/kebijakan.blade.php` — blok "Penutupan Keanggotaan (Member Exit)" dengan dua input dan `step="{{ \App\Support\Setting::langkah('<key>') }}"`.
2. `app/Http/Controllers/Dashboard/PengurusController.php` — entri `$fieldNominal` (normalisasi format angka Indonesia) dan aturan validasi `nullable|numeric|min:0|max:99999999999.99`.
3. `PengurusController::updateKebijakan()` — assignment dengan fallback ke `Setting::closureAdminFee()` / `Setting::highValueWithdrawalLimit()` sehingga pembaruan sebagian tidak menimpa nilai yang ada.

Arti nilai `0`: biaya administrasi nihil, dan approval pencairan nonaktif (default aman).

---

## 3. Skenario 1 — Pengajuan Pengunduran Diri & Keluar dari Organisasi

**Fixture:** anggota aktif dengan Simpanan Pokok Rp 100.000 dan Simpanan Wajib Rp 50.000; tidak ada sukarela/deposito; tidak ada tagihan/pembiayaan berjalan.

### 3.1 Alur yang diverifikasi

| Langkah | Yang diuji | Hasil |
|---|---|---|
| Pengajuan via HTTP | `POST member-terminations.store` oleh **teller** | 302 ke halaman detail; dokumen tersimpan |
| Pratinjau nominal | pokok 100.000 / wajib 50.000 / sukarela 0 / deposito 0 / zakat 0 / biaya 0 | Tepat |
| `total_net` (estimasi) | 150.000 | Tepat |
| `total_dikembalikan` saat pengajuan | 0 (belum dibayar) | Tepat |
| Status dokumen | `DIAJUKAN` | Tepat |
| Status anggota | Tetap `ACTIVE` | Tepat — pengajuan ≠ keluar |
| Ledger sebelum pencairan | 0 baris transaksi | Tepat — tidak ada uang bergerak |

### 3.2 Validasi & keamanan

| Skenario | Perilaku | Hasil |
|---|---|---|
| Pengajuan ganda untuk anggota yang sama | Ditolak; tetap 1 dokumen | Tepat |
| Anggota berstatus `TERMINATED` mengajukan lagi | Ditolak; tidak ada dokumen baru | Tepat |
| Peran tanpa izin `terminasi.pengajuan` menembak endpoint | **HTTP 403** | Tepat |
| Masih ada tagihan Simpanan Wajib belum lunas | Exit ditolak | Tepat |
| Masih ada pembiayaan berjalan (NPF ≠ 0) | Exit ditolak | Tepat |
| Tidak ada saldo ekuitas | Exit ditolak | Tepat |

### 3.3 Jalur approval (pencairan bernilai tinggi)

Dengan `high_value_withdrawal_limit = 100.000` dan `total_net = 150.000`:

- Status menjadi **`MENUNGGU_APPROVAL`** dan `total_dikembalikan` tetap 0.
- Percobaan `eksekusi` **diblokir** — 0 transaksi terbentuk, status anggota tetap `ACTIVE`.
- Notifikasi `PengunduranDiriApprovalNotification` terkirim ke **seluruh peran SSOT `terminasi.approval`** (`manajer`, `pengurus`) dan **tidak** terkirim ke `teller`.
- Setelah disetujui (`approved_at` terisi), eksekusi dapat dijalankan; status berubah menjadi `DISETUJUI` dan CIF menjadi `TERMINATED` dengan estimasi tepat.
- Approval **tidak dapat** dibatalkan/ditolak setelah disetujui (temuan #2), dan tidak dapat diputuskan lagi setelah status terminal (temuan #4). Setiap penolakan/pembatalan meninggalkan jejak `AuditLog`.

---

## 4. Skenario 2 — Pencairan Seluruh Aset Setelah Anggota Keluar

**Fixture:** anggota dengan **seluruh jenis kepentingan** — Pokok Rp 100.000, Wajib Rp 50.000, Sukarela Rp 25.000, Deposito Rp 200.000 (12 bulan, nisbah anggota 60%), ditambah akrual bagi hasil. Parameter: `deposito_rate_pa = 8%`, `pembulatan_desimal = 0`, nishab zakat 1 gram × Rp 1.000 = Rp 1.000 (agar komponen zakat teruji), `closure_admin_fee = Rp 5.000`.

### 4.1 Rekonsiliasi perhitungan (diverifikasi numeric)

| Komponen | Perhitungan | Nominal (Rp) |
|---|---|---|
| Simpanan Pokok | — | 100.000 |
| Simpanan Wajib | — | 50.000 |
| Simpanan Sukarela | — | 25.000 |
| Simpanan Berjangka | — | 200.000 |
| Akrual bagi hasil | 200.000 × 8% × (365/365) × 60% | 9.600 |
| **Bruto** | 100.000 + 50.000 + 25.000 + 200.000 + 9.600 | **384.600** |
| Zakat | 2,5% × 384.600 | (9.615) |
| Biaya administrasi | parameter `closure_admin_fee` | (5.000) |
| **Net dikembalikan** | 384.600 − 9.615 − 5.000 | **369.985** |

Pratinjau saat pengajuan dan nilai final setelah eksekusi **identik** — tidak ada selisih kalkulasi antara estimasi dan realisasi.

### 4.2 Jurnal PSAK 108 (8 baris)

| Akun (CoA) | Debet | Kredit |
|---|---|---|
| 3-1001 Simpanan Pokok | 100.000 | — |
| 3-1002 Simpanan Wajib — Modal | 50.000 | — |
| 2-1001 Simpanan Sukarela | 25.000 | — |
| 2-1003 Simpanan Berjangka | 200.000 | — |
| 5-3001 Beban Bagi Hasil Deposito | 9.600 | — |
| 2-3002 Dana Zakat | — | 9.615 |
| 4-2001 Pendapatan Administrasi | — | 5.000 |
| 1-1001 Kas Teller | — | 369.985 |
| **Total** | **384.600** | **384.600** |

Jurnal berstatus **POSTED** dan seimbang (selisih 0,00).

### 4.3 Ledger transaksi

- **5 baris** `Transaksi` dengan `referensi = no_terminasi`: Pokok, Wajib, Sukarela, Berjangka, Akrual.
- Semua bertipe `CASH_OUT` (atau `BANK_OUT` untuk transfer), jumlah total **384.600**, dan setiap baris mencatat `user_id` operator pemroses.

### 4.4 Penutupan seluruh kepentingan

| Objek | Kondisi akhir | Hasil |
|---|---|---|
| Semua `Rekening` anggota | `status_rekening = Ditutup`, `saldo = 0`, `closed_at` & `closed_by` terisi | Tepat |
| `Deposito` | `status = CLOSED` (tidak ada ARO untuk anggota keluar) | Tepat |
| CIF `Anggota` | `status_anggota = TERMINATED`, `isTerminated() = true` | Tepat |
| Rekonsiliasi CIF | `total_dana_dikembalikan = 369.985`, `terminasi_diproses_oleh = teller`, `tanggal_keluar/tanggal_terminasi/exit_date = 2026-01-01`, `exit_reason` tersimpan | Tepat |
| Dokumen terminasi | `status = DISETUJUI`, `jurnal_id`, `no_jurnal`, `transaksi_id`, `diproses_pada` terisi | Tepat |
| Jejak audit | `AuditLog` jenis `EXIT_KEANGGOTAAN` memuat nama anggota | Tepat |

### 4.5 Transfer bank

- `metode = TRANSFER_BANK` tanpa nomor rekening tujuan → **ditolak**.
- Dengan tujuan lengkap → jurnal mengkredit **1-1002 Bank Koperasi** sebesar net (bukan Kas Teller), dan nomor rekening + nama bank tujuan tercatat di `transaksi.catatan` (mengunci perbaikan temuan #8).

### 4.6 Idempotensi & integritas

| Uji | Hasil |
|---|---|
| `eksekusi` dipanggil ulang pada dokumen yang sudah diposting | Tidak membuat jurnal kedua, tidak menggandakan baris ledger |
| Metode pengembalian tidak dikenal | Ditolak sebelum menyentuh data |
| Dokumen terminasi | Append-only setelah jurnal diposting |
| Pembatalan setelah jurnal diposting | Ditolak dengan pesan yang menyebut PSAK 108 |

---

## 5. Mekanisme Keamanan yang Terverifikasi

1. **RBAC (SSOT `config/izin.php` + middleware `izin`)**
   - `terminasi.pengajuan` / `terminasi.eksekusi` → `teller`, `manajer`, `pengurus`
   - `terminasi.approval` → `manajer`, `pengurus`
   - Peran di luar daftar menerima **403**; peran tanpa izin tidak dapat mengunduh dokumen.
2. **Approval berjenjang** — ambang `high_value_withdrawal_limit` memblokir pencairan sampai `approved_at` terisi; notifikasi mengikuti SSOT, bukan peran yang di-hardcode.
3. **Audit trail** — pengajuan, approval, penolakan, pembatalan, eksekusi, dan penutupan rekening meninggalkan jejak `AuditLog`.
4. **PSAK 108** — dokumen yang sudah dijurnal tidak dapat dibatalkan; tidak ada reversal diam-diam.
5. **PII (UU PDP 27/2022)** — NIK penuh hanya pada PDF A4 (dokumen resmi), tersamar pada struk thermal 80mm; dokumen cetak hanya tersedia **setelah jurnal diposting**, dengan nomor `EXIT/[TahunBulan]/[6Digit]`, barcode Code 39, dan kode verifikasi HMAC.
6. **Integritas transaksi** — `NomorSequence` di dalam transaksi (race-safe), isolasi `READ COMMITTED`, dan seluruh mutasi uang dalam satu `DB::transaction`.

---

## 6. Hasil Pengujian

### 6.1 Berkas uji utama

`tests/Feature/ExitEndToEndTest.php` — **11 test, 149 assertion**

| Kelompok | Test |
|---|---|
| Skenario 1 | pengajuan via HTTP; pengajuan ganda & anggota terminated; high-value approval; RBAC |
| Skenario 2 | pencairan seluruh aset; transfer bank; idempotensi pencairan ulang |
| Regresi audit | tolak setelah disetujui; tolak/batalkan status terminal; tolak/batalkan setelah jurnal diposting; notifikasi approval mengikuti SSOT |

### 6.2 Rekap eksekusi

| Perintah | Hasil |
|---|---|
| `run-tests.ps1 -Filter "Exit"` | **47 passed (357 assertions)**, 11,86 detik |
| `run-tests.ps1 -Filter "Terminasi"` | 1 passed (6 assertions) |
| `run-tests.ps1` (suite penuh) | **5 skipped, 1763 passed (8358 assertions)**, 293,49 detik, exit code 0 |
| `run-tests.ps1 -Filter "KebijakanFormMetadataTest"` | 4 passed (43 assertions) |

Kegagalan tunggal yang sempat muncul pada suite penuh — `KebijakanFormMetadataTest::test_semua_parameter_editable_muncul_di_form` — adalah temuan #9 (DYN-08) dan telah teratasi; suite penuh berikutnya lulus tanpa kegagalan.

---

## 7. Catatan Lingkungan & Prosedur Reproduksi

- Jalur uji resmi: `powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter "<pola>"` (membersihkan `bootstrap/cache` lebih dulu; cache config dapat membekukan `APP_KEY` dan memicu 419 massal).
- Test memakai `DatabaseTransactions` sehingga data uji tidak tertinggal; `Setting::flush()` dipanggil pada `setUp`/`tearDown` agar cache parameter tidak bocor antar-test.
- Isolasi `SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED` wajib dipasang **sebelum** `DB::transaction()` pada jalur eksekusi.

---

## 8. Sisa Risiko & Rekomendasi

| Risiko | Tingkat | Rekomendasi |
|---|---|---|
| Akrual deposito memakai basis 365 hari penuh untuk seluruh tenor | Rendah | Konfirmasi kebijakan pembulatan akrual partial-period dengan tim akuntansi bila deposito dicairkan sebelum jatuh tempo |
| Approval default nonaktif (`high_value_withdrawal_limit = 0`) | Rendah | Tetapkan ambang di halaman Kebijakan sesuai SOP koperasi sebelum go-live |
| Zakat bergantung pada nishab & harga emas manual | Rendah | Jadwalkan pembaruan `harga_emas_per_gram` berkala |

Tidak ada temuan terbuka yang berdampak pada integritas jurnal, data anggota, atau kewenangan.

---

**Status akhir: MODUL EXIT KEANGGOTAAN — TERVERIFIKASI & STABIL.**
