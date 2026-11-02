# Hasil Audit P1-2 — Atribusi Selisih Subledger Simpanan

**Tanggal audit:** 2026-10-21
**Lingkup:** akun `2-1001` (Simpanan Sukarela), `2-1003` (Simpanan Berjangka), `3-1001` (Simpanan Pokok), `3-1002` (Simpanan Wajib / Modal Anggota)
**Sifat:** **read-only** — tidak ada jurnal, saldo, maupun transaksi yang diubah
**Alat:** `php artisan simpanan:audit-subledger` ([AuditSubledgerSimpanan.php](file:///c:/laragon/www/koperasi_syariah/app/Console/Commands/AuditSubledgerSimpanan.php))
**Database:** `koperasi_syariah` (Laragon, MySQL 8.4.3), toleransi selisih `0,01`

---

## 1. Ringkasan Eksekutif

| Akun | Nama | GL | Subledger | Selisih | Status |
|---|---|---:|---:|---:|---|
| `2-1001` | Simpanan Sukarela | 80.950.000 | 102.450.000 | **−21.500.000** | Berselisih |
| `2-1003` | Simpanan Berjangka | 15.000.000 | 15.000.000 | 0 | **Cocok** |
| `3-1001` | Simpanan Pokok | 2.800.000 | 600.000 | **+2.200.000** | Berselisih (di luar gate) |
| `3-1002` | Simpanan Wajib | 1.550.000 | 2.400.000 | **−850.000** | Berselisih (di luar gate) |

Kesimpulan utama:

1. Selisih `2-1001` **Rp21.500.000 dapat diatribusikan 100%** ke dua sebab yang berbeda sifatnya:
   - **Rp18.500.000** — 3 rekening legacy hasil `RekeningSeeder` yang saldonya disemai langsung **tanpa transaksi dan tanpa jurnal**.
   - **Rp3.000.000** — bug data seed `DemoDataSeeder`: setoran Simpanan Pokok & Wajib 5 anggota dicatat ke **rekening Sukarela**, sehingga masuk subledger `2-1001` sementara jurnalnya mengkredit `3-1001`/`3-1002`.
2. **Tidak ada baris `TANPA_ANGGOTA`** pada keempat akun. Seluruh `jurnal_detail` tertaut ke anggota, sehingga atribusi lengkap per anggota (tidak ada dana yatim di sisi GL).
3. **Jalur produksi tidak memiliki bug ini.** `TellerController::setorStore` menentukan jenis transaksi dan akun kredit dari `jenis_rekening` rekening yang dipilih, meneruskan `anggota_id`, dan menulis saldo + jurnal dalam satu `DB::transaction` dengan `lockForUpdate()`. Masalah berasal dari **data seed/demo**, bukan alur aplikasi.
4. `3-1001` dan `3-1002` **tidak termasuk** `KODE_SUBLEDGER_DEFAULT` gate P1-1, sehingga selisihnya **tidak memblokir operasional**. Namun angkanya perlu dibereskan sebelum laporan keuangan dipakai.

---

## 2. Metode

```bash
php artisan simpanan:audit-subledger --format=json
php artisan simpanan:audit-subledger --kode=3-1001 --kode=3-1002 --hanya-berselisih --format=csv
```

- `mutasi_gl` per anggota diambil dari `jurnal_detail.anggota_id` — bukan header jurnal (tabel `jurnal` tidak punya kolom `anggota_id`).
- `saldo_subledger` diambil dari `rekening.saldo` yang dikelompokkan menurut `jenis_rekening` → akun kontrol.
- Total per akun dihitung dari **seluruh baris** sebelum opsi `--hanya-berselisih` menyaring, sehingga angkanya selalu identik dengan tabel `jurnal:rekonsiliasi`.
- Selisih = `mutasi_gl − saldo_subledger` (negatif = GL kurang catat dibanding subledger).

---

## 3. Akun `2-1001` — Simpanan Sukarela (−Rp21.500.000)

GL **Rp80.950.000** vs subledger **Rp102.450.000**. Delapan baris berselisih:

| Anggota | No. Anggota | Nama | No. Rekening | Subledger | GL | Selisih | Trx |
|---|---|---|---:|---:|---:|---:|---:|
| 1 | ANG-2022-0001 | Budi Santoso | REK-0001-03 | 5.500.000 | 0 | −5.500.000 | 0 |
| 2 | ANG-2022-0002 | Siti Rahayu | REK-0002-02 | 2.750.000 | 0 | −2.750.000 | 0 |
| 4 | ANG-2023-0001 | Dewi Lestari | REK-0004-02 | 10.250.000 | 0 | −10.250.000 | 0 |
| 6 | ANG-2026-0001 | Budi Santoso | 100-2026-000001 | 12.100.000 | 11.500.000 | −600.000 | 6 |
| 7 | ANG-2026-0002 | Siti Aminah | 100-2026-000002 | 19.050.000 | 18.450.000 | −600.000 | 6 |
| 8 | ANG-2026-0003 | Ahmad Fauzi | 100-2026-000003 | 21.800.000 | 21.200.000 | −600.000 | 6 |
| 9 | ANG-2026-0004 | Dewi Rahayu | 100-2026-000004 | 13.600.000 | 13.000.000 | −600.000 | 5 |
| 10 | ANG-2026-0005 | Hasan Basri | 100-2026-000005 | 6.100.000 | 5.500.000 | −600.000 | 5 |
| | | | **Jumlah** | | | **−21.500.000** | |

Rekening yang **cocok** (selisih 0): anggota 5 `SUK-000005` Rp4.550.000; anggota 12 `REK-0022-2026-03` Rp4.750.000; anggota 13 `REK-0023-2026-03` Rp2.000.000.

### 3.1 Sebab A — rekening legacy tanpa jurnal (Rp18.500.000)

Tiga rekening dibuat langsung oleh [RekeningSeeder.php](file:///c:/laragon/www/koperasi_syariah/database/seeders/RekeningSeeder.php#L12-L131) dengan `saldo` terisi, **tanpa** `transaksi` dan **tanpa** `jurnal`:

| Anggota | Rekening | Jenis | Saldo seed |
|---|---|---|---:|
| 1 | `REK-0001-03` | Simpanan Sukarela | 5.500.000 |
| 2 | `REK-0002-02` | Simpanan Sukarela | 2.750.000 |
| 4 | `REK-0004-02` | Simpanan Sukarela | 10.250.000 |
| | | **Total** | **18.500.000** |

Bukti: `jumlah_transaksi = 0` pada ketiga rekening, dan `jurnal_detail` untuk anggota 1, 2, 3, 4 **tidak memuat satu baris pun** pada akun `2-1001`. Dana ini hanya ada di subledger, tidak pernah masuk buku besar.

Sebab struktural: `RekeningSeeder` menulis `saldo` sebagai angka awal, padahal `saldo` adalah kolom turunan dari mutasi. Seeder tidak menyertakan jurnal pembuka yang menjadi lawannya.

### 3.2 Sebab B — bug data seed pokok/wajib (Rp3.000.000)

Lima anggota 2026 (6–10) masing-masing berselisih **−Rp600.000**, terdiri dari:

- **Rp500.000** — setoran Simpanan Pokok (`TRX-SP-000x`),
- **Rp100.000** — setoran Simpanan Wajib (`TRX-SW-000x`).

Akar masalah ada di [DemoDataSeeder.php](file:///c:/laragon/www/koperasi_syariah/database/seeders/DemoDataSeeder.php#L242-L332):

```php
private function setorSimpananPokok(array $anggota, array $rekening): void
{
    foreach ($anggota as $i => $ang) {
        $rek = $rekening[$i];              // ← rekening SUKARELA (100-2026-00000x)
        $trx = Transaksi::create([
            'rekening_id'     => $rek->id,
            'jenis_transaksi' => 'Simpanan Pokok',
            ...
        ]);
        $rek->increment('saldo', $nominal); // ← saldo masuk rekening Sukarela → subledger 2-1001
        $this->jurnal->jurnalSetorSimpananPokok(...); // ← tetapi GL mengkredit 3-1001
    }
}
```

`$rekening` yang diteruskan ke kedua method tersebut berisi rekening **Sukarela** (`100-2026-000001` … `100-2026-000005`), bukan rekening Pokok/Wajib. Akibatnya:

| Transaksi | Rekening tertulis | Saldo bertambah di | Jurnal mengkredit | Selisih |
|---|---|---|---|---:|
| `TRX-SP-000x` Rp500.000 | rekening Sukarela | `2-1001` | `3-1001` | −500.000 |
| `TRX-SW-000x` Rp100.000 | rekening Sukarela | `2-1001` | `3-1002` | −100.000 |
| | | | **per anggota** | **−600.000** |
| | | | **5 anggota** | **−3.000.000** |

Inilah yang membuat `3-1001` kelebihan catat **+Rp2.500.000** (5 × Rp500.000) dan `3-1002` kelebihan catat **+Rp500.000** (5 × Rp100.000) — lihat §5 dan §6.

### 3.3 Rekonsiliasi

```
−18.500.000  (3 rekening legacy tanpa GL)
− 3.000.000  (5 × Rp600.000 bug seed pokok/wajib)
─────────────
−21.500.000  = selisih 2-1001  ✔ cocok dengan keluaran command
```

---

## 4. Akun `2-1003` — Simpanan Berjangka (COCOK)

GL = subledger = **Rp15.000.000**, selisih **0**.

| Anggota | No. Rekening | Subledger | GL | Selisih |
|---|---|---:|---:|---:|
| 6 — Budi Santoso | `DEP-2026-0001` | 5.000.000 | 5.000.000 | 0 |
| 10 — Hasan Basri | `DEP-2026-0002` | 10.000.000 | 10.000.000 | 0 |

Catatan: kedua rekening ini **belum memiliki bilyet** di tabel `depositos` (0 baris). Itu adalah temuan **D-2 / P1-3** yang terpisah dan masih menunggu keputusan. Dari sisi subledger–GL, akun ini sehat.

---

## 5. Akun `3-1001` — Simpanan Pokok (+Rp2.200.000)

GL **Rp2.800.000** vs subledger **Rp600.000**. Di luar `KODE_SUBLEDGER_DEFAULT`, jadi tidak memblokir operasional, tetapi perlu dibereskan.

| Anggota | Rekening | Subledger | GL | Selisih | Sebab |
|---|---|---:|---:|---:|---|
| 1 | REK-0001-01 | 100.000 | 0 | −100.000 | saldo seed tanpa jurnal |
| 2 | REK-0002-01 | 100.000 | 0 | −100.000 | saldo seed tanpa jurnal |
| 3 | REK-0003-01 | 100.000 | 0 | −100.000 | saldo seed tanpa jurnal |
| 4 | REK-0004-01 | 100.000 | 0 | −100.000 | saldo seed tanpa jurnal |
| 5 | — | 0 | 100.000 | +100.000 | bundling `JRN-20261015-0030` tanpa `rekening_id` |
| 6 | — | 0 | 500.000 | +500.000 | bug seed (masuk rekening Sukarela) |
| 7 | — | 0 | 500.000 | +500.000 | idem |
| 8 | — | 0 | 500.000 | +500.000 | idem |
| 9 | — | 0 | 500.000 | +500.000 | idem |
| 10 | — | 0 | 500.000 | +500.000 | idem |
| | **Jumlah** | **600.000** | **2.800.000** | **+2.200.000** | |

Anggota 12 dan 13 masing-masing Rp100.000 subledger = Rp100.000 GL (cocok, tidak tampil dengan `--hanya-berselisih`).

---

## 6. Akun `3-1002` — Simpanan Wajib (−Rp850.000)

GL **Rp1.550.000** vs subledger **Rp2.400.000**.

| Anggota | Rekening | Subledger | GL | Selisih | Sebab |
|---|---|---:|---:|---:|---|
| 1 | REK-0001-02 | 1.500.000 | 0 | −1.500.000 | saldo seed tanpa jurnal |
| 3 | REK-0003-02 | 800.000 | 0 | −800.000 | saldo seed tanpa jurnal |
| 5 | — | 0 | 500.000 | +500.000 | bundling Rp50.000 + auto-debit Feb–Okt 2026 9 × Rp50.000 |
| 6 | — | 0 | 100.000 | +100.000 | bug seed (masuk rekening Sukarela) |
| 7 | — | 0 | 100.000 | +100.000 | idem |
| 8 | — | 0 | 100.000 | +100.000 | idem |
| 9 | — | 0 | 100.000 | +100.000 | idem |
| 10 | — | 0 | 100.000 | +100.000 | idem |
| 12 | REK-0022-2026-02 | 50.000 | 300.000 | +250.000 | auto-debit wajib Jun–Okt 2026 5 × Rp50.000 |
| 13 | REK-0023-2026-02 | 50.000 | 250.000 | +200.000 | 4 setoran wajib via teller tanpa `rekening_id` |
| | **Jumlah** | **2.400.000** | **1.550.000** | **−850.000** | |

Dua pola tambahan yang muncul di akun ini (di luar bug seed) dan perlu ditelusuri sebelum remediasi:

1. **Auto-debit simpanan wajib tidak menambah saldo rekening Wajib.** Anggota 5 dan 12 memiliki mutasi GL dari auto-debit, tetapi `rekening` berjenis Simpanan Wajib miliknya hanya berisi saldo awal Rp50.000. Auto-debit mengurangi rekening sumber (Sukarela) dan mengkredit `3-1002`, namun subledger rekening Wajib tidak ikut bertambah.
2. **Setoran wajib anggota 13 tanpa `rekening_id`.** Empat transaksi (`trx 54–57`) tercatat dengan `rekening_id` kosong, sehingga GL bertambah tanpa jejak rekening tujuan.

---

## 7. Bukti tidak ada dana yatim di sisi GL

Skrip `audit_tanpa_anggota.php` menghitung `jurnal_detail` dengan `anggota_id IS NULL` untuk keempat akun:

| Akun | Baris `anggota_id IS NULL` | Nominal |
|---|---:|---:|
| `2-1001` | 0 | 0 |
| `2-1003` | 0 | 0 |
| `3-1001` | 0 | 0 |
| `3-1002` | 0 | 0 |

Artinya: **seluruh selisih dapat dipetakan ke anggota tertentu**. Tidak ada selisih yang harus ditelusuri "gelap" tanpa pemilik.

---

## 8. Mengapa jalur produksi aman

[TellerController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L116-L165) menentukan akun kredit dari rekening yang dipilih:

```php
$jenisSimpanan  = $rekening->jenis_rekening;
$tipeSimpanan   = JenisSimpanan::tipe($jenisSimpanan);

$jenisTransaksi = in_array($tipeSimpanan, [JenisSimpanan::TIPE_POKOK, JenisSimpanan::TIPE_WAJIB], true)
    ? $jenisSimpanan
    : 'Setoran Tunai';
```

lalu menulis saldo dan jurnal dalam satu transaksi DB dengan penguncian baris:

```php
DB::transaction(function () use (...) {
    $rekening = Rekening::lockForUpdate()->findOrFail($rekeningId);
    $rekening->increment('saldo', $nominal);
    $this->jurnal->jurnalSetorSimpanan($nominal, ..., $anggotaId);
});
```

Karena `jenis_rekening` dan akun kredit selalu sejalan, **tidak mungkin** terjadi saldo masuk ke `2-1001` sementara jurnal mengkredit `3-100x` pada alur aplikasi. Perbedaan hanya muncul pada **data seed** yang menulis saldo langsung.

---

## 9. Rekomendasi (BELUM DIEKSEKUSI)

Sesuai keputusan pemilik sistem — **audit dulu, belum diputuskan** — tidak ada satu pun rekomendasi di bawah ini yang dijalankan. Semua masih menunggu keputusan.

| Kode | Untuk masalah | Bentuk tindakan | Dampak |
|---|---|---|---|
| **R-1** | 3 rekening legacy Rp18.500.000 + bug seed Rp3.000.000 | Jurnal penyesuaian manual (D/K akun lawan yang sah) disetujui pengurus, dengan nomor bukti & berita acara | GL bertambah Rp21.500.000 agar setara subledger |
| **R-2** | Bila dana legacy dianggap tidak pernah ada | Koreksi `rekening.saldo` + transaksi koreksi beralasan | Subledger turun Rp18.500.000 |
| **R-3** | Reklasifikasi antar akun `2-100x`/`3-100x` | Jurnal reklasifikasi | Memindahkan saldo yang salah akun tanpa mengubah total |

Catatan pemilihan:

- Untuk **3 rekening legacy**, R-1 lebih tepat bila dana nasabah benar-benar pernah diterima (koperasi sudah beroperasi sejak 2022) — saldo nasabah tidak boleh dikurangi. R-2 hanya sah bila saldo itu memang fiktif.
- Untuk **Rp3.000.000 bug seed**, dana nasabah juga nyata (tercermin di saldo rekening Sukarela anggota 6–10). Karena itu R-2 akan merugikan anggota. Pilihan yang dapat dipertanggungjawabkan: **R-1** (tambah GL `3-1001`/`3-1002` sebesar Rp2.500.000/Rp500.000) **atau R-3** (reklasifikasi Rp3.000.000 dari `2-1001` ke akun modal yang sesuai).
- Untuk **auto-debit wajib anggota 5, 12, 13**, remediasi harus menyertakan penelusuran `MandatorySavingsService`: apakah subledger rekening Wajib memang sengaja tidak ditambah, atau ada langkah yang terlewat.
- **`DemoDataSeeder` sebaiknya diperbaiki** agar tidak membuat data baru yang mengulang selisih ini (buat rekening Pokok/Wajib terpisah, atau jangan `increment` rekening Sukarela). Perbaikan seeder **tidak** memperbaiki data yang sudah ada — keduanya pekerjaan berbeda.

---

## 10. Lampiran — reproduksi

```bash
# seluruh akun default (2-1001, 2-1003)
php artisan simpanan:audit-subledger

# hanya yang berselisih, format CSV
php artisan simpanan:audit-subledger --hanya-berselisih --format=csv

# akun modal (di luar gate, untuk diagnosis)
php artisan simpanan:audit-subledger --kode=3-1001 --kode=3-1002 --hanya-berselisih
```

Pengujian otomatis: `php artisan test --filter=AuditSubledgerSimpanan` → **9 test, 48 assertion** (termasuk uji bahwa command tidak mengubah jurnal maupun saldo, dan uji regresi total akun saat `--hanya-berselisih` dipakai).
