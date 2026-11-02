# LAPORAN AUDIT MENYELURUH — AKSES PERAN (RBAC) & DASBOR

**Sistem:** Koperasi Syariah (Laravel 12.69.1 / PHP 8.3.33 / MySQL 8.4.3 / PHPUnit 11.5.56)
**Ruang lingkup:** Seluruh peran (role) yang terkait dengan setiap dasbor — konfigurasi akses, izin fitur, visualisasi data, dan fungsi operasional
**Tanggal audit:** 2026-10-13
**Jenis audit:** Audit pengendalian internal (internal control) + audit keamanan aplikasi + audit kepatuhan
**Metode:** Inspeksi kode sumber, walkthrough alur (rute → middleware → controller → view), **probe HTTP langsung** atas matriks peran × halaman, deteksi eskalasi lintas-peran, dan pengujian otomatis.

---

## Ringkasan Eksekutif

Audit terhadap **7 peran** (`admin`, `teller`, `ao`, `manajer`, `pengurus`, `dps`, `anggota`) menemukan **11 temuan**. Semua telah diperbaiki dan diverifikasi ulang.

Temuan terpenting:

- **F-03 (Tinggi)** — Peran `anggota` sama sekali **tidak memiliki sidebar**. Menu yang tampil kosong total, sehingga seluruh portal beranggota tidak dapat dinavigasi. Akar masalahnya: nilai `users.role` yang tersimpan di DB adalah `anggota`, sedangkan `NavigationService::getMenu()` hanya punya cabang `'nasabah'`.
- **F-01 (Sedang)** — Dasbor Teller menampilkan "Batas mandiri: Rp 10.000.000" padahal konfigurasi resmi `teller.tarik_tunai.limit_per_transaksi = 5.000.000`. Angka di layar **menjanjikan kewenangan 2× lipat** dari yang sebenarnya.
- **F-05 (Sedang)** — Dasbor DPS memiliki **dua blok `@section('content')`**. Karena `getSection()` memakai `array_pop()`, blok **terakhir menang** dan 362 baris di block pertama adalah dead code.
- **F-09/F-10 (Sedang)** — Dua tautan "lintasan" yang pasti berakhir **403** bagi peran yang memblokirnya (teller & manajer/DPS).

Yang **terverifikasi aman** dan menjadi bukti positif:

- **Tidak ada privilege escalation lintas-peran.** Probe 6×6 (peran × peran) terhadap seluruh rute menu menghasilkan **0 bocor**.
- **Seluruh 361 rute web terpetakan**: 338 ber-guard `role:`, 23 sisanya bergantung pada middleware khusus (`auth`, `verified`, `anggota.aktif`) atau otorisasi in-controller yang sudah diverifikasi.
- **Kontrak mesin denyut (real-time polling) 6/6 dashboard cocok persis** antara `data-denyut-sasaran` di view dan kunci JSON `html` di `ringkas()`.
- **Tidak ditemukan celah IDOR** — seluruh query `NasabahController` di-scope `anggota_id` milik user yang login, dengan `abort_if(!$anggota, 403)`.

| Tingkat | Jumlah | ID Temuan |
|---------|--------|-----------|
| Tinggi  | 0      | — (F-03 tertutup) |
| Sedang  | 0      | — (F-01, F-02, F-04, F-05, F-09, F-10 tertutup) |
| Rendah  | 0      | — (F-06, F-07, F-08, F-11 tertutup) |

**Opini audit:** Sistem **layak operasional** dari sisi pengendalian akses. Tidak ada temuan terbuka.

**Baseline regresi akhir: `5 skipped, 1509 passed (6833 assertions)` — 0 gagal** (naik dari 1508/6831 karena penambahan 1 pengujian invariant baru).

---

## 1. Latar Belakang & Tujuan

Koperasi Syariah beroperasi pada prinsip **pemisahan tugas (maker–checker)** dan **pengawasan syariah**. Setiap peran memiliki dasbor dan modul yang berbeda, dan kesalahan dalam pengendalian akses berdampak langsung pada **keterbukaan data anggota**, **integritas pembukuan**, serta **kepatuhan UU/No. 25/1992 Pasal 30**.

Tujuan audit:
1. **Mendokumentasikan konfigurasi akses setiap role** — matriks peran ↔ modul ↔ batas kewenangan.
2. **Memverifikasi izin fitur, visualisasi data, dan fungsi operasional** tiap dasbor berjalan sesuai fungsionalitas yang seharusnya untuk role tsb.
3. **Mengidentifikasi celah keamanan, ketidaksesuaian izin, dan gangguan fungsional.**
4. **Menyusun laporan temuan + langkah perbaikan + kriteria validasi.**
5. **Melakukan pengujian fungsional menyeluruh** setelah perbaikan untuk memastikan tidak ada regresi.

---

## 2. Ruang Lingkup & Metodologi

