# Laporan Audit Kepatuhan Chart of Accounts (COA)

**Sistem:** Koperasi Syariah
**Periode Audit:** 09 Juli 2026 – 09 Oktober 2026 (3 bulan terakhir)
**Tanggal Audit:** 09 Oktober 2026
**Ruang Lingkup:** 37 akun COA aktif, 62 jurnal Posted (`jurnal_detail`), selaras PSAK 101/102/105/107/108/109/71 & Fatwa DSN-MUI 17/DSN-MUI/IX/2000.

---

## 1. Metodologi

| Tahap | Metode |
|---|---|
| Inventarisasi | Ekstraksi 37 COA aktif dari master `coas` + dokumentasi konstanta `App\Support\Coa`, aturan di `AutoJournalingService`, `IkhtisarShuService`, `MandatorySavingsService`, `CoaResolver`. |
| Audit transaksi | Agregasi mutasi D/K per COA dari `jurnal_detail` (jurnal berstatus `Posted`, ≤ 2026-10-09), pengecekan integritas (balance, status, orphan `coa_id`, akun nonaktif). |
| Uji kepatuhan | Pencocokan setiap pasangan debit/kredit terhadap aturan akun; verifikasi ulang klaim `tutupBuku()` (dry-run) tanpa mengubah data. |
| Bukti | Nomor jurnal + baris detail riil dari database dev. |

> Catatan: seluruh data bersumber dari seeder demo (`CoaSeeder`, `DemoDataSeeder`, `JenisSimpananSeeder`) — perilaku sistem produksi mengikuti service yang sama.

---

## 2. Ringkasan Eksekutif

| Klasifikasi | Jumlah COA |
|---|---|
| ✅ Berjalan sesuai logika (terverifikasi bermutasi benar) | 18 akun |
| 🟡 Sesuai aturan namun perlu perbaikan DATA (jurnal demo) | 4 akun terdampak |
| ⚪ Tidak bermutasi pada periode audit (wajar/idle) | 15 akun |
| 🔴 Temuan ketidaksesuaian | **3 (1 kritis, 1 sedang, 1 rendah)** |

**Integritas jurnal:** 62 jurnal — **100% balance**, 100% status `Posted`, **0** detail tanpa `coa_id`, **0** detail menunjuk COA nonaktif. Seluruh pelanggaran debit/kredit ditemukan pada **DATA DEMO** (jalur `Jurnal::create()` langsung di `DemoDataSeeder`), **bukan** pada service produksi `AutoJournalingService`.

---

## 3. Daftar COA yang Berjalan Sesuai Logika (✅)

| Kode | Nama Akun | Logika/Aturan | Bukti Transaksi (terverifikasi) |
|---|---|---|---|
| 1-2001 | Piutang Murabahah | PSAK 102 — piutang sebesar pokok + margin | Jurnal#25: `D 1-1001 933.333 / K 1-2001 933.333` (angsuran pokok) |
| 1-2101 | Margin Murabahah Ditangguhkan | **KONTRA-aset**: `D 1-2101 / K 4-1001` saat amortisasi | Jurnal#25: `D 1-2101 100.000 / K 4-1001 100.000` (realisasi margin) |
| 2-1001 | Simpanan Sukarela/Wadiah | Kewajiban — `D` saat tarik, `K` saat setor | Jurnal setor/penarikan sukarela & Jurnal#34 (ta'zir, `D 2-1001`) |
| 2-1003 | Deposito Mudharabah | **Dana Syirkah Temporer** — `D 1-1001 / K 2-1003` | Jurnal#37 (5 jt) & #38 (10 jt) |
| 2-3001 | Dana Kebajikan | Fatwa DSN-MUI 17 — ta'zir masuk `K` | Jurnal#34: `D 2-1001 50.000 / K 2-3001 50.000` |
| 3-1001 | Simpanan Pokok Anggota | Ekuitas — modal anggota | LANGKAH 5 seeder + `jurnalSetorSimpananPokok` |
| 3-1002 | Simpanan Wajib Anggota | **PSAK 101**: modal anggota (bukan kewajiban) | `jurnalSetorSimpananWajib()` → `K 3-1002` |
| 3-2001 | Dana Cadangan Koperasi | Ekuitas — alokasi SHU | Jurnal#36: `D 3-9000 500.000 / K 3-2001 500.000` |
| 4-1001 | Pendapatan Margin Murabahah | Pendapatan (`K`) | Jurnal#25 |
| 4-1002/4-1003/4-1004 | Bagi hasil Mudharabah/Musyarakah, Sewa Ijarah | Pendapatan (`K`) | Angsuran bagi-hasil & ijarah |
| 4-2001 | Pendapatan Biaya Administrasi | `D 1-1001 / K 4-2001` | `jurnalBiayaAdmin()` (150.000) |
| 1-1002 | Bank/Rekening Utama | Aset — transfer internal | Jurnal#19: `D 1-1002 / K 1-1001 3.000.000` |
| 1-2002/1-2003/1-2004/1-2005 | Piutang Mudharabah/Musyarakah/Ijarah/Qardh | Piutang per akad (`CoaResolver`), Qardh **tanpa margin** | Jurnal pencairan#21/#22/#23/#24 |

