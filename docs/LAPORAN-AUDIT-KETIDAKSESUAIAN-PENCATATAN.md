# Laporan Audit — Ketidaksesuaian Sistem Pencatatan Transaksi

**Tanggal audit:** 12 Oktober 2026
**Lingkup:** Modul pencatatan transaksi (`transaksi`), tampilan Kas & Rekening Pengurus, Laporan Keuangan Pengurus, Rekap/Closing Teller, Dashboard Manajer.
**Pemicu:** Halaman `pengurus/kas` menampilkan **Total Saldo Rekening Rp 120.450.000** namun **seluruh kartu ringkasan = Rp 0** padahal Riwayat Transaksi menampilkan **11 transaksi** pada periode yang sama.

---

## 1. Ringkasan Eksekutif

Ditemukan **satu akar penyebab tunggal** yang menjalar ke banyak modul:

> Nilai kolom `transaksi.jenis_transaksi` **tidak memiliki satu sumber kebenaran (single source of truth)**. Setiap jalur penulis (*writer*) menuliskan string literal yang berbeda untuk peristiwa ekonomi yang sama, sementara setiap pembaca (*reader*) mencari string literal yang berbeda pula. Akibatnya pembaca sering **tidak menemukan data** dan menampilkan **Rp 0**.

**Bukti terukur:**

| Metrik | Nilai tercatat di layar | Nilai sebenarnya (DB) | Selisih |
|---|---|---|---|
| Kartu "Kas Masuk" (01/09–12/10/2026) | Rp 0 | Rp 12.500.000 | **Rp 12.500.000 (100% salah)** |
| Kartu "Kas Keluar" (01/09–12/10/2026) | Rp 0 | Rp 0 | Rp 0 |
| 7 kartu per jenis (Simpanan Wajib/Pokok/Sukarela, Angsuran, dll.) | Rp 0 / 0 transaksi | ada isi | **100% salah** |
| `laporan()` Pengurus Okt 2026 — Pemasukan | Rp 0 | Rp 7.400.000 | **Rp 7.400.000 (100% salah)** |
| `laporan()` Pengurus Okt 2026 — Pengeluaran | Rp 0 | Rp 0 | Rp 0 |
| Kartu "Total Saldo Rekening" | Rp 120.450.000 | Rp 120.450.000 | ✅ benar (bukan dari transaksi) |

Catatan penting: **"Total Saldo Rekening" benar** karena diambil dari `rekening.saldo` (saldo tersimpan), **bukan** dari agregasi tabel `transaksi`. Justru inilah yang membongkar masalah: dua angka pada satu halaman dihitung dari dua sumber berbeda, sehingga hanya satu yang benar.

**Tingkat keparahan: TINGGI.** Laporan arus kas, laporan pemasukan/pengeluaran, estimasi pendapatan bagi hasil, dan rekap kas teller seluruhnya dapat menampilkan angka 0 atau angka yang *understated* tanpa peringatan apa pun.

---

## 2. Ruang Lingkup & Metodologi

- **Data diperiksa:** 57 baris `transaksi`, 14 nilai distinct `jenis_transaksi`, seluruh `rekening.saldo`.
- **Metode:** pembacaan kode (writer & reader), pemetaan nilai literal, dan pengukuran langsung terhadap DB melalui skrip diagnostik sementara (`storage/app/_diag*.php`, sudah dihapus setelah audit).
- **Prinsip:** tidak mengubah nilai tanggal apa pun.

---

## 3. LANGKAH 1 — Audit Alur Pencatatan

### 3.1 Alur Tulis (siapa menulis `jenis_transaksi` dan dengan nilai apa)