### 2.1 Komponen yang diaudit

| Lapisan | Komponen |
|---------|----------|
| Sumber kebenaran RBAC | `config/role_hierarki.php`, `app/Support/HierarkiRole.php`, `app/Support/LaporanKeuanganPermission.php` |
| Gerbang akses | `app/Http/Middleware/CheckRole.php`, `LaporanKeuanganAccess.php`, `PastikanAnggotaAktif.php` |
| Navigasi | `app/Services/NavigationService.php`, `app/Http/Middleware/ShareNavigationMenu.php`, `resources/views/layouts/sidebar.blade.php` |
| Peta rute | `routes/web.php` (361 rute) |
| Dasbor | `resources/views/dashboard/{teller,ao,manajer,pengurus,dps}.blade.php`, `resources/views/nasabah/dashboard.blade.php` |
| Controller dasbor | `app/Http/Controllers/Dashboard/*.php`, `NasabahController`, `WebAuthController` |
| Pengujian | `tests/Feature/NavigasiWewenangPeranTest.php`, `RbacHierarkiRoleTest.php`, `MatriksHakAksesAuditTest.php` |

### 2.2 Teknik audit

| Teknik | Penerapan | Hasil |
|--------|-----------|-------|
| Inspeksi kode | Pembacaan penuh seluruh sumber kebenaran RBAC & 7 dasbor | 11 temuan |
| Walkthrough alur | Rute → middleware → controller → view, per peran | Kontrak denyut terverifikasi |
| **Probe HTTP langsung** | Matriks 7 peran × seluruh rute menu, dijalankan lewat HTTP | **0 × 403** |
| **Deteksi eskalasi** | 6×6 probe: peran A mengakses menu peran B, dibandingkan dengan guard `role:` aktual | **0 bocor** |
| Kontrol silang | Bandingkan `NavigationService` ↔ `routes/web.php` ↔ matriks `LaporanKeuanganPermission` | 0 rute menu tak terdaftar |
| Uji negatif | Validasi tautan lintas-peran di dalam view tiap peran | 2 tautan 403 (F-09, F-10) |
| Analisis kesenjangan | Nilai batas di view vs nilai batas di konfigurasi | 1 hardcode (F-01) |
| Uji regresi | `php artisan test` penuh | 1509 passed, 0 failed |

### 2.3 Metodologi khusus: matrix menu per role

`NavigationService::getMenu($role)` dipanggil langsung untuk setiap peran, lalu setiap rute di menu dicocokkan dengan `RouteFacade::getRoutes()`:

```
teller       entri=18  rute=13  terdaftar=13  hilang=0
ao           entri=16  rute=12  terdaftar=12  hilang=0
manajer      entri=24  rute=20  terdaftar=20  hilang=0
pengurus     entri=31  rute=26  terdaftar=26  hilang=0
dps          entri=19  rute=15  terdaftar=15  hilang=0
anggota      entri=9   rute=6   terdaftar=6   hilang=0   ← SEBELUM FIX: entri=0
admin        entri=5   rute=3   terdaftar=3   hilang=0
role-hantu   entri=0   rute=0   terdaftar=0   hilang=0   ← fallback aman, tidak crash
```

Keterangan: `entri` = jumlah node menu (termasuk grup), `rute` = nama rute daun unik, `hilang` = rute yang ditawarkan tapi tidak terdaftar di `routes/web.php` (0 = tidak ada tautan mati di menu).

### 2.4 Metodologi khusus: sebaran guard `role:` pada 361 rute

```
Total rute web            : 361
Rute ber-guard role:      : 338
Rute tanpa guard role:    : 23   (ditangani middleware khusus / in-controller)
```

| Peran | Jumlah rute yang boleh dibuka |
|-------|------------------------------|
| `pengurus` | 216 |
| `manajer`  | 191 |
| `teller`   | 115 |
| `ao`       | 72  |
| `admin`    | 56  |
| `dps`      | 47  |
| `anggota`  | 19  |

Angka-angka ini tidak identik per peran karena banyak rute memang dirancang **dipakai bersama** (mis. `role:teller,manajer,pengurus`) — hal ini sah dan memang tercatat pada daftar rute.

---

## 3. Dokumentasi Konfigurasi Akses Setiap Role

### 3.1 Hierarki & sifat peran

Sumber: `config/role_hierarki.php`.