**Pencocokan debit/kredit: ✅ benar** untuk seluruh jurnal di atas (aset bertambah `D`, kewajiban/ekuitas/pendapatan bertambah `K`).

---

## 4. Temuan Ketidaksesuaian

### 🔴 T-01 (KRITIS) — SHU dibukukan tanpa penutupan pendapatan & beban

- **Akun terdampak:** `1-1001` Kas Teller, `3-9000` Ikhtisar SHU
- **Bukti:** Jurnal `JRN-SHU-*-001` (LANGKAH 15 `DemoDataSeeder::alokasiShu()`), tanggal 2026-10-05, `sumber = Ikhtisar SHU`:
  - Baris 1: `D 1-1001 Kas Teller 2.500.000`
  - Baris 2: `K 3-9000 Ikhtisar SHU 2.500.000`
- **Pelanggaran aturan:**
  1. **Mengakui SHU (laba) tanpa realisasi.** Nominal `2.500.000` *hardcoded*, tidak berasal dari selisih Pendapatan kepala 4 dikurangi Beban kepala 5.
  2. **Menggelembungkan Kas Teller** tanpa arus kas nyata.
  3. **Melanggar logika `IkhtisarShuService`** — SHU sahnya terbentuk dari `tutupBuku()` yang menutup kepala 4 & 5 secara presisi; jurnal penutup meng-*debit* Pendapatan dan meng-*kredit* Beban ke `3-9000`.
- **Bukti ketidaksesuaian kuantitatif** (dry-run `pratinjauTutupBuku()`):
  - SHU **sebenarnya** = Pendapatan `439.167` − Beban `2.000.000` = **−1.560.833 (RUGI)**.
  - Saldo `3-9000` saat ini = **+2.000.000** (kredit 2.500.000 dari jurnal fiktif − 500.000 alokasi). **Selisih terhadap nilai benar = 3.560.833.**
- **Akar masalah:** jalur `Jurnal::create()` langsung (tanpa validasi service) yang memakai asumsi laba tetap.
- **Rekomendasi (SUDAH DITERAPKAN):** hapus posting manual; ganti dengan `IkhtisarShuService::tutupBuku()`; alokasi 20% ke Dana Cadangan **hanya bila laba > 0**.

### 🟠 T-02 (SEDANG) — Inkonsistensi klasifikasi Beban Operasional

- **Akun terdampak:** `5-1001`, `5-1002`, `5-1003`
- **Bukti:** LANGKAH 13 `DemoDataSeeder::catatBebanOperasional()` — 3 pengeluaran (listrik 500.000, ATK 300.000, gaji 1.200.000) **semuanya** di-`D` ke `5-1001`, padahal akun spesifik `5-1002` (ATK) dan `5-1003` (Utilitas) tersedia, dan `kodeAkunBebanByJenis()` sudah menyediakan pemetaan resmi.
- **Pelanggaran aturan:** laporan Beban tidak dapat dianalisis per jenis (tumpang tindih klasifikasi antar jenis beban).
- **Rekomendasi (SUDAH DITERAPKAN):** pakai `AutoJournalingService::kodeAkunBebanByJenis()` → `utilitas→5-1003`, `atk→5-1002`, `gaji→5-1001`. Terverifikasi:
  ```
  jenis=utilitas -> 5-1003 | jenis=atk -> 5-1002 | jenis=gaji -> 5-1001
  ```

### 🟡 T-03 (RENDAH) — Dokumentasi stale akun legacy `2-1002`

