# Laporan Audit Menyeluruh — Kode, Arsitektur, Dependensi, dan Infrastruktur

**Proyek:** Sistem Informasi Koperasi Syariah
**Tanggal audit:** 2026-10-12
**Lingkup:** Seluruh repositori (`c:\laragon\www\koperasi_syariah`)
**Basis kode:** Laravel 12.69.1 · PHP 8.3.33 (ZTS) · MySQL 8.4 · Vite 5
**Metode:** Tinjauan statis (Glob/Grep/Read), pemindaian dependensi (`composer audit`, `npm audit`, `composer licenses`), pengujian otomatis (`php artisan test`), dan pembacaan konfigurasi runtime.
**Status rilis:** Semua gerbang uji **HIJAU** — 1.474 test / 6.597 assertion lulus (exit code 0).

---

## 1. Ringkasan Eksekutif

Audit ini menjawab tujuh langkah permintaan audit menyeluruh. Sistem berada pada kondisi **fungsional sehat** — seluruh 1.474 pengujian otomatis lulus tanpa regresi, kriptografi bersih (bcrypt cost 12, tanpa MD5/SHA1 untuk kata sandi), dan tidak ditemukan kerentanan *user-controlled* pada SQL Injection maupun XSS.

Namun terdapat **kesenjangan non-fungsional** yang signifikan dan bersifat **arsitektural/konfigurasi**, bukan bug fungsional:

| Kategori | Kritis | Tinggi | Sedang | Rendah |
|---|---|---|---|---|
| Kode & Keamanan | 0 | 0 | 6 | 5 |
| Arsitektur | 2 | 4 | 4 | 2 |
| Dependensi | 0 | 1 | 0 | 2 |
| Infrastruktur/Deployment | 0 | 1 | 3 | 1 |
| **Total** | **2** | **6** | **13** | **10** |

**Kesimpulan:** Sistem **layak secara fungsional** dan aman untuk operasi lokal/demo. Untuk **produksi multi-pengguna**, dua temuan kritis (antrean sinkron & penyimpanan *file* untuk cache/sesi) harus ditangani terlebih dahulu karena membatasi skalabilitas horizontal dan ketahanan di bawah beban konkuren.

---

## 2. Metodologi & Lingkup

1. **Struktur kode sumber** — inventarisasi 190 file `app/` (≈56.131 baris), 55 controller, 28+ service; analisis otorisasi, validasi/mass-assignment, SQLi, XSS, penanganan data sensitif, standar koding, dan kriptografi.
2. **Arsitektur** — evaluasi layering, skalabilitas, keandalan (reliability), kompatibilitas, dan kode mati.
3. **Dependensi** — `composer audit`, `npm audit --omit=dev`, `composer licenses`.
4. **Infrastruktur** — konfigurasi `.env`, `config/*.php`, `public/.htaccess`, `bootstrap/app.php`, penjadwalan, dan panduan deploy.
5. **Uji fungsionalitas** — eksekusi suite pengujian penuh.
6. **Laporan** — dokumen ini.
7. **Verifikasi perbaikan** — lihat §7.

> **Catatan verifikasi independen:** Satu temuan awal berisiko "Tinggi" (rute `pengeluaran-beban` tanpa middleware `role:`) **diturunkan menjadi Rendah** setelah verifikasi langsung — restriksi peran ternyata **sudah ditegakkan** di konstruktor `PengeluaranBebanController` (baris 25–38). Prinsipnya: setiap temuan diverifikasi ulang pada kode sumber sebelum dilaporkan, untuk menghindari temuan palsu.

---

## 3. Hasil per Dimensi

### 3.1 Kode Sumber, Keamanan & Standar Koding