| Peran | Level | Kategori | Penyetuju | Operasional | Ringkasan kewenangan |
|-------|-------|----------|------------|-------------|----------------------|
| `dps`      | 60 | pengawas  | ✗ | ✗ | pengawas syariah; **hanya melihat**, tidak boleh memutuskan |
| `pengurus` | 50 | pengurus  | ✓ | ✓ | wewenang tertinggi operasional; memegang modul penyesuaian jurnal (tulis) |
| `manajer`  | 40 | pengelola  | ✓ | ✓ | verifikasi & approval; **tidak boleh mengubah data akuntansi** |
| `ao`       | 30 | pengelola  | ✓ | ✓ | account officer; jenjang di atas teller, mengelola funding dan pembiayaan |
| `teller`   | 20 | pengelola  | ✓ | ✓ | transaksi loket (setor, tarik, angsuran, pencairan) |
| `admin`    | 10 | sistem     | ✗ | ✗ | dukungan teknis; **tidak diberi hak edit/hapus data akuntansi** |
| `anggota`  | 0  | anggota    | ✗ | ✗ | swalayan; **hanya melihat data miliknya sendiri** |

### 3.2 Matriks modul → peran (dari `LaporanKeuanganPermission`)

Modul **laporan keuangan bersifat hanya-baca** bagi semua peran; hak tulis dipisahkan ke modul `penyesuaian_jurnal` (R19/B11 — pemisahan tugas).

| Modul | admin | pengurus | manajer | dps | teller | ao | anggota |
|-------|-------|----------|---------|-----|--------|----|---------|
| Neraca                | L, U, E | L, U, E | L, U, E | L | ✗ | ✗ | ✗ |
| Buku Besar            | L, U, E | L, U, E | L, U, E | L | ✗ | ✗ | ✗ |
| Laporan Operasional   | L, U, E | L, U, E | L, U, E | ✗ | ✗ | ✗ | ✗ |
| Laporan PSAK 101      | L, U, E | L, U, E | L, U, E | L | ✗ | ✗ | ✗ |
| Penyesuaian Jurnal    | L | **L, E, H** | ✗ | ✗ | ✗ | ✗ | ✗ |
| Audit Laporan         | L, E | L, E | L | L | ✗ | ✗ | ✗ |

Legenda: **L** = lihat, **U** = unduh, **E** = ekspor, **E(H)** = hapus, ✗ = tidak ada akses.

> **Catatan:** DPS diberi `L` pada Neraca/Buku Besar tetapi **dibatasi per-akun ke Ekuitas & Kewajiban** di controller (pembatasan bergantung parameter akun, bukan sekadar peran).

### 3.3 Matriks menu sidebar per role

| Peran | Jumlah entri menu | Rute daun | Dasbor |
|-------|------------------|-----------|--------|
| `teller`   | 18 | 13 | `/teller/dashboard` |
| `ao`       | 16 | 12 | `/ao/dashboard` |
| `manajer`  | 24 | 20 | `/manajer/dashboard` |
| `pengurus` | 31 | 26 | `/pengurus/dashboard` |
| `dps`      | 19 | 15 | `/dps/dashboard` |
| `anggota`  |  9 |  6 | `/nasabah/dashboard` |
| `admin`    |  5 |  3 | `/admin/settings` |

### 3.4 Batas operasional per role

Sumber: `config/role_hierarki.php` → `batas` (dapat di-override tabel `limit_transaksi_role`).

Contoh kunci yang diaudit: `teller.tarik_tunai.limit_per_transaksi = 5.000.000` — nilai inilah yang divalidasi `TransactionLimitService` saat transaksi tarik tunai, dan **harus sama dengan yang ditampilkan di layar** (lihat F-01).

---

## 4. Hasil Verifikasi Fungsional per Dasbor

### 4.1 Kontrak mesin denyut (real-time polling) — 6/6 LULUS

Pola "mesin denyut": view punya atribut `data-denyut-sasaran="<key>"`, controller `ringkas()` mengembalikan JSON `html => ['<key>' => view(...)->render()]`. Kunci harus **PERSIS cocok**.

| Dasbor | Target denyut di view | Kunci JSON `ringkas()` | Status |
|--------|----------------------|------------------------|--------|
| Teller   | 2 | 2 | ✓ |
| AO       | 3 | 3 | ✓ |
| Manajer  | 5 | 5 | ✓ |
| Pengurus | 8 | 8 | ✓ |
| DPS      | 6 | 6 | ✓ |
| Nasabah  | 3 | 3 | ✓ |

Tidak ada mismatch pada keenam dasbor.

### 4.2 Probe HTTP matriks peran × halaman — 0 × 403

Setiap peran di-`actingAs`, lalu membuka **seluruh** rute GET tanpa parameter yang ditawarkan menunya. Hasil akhir: **semua lulus**. Tidak ada satu pun tautan menu yang berakhir 403 bagi pemiliknya.

### 4.3 Probe eskalasi lintas-peran — 0 bocor

Untuk setiap pasangan (peran A, peran B), rute-rute menu B diuji memakai token peran A, lalu hasilnya dibandingkan terhadap guard `role:` aktual pada rute tersebut. Hasil: **Bocor: 0**.