- **Akun terdampak:** `2-1002` Simpanan Wajib Anggota (kewajiban) — legacy.
- **Bukti:** `2-1002` **tidak bermutasi** pada periode audit; seluruh jurnal Simpanan Wajib memakai `3-1002` (benar per PSAK 101). Namun komentar di `DemoDataSeeder` (LANGKAH 6) dan docblock `JenisSimpananSeeder` masih menyebut `2-1002` sebagai akun operasional.
- **Status:** **bukan bug akuntansi** (`2-1002` sudah `@deprecated` di `AutoJournalingService`; titik resolusi tunggal `MandatorySavingsService::akunSimpananWajib()` fallback resmi = `3-1002`).
- **Rekomendasi (SUDAH DITERAPKAN):** koreksi komentar stale. Opsional: set `is_active=false` pada `2-1002` agar tidak muncul di UI.

---

## 5. Catatan Jurnal Saldo Awal (Tidak Dikategorikan Temuan)

- **Jurnal `JRN-OPENING-001`** (2026-04-05): `D 1-1001 Kas Teller 5.000.000 / K 3-2001 Dana Cadangan 5.000.000`.
- **Analisis:** `3-2001` (Dana Cadangan) tidak bermutasi pada periode 3 bulan terakhir (di luar cakupan), sehingga tidak dihitung sebagai pelanggaran. Secara akuntansi lebih lazim saldo awal disetor sebagai **Modal Anggota** (`3-1001`/`3-1002`), bukan langsung Dana Cadangan. Namun keduanya sama-sama Ekuitas (kepala 3) dan jurnal **balance**, sehingga hanya berupa **observasi**.
- **Rekomendasi (opsional):** ganti lawan `3-2001` → `3-1001` bila ingin merepresentasikan setoran modal pendiri.

---

## 6. Rekomendasi Perbaikan — Ringkasan

| ID | Temuan | Perbaikan Diterapkan | Status |
|---|---|---|---|
| T-01 | SHU tanpa penutupan 4 & 5; Kas Teller inflated | `DemoDataSeeder::alokasiShu()` kini memanggil `IkhtisarShuService::tutupBuku()`; alokasi hanya bila laba | ✅ Diterapkan |
| T-02 | Beban menumpuk di `5-1001` | `catatBebanOperasional()` memakai `kodeAkunBebanByJenis()` | ✅ Diterapkan |
| T-03 | Komentar stale `2-1002` | Koreksi `DemoDataSeeder` LANGKAH 6 & docblock `JenisSimpananSeeder` | ✅ Diterapkan |
| — | Saldo awal ke Dana Cadangan | Observasi (opsional ubah ke Modal) | ⚪ Opsional |

### Koreksi data pada DB yang sudah terisi (penting)
Dev/produksi DB yang **sudah** memiliki jurnal lama `JRN-SHU-*-001` perlu diperbaiki agar konsisten:
```sql
-- 1) Hapus jurnal SHU fiktif beserta detailnya (badge sumber = 'Ikhtisar SHU')
DELETE d FROM jurnal_detail d JOIN jurnal j ON j.id = d.jurnal_id
  WHERE j.sumber = 'Ikhtisar SHU' AND j.keterangan = 'Akumulasi SHU periode berjalan';
DELETE FROM jurnal WHERE sumber = 'Ikhtisar SHU' AND keterangan = 'Akumulasi SHU periode berjalan';
-- 2) Bentuk ulang penutupan pendapatan & beban via aplikasi:
--    php artisan ... (menu Tutup Buku) atau IkhtisarShuService::tutupBuku()
```
Atau jalankan ulang: `php artisan migrate:fresh --seed`.

---

## 7. Kesimpulan

1. **Logika akuntansi inti benar** — seluruh service produksi (`AutoJournalingService`, `IkhtisarShuService`, `MandatorySavingsService`, `CoaResolver`) konsisten dengan PSAK 101/102/107/108/109 dan Fatwa DSN-MUI 17. Tidak ada pelanggaran debit/kredit pada jalur produksi.
2. **Tiga penyimpangan** bersumber pada **data demo** yang menulis jurnal lewat jalur langsung, dan **semuanya telah diperbaiki** pada seeder.
3. **Integritas jurnal 100%** (balance, Posted, tanpa orphan).

**Verifikasi ulang (Langkah 5)** dipisahkan pada bagian berikut.