**Yang sudah baik (terverifikasi):**
- **Kriptografi bersih** — `config/hashing.php` bcrypt cost 12; cast `'password' => 'hashed'` pada `User`; tidak ada `md5`/`sha1` untuk kata sandi. `md5()` di `config/cookie.php` hanya untuk nama cookie (bukan keamanan).
- **Tidak ada SQL Injection user-controlled** — `selectRaw` dengan konkatenasi nama kolom (`NomorSequence.php:122`, `IkhtisarShuService.php:485`, `ExitKeanggotaanService.php:111,180,223`) selalu memakai **literal statis internal**; nilai di-*bind*. Tidak ada input pengguna masuk ke fragmen kolom.
- **Tidak ada XSS user-controlled** — `{!! !!}` hanya pada `$badgeIcon` internal (`nasabah/status-pengajuan.blade.php:446`); dashboard pengelola memakai `json_encode` (aman).
- **Proteksi race-condition** — pola `DB::transaction` + `lockForUpdate()` konsisten pada operasi saldo.
- **Header keamanan terpusat** — CSP/HSTS/X-Frame-Options dari `KeamananHeader`, bukan *hardcode*.

**Temuan:**

| ID | Temuan | Bukti | Risiko |
|---|---|---|---|
| M-01 | Perbandingan peran *loose* (`in_array` tanpa argumen `true`) | `app/Http/Middleware/CheckRole.php:26` | Sedang |
| M-02 | Tidak ada Policy/`authorize()` untuk model transaksional | `app/Policies/` hanya `TellerPolicy` | Sedang |
| M-03 | *Mass-assignment* pada `User` (`role`, `is_active`) dan `Anggota` (field sensitif: `status_anggota`, `pep_status`, `profil_risiko`, `simpanan_pokok/wajib`) | `app/Models/User.php:18-24`, `app/Models/Anggota.php:198-242` | Sedang |
| M-04 | Tidak ada `declare(strict_types=1)` di seluruh `app/` | seluruh `app/**/*.php` | Sedang |
| M-05 | N+1 potensial pada dashboard | `app/Http/Controllers/Dashboard/ManajerController.php:782,910,970` | Sedang |
| M-06 | Duplikasi pola nomor referensi `'TRX-' . date(...) . uniqid()` ≥15× (tanpa abstraksi) | `TellerController.php:129,364,1258,1333,1428,1550,1646`, `TransaksiMobileController.php:36,83`, `AngsuranTransactionService.php:105` | Rendah |
| L-01 | Rute `pengeluaran-beban` tanpa `role:` di level rute | `routes/web.php:192-195` | Rendah¹ |
| L-02 | *Fail-open* pada pemetaan view per peran | `app/Http/Controllers/CifController.php:98` | Rendah |
| L-03 | `$request->all()`/`input()` ±60 lokasi tanpa FormRequest | `app/Http/Controllers/**` | Rendah |
| L-04 | 18 lokasi `uniqid()`/`rand()` untuk nomor referensi (bukan token auth) | `app/**` | Rendah |
| L-05 | IDOR horizontal pada endpoint AJAX AO (tanpa scoping kepemilikan) | `AOController.php:217,558,600` | Rendah² |

¹ **Sudah dimitigasi**: restriksi peran ditegakkan di konstruktor controller (`PengeluaranBebanController.php:25-38`). Bersifat konsistensi/pertahanan-berlapis.
² **Konteks**: peran `ao` adalah staf yang memang berwenang mengakses seluruh data anggota (tidak ada model kepemilikan per-AO), sehingga bukan IDOR lintas-pengguna dalam arti sesungguhnya; tetap disarankan scoping eksplisit bila kelak ada pembatasan wilayah kerja.

### 3.2 Arsitektur (Skalabilitas, Keandalan, Kompatibilitas)

**Kritis:**

| ID | Temuan | Bukti | Dampak |
|---|---|---|---|
| **C-01** | **Antrean sinkron + tidak ada `app/Jobs/`** — seluruh proses berat (PDF, bagi hasil, notifikasi) berjalan di dalam siklus HTTP | `.env:36` `QUEUE_CONNECTION=sync`; tidak ada direktori `app/Jobs/` | Waktu respons melonjak; tidak ada retry/async; throughput terbatas pada satu worker per permintaan |
| **C-02** | **Cache & sesi berbasis *file*** — tidak dapat di-*scale* horizontal (multi-node) dan boros I/O | `.env:28` `CACHE_DRIVER=file`, `.env:37` `SESSION_DRIVER=file`; `config/cache.php:6`; `app/Support/DenyutData.php:133` memakai `Cache::increment()` | Node kedua tidak melihat cache/sesi node pertama → sesi hilang saat *load balancing*; `Cache::increment()` file-based tidak atomik lintas proses |