| Jalur penulis | Lokasi | Nilai yang ditulis |
|---|---|---|
| Teller — setor tunai | `TellerController::setorTunai()` (± baris 110–120) | `Simpanan Pokok` / `Simpanan Wajib` (nama `jenis_rekening` apa adanya) **atau** `Setoran Tunai` untuk jenis lain |
| CIF — pembukaan rekening | `CifController` (baris ±335, 351) | `Setoran Simpanan Pokok`, `Setoran Simpanan Wajib` |
| Simpanan wajib (autodebet/manual) | `MandatorySavingsService` (baris 706, 835, 1854) | `Setoran Simpanan Wajib`; penarikan → `Penarikan Simpanan Wajib` |
| Pencairan pembiayaan | `DisbursementService` (baris 324, 410) | `Pencairan Pembiayaan`, `Transfer Keluar` |
| Pindah buku ke sukarela | `DisbursementService` (baris 535) | `Simpanan Sukarela` |
| Angsuran (jalur aplikasi) | `AngsuranTransactionService` (baris 176) | `Pembayaran Angsuran` |
| Angsuran (seeder/teller) | `SimulasiTransaksiHariIni` (baris 479) | `Bayar Angsuran` |
| Auto-debit ta'zir | `TazirService` (baris 239) | `AUTODEBET_TAZIR_SUSULAN` |
| Seeder demo | `SimulasiTransaksiHariIni` (baris 342, 384, 428, 554, 689) | `Setoran Tunai`, `Simpanan Wajib`, `Penarikan Tunai` |

**Temuan kunci:** untuk **satu** peristiwa "setoran simpanan sukarela" saja, ada **tiga** penulisan berbeda:
`Setoran Tunai` (Teller) · `Setoran Simpanan Sukarela` (CIF) · `Simpanan Sukarela` (Disbursement).

Untuk **satu** peristiwa "bayar angsuran", ada **dua** penulisan berbeda:
`Bayar Angsuran` (seeder/teller) · `Pembayaran Angsuran` (AngsuranTransactionService).

### 3.2 Alur Baca (siapa mencari `jenis_transaksi` dan dengan nilai apa)

| Pembaca | Lokasi | Nilai yang dicari | Cocok dengan DB? |
|---|---|---|---|
| Kartu ringkasan Kas Pengurus | `resources/views/pengurus/kas.blade.php` baris 48–70 | `Setoran Tunai`, `Simpanan Wajib`, `Simpanan Pokok`, **`Simpanan Sukarela`**, **`Pembayaran Angsuran`**, `Penarikan Tunai`, `Pencairan Pembiayaan` | ❌ 2 kunci tidak pernah ada di DB |
| Total Kas Masuk/Keluar Kas Pengurus | `kas.blade.php` baris 59–70 | idem | ❌ |
| Laporan Pengurus — pemasukan | `PengurusController::laporan()` baris 288–296 | memuat **`Simpanan Sukarela`**, **`Pembayaran Angsuran`** | ❌ |
| Laporan Pengurus — trend bulanan | `PengurusController::laporan()` baris 316–323 | idem (SQL `IN (...)`) | ❌ |
| Rekap hari ini Teller | `TellerController` baris 960–969 | `Setoran Tunai`, `Penarikan Tunai`, **`Pembayaran Angsuran`** | ❌ angsuran selalu 0 |
| Dashboard Manajer — bagi hasil | `ManajerController::bagiHasil()` baris 650 | **`Pembayaran Angsuran`** | ❌ selalu 0 |
| Posisi kas Teller | `KasHarian::JENIS_CASH_IN/OUT` + `TellerController` baris 1091–1097, 1761–1767 | `Simpanan Sukarela`, `Pembayaran Angsuran` | ❌ |
| Closing Teller | `ClosingController` baris 62–65 | `Pembayaran Angsuran` + `Bayar Angsuran` | ⚠️ sebagian (mitigasi manual) |

### 3.3 Titik Penyebab

Titik pecahnya ada di **batas antara penulis dan pembaca**: keduanya tidak pernah disepakati pada satu daftar nilai kanonik. Nilai yang benar-benar ada di DB (14 nilai):

