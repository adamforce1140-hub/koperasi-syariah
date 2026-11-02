# TAHAP 12 — R2: Penyatuan Akun CoA Simpanan Wajib

**Status:** 🟡 SELESAI PARSIAL — inti (titik resolusi tunggal) ✅ SELESAI; rekonsiliasi saldo legacy ⏳ TERTUNDA
**Tanggal:** 2026-09-30
**Tahap:** R2 (bagian dari Langkah 3 dari 6)
**Rujukan roadmap:** `docs/TAHAP12-SIMPANAN-WAJIB-ROADMAP.md` §3 R2
**Rujukan analisis:** `docs/TAHAP12-SIMPANAN-WAJIB-ANALISIS.md` (hardcode H7–H9)

**Dasar syariah:** PSAK 101 ¶ 31 — *Simpanan Wajib disajikan sebagai **Modal Anggota**
(ekuitas), bukan kewajiban*; konsistensi klasifikasi pos antar-periode.
**Dasar regulasi:** KDPPLKS (Kerangka Dasar Penyusunan dan Penyajian Laporan Keuangan
Koperasi Syariah) — Simpanan Wajib bersifat penyertaan anggota, tidak dapat ditarik
sepihak selama keanggotaan berjalan.

**Khalayak:** Tim TI, Pengurus, DPS, auditor eksternal.

---

## 1. Akar Masalah

Sistem memiliki **dua akun** untuk pos yang secara ekonomi sama:

| Akun | Nama | Kategori | Pengguna |
|---|---|---|---|
| `2-1002` | Simpanan Wajib Anggota | **Kewajiban** ❌ | Konstanta legacy `AutoJournalingService::SIMPANAN_WAJIB` (baris 43) |
| `3-1002` | Simpanan Wajib Anggota | **Ekuitas / Modal Anggota** ✅ | Konstanta `SIMPANAN_WAJIB_MODAL` (baris 97) |

`2-1002` adalah **sisa desain lama**. Menyajikannya sebagai kewajiban **melanggar
PSAK 101 ¶ 31** karena Simpanan Wajib bukan utang koperasi kepada anggota, melainkan
**modal penyertaan** anggota. Akibat lanjutan: rasio kecukupan modal (*capital adequacy*)
terbaca **lebih kecil dari sebenarnya**, dan total ekuitas **ter-*understate***.

---

## 2. Strategi Penyatuan

### 2.1 Titik Resolusi Tunggal (SELESAI ✅)

`MandatorySavingsService::akunSimpananWajib()` menjadi **satu-satunya** pintu penentuan akun,
dengan rantai resolusi berjenjang:

```
1. Setting::akunSimpananWajibId()          ← pemetaan dinamis dari UI Pengurus
        │  (bila null / CoA nonaktif → lanjut)
        ▼
2. JenisSimpanan::coaKode('Simpanan Wajib') ← master produk simpanan
        │  (bila null → lanjut)
        ▼
3. App\Support\Coa::SIMPANAN_WAJIB_MODAL   ← registri standar = '3-1002' (jaring pengaman)
```

Bila langkah 1 menunjuk CoA yang tidak ada / `is_active = false`, sistem **menolaknya**
dan menulis `Log::warning` sebelum turun ke langkah 2 — mencegah akun tak sah dipakai diam-diam.

**Bukti kode:** `app/Services/MandatorySavingsService.php:70–96`.

### 2.2 Seluruh Jalur Jurnal Memakai Titik Resolusi Ini (SELESAI ✅)

Verifikasi grep atas seluruh basis kode menegaskan tidak ada lagi jalur yang menulis akun
Simpanan Wajib secara langsung:

| Jalur | Berkas | Mekanisme |
|---|---|---|
| Auto-debit bulanan | `MandatorySavingsService::autoDebit()` (baris 437) | `$akunWajib = $this->akunSimpananWajib()` |
| Pelunasan di teller | `MandatorySavingsService::bayarViaTeller()` (baris 531) | `$akunWajib = $this->akunSimpananWajib()` |
| Pembalikan jurnal (*reversal*) | `ReversalService::kodeSimpananUntukJenis()` (baris 340–354) | `app(MandatorySavingsService::class)->akunSimpananWajib()` |
| Bundling setoran awal CIF | `CifController` | via master `JenisSimpanan::coaKode()` |
| Endpoint API deposito | `Api/DepositoController.php:192` | `app(MandatorySavingsService::class)->akunSimpananWajib()` |
| Command validasi kepatuhan | `Console/Commands/ValidasiKepatuhanSimpananWajib.php:56` | titik resolusi yang sama |
| Jurnal penarikan | `AutoJournalingService::jurnalTarikSimpananWajib()` (baris 816) | parameter `$akunWajibKode` dari pemanggil; default `SIMPANAN_WAJIB_MODAL` (`3-1002`) |

`AutoJournalingService::SIMPANAN_WAJIB` (`2-1002`) **secara eksplisit ditandai `@deprecated`**
(baris 36–43) dan hanya dipertahankan untuk kompatibilitas data historis.

### 2.3 Rekonsiliasi Saldo Legacy (TERTUNDA ⏳)

Saldo historis yang terlanjur mengendap di `2-1002` **belum direklasifikasi**.