Dua kandidat awal yang perlu ditinjau manual:
- Rute `pengeluaran-beban.index` sebelumnya **tidak** ber-guard `role:` di level rute (F-11). Otorisasi sudah ditegakkan di `PengeluaranBebanController::__construct()`, sehingga **tidak ada eskalasi nyata**, tetapi deklarasi wewenang tidak terbaca mesin.
- 40+ "kandidat bocor" awal ternyata rute yang memang dirancang dipakai bersama (`role:teller,manajer,pengurus`) — diverifikasi ulang terhadap guard aktual sebelum dinyatakan aman.

### 4.4 Isolasi data antar-anggota (IDOR) — aman

`NasabahController` mengambil data melalui `getAnggota()`:
```php
$anggota = Anggota::where('user_id', Auth::id())->first();
abort_if(!$anggota, 403, 'Data anggota tidak ditemukan untuk akun ini.');
```
Seluruh query berikutnya di-scope `where('anggota_id', $anggota->id)`. **Tidak ditemukan celah IDOR.**

### 4.5 Ketahanan middleware — diverifikasi

| Komponen | Perilaku terverifikasi |
|----------|------------------------|
| `CheckRole` | Perbandingan **STRICT** `in_array($role, $roles, true)`; belum auth → redirect login; role tak sesuai → `abort(403)` |
| `LaporanKeuanganAccess` | Menentukan aksi dari **metode HTTP** (POST/PUT/PATCH → butuh `edit`, DELETE → butuh `hapus`), sehingga route tulis baru otomatis menuntut hak tulis |
| `PastikanAnggotaAktif` | Memblokir anggota berstatus PENDING/SUSPENDED/INACTIVE; resolusi berjenjang (route param → model binding → `anggota_id` → `rekening_id`) |
| `ShareNavigationMenu` | Menyuntikkan menu sesuai `NavigationService::getMenu($user->role)` |

---

## 5. Daftar Temuan

### F-01 — Hardcode batas transaksi teller di dasbor (Sedang)

**Lokasi:** `resources/views/dashboard/teller.blade.php:37` (sebelum perbaikan)

**Isi:**
```blade
<span>Batas mandiri: Rp 10.000.000</span>
```

**Masalah:** Nilai batas yang ditampilkan adalah **literal**, bukan dari konfigurasi. `config/role_hierarki.php` menetapkan `teller.tarik_tunai.limit_per_transaksi = 5.000.000`. Artinya layar **menjanjikan kewenangan 2× lipat** dari yang benar-benar divalidasi `TransactionLimitService`.

**Dampak:** Teller mengira boleh menarik sampai 10 juta, lalu transaksi **ditolak sistem** di detik terakhir — pengalaman pengguna buruk dan rawan error operasional.

**Perbaikan:** Angka batas dibaca dari sumber kebenaran RBAC.
```blade
{{-- Angka batas TIDAK boleh ditulis literal di view. Sumber kebenaran
     adalah HierarkiRole (config role_hierarki + override DB) — sama
     dengan yang dipakai TransactionLimitService saat memvalidasi.
     Ditampilkan batas `tarik_tunai`: limit tunai terendah yang dipegang
     teller, sehingga angka di layar tidak pernah menjanjikan lebih
     besar dari kewenangan sebenarnya. --}}
<span>Batas mandiri: Rp {{ number_format(\App\Support\HierarkiRole::batas('teller', 'tarik_tunai')['limit_per_transaksi'] ?? 0, 0, ',', '.') }}</span>
```

**Status:** ✓ TERTUTUP. Angka di layar kini berasal dari konfigurasi; override dari UI otomatis tercermin.

---

### F-02 — `@section('sidebar')` mati di dasbor Manajer (Sedang)

**Lokasi:** `resources/views/dashboard/manajer.blade.php`

**Masalah:** Berkas memuat blok `@section('sidebar')` 20 baris yang **tidak pernah dirender**. Layout `layouts/app.blade.php` hanya punya `@yield('title'|'styles'|'page-title'|'content'|'scripts')` + `@include('layouts.sidebar')` — tidak ada `@yield('sidebar')`.

**Dampak:** Kode mati yang **berisiko tinggi** karena isinya menyerupai menu asli. Ia bisa berbeda dari menu sungguhan tanpa membuat test gagal — persis kelas bug yang ditemukan pada F-05.

**Perbaikan:** Blok dihapus, diganti komentar penjelas yang mencegah penulisan ulang.

**Status:** ✓ TERTUTUP. Sidebar Manajer tetap utuh karena dibangun dari `NavigationService::menuManajer()`.

---

### F-03 — Sidebar role `anggota` kosong total (Tinggi)

**Lokasi:** `app/Services/NavigationService.php` — `getMenu()`

**Akar masalah:** Nilai `users.role` yang tersimpan di basis data untuk pengguna swalayan adalah **`anggota`** — dibuktikan oleh `WebAuthController::redirectByRole()` dan `RedirectIfAuthenticated`. Namun `getMenu()` hanya memiliki cabang `'nasabah'`:
```php
'nasabah' => static::menuNasabah(),
```
Role `anggota` jatuh ke `default => []`.