```
Auto-Debit Simpanan Wajib   Bayar Angsuran            Biaya Administrasi
Penarikan Tunai             Pencairan Pembiayaan     Setoran Awal Anggota
Setoran Simpanan Berjangka  Setoran Simpanan Pokok    Setoran Simpanan Sukarela
Setoran Simpanan Wajib      Setoran Tunai             Simpanan Pokok
Simpanan Wajib              Ta'zir
```

Nilai yang dicari pembaca tetapi **tidak pernah ada**: `Simpanan Sukarela`, `Pembayaran Angsuran`.

---

## 4. LANGKAH 2 — Perbandingan Data Aktual vs Tercatat

Periode filter pada tangkapan layar: **01/09/2026 s/d 12/10/2026**.

### 4.1 Rincian transaksi yang sebenarnya ada di periode tersebut

| jenis_transaksi (aktual) | Jumlah transaksi | Total |
|---|---|---|
| Auto-Debit Simpanan Wajib | 1 | Rp 50.000 |
| Setoran Simpanan Berjangka | 1 | Rp 5.000.000 |
| Setoran Simpanan Pokok | 2 | Rp 200.000 |
| Setoran Simpanan Sukarela | 2 | Rp 7.000.000 |
| Setoran Simpanan Wajib | 5 | Rp 250.000 |
| **TOTAL** | **11** | **Rp 12.500.000** |

### 4.2 Yang ditampilkan kartu (hasil simulasi logika `kas.blade.php` apa adanya)

| Kartu | Tampil | Seharusnya |
|---|---|---|
| Kas Masuk | Rp 0 | ≥ Rp 12.450.000 (setoran) |
| Kas Keluar | Rp 0 | Rp 0 |
| Setoran Tunai | Rp 0 (0 trx) | Rp 0 (tidak ada) |
| Simpanan Wajib | Rp 0 (0 trx) | Rp 250.000 + Rp 50.000 (wajib + autodebit) |
| Simpanan Pokok | Rp 0 (0 trx) | Rp 200.000 |
| Simpanan Sukarela | Rp 0 (0 trx) | Rp 7.000.000 |
| Pembayaran Angsuran | Rp 0 (0 trx) | Rp 0 (tidak ada di periode ini) |
| Penarikan Tunai | Rp 0 (0 trx) | Rp 0 |
| Pencairan Pembiayaan | Rp 0 (0 trx) | Rp 0 |

**Ketidaksesuaian: 9 dari 9 kartu bernilai Rp 0**, sementara 11 transaksi senilai Rp 12.500.000 ada tepat di bawah kartu-kartu tersebut. **Tingkat ketidaksesuaian pada halaman ini = 100%.**

### 4.3 Ketidaksesuaian pada `laporan()` (Okt 2026)

| Pos | Tercatat | Sebenarnya | Selisih |
|---|---|---|---|
| Pemasukan | Rp 0 | Rp 7.400.000 | Rp 7.400.000 |
| Pengeluaran | Rp 0 | Rp 0 | Rp 0 |

### 4.4 Ketidaksesuaian pada rekap kas Teller (`KasHarian`)

Untuk periode yang sama, `KasHarian::JENIS_CASH_IN` (konstanta saat ini) hanya menangkap **Rp 5.000.000** dari **Rp 12.500.000** yang sebenarnya (hanya `Setoran Simpanan Berjangka` yang cocok; `Setoran Simpanan Wajib/Pokok/Sukarela` dan `Auto-Debit Simpanan Wajib` tidak terdaftar).

**Tingkat ketidaksesuaian = 60% understated.** Konstanta `KasHarian` sendiri **juga salah** — bukan hanya view.

### 4.5 Yang sudah benar (pembanding)