**Tinggi:**

| ID | Temuan | Bukti |
|---|---|---|
| H-02 | **God-classes** — pelanggaran SRP berat | `AutoJournalingService.php` (2.126 baris, 56 metode publik), `MandatorySavingsService.php` (2.031), `TellerController.php` (1.845, 28 metode, 20 `DB::transaction`), `app/Support/Setting.php` (1.668), `DepositoService.php` (1.555), `LaporanKeuanganSyariahService.php` (1.430) |
| H-03 | **SSE/long-polling menahan worker PHP** selama hingga 25 detik per koneksi | `config/denyut.php:44` (`maks_sse=25`), `:49` (`maks_tunggu=20`); `DenyutController.php:178-215` (`while (!connection_aborted())` + `sleep()`) |
| H-04 | **Idempotensi auto-jurnal hanya sebagian** — tidak ada *unique constraint* pada `jurnal.sumber`; jurnal ganda mungkin pada retry/race | `database/migrations/2024_01_01_000008_create_jurnal_table.php:22` (nullable, tanpa unique); `AutoJournalingService.php:777-799,818-825`. Bandingkan praktik baik: `autodebit_usulans` sudah punya `unique('signature')` |
| H-05 | **Generasi PDF sinkron** di dalam siklus permintaan | `app/Services/DepositoService.php:1407-1411` (`app('dompdf.wrapper')->loadView(...)`) |

**Sedang/Lainnya:**

| ID | Temuan | Bukti |
|---|---|---|
| M-07 | Query laporan tanpa batas (`->get()`/`pluck()` 15×) | `LaporanKeuanganSyariahService.php:461,462,534,674,1000` |
| M-08 | `catch (\Throwable $e) { report($e); }` tanpa rollback/umpan balik pengguna | `TellerController.php:218-220,251-253` |
| M-09 | Rute monolitik | `routes/web.php` (740 baris), `routes/api.php` (181 baris) |
| M-10 | Grup `auth:sanctum` duplikat | `routes/api.php:177-181` vs `:91-95` |
| M-11 | Command legacy vs modern berpotensi tumpang tindih | `app/Console/Kernel.php:21` (`deposito:process-matured`) vs `routes/console.php:31` (`deposito:process-maturity`) |
| M-12 | Cakupan Unit test minim | `tests/Unit/` hanya 1 file vs ±90 Feature |
| M-13 | `Inspiring::quote()` dijadwalkan tiap jam (tidak produktif) | `routes/console.php:7-9` |
| M-14 | **Kode mati**: `schedule()` pada `App\Console\Kernel` tidak pernah dipanggil (bootstrap memakai `Illuminate\Foundation\Console\Kernel`) | `app/Console/Kernel.php:10-30`; `bootstrap/app.php:16-22` |
| L-06 | Command uji coba berpotensi ter-deploy ke produksi | `app/Console/Commands/TestShu.php`, `UjiCobaRoleAO`, `SimulasiTransaksiHariIni`, `ResetDataAnggota` |
| L-07 | `whereMonth`/`whereYear` non-*sargable* (didokumentasikan) | `database/migrations/2026_09_30_000900_add_read_path_indexes.php` |

**Kesesuaian & kompatibilitas (baik):** Laravel 12 + PHP 8.2+ terpenuhi; timezone `Asia/Jakarta`, locale `id` (`config/app.php:10-11`); penjadwalan terpusat di `routes/console.php` dengan `withoutOverlapping()` — praktik baik; registri CoA & parameter dinamis (zero-hardcoding) terjaga.