**Dampak:** Anggota **bisa login dan membuka dasbornya**, tetapi sidebar kosong total — seluruh portal nasabah tidak dapat dinavigasi. Kegagalan ini **tidak tertangkap test mana pun** karena tidak ada yang melekatkan tautan yang rusak (memang tidak ada).

**Perbaikan:** Cabang `'anggota'` ditambahkan, dengan docblock yang menjelaskan bukti nilai role tersebut.
```php
'teller'   => static::menuTeller(),
'ao'       => static::menuAO(),
'manajer'  => static::menuManajer(),
'pengurus' => static::menuPengurus(),
'dps'      => static::menuDPS(),
'anggota'  => static::menuNasabah(),   // ← BARU
'nasabah'  => static::menuNasabah(),
'admin'    => static::menuAdmin(),
default    => [],
```

**Status:** ✓ TERTUTUP. Menu `anggota` kini berisi **9 entri / 6 rute**: `dashboard.nasabah`, `nasabah.simpanan.index`, `nasabah.mutasi.index`, `nasabah.angsuran.index`, `nasabah.pengajuan.index`, `nasabah.profil.index`.

---

### F-04 — Cakupan pengujian navigasi hanya satu peran (Sedang)

**Lokasi:** `tests/Feature/NavigasiWewenangPeranTest.php`

**Masalah:** Seluruh pengujian navigasi hanya menguji peran **`pengurus`**. Enam peran lain tidak punya jaminan apa pun — dan `anggota` terbukti benar-benar rusak (F-03) tanpa terdeteksi.

**Perbaikan:** Pengujian diparametrisasi ke seluruh 7 peran, ditambah pengujian invariant baru.

**Invariant yang kini ditegakkan:**
1. Setiap rute GET yang ditawarkan menu peran X harus memuat X pada guard `role:`-nya.
2. Setiap peran dasar wajib punya **menu yang tidak kosong**.
3. Setiap peran dapat **membuka seluruh halaman** yang ditawarkan menunya (probe HTTP nyata).
4. Tampilan peran X tidak boleh menautkan rute ber-guard peran lain.

```php
private const PERAN = ['teller', 'ao', 'manajer', 'pengurus', 'dps', 'anggota', 'admin'];

private const FOLDER_PERAN = [
    'teller'   => ['teller'],
    'ao'       => ['ao'],
    'manajer'  => ['manajer', 'pengelola'],
    'pengurus' => ['pengurus'],
    'dps'      => ['dps', 'laporan'],
    'anggota'  => ['nasabah'],
    'admin'    => ['admin'],
];
```

**Status:** ✓ TERTUTUP. `NavigasiWewenangPeranTest`: **5 passed**.

---

### F-05 — Dua `@section('content')` di dasbor DPS (Sedang)

**Lokasi:** `resources/views/dashboard/dps.blade.php` (sebelum perbaikan: 455 baris)

**Masalah:** Berkas memuat **dua** `@section('content')`. Karena `getSection()` pada Blade memakai `array_pop()`, **blok terakhir menang** — sehingga blok pertama (baris 6–367, inline tanpa denyut) adalah **dead code**.

**Dampak:** 362 baris kode mati yang **mirip halaman DPS sungguhan** (memuat visualisasi, statistik, dsb.). Bahaya utamanya: blok itu tidak terlihat di layar, tetapi pengujian yang memeriksa isi blok salah akan salah membaca perilaku nyata.

**Perbaikan:** Blok pertama dihapus; blok kedua (dengan 6 partial denyut) dipertahankan sebagai satu-satunya sumber.

**Status:** ✓ TERTUTUP. Berkas turun 455 → 86 baris. `@extends('layouts.app')` dipertahankan.

---

### F-06 — Komentar `CheckRole` usang dan menyesatkan (Rendah)

**Lokasi:** `app/Http/Middleware/CheckRole.php:24-26`

**Masalah:** Komentar menyatakan role `anggota` "tidak lagi akses", padahal **aktif** dipakai untuk seluruh modul nasabah (`role:anggota` di `routes/web.php`).

**Dampak:** Dokumentasi yang bertentangan dengan implementasi — sumber kesalahan saat audit berikutnya.

**Perbaikan:** Komentar dikoreksi, termasuk penjelasan mengapa perbandingan harus STRICT.

**Status:** ✓ TERTUTUP.

---

### F-07 — Dead file `dashboard/nasabah.blade.php` (Rendah)

**Lokasi:** `resources/views/dashboard/nasabah.blade.php` (151 baris)