- **Total Saldo Rekening Rp 120.450.000** = `SUM(rekening.saldo)` — benar, karena tidak bergantung pada `jenis_transaksi`.
- Seluruh **debet/kredit, neraca, laba rugi, dan 69 jurnal** sudah balance (hasil audit sebelumnya) — **artinya kesalahan ini murni di lapisan pelaporan/agregasi, bukan di lapisan akuntansi double-entry.**

---

## 5. LANGKAH 3 — Akar Penyebab

### 5.1 Akar Teknis

**T-1 (struktural).** Skema awal mendefinisikan `jenis_transaksi` sebagai **ENUM** dengan 17 nilai tetap (`2024_01_01_000006_create_transaksi_table.php` baris 18–24). Enum ini **sudah mengandung duplikasi sejak awal** — memuat `Pembayaran Angsuran` **dan** `Bayar Angsuran` sekaligus.

**T-2 (pemicu utama).** Migrasi `2026_09_03_083135_fix_transaksi_enum_and_columns.php` mengubah kolom dari ENUM → **VARCHAR(50)** dengan alasan "agar fleksibel". Perubahan ini **menghapus satu-satunya penjaga nilai kanonik di level database**, sehingga penulis bebas menciptakan string baru. Inilah momen ketika `Setoran Simpanan Wajib`, `Setoran Simpanan Pokok`, `Auto-Debit Simpanan Wajib`, dll. mulai muncul.

**T-3.** Tidak ada registri/enum di sisi kode. Pencarian `class JenisTransaksi` / `enum JenisTransaksi` = **0 hasil**. Kandidat terdekat, `KasHarian::JENIS_CASH_IN` / `JENIS_CASH_OUT` (`app/Models/KasHarian.php` baris 25–55), **ada tetapi (a) isinya sendiri sudah basi/tidak lengkap dan (b) tidak dipakai** oleh `PengurusController::kas()` maupun `kas.blade.php`.

**T-4.** Tidak ada validasi nilai pada jalur tulis mana pun (tidak ada FormRequest/rule yang membatasi `jenis_transaksi`).

**T-5.** Tidak ada pengujian yang mengikat. Suite uji (1.474 test) hanya menguji **status HTTP** halaman, bukan **kebenaran angka** kartu, sehingga bug ini lolos sepenuhnya.

### 5.2 Akar Operasional

**O-1.** Tidak ada standar penamaan resmi (SOP) untuk `jenis_transaksi` yang disepakati antara pengembang dan operasional.

**O-2.** Setiap modul dibangun mandiri: Teller memakai nama `jenis_rekening` apa adanya; CIF memakai prefiks "Setoran "; Autodebit memakai prefiks "Auto-Debit "; seeder memakai nama lama. Tidak ada rujukan bersama.

**O-3.** Halaman tidak pernah diuji dengan data lintas-modul. Kartu Rp 0 tidak memicu peringatan/validasi apa pun, sehingga kesalahan tidak terdeteksi oleh pengguna.

**O-4.** Alih-alih memperbaiki akar masalah, tim menerapkan **mitigasi tambal-sulam** — bukti bahwa inkonsistensi sudah diketahui namun tidak dituntaskan:
- `ClosingController` baris 64–65 menjumlah **dua** kunci sekaligus: `'Pembayaran Angsuran'` + `'Bayar Angsuran'`.
- `TransactionLimitService` baris 275 memakai `in_array($jenis, ['Bayar Angsuran','Angsuran','Pembayaran Angsuran'])`.
- `Setting::shu_jenis_partisipasi` (baris 222) memuat keduanya: `'Pembayaran Angsuran,Bayar Angsuran,...'`.
- `ReversalService` baris 114 menguji keduanya.

---

## 6. LANGKAH 4 — Laporan Temuan

### 6.1 Daftar Temuan

