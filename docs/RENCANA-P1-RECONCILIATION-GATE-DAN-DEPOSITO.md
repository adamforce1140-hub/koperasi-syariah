# Rencana P1 — Reconciliation Gate & Perbaikan Modul Deposito

**Status:** DRAF — menunggu persetujuan sebelum implementasi
**Tanggal:** 20 Oktober 2026
**Lingkup:** Tahap P1 (butir 4 Rekomendasi Berurutan pada `LAPORAN-PEMELIHARAAN-SISTEM.md`)
**Sifat:** Dokumen perencanaan. **Belum ada perubahan kode/data** yang dilakukan berdasarkan dokumen ini.

---

## 1. Keputusan Prioritas

**Prioritas yang dipilih: dokumen perencanaan lebih dahulu, implementasi menyusul setelah disetujui.**

Alasan:

1. **P1 menyentuh nilai uang dan jurnal.** Perbaikan `depositoStore` mengubah akun sumber dana (Kas Teller → Simpanan Sukarela) dan berpotensi mengubah saldo anggota. Tanpa persetujuan tertulis, perubahan ini tidak dapat dibalik dengan aman.
2. **Ada dua keputusan bisnis yang hanya boleh diambil pengurus**, bukan oleh agen: (a) cara membetulkan selisih Rp21.500.000, dan (b) perlakuan atas 2 rekening berjangka tanpa bilyet senilai Rp15.000.000. Keduanya memerlukan dasar akuntansi dan persetujuan, bukan sekadar perbaikan kode.
3. **Reconciliation gate akan menolak transaksi.** Begitu gate aktif, setiap ketidaksinkronan subledger vs GL membuat transaksi gagal. Gate tidak boleh dipasang sebelum selisih yang sudah ada dibereskan, karena seluruh transaksi deposito/tabungan akan terblokir.
4. **Urutan ini tidak menambah waktu total.** Pekerjaan diagnostik yang dibutuhkan dokumen ini (pengukuran selisih, pemetaan akar masalah) adalah pekerjaan yang sama yang harus dilakukan sebelum implementasi.

Konsekuensi: implementasi baru dimulai setelah bagian **§9 Keputusan yang Memerlukan Persetujuan** ditandatangani.

---

## 2. Baseline Terukur (kondisi hari ini)

### 2.1 Hasil pengukuran data deposito

Diukur langsung dari basis data `koperasi_syariah` pada 20/10/2026, memakai saldo GL `Posted` (bukan `deleted_at`) dibandingkan subledger `rekening`.

| Indikator | Nilai |
|---|---|
| GL `2-1001` Simpanan Sukarela (kredit − debet) | Rp 80.950.000 |
| Subledger rekening Sukarela (`saldo`) | Rp 102.450.000 |
| **Selisih A-1** | **−Rp 21.500.000** (GL lebih rendah) |
| GL `2-1003` Simpanan Berjangka (kredit − debet) | Rp 15.000.000 |
| Subledger rekening Berjangka (`saldo`) | Rp 15.000.000 |
| **Selisih A-2 (GL vs subledger)** | **Rp 0** |
| Rekening berjangka status `Aktif` | 2 rekening, Rp 15.000.000 |
| Baris tabel `depositos` | **0** |
| Rekening berjangka bersaldo **tanpa** baris `depositos` | **2** |
| Bilyet `ACTIVE` tanpa rekening | 0 |
| Rekening masih `Aktif`/`Jatuh Tempo` padahal bilyet `CLOSED`/`MATURED` | 0 |

**Koreksi terhadap temuan A-2 di laporan sebelumnya.** A-2 bukan selisih GL vs subledger (selisihnya nol), melainkan **subledger berisi 2 rekening berjangka yang tidak memiliki bilyet di tabel `depositos`**. Ini mengubah sifat pekerjaan: bukan jurnal penyesuaian, melainkan pemulihan data master (backfill) atau pembatalan.

### 2.2 Akar masalah A-2 sudah teridentifikasi

`TellerController::depositoStore()` ([TellerController.php](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L1400-L1495)) membuat:

- baris `rekening` (Simpanan Berjangka) — **ya**
- baris `transaksi` — **ya**
- jurnal `jurnalSetorDeposito()` → **D 1-1001 Kas Teller / K 2-1003** — **ya**
- baris `depositos` (bilyet: produk, tenor, nisbah, `source_saving_account_id`, jatuh tempo sistem) — **tidak**

Jumlah rekening yatim (2) persis sama dengan jumlah rekening berjangka aktif (2). Ini konsisten dengan kesimpulan bahwa **seluruh rekening berjangka yang ada dibuat lewat jalur lama `depositoStore`, bukan lewat `DepositoService`**.

### 2.3 Baseline pengujian

| Suite | Hasil |
|---|---|
| `php artisan test --filter=Deposito` | **150 passed, 736 assertions, 24,57 s** |
| Suite penuh (acuan sesi sebelumnya) | **1768 passed, 5 skipped, 8371 assertions** |

Baseline ini menjadi pembanding "tidak ada regresi" untuk setiap tahap.

---

## 3. Peta Temuan → Pekerjaan