**Masalah:** Berkas tidak pernah dirender siapa pun. `NasabahController::dashboard()` me-render `view('nasabah.dashboard')` — bukan `dashboard.nasabah`. Sekarang kedua file punya nama yang mirip, sehingga **`dashboard/nasabah.blade.php` adalah orphan**.

**Dampak:** Risiko pemeliharaan — siapa pun yang mengubah file ini mengira mengubah halaman portal anggota, padahal file tersebut tidak terpakai.

**Perbaikan:** Berkas dihapus setelah verifikasi bahwa tidak ada `view('dashboard.nasabah')` yang tersisa di seluruh proyek.

**Status:** ✓ TERTUTUP.

---

### F-08 — Kerusakan encoding U+FEFF pada dua view (Rendah)

**Lokasi:** `resources/views/dashboard/dps.blade.php` (21 buah), `resources/views/nasabah/dashboard.blade.php` (23 buah)

**Masalah:** Hasil pemindaian encoding project-wide menemukan dua view yang menyimpan banyak U+FEFF (BOM) di dalam byte-nya.

**Dampak:** Karakter aneh muncul di output, terutama pada `@extends` yang wajib berada di baris pertama.

**Perbaikan:** Kedua berkas ditulis ulang sebagai UTF-8 tanpa BOM.

**Status:** ✓ TERTUTUP. Scan ulang: 0 berkas view tersisa korup.

---

### F-09 — Tautan laporan yang pasti 403 di halaman Teller (Sedang)

**Lokasi:** `resources/views/teller/deposito.blade.php:42,46`

**Masalah:** Di dalam `@if(session('success'))` **tanpa penjaga role sama sekali**, halaman deposito teller menautkan:
```blade
<a href="{{ route('laporan.buku-besar', ['kode_akun' => '2-1003']) }}">→ Lihat Buku Besar Deposito (2-1003)</a>
&nbsp;|&nbsp;
<a href="{{ route('laporan.neraca') }}">→ Lihat Laporan Neraca</a>
```

Kedua rute dijaga `['laporan.akses:buku_besar', 'role:manajer,pengurus,dps']`. Setiap teller yang berhasil membuka deposito **akan melihat dua tautan yang PASTI berakhir 403**.

**Perbaikan:** Kedua tautan dibungkus penjaga yang membaca sumber kebenaran yang sama dengan middleware.
```blade
@php
    $bolehBukuBesar = \App\Support\LaporanKeuanganPermission::boleh(
        auth()->user()->role,
        \App\Support\LaporanKeuanganPermission::MODUL_BUKU_BESAR,
        \App\Support\LaporanKeuanganPermission::LIHAT
    );
    $bolehNeraca = \App\Support\LaporanKeuanganPermission::boleh(
        auth()->user()->role,
        \App\Support\LaporanKeuanganPermission::MODUL_NERACA,
        \App\Support\LaporanKeuanganPermission::LIHAT
    );
@endphp
@if($bolehBukuBesar || $bolehNeraca)
...
@endif
```
Pemisah `&nbsp;|&nbsp;` juga diproses secara kondisional agar tidak pernah tampil sendirian.

**Status:** ✓ TERTUTUP.

---

### F-10 — Tautan Bagan Akun yang pasti 403 di halaman Neraca (Sedang)

**Lokasi:** `resources/views/laporan/neraca.blade.php:365`

**Masalah:** Blok temuan "akun Ekuitas bersaldo minus" menautkan `pengurus.coa.index`, yang dijaga `role:pengurus` — sedangkan Neraca boleh dibuka manajer dan DPS juga. Penjaganya hanya `@if($akunEkuitasMinus->isNotEmpty())`, **bukan** penjaga peran.

**Dampak:** Manajer/DPS yang membaca Neraca saat ada akun ekuitas minus → klik "Bagan Akun" → **403**.

**Perbaikan:** Mengikuti pola yang sudah dipakai di baris 34 berkas yang sama.
```blade
@if(auth()->user()->role === 'pengurus')
<a href="{{ route('pengurus.coa.index') }}">Bagan Akun</a>.
@else
Bagan Akun (wewenang Pengurus).
@endif
```

**Status:** ✓ TERTUTUP.

---

### F-11 — Rute `pengeluaran-beban` tanpa guard `role:` di level rute (Rendah)

**Lokasi:** `routes/web.php:191-194`

**Masalah:** Grup `prefix('pengeluaran-beban')` **tidak** punya middleware `role:`. Otorisasi sepenuhnya bergantung pada closure di dalam `PengeluaranBebanController::__construct()`.

**Penilaian:** Bukan celah keamanan — restriksi peran sudah benar-benar ditegakkan (teller/manajer boleh; AO dapat redirect + pesan; lainnya `abort(403)`). Namun **deklarasi wewenangnya tidak terbaca mesin**: menu, audit, dan pengujian invariant semuanya membaca middleware rute. Akibatnya rute ini **tidak dapat dinilai secara otomatis** oleh pengujian anti-403, dan pemetaan peran menjadi tidak andal.