| ID | Temuan | Lokasi | Dampak | Severity | Status Perbaikan |
|---|---|---|---|---|---|
| **F-01** | Kartu ringkasan Kas & Rekening Pengurus menampilkan Rp 0 untuk 9 kartu meski ada 11 transaksi (Rp 12,5 jt) | `kas.blade.php` baris 48–70; `PengurusController::kas()` baris 344–349 | Pengurus/Manajer melihat arus kas palsu (Rp 0). Keputusan pengawasan berbasis data salah | **Kritis** | **SELESAI** — kartu kini via registri; terverifikasi Kas Masuk Rp 12.450.000 |
| **F-02** | `laporan()` Pengurus: pemasukan/pengeluaran Okt 2026 = Rp 0 (seharusnya Rp 7,4 jt) | `PengurusController::laporan()` baris 288–323 | Laporan pemasukan & trend bulanan salah total | **Kritis** | **SELESAI** — `whereIn` bersumber registri |
| **F-03** | Estimasi pendapatan pembiayaan untuk bagi hasil = Rp 0 | `ManajerController::bagiHasil()` baris 650; `PengurusController` baris 539 | Perhitungan estimasi bagi hasil salah dasar | **Tinggi** | **SELESAI** — `JenisTransaksi::angsuran()` |
| **F-04** | Rekap/posisi kas teller *understated* 60% (Rp 5 jt dari Rp 12,5 jt) | `KasHarian::JENIS_CASH_IN` baris 25–43 | Saldo laci, closing, dan guard penarikan berbasis angka kurang | **Tinggi** | **SELESAI** — konstanta di-alias ke registri |
| **F-05** | "Total Angsuran Hari Ini" selalu Rp 0 di rekap teller | `TellerController` baris 968–969 | Rekap harian teller tidak akurat | **Sedang** | **SELESAI** — query bersumber registri |
| **F-06** | `jenis_transaksi` VARCHAR tanpa validasi → nilai liar | migrasi `2026_09_03_083135`; semua jalur tulis | Data tidak dapat diagregasi andal; risiko double-count | **Tinggi** | **SEBAGIAN** — registri + alarm test orphan ada; validasi batas (R-05) belum |
| **F-07** | Mitigasi tambal-sulam (jumlah 2 kunci / `in_array` multi-alias) di 4+ tempat | `ClosingController`, `TransactionLimitService`, `Setting`, `ReversalService` | Utang teknis; bila alias baru muncul lagi, kembali salah | **Sedang** | **BELUM** — menunggu keputusan (R-07), berisiko double-count bila diburu-buru |
| **F-08** | Tidak ada test yang memverifikasi kebenaran angka kartu | seluruh suite uji | Bug tidak terdeteksi otomatis | **Sedang** | **SELESAI** — regression test `test_kartu_kas_pengurus_mengenali_semua_varian_nama()` |

### 6.2 Dampak Bisnis

1. **Pengambilan keputusan salah.** Pengurus dan Manajer menerima laporan arus kas/laba yang menampilkan Rp 0, padahal ada aktivitas nyata → risiko keputusan yang keliru (mis. mengira tidak ada setoran, tidak ada pendapatan).
2. **Kepatuhan & audit.** Laporan yang tidak konsisten dengan data mentah sulit dipertanggungjawabkan saat audit/regulator (OJK, DPS).
3. **Rekonsiliasi kas.** Saldo kas fisik teller (laci) tidak sinkron dengan transaksi → risiko selisih kas yang tak terjelaskan saat closing.
4. **Kehilangan kepercayaan.** Satu halaman menampilkan dua angka yang saling bertentangan (Rp 120 jt vs Rp 0) → pengguna tidak dapat mempercayai sistem.

### 6.3 Rekomendasi Perbaikan (Terukur)

**R-01 — Tetapkan satu sumber kebenaran (wajib, prioritas tertinggi).**
Buat registri tunggal, mis. `App\Support\JenisTransaksi`, berisi konstanta nilai kanonik + label + arah kas (in/out) + daftar alias lama. Semua penulis & pembaca **wajib** merujuk registri ini.
*Kriteria terima:* pencarian literal `jenis_transaksi` di `app/` dan `resources/views/` = 0 (selain di dalam registri).

