# TAHAP 7 — SKALABILITAS JALUR BACA (SARGABILITY & INDEKS PENOPANG)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-29
**Lingkup pekerjaan:** Verifikasi & perbaikan **skalabilitas jalur baca** — butir
permintaan induk yang belum diverifikasi khusus setelah Fase 1–4 (R1–R19) tuntas.
**Dasar standar:** Karakteristik kualitatif laporan keuangan PSAK 101 — *tepat
waktu* dan *dapat dibandingkan*; prinsip *scalability* & *observability* sistem
informasi koperasi; praktik optimasi basis data (prinsip **sargability** indeks
MySQL).
**Tahap sebelumnya:** `docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md`
(955 passed / 4629 assertions)

**Dokumen Pendahulu (rantai deliverable Tahap 2–7):**

| Tahap | Dokumen | Hasil suite saat penutupan |
|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | 872 passed / 4160 assertions |
| 5 | `docs/TAHAP5-HARDENING.md` | 900 passed / 4244 assertions |
| 6 | `docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md` | 955 passed / 4629 assertions |
| **7** | **`docs/TAHAP7-SKALABILITAS-JALUR-BACA.md` (dokumen ini)** | **967 passed / 4666 assertions** |

---

## 1. Ringkasan Eksekutif

Seluruh rekomendasi audit (R1–R19) telah tuntas pada Fase 1–4. Tahap 7 menyasar
**butir permintaan induk yang belum diverifikasi khusus**: *"verifikasi
skalabilitas (peningkatan jumlah anggota & volume transaksi)"*.

Pendekatan yang dipakai bukan asumsi, melainkan **pengukuran langsung**:

1. **Peta indeks** seluruh tabel utama (`SHOW INDEX` / `information_schema`).
2. **Rencana eksekusi** kueri nyata dari kode produksi (`EXPLAIN`) — sehingga
   teridentifikasi **5 full table scan** pada jalur baca berulang.
3. **Probe empiris**: setiap kandidat indeks dibuat, diuji dengan `EXPLAIN`,
   lalu dihapus — hanya indeks yang **terbukti mengubah rencana eksekusi** yang
   dipertahankan.

Hasil pengukuran menemukan **dua akar masalah yang berbeda**, dan membuktikan
bahwa perbaikan tiap akar masalah memerlukan penanganan yang bersifat
**berlawanan**:

| Akar masalah | Gejala | Penanganan Tahap 7 |
|---|---|---|
| **A. Bentuk kueri tidak sargable** | `whereMonth()/whereYear()` membungkus kolom dengan `MONTH()`/`YEAR()` → indeks tanggal yang **sudah ada** diabaikan → full table scan | Ganti ke **rentang eksplisit** (`App\Support\RentangPeriode`) → indeks eksisting terpakai (`type=range`) |
| **B. Indeks penopang memang tidak ada** | `rekening`, `jurnal`, `anggota`, `angsuran` tidak punya indeks untuk pola `WHERE` yang dipakai berulang | Tambah **5 indeks** yang terbukti dipakai (`type=ref`/`type=range`) |

**Temuan penting (anti-optimasi):** probe membuktikan bahwa **menambah indeks
apa pun pada tabel `transaksi` TIDAK membantu** kueri laporan bulanan selama
bentuknya non-sargable (`transaksi(jenis_transaksi, status_transaksi)` →
tetap `type=ALL`; `transaksi(jenis_transaksi, status_transaksi, waktu_transaksi)`
→ tetap `type=ALL`). Karena `transaksi` adalah tabel **paling write-heavy** di
sistem, menambah indeks di sana hanya akan memperlambat tulis tanpa manfaat.
Karena itu **Tahap 7 sengaja tidak menambah satu pun indeks pada `transaksi`** —
perbaikannya murni di sisi kode.

Dampak paling terasa: `ManajerController::laporan()` menjalankan **12× agregat
per bulan** (`rekapBulanan`) plus agregat periode terpilih; dan
`PengurusController::laporan()` menjalankan agregat serupa. Pola non-sargable
membuat tiap halaman laporan memicu **puluhan** full table scan pada `transaksi`,
`angsuran`, dan `rekening` — biaya yang tumbuh **linier** terhadap volume
transaksi & jumlah anggota.

---

## 2. Sasaran & Kriteria Keberhasilan