**Perbaikan:** Guard dideklarasikan di level rute dengan nilai yang **PERSIS sama** dengan controller — perilaku tidak berubah, hanya ditegaskan. AO sengaja didaftarkan agar tetap mendapat redirect + pesan ramah dari controller, bukan 403 telanjang.
```php
Route::middleware('role:teller,manajer,ao')->prefix('pengeluaran-beban')->group(function () {
    Route::get('/',  [PengeluaranBebanController::class, 'index'])->name('pengeluaran-beban.index');
    Route::post('/', [PengeluaranBebanController::class, 'store'])->name('pengeluaran-beban.store');
});
```

**Status:** ✓ TERTUTUP. RESTful defense-in-depth: dua lapis penjaga, satu deklarasi.

---

## 6. Rekomendasi & Kriteria Validasi

### 6.1 Rekomendasi yang sudah diterapkan

| # | Rekomendasi | Temuan | Status |
|---|-------------|--------|--------|
| R-01 | Semua angka batas di view WAJIB dibaca dari `HierarkiRole::batas()` — dilarang literal | F-01 | ✓ Diterapkan |
| R-02 | Menambahkan cabang `'anggota'` pada `NavigationService::getMenu()` | F-03 | ✓ Diterapkan |
| R-03 | Menambah pengujian invariant navigasi untuk **seluruh** peran, bukan satu | F-04 | ✓ Diterapkan |
| R-04 | Tautan lintas-peran di view WAJIB dijaga `LaporanKeuanganPermission::boleh()` atau `@if(role)` | F-09, F-10 | ✓ Diterapkan |
| R-05 | Setiap rute yang muncul di menu WAJIB punya guard `role:` yang dapat dibaca mesin | F-11 | ✓ Diterapkan |
| R-06 | Membersihkan dead code & dead file pada view | F-02, F-05, F-07 | ✓ Diterapkan |

### 6.2 Kriteria validasi — semua role beroperasi sesuai spesifikasi dasbornya

Audit dinyatakan **LULUS** bila keenam kriteria berikut terpenuhi. Semua kriteria kini terpenuhi dan terverifikasi otomatis.

| # | Kriteria validasi | Cara verifikasi | Hasil |
|---|-------------------|-----------------|-------|
| **V-1** | Setiap peran punya **menu sidebar tidak kosong** | `NavigasiWewenangPeranTest::test_tiap_peran_dasar_memiliki_menu_yang_tidak_kosong` | ✓ 7/7 peran |
| **V-2** | Setiap rute yang ditawarkan menu peran X **memuat X** pada guard `role:`-nya | `NavigasiWewenangPeranTest::test_setiap_rute_menu_hanya_menunjuk_rute_yang_diizinkan_peran_itu` | ✓ 0 pelanggaran |
| **V-3** | Setiap peran dapat **membuka seluruh halaman** di menunya tanpa 403 | `NavigasiWewenangPeranTest::test_setiap_peran_dapat_membuka_seluruh_rute_pada_menunya` (probe HTTP nyata) | ✓ 0 × 403 |
| **V-4** | Tampilan peran X **tidak menautkan** rute ber-guard peran lain | `NavigasiWewenangPeranTest::test_tampilan_setiap_peran_tidak_menautkan_rute_ber_guard_peran_lain` | ✓ 0 pelanggaran |
| **V-5** | **Tidak ada eskalasi**: peran A tidak dapat membuka halaman peran B di luar wewenang | Probe 6×6 + perbandingan terhadap guard `role:` aktual | ✓ 0 bocor |
| **V-6** | **Kontrak mesin denyut** view ↔ controller cocok pada keenam dasbor | Verifikasi `data-denyut-sasaran` ↔ kunci JSON `html` | ✓ 6/6 cocok |

### 6.3 Catatan metodologis: keterbatasan pemindai tautan

Pengujian V-4 semula melaporkan **4 pelanggaran**, tetapi **2 di antaranya temuan palsu** — pemindai hanya melihat teks `route('...')` tanpa membaca kondisi Blade. Kendala yang sama berlaku untuk pola **benar** (`@if(auth()->user()->isPengurus())`) dan pola **jahat** (`@if(session('success'))` tanpa penjaga peran), sehingga pemindai tidak bisa membedakan keduanya.

Untuk menutup keterbatasan ini, pemindai dibuat **sadar kondisi**: ia menelusuri blok `@if`/`@elseif`/`@else`/`@endif`/`@can` yang masih terbuka pada posisi kemunculan `route()`, dan mengabaikan tautan yang condition-nya berbicara tentang wewenang. `@else` sengaja menghapus kondisi sebelumnya — di cabang `@else`, `@if(role === 'pengurus')` justru **tidak berlaku**.

Hasil: **4 temuan → 2 temuan NYATA (F-09, F-10), keduanya diperbaiki.**