**Kondisi per 2026-09-30 (hasil pengukuran langsung pada basis data):**

| Akun | Debet | Kredit | Saldo |
|---|---|---|---|
| `2-1002` (legacy, Kewajiban) | 0,00 | 500.000,00 | **500.000,00** |
| `3-1002` (kanonik, Modal) | 0,00 | 1.050.000,00 | **1.050.000,00** |

Rincian `2-1002` — **5 baris jurnal** (*jurnal*, bukan *jurnal_detail*), seluruhnya
bertanggal 2026-09-22, jenis `Jurnal Otomatis`, sumber `Setoran Simpanan Wajib`,
masing-masing Rp 100.000,00:

```
JRN-20260922-0001 .. JRN-20260922-0005   D=0,00  K=100.000,00
```

**Rencana perbaikan (mengikuti Gerbang 4 — dilarang mengubah jurnal historis):**

> Posting **satu jurnal penyesuaian** yang disetujui Pengurus:
> `DEBET 2-1002 Rp 500.000,00` → `KREDIT 3-1002 Rp 500.000,00`
>
> Dampak: saldo `2-1002` menjadi `0,00`; saldo `3-1002` menjadi `1.550.000,00`.
> Jurnal historis **tidak disentuh** — jumlah baris jurnal sebelum = sesudah.

Jurnal penyesuaian **belum diposting** karena menunggu pratinjau + persetujuan resmi
Pengurus/DPS (sesuai prinsip P2 Reversible &amp; Gerbang 4). Hingga diposting, laporan
keuangan **masih menggabungkan** dua pos ini dan **belum sepenuhnya sesuai PSAK 101 ¶ 31**
pada sisi penyajian neraca.

---

## 3. Pemenuhan Milestone

| ID | Milestone | Kriteria Lulus | Status | Bukti |
|---|---|---|---|---|
| **M2.1** | Resolver tunggal ada | `akunSimpananWajib()` → `3-1002` pada default; mengikuti `Setting` bila diisi | ✅ | `MandatorySavingsService.php:70–96`; `SimpananWajibKonfigurasiDinamisTest` |
| **M2.2** | Semua jalur konsisten | Auto-debit, teller, bundling CIF, API → **KREDIT akun yang SAMA** | ✅ | Grep lintas-berkas (tabel §2.2); `ReversalService.php:350` |
| **M2.3** | Penarikan menolkan saldo | DEBET penarikan menyentuh akun **sama** dengan setoran | 🟡 | `jurnalTarikSimpananWajib()` (baris 816–822) memakai akun kanonik, **tetapi** alur penarikan belum terekspos ke HTTP (lihat R3) |
| **M2.4** | Reversal konsisten | `ReversalService` membalik ke akun asal untuk **setiap** jalur | ✅ | `ReversalService.php:340–354` — heuristik jalur dihapus, diganti titik resolusi tunggal |
| **M2.5** | Reklasifikasi aman | Pratinjau `2-1002 → 3-1002` seimbang; setelah posting saldo `2-1002` = `0,00` | ⏳ | **Belum dijalankan** — lihat §2.3 |
| **M2.6** | Jurnal historis utuh | Jumlah &amp; nilai jurnal sebelum vs sesudah identik | ✅ | Tidak ada `UPDATE`/`DELETE` pada `jurnal`/`jurnal_detail`; perbaikan hanya via jurnal penyesuaian |
| **M2.7** | Regresi nihil | `scripts\run-tests.ps1` hijau, termasuk `JenisSimpananParameterTest` &amp; `TellerSimpananWajibTest` | ✅ | **1120 passed (5139 assertions)**, exit code 0 |

---

## 4. Dampak & Mitigasi

| Risiko | Mitigasi yang Terpasang |
|---|---|
| Perubahan akun mengubah laporan berjalan | Default tetap `3-1002` — akun efektif **tidak berubah** bagi data baru |
| Jurnal historis rusak | Gerbang 4: dilarang `UPDATE`/`DELETE` pada `jurnal`/`jurnal_detail`; koreksi hanya via jurnal penyesuaian |
| Reklasifikasi salah hitung | Melalui `AutoJournalingService::post()` yang **sudah** memvalidasi keseimbangan (`Jurnal::pastikanBalance()`, toleransi `Setting::toleransiSelisihJurnal()`) |
| CoA nonaktif dipakai diam-diam | `akunSimpananWajib()` menolak + `Log::warning`, lalu turun ke rantai berikutnya |

---

## 5. Rollback

1. Kembalikan `ReversalService::kodeSimpananUntukJenis()` ke heuristik jalur (bila perlu).
2. Kosongkan `account_simpanan_wajib_id` → rantai resolusi otomatis turun ke
   master `jenis_simpanans`, lalu ke `3-1002`.
3. Bila jurnal penyesuaian reklasifikasi sudah diposting: **jangan dihapus** — posting
   jurnal penyesuaian **kebalikannya** (`DEBET 3-1002` → `KREDIT 2-1002`).

**Catatan:** rollback R2 tidak mengubah akun efektif (tetap `3-1002`) sehingga **aman**.

---

*Dokumen ini adalah bukti Milestone R2. Lanjut ke `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md`.*