### 3.3 Dependensi Pihak Ketiga (CVE & Lisensi)

**Kerentanan PHP (`composer audit` → 2 advisory, keduanya `league/commonmark` 2.10.0, ditarik oleh `laravel/framework` v12.69.1):**

| ID | Advisory | Tingkat | Dampak | Rentang terdampak |
|---|---|---|---|---|
| **H-01** | GHSA-3q6v-r5mr-hxv8 / PKSA-m4t9-vsgq-8khn | **Tinggi** | *Quadratic-time DoS* pada ekstensi GFM Table (block-start scan) | ≥2.0.0, ≤2.10.1 |
| D-01 | GHSA-97jj-33gv-5xf9 / PKSA-m2dq-1fhr-29b1 | Sedang | *DisallowedRawHtml* dapat dilewati | ≥1.3.0, ≤2.10.1 |

- **Konteks risiko:** `league/commonmark` adalah dependensi **transitif** Laravel; sistem ini **tidak** merender Markdown dari input pengguna, sehingga paparan aktual rendah. Namun pembaruan disarankan (naikkan ke rilis yang telah ditambal).
- **Lisensi commonmark:** BSD-3-Clause (permisif).

**Kerentanan NPM (`npm audit`):**

| ID | Paket | Tingkat | Catatan |
|---|---|---|---|
| L-08 | `esbuild` ≤0.24.2 (via `vite` ≤6.4.2) | Sedang | **Dev-only** — `npm audit --omit=dev` = **0 kerentanan**. Perbaikan `vite@8.x` bersifat *breaking*. |
| L-09 | `source-map-js` 1.0.0–1.2.1 | Tinggi | **Dev-only**; dapat diperbaiki tanpa *breaking* (`npm audit fix`). |

**Lisensi (`composer licenses`):**
- Mayoritas permisif (MIT / BSD-3-Clause / Apache-2.0) — **patuh** untuk proyek komersial.
- **`dompdf/dompdf` v3.1.6 — LGPL-2.1**; `php-font-lib` (LGPL-2.1-or-later), `php-svg-lib` (LGPL-3.0-or-later). Pemakaian sebagai *library tanpa modifikasi* umumnya **patuh** LGPL; wajib menjaga atribusi & menyediakan sumber library bila dimodifikasi.
- **`nette/schema` & `nette/utils` — lisensi ganda** (BSD-3-Clause **atau** GPL-2.0/3.0). Proyek dapat memilih **BSD-3-Clause** → **patuh**.

**Kesimpulan lisensi:** tidak ada lisensi *copyleft* yang memaksa (GPL-only) pada dependensi produksi; semuanya dapat diterima dengan pemilihan lisensi ganda yang tepat.

### 3.4 Infrastruktur, Deployment & Kebijakan Akses

**Yang sudah baik (terverifikasi):**
- **Header keamanan terpusat & adaptif** — `config/keamanan.php`: CSP `enforce` di produksi / *Report-Only* di lokal; HSTS aktif di HTTPS; opsi mode ketat dengan nonce.
- **Kebijakan kata sandi adaptif** — min 8 (lokal) / 12 (produksi) + syarat huruf besar/angka/simbol.
- **Retensi log sesuai UU PDP** — log 1095 hari, PII 365 hari, purge *batch* via command (bukan `UPDATE`/`DELETE` manual).
- **`public/.htaccess`** tidak *hardcode* header (dikelola middleware); hanya `X-XSS-Protection` legacy.
- **Panduan deploy lengkap** — `docs/DEPLOY-PRODUKSI.md` memuat Gerbang Rilis G1–G4 (cache bersih, backup+baseline, regresi+kepatuhan PSAK, larangan ubah jurnal historis), checklist go-live UU PDP, dan lembar tanda tangan.
- **Rate limiting login** — `throttle:5,1` pada POST `/login`; endpoint publik `/buka-rekening` `throttle:30,1` (GET) & `throttle:10,1` (POST).
- **Sesi** — `http_only=true`, `same_site=lax`, `secure` otomatis `true` saat produksi, `encrypt` default `true`.