---

## 7. Pengujian Fungsional Menyeluruh (Pasca-Perbaikan)

### 7.1 Pengujian khusus navigasi & RBAC

```
php artisan optimize:clear ; php artisan test --filter="NavigasiWewenangPeranTest"

  PASS  Tests\Feature\NavigasiWewenangPeranTest
  ✓ setiap rute menu hanya menunjuk rute yang diizinkan peran itu
  ✓ menu pengurus tidak lagi menautkan rute khusus dps
  ✓ setiap peran dapat membuka seluruh rute pada menunya
  ✓ tiap peran dasar memiliki menu yang tidak kosong
  ✓ tampilan setiap peran tidak menautkan rute ber guard peran lain

  Tests:    5 passed (8 assertions)
```

### 7.2 Regresi penuh

```
php artisan optimize:clear ; php artisan test

  Tests:    5 skipped, 1509 passed (6833 assertions)
  Duration: 209.10s
```

**Tidak ada regresi.** Baseline naik dari `1508 passed (6831 assertions)` menjadi `1509 passed (6833 assertions)` — murni penambahan cakupan pengujian, nol kegagalan baru.

### 7.3 Catatan penting selama perbaikan

> Selama perbaikan F-10 ditemukan satu regresi yang **semuanya sendiri akan lolos bila tidak diuji penuh**: penutup komentar Blade yang salah ketik (`)}}` alih-alih `--}}`) menyebabkan **12 test gagal** dengan `syntax error, unexpected token "\`"` di `neraca.blade.php:365`. Ini justru bukti bahwa pengujian regresi penuh **wajib** dijalankan — test khusus navigasi tetap hijau karena tidak merender halaman Neraca. Perbaikan: penutup komentar dikoreksi.

---

## 8. Ringkasan Berkas yang Diubah

| Berkas | Perubahan | Temuan |
|--------|-----------|--------|
| `app/Services/NavigationService.php` | Tambah cabang `'anggota'` pada `getMenu()` + docblock bukti | F-03 |
| `app/Http/Middleware/CheckRole.php` | Koreksi komentar usang soal role `anggota` | F-06 |
| `routes/web.php` | Tambah `role:teller,manajer,ao` pada grup `pengeluaran-beban` | F-11 |
| `resources/views/dashboard/teller.blade.php` | Batas transaksi dari `HierarkiRole`, bukan literal | F-01 |
| `resources/views/dashboard/manajer.blade.php` | Hapus `@section('sidebar')` mati | F-02 |
| `resources/views/dashboard/dps.blade.php` | Hapus blok `@section('content')` mati, bersihkan 21 U+FEFF | F-05, F-08 |
| `resources/views/nasabah/dashboard.blade.php` | Bersihkan 23 U+FEFF | F-08 |
| `resources/views/teller/deposito.blade.php` | Penjaga `LaporanKeuanganPermission::boleh()` pada tautan laporan | F-09 |
| `resources/views/laporan/neraca.blade.php` | Penjaga `@if(role === 'pengurus')` pada tautan Bagan Akun | F-10 |
| `resources/views/dashboard/nasabah.blade.php` | **Dihapus** (dead file) | F-07 |
| `tests/Feature/NavigasiWewenangPeranTest.php` | Cakupan 7 peran, 2 invariant baru, pemindai sadar kondisi | F-04 |

---

## 9. Opini Audit Penutup

Sistem **layak secara pengendalian akses** untuk seluruh 7 peran. Yang paling menentukan:

1. **Tidak ada privilege escalation** — dibuktikan probe langsung, bukan hanya pembacaan kode.
2. **Tidak ada tautan mati di navigasi** — setiap peran dapat mencapai seluruh halaman yang ditawarkan menunya tanpa satu pun 403.
3. **Isolasi data anggota terpenuhi** — `abort_if(!$anggota, 403)` + seluruh query di-scope `anggota_id`.
4. **Pemisahan tugas (maker–checker) terjaga** — modul laporan hanya-baca; hak tulis dipisah ke modul penyesuaian jurnal yang hanya dipegang Pengurus.

Temuan tersisa seluruhnya bersifat **kebenaran tampilan** (F-01, F-02, F-05, F-07) atau **keterbacaan wewenang** (F-11) — bukan celah keamanan. Setelah perbaikan, **seluruh temuan (F-01 s.d. F-11) telah TERTUTUP** dan tidak ada temuan terbuka yang menghalangi penggunaan pada data produksi.

**Pemantauan berkelanjutan yang direkomendasikan:** jalankan `NavigasiWewenangPeranTest` sebagai bagian dari pipeline CI setiap kali menu, rute, atau dasbor berubah. Invariant V-1 s.d. V-4 bersifat otomatis dan akan menangkap regresi seperti F-03 dan F-05 **sebelum** mencapai pengguna.