| # | Kriteria | Target | Hasil | Status |
|---|---|---|---|---|
| K7.1 | Tidak ada halaman laporan yang memicu full table scan pada tabel besar | 0 full scan pada jalur baca berulang | 5 pola `WHERE` berulang sebelumnya full scan → semuanya `type=ref`/`type=range` | ✅ |
| K7.2 | Filter periode sargable | indeks tanggal eksisting terpakai | `transaksi_waktu_transaksi_index` dipakai (`type=range`) via `RentangPeriode` | ✅ |
| K7.3 | Indeks baru hanya yang terbukti dipakai | 0 indeks spekulatif | 5 indeks, semuanya lolos probe `EXPLAIN` | ✅ |
| K7.4 | Tidak membebani jalur tulis | 0 indeks baru pada tabel write-heavy | 0 indeks ditambahkan pada `transaksi` | ✅ |
| K7.5 | Hasil laporan tidak berubah | angka identik sebelum/sesudah | test ekuivalensi (bulan, tahun, batas 23:59:59, kabisat) | ✅ |
| K7.6 | Migrasi idempoten & sadar-driver | aman diulang, multi-DB | `indeksAda()` (mysql/mariadb/sqlite/pgsql) + `Schema::hasColumn` guard | ✅ |
| K7.7 | Tidak ada regresi | baseline suite tidak turun | 955 → **967 passed** (naik 12), 4629 → 4666 assertions | ✅ |

---

## 3. Perubahan yang Dilakukan

### 3.1 Komponen baru — `app/Support/RentangPeriode.php`

Kelas util **satu sumber kebenaran** untuk filter periode sargable, lengkap
dengan dokumentasi *mengapa* (bukan sekadar *apa*). API:

| Metode | Fungsi |
|---|---|
| `awalBulan($tahun, $bulan)` / `akhirBulan(...)` | Batas inklusif `00:00:00` … `23:59:59` |
| `awalTahun($tahun)` / `akhirTahun($tahun)` | Batas rentang satu tahun takwim |
| `padaBulan($query, $kolom, $bulan, $tahun)` | Pengganti `whereMonth + whereYear` |
| `padaTahun($query, $kolom, $tahun)` | Pengganti `whereYear` |
| `padaBulanAtauTahun($query, $kolom, $tahun, ?$bulan)` | Filter opsional (bulan kosong → setahun) |

Menerima **Eloquent maupun Query Builder** agar dapat dipakai langsung di dalam
`scope*` model (yang menerima `Illuminate\Database\Query\Builder`).

**Jaminan ekuivalensi:** batas atas inklusif `endOfMonth()` (23:59:59) tetap
mencakup transaksi pada detik terakhir bulan, sehingga himpunan baris **identik**
dengan `whereMonth/whereYear` — diuji pada bulan dengan 31 hari, Februari
kabisat, dan baris tepat di batas.

### 3.2 Migrasi baru — `2026_09_30_000900_add_read_path_indexes.php`

Aditif, idempoten (`indeksAda()` sadar-driver: `information_schema.STATISTICS`
untuk MySQL/MariaDB, `sqlite_master`, `pg_indexes`), dengan guard
`Schema::hasTable` + `Schema::hasColumn` (lewati alih-alih gagal).

| Nama indeks | Tabel | Kolom | Melayani | Bukti EXPLAIN |
|---|---|---|---|---|
| `rekening_status_saldo_index` | `rekening` | `(status_rekening, saldo)` | `scopeAktif()` + `SUM(saldo)` | `type=ref`, `Extra=Using index` (**covering**) |
| `rekening_jenis_status_saldo_index` | `rekening` | `(jenis_rekening, status_rekening, saldo)` | filter jenis rekening + agregat saldo | `type=ref`, `Extra=Using index` |
| `jurnal_sumber_index` | `jurnal` | `(sumber)` | cek idempotensi posting `WHERE sumber = ?` | `type=ref`, `Extra=Using index` |
| `anggota_status_anggota_index` | `anggota` | `(status_anggota)` | `scopeAktif()` + hitungan per status | `type=ref` |
| `angsuran_status_angsuran_tanggal_bayar_index` | `angsuran` | `(status_angsuran, tanggal_bayar)` | rekap angsuran masuk bulanan | `type=range` |

### 3.3 Konversi filter non-sargable → sargable

**8 berkas produksi** diubah; seluruh `whereMonth()/whereYear()` pada kolom
tanggal bisnis dikonversi ke rentang eksplisit:

| Berkas | Titik yang diperbaiki |
|---|---|
| `app/Http/Controllers/Dashboard/PengurusController.php` | `index()` total transaksi bulan ini; `laporan()` pemasukan & pengeluaran; `pengesahanShu()` pendapatan setahun |
| `app/Http/Controllers/Dashboard/ManajerController.php` | `laporan()`: closure `$queryTransaksi`, pembiayaan cair, angsuran masuk, transaksi bermasalah, `$rincianTransaksi` (GROUP BY), **`$rekapBulanan` (12× agregat)**; `agregatKinerjaAO()` 2 closure; `audit()` filter opsional bulan/tahun |
| `app/Http/Controllers/Dashboard/PengelolaController.php` | grafik 6 bulan pembiayaan & angsuran |
| `app/Http/Controllers/NasabahController.php` | total transaksi bulan ini (portal anggota) |
| `app/Http/Controllers/AngsuranController.php` | filter `index()` bulan+tahun |
| `app/Http/Controllers/PengeluaranBebanController.php` | total beban disetujui bulan ini |
| `app/Models/Angsuran.php` | `scopeJatuhTempoBulanIni()` |

**Fallback yang sengaja dipertahankan:** filter **bulan saja tanpa tahun**
(mis. `?bulan=5` pada halaman angsuran, atau filter audit) tidak dapat
dijadikan rentang tanggal karena rentangnya tak tentu — titik ini tetap memakai
`whereMonth()`. Perilaku lama dipertahankan penuh agar tidak ada perubahan
semantik yang tidak diminta.

---

## 4. Pemetaan Temuan → Perbaikan → Bukti

| # | Temuan (hasil `EXPLAIN` pra-perbaikan) | Perbaikan | Bukti pasca-perbaikan |
|---|---|---|---|
| T7-01 | `rekening`: `SUM(saldo) WHERE status_rekening='Aktif'` → `type=ALL`, `key=NULL` | indeks `(status_rekening, saldo)` | `type=ref`, `key=rekening_status_saldo_index`, `Extra=Using index` |
| T7-02 | `rekening`: `WHERE jenis_rekening=…` → `type=ALL` | indeks `(jenis_rekening, status_rekening, saldo)` | `type=ref`, `Extra=Using index` |
| T7-03 | `jurnal`: `WHERE sumber=…` → `type=ALL` (jalur terpanas akuntansi) | indeks `(sumber)` | `type=ref`, `key=jurnal_sumber_index` |
| T7-04 | `anggota`: `WHERE status_anggota='Aktif'` → `type=ALL` | indeks `(status_anggota)` | `type=ref`, `key=anggota_status_anggota_index` |
| T7-05 | `angsuran`: rekap masuk (status + tanggal) → full scan | indeks `(status_angsuran, tanggal_bayar)` + filter sargable | `type=range`, `key=angsuran_status_angsuran_tanggal_bayar_index` |
| T7-06 | `transaksi`: laporan bulanan `MONTH()/YEAR()` → `type=ALL` | **konversi kode** ke `RentangPeriode` (indeks eksisting) | `type=range`, `key=transaksi_waktu_transaksi_index` |
| T7-07 | Probe: menambah indeks pada `transaksi` **tetap** `type=ALL` | **keputusan: 0 indeks** pada `transaksi` | jalur tulis `transaksi` tidak dibebani; terbukti tidak berguna |

---

## 5. Pengujian

### 5.1 Test regresi baru — `tests/Feature/Tahap7SkalabilitasTest.php`

12 test / 37 assertions, murni-baca (`DatabaseTransactions`), dijalankan pada
MySQL (skip otomatis pada driver lain):

| Kelompok | Test |
|---|---|
| **Keberadaan indeks** | `test_indeks_jalur_baca_tahap_7_terpasang` |
| **Indeks benar-benar dipakai (`EXPLAIN`)** | rekening agregat saldo, jurnal `sumber`, anggota `status_anggota`, angsuran rekap, dan **filter sargable transaksi** (sekaligus membuktikan bentuk lama memang non-sargable) |
| **Ekuivalensi hasil** | `padaBulan` ≡ `whereMonth+whereYear` (dengan baris di batas `00:00:00` & `23:59:59`), `padaTahun` ≡ `whereYear`, `padaBulanAtauTahun`, scope `Angsuran::jatuhTempoBulanIni()` |
| **Kasus tepi** | presisi batas periode & **Februari kabisat** (`2024-02-29`), akhir Desember |
| **Integrasi HTTP** | halaman `manajer.laporan.index` & `pengurus.laporan.index` tetap **200 OK** |

### 5.2 Verifikasi manual (`EXPLAIN` atas kueri nyata)