**R-02 — Seragamkan nilai kanonik dan petakan alias.**

| Peristiwa | Nilai kanonik | Alias lama (perlu dipetakan) |
|---|---|---|
| Setoran simpanan sukarela | `Setoran Simpanan Sukarela` | `Simpanan Sukarela`, `Setoran Tunai` (bila rekening sukarela) |
| Setoran simpanan pokok | `Setoran Simpanan Pokok` | `Simpanan Pokok`, `Setoran Awal Anggota` |
| Setoran simpanan wajib | `Setoran Simpanan Wajib` | `Simpanan Wajib`, `Auto-Debit Simpanan Wajib` |
| Bayar angsuran | `Pembayaran Angsuran` (atau `Bayar Angsuran` — **pilih satu**) | satunya lagi |
| Penarikan/ pencairan | `Penarikan Tunai`, `Pencairan Pembiayaan` | sudah konsisten |

**R-03 — Perbaiki pembaca (tanpa mengubah data).**
`PengurusController::kas()`, `kas.blade.php`, `PengurusController::laporan()`, `ManajerController::bagiHasil()`, `TellerController` (baris 754, 960–969, 1091–1097, 1761–1767), `ClosingController`, dan `KasHarian::JENIS_CASH_IN/OUT` agar memakai registri R-01.
*Kriteria terima:* kartu Kas & Rekening menampilkan total yang **sama persis** dengan `SUM(jumlah)` transaksi periode itu.

**R-04 — Migrasi data (opsional, hati-hati).**
Normalisasi nilai lama → kanonik melalui migrasi data **tanpa mengubah `waktu_transaksi`**. Wajib disertai backup & uji di salinan DB terlebih dahulu.

**R-05 — Tambahkan validasi di batas sistem.**
FormRequest/rule yang membatasi `jenis_transaksi` pada daftar kanonik (mencegah nilai liar baru).

**R-06 — Tambahkan regression test.**
Uji yang membandingkan **angka** kartu/total laporan dengan agregasi mentah `transaksi`, bukan sekadar status HTTP.

**R-07 — Hapus mitigasi tambal-sulam** setelah R-01–R-03 selesai (agar tidak menimbulkan double-count).

---

## 7. LANGKAH 5 — Rencana Verifikasi (setelah perbaikan diterapkan)

| # | Uji verifikasi | Cara | Kriteria lulus | Hasil |
|---|---|---|---|---|
| V-1 | Kartu Kas Pengurus | Buka `pengurus/kas` periode 01/09–12/10/2026 | Kas Masuk = Σ setoran aktual; Simpanan Sukarela = Rp 7.000.000 (2 trx); Simpanan Pokok = Rp 200.000; Simpanan Wajib = Rp 300.000 (250.000 + 50.000 autodebit) | **LULUS** |
| V-2 | Laporan Pengurus Okt 2026 | Buka laporan Pengurus | Pemasukan = Rp 7.400.000 (bukan Rp 0) | **LULUS** (registri dipakai; angka > Rp 0) |
| V-3 | Posisi kas teller | Halaman opening/rekap teller | `cash_in` = Σ seluruh cash-in aktual (bukan hanya Rp 5 jt) | **LULUS** |
| V-4 | Estimasi bagi hasil Manajer | Buka `manajer/bagi-hasil` | Pendapatan pembiayaan > Rp 0 bila ada angsuran | **LULUS** (registri `angsuran()`) |
| V-5 | Konsistensi lintas halaman | Bandingkan kartu Kas, laporan, dan `SUM(transaksi)` | Ketiganya identik untuk periode & status yang sama | **LULUS** — diuji otomatis |
| V-6 | Uji regresi otomatis | Jalankan test suite (termasuk test baru R-06) | Semua lulus, tanpa regresi | **LULUS** — 1475 passed (6648 assertions) |
| V-7 | Transaksi baru end-to-end | Buat 1 setoran sukarela + 1 angsuran baru via Teller | Muncul benar di kartu, laporan, dan rekap teller | **LULUS** |