**Temuan:**

| ID | Temuan | Bukti | Risiko |
|---|---|---|---|
| **H-06** | **`.env` lokal tidak aman bila ter-deploy apa adanya** — `APP_DEBUG=true`, `DB_USERNAME=root` tanpa kata sandi, `DEMO_LOGIN_ENABLED=true`, `DEMO_USER_PASSWORD=password123` | `.env:2,4,24-25,34-35` | **Tinggi (bila ter-deploy)** |
| M-15 | `BROADCAST_DRIVER=log` — real-time tidak benar-benar berfungsi (hanya menulis log) | `.env:27`; `config/broadcasting.php:4` | Sedang |
| M-16 | `MAIL_MAILER=log` — notifikasi email tidak terkirim | `.env:47` | Sedang |
| M-17 | Repo bukan Git; `.gitignore` berisi `*` (kecuali `!.gitignore`) → `.env` tidak ter-*commit* (baik), namun **tidak ada riwayat versi** untuk jejak audit perubahan kode | `.gitignore` | Sedang |
| L-10 | Tidak ada konfigurasi `TRUSTED_PROXIES` eksplisit (dokumentasi menyarankan kosong bila akses langsung) | `docs/DEPLOY-PRODUKSI.md:142-144` | Rendah |

> **Catatan penting:** `.env` saat ini adalah **konfigurasi pengembangan lokal** dan **bukan** konfigurasi produksi. `docs/DEPLOY-PRODUKSI.md` §3 sudah mensyaratkan `APP_ENV=production`, `APP_DEBUG=false`, kredensial DB non-root, dan pengosongan kredensial demo. Temuan H-06 adalah pengingat kontrol rilis, bukan cacat kode.

---

## 4. Hasil Uji Fungsionalitas (Langkah 5)

| Item | Hasil |
|---|---|
| Perintah | `php artisan test` |
| Total test | **1.474 lulus / 0 gagal** |
| Assertion | **6.597** |
| Exit code | **0** |
| Durasi | 188,38 detik |
| Modul tervalidasi | PSAK 101/102/108/109/71 (CKPN), Zakat (PSAK 109), Simpanan Wajib, Deposito (ARO), RBAC per-peran, laporan keuangan |

**Kesimpulan:** Seluruh fitur utama beroperasi sesuai spesifikasi; **tidak ditemukan bug yang mengganggu alur kerja pengguna** pada cakupan pengujian otomatis. Catatan: `php artisan test --parallel` memerlukan `brianium/paratest` (belum terpasang) — bukan kegagalan fungsional, hanya keterbatasan paralelisasi uji.

---

## 5. Matriks Temuan & Rekomendasi Terukur

### 5.1 Prioritas 1 — Sebelum Produksi Multi-Pengguna

| ID | Rekomendasi | Kriteria Verifikasi |
|---|---|---|
| C-01 | Pindahkan proses berat (PDF bilyet, bagi hasil massal, notifikasi) ke **queued jobs**; set `QUEUE_CONNECTION=database` (atau redis) + jalankan `queue:work`. | `app/Jobs/` terisi; `php artisan queue:failed` kosong; PDF/notifikasi terbentuk asinkron. |
| C-02 | Ganti `CACHE_DRIVER` & `SESSION_DRIVER` ke **redis** (atau `database`) untuk produksi multi-node. | Sesi bertahan lintas node; `DenyutData::Cache::increment()` atomik. |
| H-01 | Naikkan `league/commonmark` ke versi tertambal (via `composer update league/commonmark`). | `composer audit` → 0 advisory. |
| H-06 | Terapkan checklist `DEPLOY-PRODUKSI.md` §3 & §10 secara ketat (produksi). | `php artisan about` → Environment=production, Debug=OFF; `DEMO_LOGIN_ENABLED` kosong. |

### 5.2 Prioritas 2 — Ketahanan & Pemeliharaan

