# TAHAP 12 — R4: Mekanisme Validasi Akuntansi PSAK

**Status:** ✅ SELESAI &amp; TERVERIFIKASI
**Tanggal:** 2026-09-30
**Tahap:** R4 (Langkah 4 dari 6)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R4

**Dasar syariah:** PSAK 101 ¶ 31 (penyajian Simpanan Wajib sebagai Modal Anggota);
PSAK 101 ¶ 45–47 (konsistensi kebijakan akuntansi); Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000
(denda/*ta'zir* wajib masuk **Dana Kebajikan**, bukan pendapatan koperasi).
**Dasar regulasi:** Permen KUKM No. 09/2018 Pasal 24–26 (pengawasan &amp; pertanggungjawaban
Pengurus); POJK No. 76/2016 (prinsip kehati-hatian &amp; tata kelola).

**Khalayak:** Tim TI, Pengurus, DPS, auditor eksternal &amp; internal.

---

## 1. Tujuan

R4 menyediakan **gerbang kepatuhan otomatis** yang dapat dijalankan kapan saja untuk
memverifikasi bahwa seluruh transaksi Simpanan Wajib telah dicatat sesuai PSAK 101/108.
Perintah ini bersifat **hanya-baca** (*read-only*) — tidak pernah mengubah data.

Perintah bersifat **mesin-terbaca**: mengembalikan **exit code** sehingga dapat dipakai
sebagai gerbang pada pipeline rilis maupun audit otomatis.

---

## 2. Perintah

```bash
php artisan psak:validasi-simpanan-wajib
php artisan psak:validasi-simpanan-wajib --contoh=25
```

**Berkas:** `app/Console/Commands/ValidasiKepatuhanSimpananWajib.php`
**Signature:** `psak:validasi-simpanan-wajib {--contoh=10}`
**Exit code:** `0` = patuh (SUCCESS) · `1` = ada temuan (FAILURE)

> **Catatan penamaan:** roadmap menyebut `simpanan-wajib:audit-kepatuhan`; implementasi
> final memakai prefiks domain PSAK yaitu **`psak:validasi-simpanan-wajib`** agar konsisten
> dengan perintah kepatuhan lain di sistem.

---

## 3. Tujuh Kategori Pemeriksaan

| # | Kategori | Yang Dideteksi | Dasar |
|---|---|---|---|
| 1 | `AKUN_SIMPANAN_WAJIB_KEWAJIBAN` | Tagihan lunas yang jurnalnya **mengkredit akun berkategori Kewajiban** | PSAK 101 ¶ 31 |
| 2 | `TAGIHAN_LUNAS_TANPA_JURNAL` | Tagihan `PAID` tanpa `jurnal_id` / jurnal tidak ditemukan | Jejak audit |
| 3 | `TAGIHAN_PAID_TANPA_TRANSAKSI` | Tagihan `PAID` tanpa `transaksi_id` | Integritas data |
| 4 | `JURNAL_TIDAK_BERIMBANG` | Total debet ≠ kredit di luar toleransi kebijakan | Double-entry |
| 5 | `NOMINAL_TAGIHAN_TIDAK_SESUAI` | Nominal tagihan ≠ jumlah kredit jurnal simpanan | Integritas double-entry |
| 6 | `TAGIHAN_DUPLIKAT_PERIODE` | Lebih dari satu tagihan untuk (anggota, periode) yang sama | Kelengkapan |
| 7 | `SALDO_MODAL_NEGATIF` | Akun modal Simpanan Wajib bersaldo **negatif** (indikasi jurnal terbalik) | PSAK 101 ¶ 31 |

Kategori #1 dan #7 adalah **penjaga langsung PSAK 101 ¶ 31**: keduanya akan menangkap
upaya mengembalikan Simpanan Wajib ke pos kewajiban atau membalik jurnalnya secara keliru.

---

## 4. Keluaran Perintah (Bukti Nyata 2026-09-30)

Dijalankan terhadap basis data pengembangan setelah perbaikan bug (lihat §5):

```
╔══════════════════════════════════════════════════════════════════╗
║  VALIDASI KEPATUHAN PSAK 101/108 — SIMPANAN WAJIB                ║
╚══════════════════════════════════════════════════════════════════╝
  Akun kanonik (titik resolusi) : 3-1002
  Akun modal anggota terdaftar  : 4
  Toleransi selisih jurnal      : 0.01

+-------------------------------+--------+
| Kategori Pelanggaran          | Jumlah |
+-------------------------------+--------+
| AKUN_SIMPANAN_WAJIB_KEWAJIBAN | 0      |
| TAGIHAN_LUNAS_TANPA_JURNAL    | 0      |
| TAGIHAN_PAID_TANPA_TRANSAKSI  | 0      |
| JURNAL_TIDAK_BERIMBANG        | 0      |
| NOMINAL_TAGIHAN_TIDAK_SESUAI  | 0      |
| TAGIHAN_DUPLIKAT_PERIODE      | 0      |
| SALDO_MODAL_NEGATIF           | 0      |
+-------------------------------+--------+

  Total tagihan............. 23
  Tagihan lunas (PAID)...... 21
  Tagihan tunggakan......... 1

✔ LULUS — tidak ditemukan pelanggaran kepatuhan PSAK 101/108.
```

**Exit code: `0`** — gerbang kepatuhan terbuka.

---

## 5. Temuan Nyata yang Ditemukan &amp; Diperbaiki (Nilai Tambah R4)

Gerbang R4 **langsung membuktikan kegunaannya** dengan menangkap cacat nyata yang tidak
terdeteksi lewat pengujian biasa:

### 5.1 Bug Seeder — nominal tagihan bundling

**Temuan:**
```
NOMINAL_TAGIHAN_TIDAK_SESUAI → Anggota #5 periode 2026-01
                                (tagihan 150.000,00 ≠ jurnal 50.000,00)
```

**Akar masalah:** `AdamRenataSeeder::simulasikanPelunasanSetoranAwal()` menyetel
`mandatory_savings_bills.amount` = **total bundling** (Pokok 100.000 + Wajib 50.000 =
150.000), padahal `jurnalBundling()` hanya mengkredit akun `3-1002` sebesar **porsi wajib**
(50.000). Jalur produksi `CifController` sudah **benar** (`'amount' => $simpananWajib1`).

**Perbaikan:** kolom `amount` pada tagihan berjadwal diubah menjadi `$wajib` di **dua tempat**
(create + update), disertai komentar PSAK 101 yang menjelaskan bahwa kolom `amount` **hanya
mewakili porsi Simpanan Wajib**, bukan total bundling. Transaksi kas tetap memakai
`'jumlah' => $jumlah` karena memang mencerminkan uang masuk penuh.

### 5.2 Perbaikan Data Historis Tercemar

Satu baris data terdampak: **bill #274** (anggota #5, periode 2026-01).

| Sebelum | Sesudah |
|---|---|
| `amount = 150.000,00` (≠ kredit jurnal 50.000,00) | `amount = 50.000,00` |

Perbaikan dilakukan **hanya pada kolom `amount` tabel tagihan** — **bukan** pada
`jurnal`/`jurnal_detail` (patuh Gerbang 4: dilarang mengubah jurnal historis).

### 5.3 Verifikasi Ulang

Setelah perbaikan, seeder dijalankan ulang (`php artisan db:seed --class=AdamRenataSeeder --force`):

```
Setoran awal : Rp 100.000 (PAID via Teller)
- 2026-01 | PAID | CASH | Rp 50.000        ← sebelumnya tertulis Rp 150.000
Saldo Simpanan Wajib (buku CoA): Rp 450.000  ← 9 periode × Rp 50.000
```

Audit kepatuhan kembali **LULUS** (exit `0`, seluruh 7 kategori `0`).

---

## 6. Pemenuhan Milestone

| ID | Milestone | Kriteria Lulus | Status | Bukti |
|---|---|---|---|---|
| **M4.1** | V1 mendeteksi penyimpangan | Jurnal ke akun berbeda → validator melaporkan temuan | ✅ | `ValidasiKepatuhanSimpananWajibTest::test_mendeteksi_kredit_ke_akun_kewajiban` |
| **M4.2** | V1 lolos pada data sehat | Data normal → 0 temuan | ✅ | `test_lulus_pada_data_sehat_dan_tidak_mengubah_data` + keluaran §4 |
| **M4.3** | V3 &amp; V4 berfungsi | Tagihan PAID tanpa jurnal terdeteksi; nominal beda terdeteksi | ✅ | `test_exit_code_gagal_bila_ada_tagihan_paid_tanpa_jurnal`; `test_mendeteksi_nominal_tagihan_tidak_sesuai_jurnal` |
| **M4.4** | Kepatuhan syariah | Penyimpangan akun &amp; jurnal tidak berimbang terdeteksi | ✅ | `test_mendeteksi_jurnal_tidak_berimbang`; `test_mendeteksi_tagihan_paid_tanpa_transaksi` |
| **M4.5** | Exit code benar | Mengembalikan `0`/`1` sesuai kondisi | ✅ | `test_lulus_...` (0) vs `test_exit_code_gagal_...` (1) |
| **M4.6** | Regresi nihil | `scripts\run-tests.ps1` hijau | ✅ | **1120 passed (5139 assertions)**, exit code 0 |

**Berkas uji:** `tests/Feature/ValidasiKepatuhanSimpananWajibTest.php` — **10 test, 18 assertion**.
Termasuk `test_lulus_pada_data_sehat_dan_tidak_mengubah_data` yang membandingkan *snapshot*
tabel sebelum vs sesudah, membuktikan perintah **benar-benar hanya-baca**.

---

## 7. Rollback

1. Hapus `app/Console/Commands/ValidasiKepatuhanSimpananWajib.php`.
2. Hapus `tests/Feature/ValidasiKepatuhanSimpananWajibTest.php`.
3. `php artisan optimize:clear`.

Perintah bersifat hanya-baca — **tidak ada data yang perlu dipulihkan** saat rollback.

**Catatan:** perbaikan bug seeder (§5.1) **tidak boleh** di-rollback — itu perbaikan
kebenaran akuntansi, bukan bagian dari fitur validasi.

---

*Dokumen ini adalah bukti Milestone R4. Lanjut ke `docs/TAHAP12-R5-PENGUJIAN-FUNGSIONAL-KEPATUHAN.md`.*