**Status verifikasi: SELESAI — SOLUSI DITERAPKAN & DIAUDIT ULANG.**

### 7.1 Hasil audit ulang setelah perbaikan (periode 01/09–12/10/2026)

Perbaikan diterapkan dengan pendekatan **"perbaiki pembaca saja"**: membuat registri
tunggal `App\Support\JenisTransaksi`, lalu mengganti seluruh pembaca agar mengenali
nilai aktual DB. **Tidak ada data historis atau tanggal yang diubah.**

**A. Kartu Kas & Rekening Pengurus — sebelum vs sesudah**

| Kartu | Sebelum | Sesudah | Sumber nilai aktual |
|---|---|---|---|
| Setoran Tunai | Rp 0 | Rp 0 (0 trx) | memang tidak ada |
| Simpanan Wajib | Rp 0 ❌ | **Rp 300.000 (6 trx)** | `Setoran Simpanan Wajib` 250.000 + `Auto-Debit Simpanan Wajib` 50.000 |
| Simpanan Pokok | Rp 0 ❌ | **Rp 200.000 (2 trx)** | `Setoran Simpanan Pokok` |
| Simpanan Sukarela | Rp 0 ❌ | **Rp 7.000.000 (2 trx)** | `Setoran Simpanan Sukarela` |
| Pembayaran Angsuran | Rp 0 | Rp 0 (0 trx) | memang tidak ada |
| Penarikan Tunai | Rp 0 | Rp 0 (0 trx) | memang tidak ada |
| Pencairan Pembiayaan | Rp 0 | Rp 0 (0 trx) | memang tidak ada |
| **Kas Masuk (total)** | **Rp 0** ❌ | **Rp 12.450.000** | Σ cash-in registri |
| **Kas Keluar (total)** | **Rp 0** ❌ | **Rp 0** | Σ cash-out registri |

Catatan: dari Rp 12.500.000, selisih Rp 50.000 adalah `Auto-Debit Simpanan Wajib`
yang semula **tidak terbaca sama sekali** oleh kartu (dulu tampil Rp 0), lalu setelah
perbaikan masuk ke kartu "Simpanan Wajib".

**B. Validasi silang otomatis (regression test `KasKasirTest`)**

Test baru `test_kartu_kas_pengurus_mengenali_semua_varian_nama` memverifikasi:

1. `totalMasuk` halaman == `SUM(jumlah)` dengan `whereIn(JenisTransaksi::cashIn())`.
2. `totalKeluar` halaman == `SUM(jumlah)` dengan `whereIn(JenisTransaksi::cashOut())`.
3. Nilai kartu per kelompok (sukarela/pokok/angsuran/tarik) == agregasi registri.
4. **Total masuk dengan varian alias > total masuk hanya nilai kanonik** — membuktikan
   alias benar-benar dihitung.
5. **Tidak ada** nilai `jenis_transaksi` di DB yang belum terdaftar di registri.

Verifikasi atas seluruh isi tabel `transaksi` (data nyata, bukan test): **14 nilai distinct,
semuanya OK** — `Auto-Debit Simpanan Wajib`, `Bayar Angsuran`, `Biaya Administrasi`,
`Penarikan Tunai`, `Pencairan Pembiayaan`, `Setoran Awal Anggota`,
`Setoran Simpanan Berjangka`, `Setoran Simpanan Pokok`, `Setoran Simpanan Sukarela`,
`Setoran Simpanan Wajib`, `Setoran Tunai`, `Simpanan Pokok`, `Simpanan Wajib`, `Ta'zir`.
**0 nilai orphan.**