| Kode | Temuan | Bukti | Pekerjaan |
|---|---|---|---|
| B-1 | `depositoStore` tanpa `try/catch`; exception jurnal bocor sebagai stack trace | `DB::transaction` di [TellerController.php#L1443](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L1443) tanpa penangkap; handler lain (`depositoCair`, `tambahModalKas`, `setorBrankas`) memakai `catch (\Throwable)` | P1-4 |
| B-2 | `depositoStore` melewati `DepositoService` → tidak ada bilyet, produk, tenor, nisbah snapshot, `source_saving_account_id`, validasi `min_amount` | `depositos` 0 baris; 2 rekening berjangka yatim | P1-4, P1-3 |
| B-3 | Jurnal pembukaan mendebet **Kas Teller 1-1001**, melanggar kontrak akuntansi | `jurnalSetorDeposito()` [AutoJournalingService.php#L471-L477](file:///c:/laragon/www/koperasi_syariah/app/Services/AutoJournalingService.php#L471-L477) vs kontrak [DepositoService.php#L22-L38](file:///c:/laragon/www/koperasi_syariah/app/Services/DepositoService.php#L22-L38) dan `jurnalPembukaanDeposito()` di baris 516 | P1-4 |
| B-4 | Tidak ada validasi saldo/kepemilikan rekening Simpanan Sukarela sumber dana | Form hanya mengirim `anggota_id`, `nominal`, `tanggal_jatuh_tempo`, `nisbah_nasabah` | P1-4 |
| B-5 | Jatuh tempo & nisbah diambil dari input pengguna, bukan dari produk | `'tanggal_jatuh_tempo' => 'required|date|after:today'`, `'nisbah_nasabah' => nullable|numeric` | P1-4 |
| B-6 | `nomorRekeningBaru()` dipanggil di luar `DB::transaction` → celah duplikasi saat submit bersamaan | [TellerController.php#L1421](file:///c:/laragon/www/koperasi_syariah/app/Http/Controllers/TellerController.php#L1421) | P1-4 |
| B-7 | Tidak ada kunci baris (`lockForUpdate`) pada rekening/anggota | tidak ada `lockForUpdate` di seluruh `depositoStore` | P1-4 |
| B-8 | Tidak ada gate yang membandingkan subledger `rekening` dengan saldo GL | `JurnalReconciliationService` hanya memeriksa balance/header/status jurnal | P1-1 |
| B-9 | Selisih A-1 Rp21.500.000 belum teratribusi per anggota | pengukuran agregat saja | P1-2 |
| B-10 | 2 rekening berjangka tanpa bilyet | lihat §2.1 | P1-3 |

---

## 4. Detail Teknis per Pekerjaan

### P1-0 — Gerbang baseline & cabang kerja

| Item | Isi |
|---|---|
| Berkas | tidak ada perubahan kode |
| Tindakan | (1) `git status` bersih / commit penanda; (2) jalankan `php artisan test --filter=Deposito` dan simpan keluaran; (3) `php artisan db:cadangkan --tujuan=storage/app/backups/pra-p1.sql.gz`; (4) catat snapshot angka §2.1 |
| Keluaran | berkas cadangan pra-perubahan + log baseline |
| Kriteria lulus | cadangan dapat dibuka (`gzip -t`), baseline 150 passed tercatat |

### P1-1 — Reconciliation gate: subledger vs Buku Besar

**Tujuan:** mendeteksi dan mencegah selisih antara saldo rekening anggota (subledger) dan saldo akun kontrol di GL, untuk akun `2-1001` dan `2-1003`.

**Berkas yang disentuh**

| Berkas | Perubahan |
|---|---|
| `app/Services/JurnalReconciliationService.php` | tambah `rekonsiliasiSubledger()`, `selisihSubledger()`, `pastikanSubledgerSehat()` |
| `app/Console/Commands/RekonsiliasiJurnal.php` (nama mengikuti command `jurnal:rekonsiliasi` yang ada) | sertakan hasil gate ke keluaran & exit code |
| `app/Console/Commands/PeriksaDenyutOperasional.php` | tambah indikator keempat: selisih subledger |
| `app/Services/DepositoService.php` | panggil gate di akhir transaksi pembukaan/pencairan/bagi hasil |
| `app/Http/Controllers/TellerController.php` | panggil gate di akhir `depositoStore` & `depositoCair` |
| `config/reconciliation.php` | `gate_enforce` dari env `RECONCILIATION_GATE_ENFORCE` |
| `tests/Feature/RekonsiliasiSubledgerTest.php` | 12 test: deteksi, cakupan status, CoA hilang, dua mode gate, keluaran command |

**Rancangan metode**

```php
/**
 * @return array<int, array{kode:string, gl:float, subledger:float, selisih:float, status:string}>
 */
public function selisihSubledger(array $kodeAkun = ['2-1001', '2-1003']): array

/**
 * Gate transaksional. Dipanggil DI DALAM DB::transaction sehingga membaca
 * keadaan belum-commit pada koneksi yang sama.
 * @throws \RuntimeException bila |selisih| > toleransi
 */
public function pastikanSubledgerSehat(array $kodeAkun = ['2-1001', '2-1003']): void

public function rekonsiliasiBukuBesar(?string $dari = null, ?string $sampai = null): array
// hasil ditambah kunci 'subledger' => $this->selisihSubledger()
```

**Aturan hitung**

- GL: `SUM(jurnal_detail.kredit) − SUM(jurnal_detail.debet)` untuk `jurnal.status = 'Posted'` dan `jurnal.deleted_at IS NULL`, dikelompokkan per `coa.kode`.
- Subledger `2-1001`: `SUM(rekening.saldo)` untuk `jenis_rekening IN (nama Sukarela)`, `deleted_at IS NULL`, **seluruh status rekening** (`Aktif`, `Dibekukan`, `Jatuh Tempo`, `Ditutup`).
- Subledger `2-1003`: sama untuk jenis Berjangka.
- Toleransi: `Setting::toleransiSelisihJurnal()` (bawaan `0.01`), konsisten dengan `periksaJurnal()`.
- **Catatan revisi (saat implementasi):** filter `status_rekening = 'Aktif'` sengaja **tidak** dipakai. Rekening `Jatuh Tempo`/`Dibekukan` tetap menyimpan kewajiban koperasi kepada anggota (lihat keputusan X-4), sehingga mengecualikannya menciptakan selisih palsu. Rekening `Ditutup` selalu bersaldo nol pada alur yang benar; bila masih bersaldo, itu memang temuan sah.
- Bila `coa` untuk kode tersebut tidak ada → kembalikan status `coa_hilang`, jangan melempar (agar command tetap bisa melaporkan).

**Perilaku gate**

- `pastikanSubledgerSehat()` melempar `RuntimeException` dengan pesan yang menyebut kode akun, nilai GL, nilai subledger, dan selisih. Pemanggil (`DB::transaction`) otomatis rollback.
- Gate **tidak** memanggil dirinya sendiri dari `AutoJournalingService::post()`. Alasannya: pada banyak alur, jurnal diposting sebelum saldo rekening diperbarui, sehingga pemeriksaan di dalam `post()` akan memberi false positive. Gate dipasang di **batas transaksi**, setelah seluruh mutasi selesai.

**Titik pemasangan**

| Pemanggil | Lokasi | Catatan |
|---|---|---|
| `DepositoService::createDeposito` | sebelum `return $deposito->fresh(...)` | setelah saldo sukarela dipotong & jurnal pembukaan diposting |
| `DepositoService::settleMaturedDeposito` | sebelum `return` | |
| `DepositoService::earlyWithdrawal` | sebelum `return` | |
| `DepositoService::distributeProfitSharing` | sebelum `return` | memengaruhi `2-1001` |
| `TellerController::depositoStore` | akhir closure transaksi | |
| `TellerController::depositoCair` | akhir closure transaksi | |

`processMaturity()` **tidak** dipasangi gate langsung: ia memanggil `cairkanPenuhNonAro()`/`perpanjangAro()` yang memodifikasi saldo, dan `selesaikanBagiHasilTertunda()` berjalan lebih dulu di dalam closure yang sama sehingga gate di titik itu berisiko melaporkan selisih transien. Gerbangnya sudah tercakup lewat `settleMaturedDeposito()`.

**Catatan penting:** gate ini akan **gagal** pada data hari ini (selisih Rp21.500.000). Karena itu P1-1 hanya boleh di-merge setelah P1-2 diselesaikan, atau diaktifkan bertahap:

1. **Mode lapor** (default): gate hanya menulis log `critical` + menambah temuan pada `jurnal:rekonsiliasi`, tidak melempar.
2. **Mode tegakkan**: diaktifkan lewat `Setting`/env `RECONCILIATION_GATE_ENFORCE=true` setelah selisih nol.

Mode lapor dipilih untuk rilis pertama agar tidak memblokir operasional teller.

### P1-2 — Atribusi & remediasi selisih A-1 (Rp21.500.000)

**Tujuan:** mengetahui rekening/anggota mana yang menyebabkan selisih, lalu menyusun jurnal penyesuaian yang dapat dipertanggungjawabkan.

**Berkas baru:** `app/Console/Commands/AuditSubledgerSimpanan.php` → `simpanan:audit-subledger`

| Opsi | Fungsi |
|---|---|
| `--kode=2-1001` | akun yang diaudit (boleh berulang) |
| `--format=table\|json\|csv` | format keluaran |
| `--hanya-berselisih` | sembunyikan baris yang cocok |

**Keluaran per anggota**

```
anggota_id | no_anggota | nama | no_rekening | saldo_subledger | mutasi_gl | selisih | jumlah_transaksi
```

`mutasi_gl` per anggota dihitung dari `jurnal_detail.anggota_id` — **bukan** dari header jurnal, karena tabel `jurnal` tidak memiliki kolom `anggota_id` (lihat migration `2024_01_01_000008_create_jurnal_table.php`). Bila `jurnal_detail.anggota_id` kosong, baris dikelompokkan sebagai `TANPA_ANGGOTA`; kelompok itu harus ditelusuri manual karena tidak dapat diatribusikan ke anggota.

**Sifat:** read-only. Command ini **tidak menulis** jurnal maupun saldo.

**Implementasi (selesai 2026-10-21)**

- `JurnalReconciliationService::TIPE_BY_KODE` dijadikan `public const` dan diperluas ke `3-1001`/`3-1002` agar command memakai pemetaan akun→tipe yang sama. `KODE_SUBLEDGER_DEFAULT` **tidak diubah** (tetap `2-1001` & `2-1003`), sehingga perilaku gate P1-1 tidak berubah.
- Total per akun (`gl`, `subledger`, `selisih`) dihitung **sebelum** `--hanya-berselisih` menyaring baris, agar selalu sama dengan tabel `jurnal:rekonsiliasi`.
- `tests/Feature/AuditSubledgerSimpananTest.php` — 9 test (termasuk uji bahwa command tidak mengubah jurnal/saldo, serta uji regresi total akun saat `--hanya-berselisih` dipakai).
- Hasil audit: `docs/HASIL-AUDIT-P1-2-SUBLEDGER-SIMPANAN.md`. Ringkas: `2-1001` −Rp21.500.000 = Rp18.500.000 (3 rekening legacy `RekeningSeeder` tanpa jurnal) + Rp3.000.000 (bug `DemoDataSeeder` — pokok/wajib 5 anggota masuk rekening Sukarela); `2-1003` cocok; `3-1001` +Rp2.200.000 dan `3-1002` −Rp850.000 (di luar gate). Tidak ada baris `TANPA_ANGGOTA`.

**Skenario remediasi (dipilih setelah hasil audit)**

| Skenario | Kapan dipilih | Bentuk tindakan |
|---|---|---|
| R-1 Jurnal penyesuaian | subledger benar, GL kurang catat | jurnal manual D/K akun lawan yang sah, disetujui pengurus, dengan nomor bukti |
| R-2 Koreksi subledger | GL benar, saldo rekening salah | penyesuaian `rekening.saldo` + `transaksi` koreksi beralasan |
| R-3 Reklasifikasi | dana tercatat di akun salah | jurnal reklasifikasi antar akun `2-100x` |

**Aturan:** eksekusi remediasi **tidak** dilakukan lewat command otomatis. Disediakan sebagai jurnal manual di UI jurnal dengan lampiran berita acara, agar jejak audit dan otorisasi terbentuk.

### P1-3 — Pemulihan 2 rekening berjangka tanpa bilyet (Rp15.000.000)

**Tujuan:** menghilangkan rekening yatim sehingga `rekening` dan `depositos` konsisten 1:1.

**Langkah diagnosis (read-only)**

1. Tampilkan 2 rekening tersebut: `id`, `no_rekening`, `anggota_id`, `saldo`, `tanggal_buka`, `tanggal_jatuh_tempo`, `nisbah_nasabah`, `status_rekening`.
2. Tampilkan seluruh `transaksi` yang menyentuh rekening tersebut beserta `no_jurnal` pasangannya.
3. Periksa apakah anggota pemilik masih aktif dan apakah memiliki rekening Sukarela dengan saldo cukup.

**Dua opsi penyelesaian (butuh keputusan pengurus)**

| Opsi | Isi | Dampak jurnal | Risiko |
|---|---|---|---|
| **O-1 Backfill bilyet** | buat baris `depositos` untuk tiap rekening: `product_id` = produk deposito aktif yang tenornya cocok dengan selisih tanggal, `principal_amount` = saldo, `source_saving_account_id` = rekening Sukarela anggota, `status = ACTIVE` | tidak ada jurnal baru | nisbah & produk diisi hasil rekonstruksi, bukan akad asli → perlu berita acara |
| **O-2 Batalkan & bukukan ulang** | jurnal balik D `2-1003` / K `1-1001`, tutup rekening, lalu buka ulang lewat `DepositoService` dari Simpanan Sukarela | 2 jurnal balik + 1 jurnal pembukaan baru per rekening | memerlukan saldo Sukarela cukup; jika tidak cukup, transaksi tidak dapat dibukukan |

Rekomendasi awal: **O-1** bila akad asli terverifikasi dari dokumen fisik; **O-2** bila tidak ada dokumen akad. Keputusan ini tidak diambil oleh agen.

**Berkas baru:** `app/Console/Commands/PulihkanDepositoTanpaBilyet.php` → `deposito:pulihkan-tanpa-bilyet`
- Default `--dry-run`.
- `--terapkan` wajib disertai `--product-id=` dan `--konfirmasi=` (mengetik ulang jumlah rekening).
- Setiap penulisan memakai `DB::transaction` + `AuditLog::record`.
- Menolak berjalan bila `depositos` untuk rekening tersebut sudah ada.

### P1-4 — Perbaikan menyeluruh `depositoStore`

**Prinsip:** `TellerController::depositoStore()` diubah menjadi **adaptor tipis** di atas `DepositoService::createDeposito()`. Satu jalur pembukaan deposito saja; jalur lama dihapus agar tidak ada dua sumber kebenaran.

**Perubahan controller**

| Sebelum | Sesudah |
|---|---|
| validasi `anggota_id`, `nominal`, `tanggal_jatuh_tempo`, `nisbah_nasabah`, `catatan` | validasi `anggota_id`, `product_id` (exists & aktif), `source_saving_account_id` (milik anggota, Sukarela, Aktif), `nominal`, `aro_mode`, `catatan` |
| `tanggal_jatuh_tempo` dari input | dihapus; dihitung `DepositoService` dari `start_date + tenor_months` |
| `nisbah_nasabah` dari input | dihapus; diambil dari snapshot produk |
| `nomorRekeningBaru()` di luar transaksi | dihapus; `DepositoService::generateNoRekening()` di dalam transaksi |
| `Transaksi::create` + `jurnalSetorDeposito()` manual | dihapus; ditangani `DepositoService` |
| `DB::transaction` tanpa `catch` | `try { DB::transaction(...) } catch (ValidationException $e) { return back()->withErrors(...) } catch (\Throwable $e) { report($e); return back()->withInput()->with('error', 'Pembukaan deposito dibatalkan: '.$e->getMessage()); }` |
| redirect ke `teller.nota` | tetap, memakai transaksi keluar dari Sukarela yang dibuat `DepositoService` |

**Perubahan view** `resources/views/teller/deposito.blade.php`

- Tambah `<select name="product_id">` (produk `active()->deposito()`, menampilkan tenor & nisbah).
- Tambah `<select name="source_saving_account_id">` yang terisi dinamis per anggota (endpoint data rekening Sukarela aktif + saldo), dengan tampilan saldo.
- Hapus input `tanggal_jatuh_tempo` dan `nisbah_nasabah`; ganti dengan teks read-only "dihitung sistem".
- Tambah `<select name="aro_mode">` (`NON_ARO`, `ARO_POKOK`, `ARO_PLUS`).
- Preview jurnal diperbarui: **D 2-1001 / K 2-1003** (bukan D 1-1001).

**Perubahan service**

- `DepositoService::createDeposito()` sudah memenuhi kontrak (validasi saldo, lock, snapshot nisbah, jurnal `2-1001`/`2-1003`, rollback). Yang ditambahkan hanya pemanggilan gate P1-1.
- Tidak ada perubahan pada `AutoJournalingService`. Fungsi `jurnalSetorDeposito()` tetap ada karena masih dipakai `TransactionLimitService` untuk setoran tunai ke rekening berjangka; **tidak dihapus** pada tahap ini untuk menghindari perubahan perilaku di luar lingkup.

**Perubahan route** `routes/web.php`

- `POST /deposito` tetap, middleware `['anggota.aktif','kas.terbuka']` dipertahankan.
- Tambah route data: `GET /deposito/rekening-sumber/{anggota}` (JSON: rekening Sukarela aktif + saldo) untuk mengisi dropdown.

**Kasus tepi yang harus ditangani**

| Kasus | Perilaku |
|---|---|
| Anggota tanpa rekening Sukarela aktif | `back()->withErrors(['source_saving_account_id' => ...])` |
| Saldo Sukarela < nominal | pesan menyebut saldo tersedia & dibutuhkan |
| Nominal di bawah `min_amount` produk | pesan menyebut minimum produk |
| Produk non-aktif / bukan tipe deposito | ditolak |
| Jurnal gagal diposting | rollback penuh, pesan ramah, **tanpa stack trace** |
| Submit ganda (double click) | dicegah `lockForUpdate` pada rekening sumber; percobaan kedua gagal validasi saldo |

### P1-5 — Pengujian & pencegahan regresi

**Berkas baru:** `tests/Feature/ReconciliationGateTest.php`, `tests/Feature/DepositoStoreRefactorTest.php`

| No | Skenario uji | Ekspektasi |
|---|---|---|
| T-1 | Selisih subledger vs GL disuntikkan → `selisihSubledger()` | melaporkan selisih tepat, status `selisih` |
| T-2 | Gate mode tegakkan dipanggil saat selisih ada | `RuntimeException`, transaksi rollback, saldo tidak berubah |
| T-3 | Gate mode lapor saat selisih ada | tidak melempar, log `critical` tercatat |
| T-4 | `depositoStore` sukses | baris `depositos` terbentuk, `source_saving_account_id` terisi, `maturity_date` = start + tenor |
| T-5 | `depositoStore` sukses | jurnal D `2-1001` / K `2-1003`; **tidak ada** baris D `1-1001` |
| T-6 | Saldo Sukarela kurang | 422/redirect error, saldo & jumlah rekening tidak berubah |
| T-7 | Anggota tanpa Sukarela | ditolak dengan pesan jelas |
| T-8 | Jurnal gagal (CoA dinonaktifkan) | redirect + `error`, **tidak ada** stack trace, tidak ada rekening yatim |
| T-9 | Submit dua kali berurutan dengan saldo hanya cukup sekali | percobaan kedua ditolak, total saldo benar |
| T-10 | `simpanan:audit-subledger --format=json` | JSON valid, total selisih = angka §2.1 |
| T-11 | `deposito:pulihkan-tanpa-bilyet --dry-run` | tidak menulis apa pun |
| T-12 | Regresi suite deposito | 150 passed (baseline) |
| T-13 | Regresi suite penuh | ≥1768 passed, 0 failed |

### P1-6 — Observabilitas, runbook, dan dokumentasi

| Item | Isi |
|---|---|
| `jurnal:rekonsiliasi` | tambah baris ringkasan `SUBledger 2-1001/2-1003`, exit code 1 bila selisih > toleransi |
| `denyut:pemeriksaan` | indikator keempat: `selisih_subledger`; kegagalan memicu exit 1 |
| Log | kanal `rekonsiliasi`, level `critical`, pembatas 1 pesan/jam/akun |
| Runbook | bagian baru di dokumen ini: prosedur saat gate menolak transaksi (cek `simpanan:audit-subledger`, eskalasi, remediasi) |
| Dokumentasi | perbarui `docs/LAPORAN-PEMELIHARAAN-SISTEM.md` §3 dan §Rekomendasi setelah selesai |

---

## 5. Dependensi Antar Modul

### 5.1 Matriks dependensi

| Pekerjaan | Bergantung pada | Diperlukan oleh | Jenis ketergantungan |
|---|---|---|---|
| P1-1 Gate | `Coa`, `Jurnal`, `JurnalDetail`, `Rekening`, `JenisSimpanan` | `jurnal:rekonsiliasi`, `denyut:pemeriksaan`, `DepositoService`, `TellerController` | **kuat** — gate tidak bisa dihitung tanpa model CoA & pemetaan jenis rekening |
| P1-2 Audit A-1 | P1-1 (fungsi `selisihSubledger`) | P1-1 mode tegakkan, laporan neraca | **kuat** — angka harus nol sebelum gate ditegakkan |
| P1-3 Backfill bilyet | `DepositoProduct`, `Rekening`, `Deposito` | P1-1 (agar `2-1003` punya rujukan master), modul Exit Keanggotaan | **sedang** |
| P1-4 Refactor `depositoStore` | `DepositoService`, `AutoJournalingService`, view teller, route | P1-5, P1-1 | **kuat** — satu-satunya jalur pembukaan deposito |
| P1-5 Pengujian | P1-1 s.d. P1-4 | — | **kuat** |
| P1-6 Observabilitas | P1-1, P1-2 | Operasional harian | **sedang** |

### 5.2 Modul lain yang terkena dampak

| Modul | Dampak | Penanganan |
|---|---|---|
| **Neraca & Buku Besar** (`laporan.neraca`, `laporan.buku-besar`) | angka `2-1001`/`2-1003` berubah bila remediasi dijalankan | laporkan dampak per tanggal; remediasi memakai tanggal jurnal yang disetujui, bukan hari ini |
| **Exit Keanggotaan** (`ExitKeanggotaanService`) | menutup rekening berjangka; rekening yatim saat ini tidak tertangani karena tidak punya bilyet | P1-3 harus selesai lebih dulu agar exit dapat menutup seluruh rekening berjangka |
| **SHU** (`Pengurus\ShuController`) | mengkredit `2-1001`; penyebab potensial selisih A-1 | P1-2 harus memeriksa jurnal SHU yang tanpa `anggota_id` |
| **Bagi Hasil Deposito** (`DistribusiBagiHasilDeposito`) | mengkredit `2-1001`; ikut terkena gate | uji regresi `Fase3BagiHasilLabaRiilTest` |
| **Simpanan Wajib** (`MandatorySavingsService`) | tidak memakai `2-1001`, tetapi berbagi `AutoJournalingService` | uji regresi suite penuh |
| **API Deposito** (`Api\DepositoController`) | sudah benar; menjadi acuan implementasi P1-4 | jadikan referensi, bukan diubah |
| **CIF / AO** (`CifController::bukaDeposito`) | sudah benar (memakai `DepositoService`) | tidak diubah |
| **Reversal** (`ReversalService`) | jurnal balik harus tetap seimbang | `ReversalDepositoDanNomorSequenceTest` |

### 5.3 Urutan pelaksanaan yang tidak dapat dibalik

```
P1-0 baseline
   └─> P1-1 (mode lapor)  ──> P1-2 audit  ──> [persetujuan pengurus] ──> remediasi A-1
                                   │
                                   └─> P1-3 diagnosis ──> [persetujuan pengurus] ──> O-1/O-2
   └─> P1-4 refactor (paralel dengan P1-2, tidak menyentuh data lama)
                                   └─> P1-5 uji ──> P1-6 ──> P1-1 mode tegakkan
```

P1-4 boleh berjalan paralel dengan P1-2 karena hanya mengubah jalur transaksi **baru**. P1-1 mode tegakkan adalah langkah **terakhir**, setelah selisih nol.

---

## 6. Timeline Estimasi

Estimasi dalam hari kerja orang, bukan hari kalender. Asumsi satu pelaksana.

| Tahap | Pekerjaan | Estimasi | Keluaran | Gerbang |
|---|---|---|---|---|
| 0 | Baseline, cadangan, cabang kerja | 0,5 hari | cadangan + log baseline | cadangan terverifikasi |
| 1 | P1-1 gate mode lapor + `selisihSubledger()` | 1,5 hari | service + keluaran command | T-1, T-3 lulus |
| 2 | P1-2 `simpanan:audit-subledger` + laporan atribusi | 1 hari | tabel per anggota + daftar tersangka | **review pengurus** |
| 3 | P1-3 diagnosis + command dry-run | 1 hari | daftar 2 rekening + opsi O-1/O-2 | **keputusan pengurus** |
| 4 | P1-4 refactor controller + view + route | 2 hari | satu jalur pembukaan deposito | T-4 s.d. T-9 lulus |
| 5 | P1-5 suite uji baru + regresi penuh | 1,5 hari | 13 skenario + suite penuh hijau | T-12, T-13 |
| 6 | Eksekusi remediasi A-1 & A-2 (setelah disetujui) | 0,5 hari | jurnal penyesuaian / bilyet terisi | selisih = 0 |
| 7 | P1-6 observabilitas + runbook + dokumentasi | 0,5 hari | gate di `denyut:pemeriksaan` | exit code benar |
| 8 | Aktivasi P1-1 mode tegakkan | 0,5 hari | `RECONCILIATION_GATE_ENFORCE=true` | T-2 lulus di lingkungan uji |
| | **Total** | **8,5 hari** | | |

Buffer risiko 20% → **±10 hari kerja**. Buffer mencakup: penelusuran `TANPA_ANGGOTA` pada P1-2 dan kemungkinan saldo Sukarela tidak cukup untuk opsi O-2.

**Jalur cepat (bila pengurus memilih):** P1-4 + P1-5 saja tanpa remediasi = 3,5 hari. Namun gate tidak boleh ditegakkan, dan selisih Rp21.500.000 tetap ada. Tidak direkomendasikan.

---

## 7. Kriteria Keberhasilan per Tahap

Kriteria bersifat **terukur** dan dapat diperiksa ulang.

### Tahap 0 — Baseline
- [ ] Cadangan `pra-p1.sql.gz` ada, `gzip -t` lolos, ukuran > 0.
- [ ] `php artisan test --filter=Deposito` menghasilkan 150 passed, 0 failed.
- [ ] Angka §2.1 dicatat dan cocok dengan hasil query ulang.

### Tahap 1 — Gate mode lapor
- [ ] `selisihSubledger()` mengembalikan tepat 2 baris (`2-1001`, `2-1003`) dengan nilai sama seperti §2.1.
- [ ] `jurnal:rekonsiliasi` mencetak bagian subledger dan **tidak** mengubah exit code saat mode lapor.
- [ ] Tidak ada transaksi yang gagal akibat gate (uji T-3).
- [ ] Suite deposito tetap 150 passed.

### Tahap 2 — Audit A-1
- [ ] `simpanan:audit-subledger --format=json` menghasilkan JSON valid.
- [ ] `SUM(selisih)` seluruh baris = **−21.500.000** (sama dengan §2.1).
- [ ] Setiap baris berselisih memiliki `anggota_id` atau ditandai `TANPA_ANGGOTA`.
- [ ] Tidak ada penulisan ke basis data (dibuktikan dengan `git diff` kosong pada model/migrasi dan saldo tidak berubah).
- [ ] Laporan atribusi diserahkan ke pengurus; **tidak ada remediasi dijalankan sebelum persetujuan**.

### Tahap 3 — Diagnosis A-2
- [ ] Daftar 2 rekening berjangka yatim lengkap (id, nomor, anggota, saldo, tanggal).
- [ ] Setiap rekening memiliki jejak `no_jurnal` pembentuknya.
- [ ] `deposito:pulihkan-tanpa-bilyet --dry-run` melaporkan tepat 2 kandidat dan **0** penulisan.
- [ ] Opsi O-1/O-2 dipilih dan ditandatangani pengurus.

### Tahap 4 — Refactor `depositoStore`
- [ ] Form tidak lagi memuat `tanggal_jatuh_tempo` dan `nisbah_nasabah` sebagai input bebas.
- [ ] Pembukaan deposito via teller menghasilkan: 1 baris `rekening` berjangka, 1 baris `depositos`, 2 baris `transaksi`, 1 jurnal D `2-1001` / K `2-1003`.
- [ ] Tidak ada jurnal D `1-1001` yang dihasilkan dari pembukaan deposito.
- [ ] `maturity_date` = `start_date + tenor_months` (diuji dengan tenor 1, 3, 6, 12 bulan dan akhir bulan).
- [ ] Kegagalan jurnal menghasilkan redirect + pesan, **tanpa** stack trace, dan tidak meninggalkan rekening yatim.
- [ ] Anggota tanpa Sukarela / saldo kurang / produk non-aktif ditolak dengan pesan spesifik.
- [ ] Tidak ada `nomorRekeningBaru()` yang dipanggil di luar transaksi pada jalur ini.

### Tahap 5 — Pengujian
- [ ] 13 skenario T-1 s.d. T-13 lulus.
- [ ] `php artisan test --filter=Deposito` ≥ 150 passed, 0 failed.
- [ ] Suite penuh ≥ 1768 passed, 0 failed, 0 error.
- [ ] Tidak ada penurunan jumlah assertions.

### Tahap 6 — Remediasi data
- [ ] Selisih `2-1001` = **0,00** dan `2-1003` = **0,00** (toleransi 0,005).
- [ ] Rekening berjangka tanpa bilyet = **0**.
- [ ] Bilyet `ACTIVE` tanpa rekening = **0**.
- [ ] Total debet = total kredit pada seluruh jurnal `Posted`.
- [ ] Neraca sebelum vs sesudah remediasi memiliki selisih yang **persis sama** dengan nilai jurnal penyesuaian (tidak ada perubahan liar).
- [ ] Setiap jurnal penyesuaian memiliki nomor bukti + lampiran berita acara + `AuditLog`.

### Tahap 7 — Observabilitas
- [ ] `denyut:pemeriksaan` mengembalikan exit 0 saat selisih nol, exit 1 saat selisih disuntikkan pada lingkungan uji.
- [ ] Log gate muncul di kanal `rekonsiliasi` dengan level `critical`, maksimal 1 pesan/jam/akun.
- [ ] Runbook diuji: seorang teller dapat mengikuti langkah eskalasi tanpa bertanya.

### Tahap 8 — Gate ditegakkan
- [ ] `RECONCILIATION_GATE_ENFORCE=true` pada lingkungan uji.
- [ ] Transaksi dengan selisih disuntikkan → rollback penuh, saldo tidak berubah (T-2).
- [ ] Transaksi normal → sukses, selisih tetap nol.
- [ ] Suite penuh tetap hijau.
- [ ] Keputusan aktivasi di produksi dicatat dengan tanggal & penanggung jawab.

---

## 8. Risiko & Rollback

| Risiko | Dampak | Mitigasi | Rollback |
|---|---|---|---|
| Gate memblokir transaksi teller | operasional berhenti | rilis pertama mode lapor; aktivasi hanya setelah selisih nol | set `RECONCILIATION_GATE_ENFORCE=false` |
| Refactor `depositoStore` mengubah perilaku UI | teller bingung / gagal input | sediakan dropdown produk & rekening sumber; pesan validasi spesifik; uji per peran | revert commit P1-4; route lama tetap berfungsi |
| Remediasi A-1 salah akun lawan | neraca salah | jurnal manual + persetujuan pengurus + berita acara; tidak ada auto-post | jurnal balik atas nomor bukti |
| O-2 memerlukan saldo Sukarela yang tidak ada | pembukaan ulang gagal | cek saldo sebelum eksekusi; fallback O-1 bila dokumen akad ada | batalkan, kembalikan ke kondisi awal |
| Backfill bilyet memakai produk/tenor tebakan | data akad tidak sah | wajib verifikasi dokumen fisik; catat dasar rekonstruksi di `keterangan` | hapus baris `depositos` hasil backfill (berdasarkan `AuditLog`) |
| Gate menimbulkan false positive pada alur bagi hasil | bagi hasil gagal | uji T-1/T-3 pada `distributeProfitSharing`; gate dipasang setelah semua mutasi | nonaktifkan gate mode tegakkan |

**Titik rollback data:** cadangan `storage/app/backups/pra-p1.sql.gz` (Tahap 0). Pemulihan memakai `scripts/restore_verify.php` yang sudah terbukti (56/56 tabel).

---

## 9. Keputusan yang Memerlukan Persetujuan

Tiga keputusan berikut **harus** dijawab sebelum implementasi dimulai:

| No | Pertanyaan | Opsi | Dampak |
|---|---|---|---|
| D-1 | Perlakuan selisih A-1 Rp21.500.000 | R-1 jurnal penyesuaian / R-2 koreksi subledger / R-3 reklasifikasi | menentukan akun lawan & nilai neraca |
| D-2 | Perlakuan 2 rekening berjangka tanpa bilyet | O-1 backfill bilyet / O-2 batalkan & bukukan ulang | menentukan apakah ada jurnal baru Rp15.000.000 |
| D-3 | Waktu aktivasi gate mode tegakkan | setelah remediasi (disarankan) / tunda ke P2 | menentukan apakah gate memblokir transaksi |

Sampai D-1 s.d. D-3 dijawab, pekerjaan yang boleh berjalan hanya **P1-0, P1-1 (mode lapor), P1-2 (read-only), P1-3 (diagnosis), dan P1-4 (refactor jalur baru)**. Remediasi data dan aktivasi gate ditahan.

---

## 10. Checklist Persetujuan

- [ ] Ruang lingkup §4 disetujui
- [ ] Timeline §6 disetujui
- [ ] Dependensi §5 dipahami (khususnya dampak ke Exit Keanggotaan & Neraca)
- [ ] Kriteria keberhasilan §7 disetujui sebagai definisi "selesai"
- [ ] D-1, D-2, D-3 dijawab
- [ ] Cadangan `pra-p1.sql.gz` dibuat
- [ ] Implementasi dimulai

---

## 11. Di Luar Lingkup Dokumen Ini

Butir P1 lain pada `LAPORAN-PEMELIHARAAN-SISTEM.md` yang **tidak** termasuk rencana ini dan perlu dokumen terpisah:

1. `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_LOGIN_ENABLED=false`.
2. User MySQL khusus (bukan `root`) dengan kata sandi kuat.
3. Enkripsi cadangan + salinan off-site.
4. Pemantauan eksternal `/health` & `/readyz` + notifikasi.
5. Temuan likuiditas A-3 (klaim simpanan Rp120.450.000 vs Kas Teller Rp49.297.500) — keputusan manajemen likuiditas, bukan perbaikan kode.