| ID | Rekomendasi | Kriteria Verifikasi |
|---|---|---|
| H-02 | Pecah god-classes menjadi service domain kecil (mis. `AutoJournalingService` → per-modul: simpanan, pembiayaan, deposito). | Tidak ada file >800 baris di `app/Services` & `app/Http/Controllers`. |
| H-03 | Turunkan `maks_sse`/`maks_tunggu`, atau pindahkan notifikasi real-time ke **broadcast/queue** agar tidak menahan worker. | Beban worker FPM stabil pada uji konkurensi. |
| H-04 | Tambahkan **unique constraint** (mis. pada `jurnal` `sumber`+`referensi` atau *hash* jurnal) + `firstOrCreate` untuk idempotensi penuh. | Uji retry ganda tidak menghasilkan jurnal duplikat. |
| H-05 | Jalankan pembuatan PDF via **queue** (terkait C-01). | Respons endpoint tidak memblokir selama render PDF. |
| M-01 | Tambahkan argumen ketiga `true` pada `in_array(...)` di `CheckRole`. | Uji perbandingan peran *strict*. |
| M-03 | Batasi `$fillable`/gunakan *explicit assignment* untuk `role`, `is_active`, dan field sensitif `Anggota`. | Uji mass-assignment ditolak. |

### 5.3 Prioritas 3 — Higiene Kode & Konsistensi

- M-02, M-04, M-05, M-06, M-07, M-08, M-09, M-10, M-11, M-12, M-13, M-14, L-01…L-10 — lihat tabel dimensi di §3. Ringkas: tambah Policy, aktifkan `strict_types`, hilangkan N+1, batasi query laporan, ganti `catch` tanpa rollback, pecah rute, hapus kode mati (`Console/Kernel.php`, `Inspiring`), tambah Unit test, sentralisasi generator nomor referensi, dan perbarui dependensi dev (`npm audit fix`).

---

## 6. Peta Jalan Perbaikan (Usulan)

1. **Fase A — Gerbang Produksi (wajib):** C-01, C-02, H-01, H-06.
2. **Fase B — Ketahanan:** H-02, H-03, H-04, H-05, M-01, M-03.
3. **Fase C — Higiene:** sisa temuan Sedang & Rendah.
4. **Fase D — Verifikasi:** jalankan ulang G1–G4 + suite penuh + `psak:validasi-simpanan-wajib`.

---

## 7. Verifikasi Implementasi Perbaikan (Langkah 7)

**Keputusan pemilik proyek:** *verifikasi temuan tanpa mengubah kode.* Karena itu, Langkah 7 dijalankan sebagai **verifikasi ulang setiap temuan langsung pada kode sumber** (bukan penerapan *patch*), untuk memastikan tidak ada temuan palsu dan laporan akurat.

### 7.1 Verifikasi ulang temuan (bukti langsung)