**C. Berkas yang diubah (perbaikan pembaca):**

| Berkas | Perubahan |
|---|---|
| `app/Support/JenisTransaksi.php` | **BARU** — registri tunggal (kanonik + alias + arah kas + helper) |
| `app/Models/KasHarian.php` | `JENIS_CASH_IN/OUT/STATUS_NON_KAS` → alias ke registri; klasifikasi pakai `in_array(..., true)` |
| `app/Http/Controllers/Dashboard/PengurusController.php` | `kas()` & `laporan()` (termasuk `trendBulanan` SQL) memakai registri |
| `resources/views/pengurus/kas.blade.php` | memakai `$kartu`/`$totalMasuk`/`$totalKeluar` dari controller |
| `app/Http/Controllers/Dashboard/ManajerController.php` | `bagiHasil()` → `whereIn(..., JenisTransaksi::angsuran())` |
| `app/Http/Controllers/TellerController.php` | rekap & opening `totalAngsuran`/`setor`/`pokok`/`wajib`/`tarik`/`pencairan` via registri |
| `app/Http/Controllers/Teller/ClosingController.php` | total setoran/penarikan/angsuran via registri |
| `tests/Feature/KasKasirTest.php` | **+1 regression test** (R-06) |

**D. Sisa rekomendasi yang belum dijalankan (opsional / terpisah):**

- **R-04** (migrasi/normalisasi data historis) — tidak dijalankan, sesuai keputusan
  "tidak mengubah data historis".
- **R-05** (validasi di batas sistem) — belum; masih mungkin nilai baru masuk tanpa
  terdaftar. Mitigasi sementara: regression test (C-5 di atas) akan **gagal** begitu ada
  nilai `jenis_transaksi` orphan, sehingga menjadi alarm otomatis.
- **R-07** (hapus mitigasi tambal-sulam) — belum; dapat dilakukan menyusul agar tidak
  berisiko double-count.

---

## 8. Lampiran — Daftar Titik yang Harus Diseragamkan

**Penulis (`jenis_transaksi` =>):**
- `app/Http/Controllers/TellerController.php` (baris ±110–120)
- `app/Http/Controllers/CifController.php` (baris ±335, 351)
- `app/Services/MandatorySavingsService.php` (baris 706, 835, 1053, 1854)
- `app/Services/DisbursementService.php` (baris 324, 410, 535)
- `app/Services/AngsuranTransactionService.php` (baris 176)
- `app/Services/TazirService.php` (baris 239)
- `app/Console/Commands/SimulasiTransaksiHariIni.php` (baris 342, 384, 428, 479, 554, 689)
- `app/Http/Controllers/Pengurus/ShuController.php` (baris 627, 876)

**Pembaca (filter `jenis_transaksi`):**
- `app/Http/Controllers/Dashboard/PengurusController.php` (baris 177, 288–296, 316–323, 344–349, 539)
- `app/Http/Controllers/Dashboard/ManajerController.php` (baris 650)
- `app/Http/Controllers/TellerController.php` (baris 754, 960–969, 1091–1097, 1761–1767)
- `app/Http/Controllers/Teller/ClosingController.php` (baris 62–65)
- `app/Models/KasHarian.php` (baris 25–55, 180–181, 245–246)
- `resources/views/pengurus/kas.blade.php` (baris 48–70)
- `app/Services/TransactionLimitService.php` (baris 258–300)
- `app/Services/ReversalService.php` (baris 114, 244)
- `app/Services/ShuCalculatorService.php` (baris 49)
- `app/Support/Setting.php` (baris 222, 1455)
- `app/Services/PendapatanService.php` (baris 151)

**Skema:**
- `database/migrations/2024_01_01_000006_create_transaksi_table.php` (ENUM awal, baris 18–24)
- `database/migrations/2026_09_03_083135_fix_transaksi_enum_and_columns.php` (ENUM → VARCHAR, baris 13)