```
PengurusController::index      → type=range  key=transaksi_waktu_transaksi_index rows=10
ManajerController::laporan     → type=range  key=transaksi_waktu_transaksi_index (GROUP BY)
rekapBulanan angsuran          → type=range  key=angsuran_status_angsuran_tanggal_bayar_index
SUM(saldo) rekening aktif      → type=ref    key=rekening_status_saldo_index  Extra=Using index
jurnal WHERE sumber = ?        → type=ref    key=jurnal_sumber_index
anggota WHERE status_anggota=? → type=ref    key=anggota_status_anggota_index
```

### 5.3 Suite penuh

```
Tests:    967 passed (4666 assertions)
Duration: 133.87s
```

**Nol regresi.** Kenaikan tepat = 12 test / 37 assertions baru Tahap 7 di atas
baseline Tahap 6 (955 / 4629).

---

## 6. Kasus Tepi yang Diuji

| Kasus | Perlakuan | Alasan |
|---|---|---|
| Batas atas bulan `23:59:59` | inklusif (`endOfMonth()`) | transaksi pada detik terakhir bulan tidak boleh hilang karena kolom DATETIME |
| Februari kabisat (29 hari) | `2024-02-29 23:59:59` | jumlah hari bulan harus diikuti, bukan diasumsikan 28/30/31 |
| Desember (bulan terakhir tahun) | `2026-12-31 23:59:59` | batas tahun tidak boleh "bocor" ke Januari tahun berikutnya |
| Baris 29 Feb / 1 Apr di luar periode | tidak ikut terhitung | memastikan batas tidak *over-inclusive* |
| Filter **bulan tanpa tahun** | tetap `whereMonth()` (fallback) | rentang tanggal tidak dapat ditentukan tanpa tahun |
| Tabel/kolom tidak ada (DB parsial) | migrasi **lewati**, tidak gagal | migrasi harus aman pada lingkungan berbeda |

---

## 7. Risiko Sisa

| Kode | Risiko | Dampak | Mitigasi yang ada |
|---|---|---|---|
| C7.1 | `PengurusController::laporanDanaKebajikan()` memakai `->get()` tanpa batas baris (`L329`) | tumbuh seiring riwayat dana kebajikan | pembatasan periode sudah ada; kandidat paginasi pada tahap berikutnya |
| C7.2 | `PengurusController::laporan()` `Rekening::sum('saldo')` agregat penuh | tetap memindai seluruh rekening | kini **covering index** `(status_rekening, saldo)` tersedia bila difilter ke rekening aktif |
| C7.3 | Kolom `waktu_transaksi`/`created_at` tanpa indeks pada sebagian tabel turunan | filter rentang belum optimal di tabel kecil | tabel turunan volumenya kecil; dievaluasi ulang bila tumbuh |
| C7.4 | Komentar usang di `CheckRole.php` & `User.php` menyebut "Role 'anggota' tidak lagi memiliki akses" | membingungkan pembaca | tidak memengaruhi perilaku (rute `role:anggota` aktif); kandidat pembersihan dokumentasi |

---

## 8. Pernyataan Penyelesaian

Tahap 7 **selesai dan terverifikasi**. Butir permintaan induk *"verifikasi
skalabilitas (peningkatan jumlah anggota & volume transaksi)"* kini terjawab
secara terukur, bukan asumtif:

1. **Lima** full table scan pada jalur baca berulang (rekening, jurnal,
   anggota, angsuran) **dihilangkan** dengan indeks yang dibuktikan terpakai.
2. **Seluruh** filter periode pada kolom tanggal bisnis kini **sargable**,
   sehingga indeks tanggal yang sudah ada benar-benar bekerja.
3. **Akumulasi 12× agregat** pada halaman laporan manajer dan agregat laporan
   pengurus tidak lagi memicu pemindaian penuh `transaksi`.
4. **Jalur tulis tidak dibebani** — nol indeks ditambahkan pada tabel
   `transaksi` yang write-heavy, berdasarkan bukti probe bahwa indeks di sana
   tidak berguna untuk bentuk kueri laporan.
5. **Angka laporan tidak berubah** — ekuivalensi dikunci oleh test, termasuk
   kasus tepi batas bulan & tahun kabisat.
6. Suite: **967 passed / 4666 assertions**, tanpa regresi dari baseline
   Tahap 6 (955 / 4629).

Dokumen ini melengkapi rantai deliverable Tahap 2–7 dan menjadi catatan progres
terkini proyek.