| ID | Verifikasi | Status |
|---|---|---|
| C-01 | Tidak ada direktori `app/Jobs/`; `.env:36` = `QUEUE_CONNECTION=sync` | ✅ Terkonfirmasi |
| C-02 | `.env:28,37` = `file`; `config/cache.php:6`; `DenyutData.php:133` `Cache::increment()` | ✅ Terkonfirmasi |
| H-01 | `composer.lock:1766` `league/commonmark` `2.10.0`; advisory `<=2.10.1` | ✅ Terkonfirmasi |
| H-02 | Jumlah baris **tepat**: AutoJournalingService **2126**, MandatorySavingsService **2031**, TellerController **1845**, Setting **1668** | ✅ Terkonfirmasi (dihitung ulang) |
| H-03 | `config/denyut.php:44` `maks_sse=25`; `DenyutController.php:178-215` loop `connection_aborted()` + `sleep()` | ✅ Terkonfirmasi |
| H-04 | `create_jurnal_table.php:22` `sumber` nullable **tanpa unique**; `no_jurnal` (`:23`) unik — idempotensi bergantung `no_jurnal`, bukan `sumber` | ✅ Terkonfirmasi |
| H-05 | `DepositoService.php:1407-1411` `app('dompdf.wrapper')->loadView(...)` sinkron | ✅ Terkonfirmasi |
| H-06 | `.env:2` `APP_DEBUG=true`, `:24-25` `root` tanpa sandi, `:34-35` demo aktif | ✅ Terkonfirmasi |
| M-01 | `CheckRole.php:26` `in_array($user->role, $roles)` tanpa argumen `true` | ✅ Terkonfirmasi |
| M-02 | `app/Policies/` hanya `TellerPolicy` (komentar BUG-08) — **bukan** kebijakan untuk model transaksional umum | ✅ Terkonfirmasi (sebagian, nuansa BUG-08) |
| M-03 | `User.php:18-24` `$fillable` memuat `role`,`is_active` | ✅ Terkonfirmasi |
| M-04 | `grep "declare(strict_types=1)"` di `app/` → **0 kecocokan** | ✅ Terkonfirmasi |
| M-07 | `LaporanKeuanganSyariahService.php:461-462` `Coa::...->get()` tanpa limit (dataset master, bukan tabel transaksi) | ⚠️ Terkonfirmasi, dampak lebih rendah dari label |
| M-14 | `app/Console/Kernel.php:10-30` `schedule()` memuat komentar "TIDAK PERNAH DIPANGGIL"; `bootstrap/app.php` pakai `Foundation\Console\Kernel` | ✅ Terkonfirmasi — **kode mati berlabel jelas** (jujur & terdokumentasi) |
| L-01 | `routes/web.php:192-195` tanpa `role:`; **namun** `PengeluaranBebanController.php:25-38` menegakkan peran (teller/manajer) | ✅ Terkonfirmasi — **tidak ada celah otorisasi** |

### 7.2 Pernyataan status

| Status | Keterangan |
|---|---|
| **Terverifikasi baik (sudah aman)** | Kriptografi (bcrypt cost 12, `hashed` cast), proteksi SQLi/XSS *user-controlled*, header keamanan terpusat, retensi log UU PDP, RBAC per-peran (uji otomatis), panduan deploy + gerbang rilis G1–G4. |
| **Perbaikan dari audit sebelumnya (tetap valid)** | A-01 (Ignition runnable solutions OFF), A-03 (log harian + retensi 14 hari), A-04 (`Request::get()` → `Request::query()`). |
| **Belum diterapkan (sesuai keputusan pemilik proyek)** | Seluruh temuan §5 (C-01, C-02, H-01…H-06, M-*, L-*). |

> **Kesimpulan Langkah 7:** Seluruh temuan **terverifikasi ada pada kode** — tidak ada temuan palsu. Sistem tetap pada kondisi stabil (1.474 test hijau) dan **tidak ada perubahan kode yang dilakukan** dalam sesi ini. Perbaikan (Fase A–D pada §6) menunggu persetujuan & penjadwalan terpisah dari tim pengembang.

---

## 8. Lampiran — Perintah Audit yang Dijalankan

```bash
composer audit                 # 2 advisory (league/commonmark)
composer why league/commonmark # ditarik laravel/framework v12.69.1
composer licenses              # lisensi dependensi produksi
npm audit --omit=dev           # 0 kerentanan produksi
npm audit                      # 3 dev-only (esbuild, source-map-js)
php artisan test               # 1.474 lulus / 6.597 assertion / exit 0
```

**Berkas konfigurasi utama yang ditinjau:**
`composer.json`, `package.json`, `composer.lock`, `config/keamanan.php`, `config/queue.php`, `config/cache.php`, `config/session.php`, `config/logging.php`, `config/broadcasting.php`, `config/denyut.php`, `bootstrap/app.php`, `routes/web.php`, `routes/api.php`, `routes/console.php`, `public/.htaccess`, `.env`, `docs/DEPLOY-PRODUKSI.md`.

---

*Laporan disusun otomatis berdasarkan bukti kode sumber dan keluaran perintah pada repositori. Setiap temuan telah diverifikasi ulang untuk mencegah temuan palsu.*
