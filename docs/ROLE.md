# ROLE — Daftar Peran & Matriks Izin Akses

**Status:** ✅ AKTIF
**Tanggal:** 2026-10-14
**Khalayak:** Pengurus, DPS, Manajer, Tim TI, auditor internal & eksternal
**Sumber kebenaran kode:**
`config/role_hierarki.php` · `app/Support/HierarkiRole.php` ·
`app/Services/NavigationService.php` · `routes/web.php` · `routes/api.php` ·
`app/Http/Middleware/CheckRole.php`

> Dokumen ini adalah **acuan tunggal** untuk pertanyaan "peran apa yang boleh
> melihat/menjalankan apa". Setiap perubahan hak akses WAJIB memperbarui
> dokumen ini pada commit yang sama.

---

## 1. Prinsip Dasar

| Prinsip | Penjabaran |
|---|---|
| **Pemisahan tugas (maker ≠ checker)** | Peran yang menginput transaksi tidak memutus approval-nya sendiri. Hak tulis jurnal hanya di tangan Pengurus. |
| **Kewenangan tata kelola ≠ kewenangan sistem** | Pengurus (tertinggi tata kelola) dan Super Admin (tertinggi sistem) adalah dua jalur berbeda yang tidak saling menggantikan. |
| **Default aman (P4)** | Nilai default di `config/role_hierarki.php` = perilaku sistem saat ini; tidak mengubah apa pun tanpa override eksplisit. |
| **Konfigurasi, bukan kode** | Menambah peran tidak mengubah kode otorisasi; cukup tambah entri config + migration ENUM + method menu. |
| **Jejak tidak dapat disunting** | Percobaan akses laporan keuangan (ditolak maupun diizinkan) direkam `laporan_keuangan_logs`. |
| **Least privilege** | Sidebar hanya menampilkan menu yang rutenya mengizinkan peran tersebut; akses URL langsung tetap dibatasi middleware. |

---

## 2. Definisi Peran (ENUM `users.role`)

Nilai sah kolom `users.role` (urut huruf, definisi final migration
`2026_10_14_000100_add_super_admin_to_users_role_enum.php`):

```
super_admin, admin, teller, manajer, pengurus, anggota, ao, dps, nasabah
```

Default kolom: `anggota`.

### 2.1 Pemetaan terminologi koperasi syariah → peran sistem

Istilah tata kelola yang umum di koperasi syariah dipetakan ke peran teknis
yang sudah ada (tidak ada peran baru dibuat):

| Istilah koperasi syariah | Peran sistem (`users.role`) | Level | Kategori |
|---|---|:--:|---|
| **Anggota** | `anggota` (+ alias swalayan `nasabah`) | 0 | anggota |
| **Pengawas** (Dewan Pengawas Syariah / DPS) | `dps` | 60 | pengawas |
| **Ketua Koperasi** / Pengurus | `pengurus` | 50 | pengurus |
| **Manajer Operasional** | `manajer` | 40 | pengelola |
| **Staf Administrasi** (Account Officer) | `ao` | 30 | pengelola |
| **Staf Administrasi** (Teller / Kasir) | `teller` | 20 | pengelola |
| **Admin Sistem** | `super_admin` (tertinggi) & `admin` | 15 / 10 | sistem |

Catatan:

- **Bendahara** pada koperasi syariah menjalankan fungsi kas & jurnal.
  Wewenang ini dipegang **`pengurus`** (hak tulis jurnal, posting penyesuaian
  CKPN) dan **`teller`** (kas loket harian) — lihat §5 (pemisahan tugas).
- **`nasabah`** adalah peran swalayan NON-anggota (calon anggota / pengguna
  jasa, POJK No. 76/2016). Ia memakai menu yang sama dengan `anggota` dan
  hanya mengakses modul `nasabah/*` (guard `role:anggota`).
- **`super_admin`** adalah administrator **sistem** (bukan tata kelola):
  levelnya tepat di atas `admin` tetapi di bawah seluruh peran operasional &
  tata kelola, **tidak** berada di rantai approval, dan **tidak** memegang
  hak tulis jurnal.

### 2.2 Registri lengkap (level, kategori, penyetuju, operasional)

| Peran | Label | Level | Kategori | Penyetuju | Operasional |
|---|---|:--:|---|:--:|:--:|
| `dps` | DPS | 60 | pengawas | ✗ | ✗ |
| `pengurus` | Pengurus | 50 | pengurus | ✓ | ✓ |
| `manajer` | Manajer | 40 | pengelola | ✓ | ✓ |
| `ao` | Account Officer | 30 | pengelola | ✓ | ✓ |
| `teller` | Teller | 20 | pengelola | ✗ | ✓ |
| `super_admin` | Super Admin | 15 | sistem | ✗ | ✗ |
| `admin` | Admin | 10 | sistem | ✗ | ✗ |
| `anggota` | Anggota | 0 | anggota | ✗ | ✗ |
| `nasabah` | Nasabah | 0 | anggota | ✗ | ✗ |

### 2.3 Rantai approval (eskalasi berjenjang)

```
teller → ao → manajer → pengurus → (puncak)
```

`dps`, `super_admin`, `admin`, `anggota`, `nasabah` **tidak** berada di rantai
approval. Status baris `approval_transaksi` diturunkan otomatis dengan pola
`PENDING_<PERAN_HURUF_BESAR>` (`PENDING_AO`, `PENDING_MANAJER`,
`PENDING_PENGURUS`).

---

## 3. Mekanisme Penegakan (Middleware)

Penegakan berlapis — **frontend** (menu tidak dirender) dan **backend**
(rute ditolak). Menu hanyalah lapis pertama; backend tetap sumber keputusan.

| Lapis | Komponen | Fungsi |
|---|---|---|
| Frontend | `App\Services\NavigationService::getMenu($role)` | Menyusun `$navMenu` per peran; di-share oleh `ShareNavigationMenu`, dirender `layouts/sidebar.blade.php`. Peran tak dikenal → menu kosong (tanpa crash). |
| Frontend | `WebAuthController::redirectByRole()` | Login diarahkan ke dashboard peran (fallback `/` bila peran tak dikenal). |
| Backend | `App\Http\Middleware\CheckRole` (`role:`) | `!auth()` → redirect `login`; peran tidak di daftar → **`abort(403)`**. Perbandingan **strict** (`in_array(..., true)`). |
| Backend | `App\Http\Middleware\LaporanKeuanganAccess` (`laporan.akses:`) | RBAC laporan keuangan per modul/hak (`LIHAT`, `UNDUH`, `EDIT`, `HAPUS`, `EKSOR`). Menulis audit log saat diizinkan **maupun** ditolak; dipasang **sebelum** `role:`. |
| Backend | `App\Support\LaporanKeuanganPermission` | Sumber kebenaran tunggal hak modul laporan keuangan. |
| Backend | `App\Support\HierarkiRole` | Sumber kebenaran tunggal hierarki peran & batas operasional. |
| Backend | `App\Support\PemisahanTugas` | Menolak & merekam self-approval. |

Alias middleware (`bootstrap/app.php`): `role`, `laporan.akses`, `anggota.aktif`.

**Halaman 403:** `resources/views/errors/403.blade.php` (dikembalikan
otomatis oleh `abort(403, ...)`).

---

## 4. Peta Menu Sidebar per Peran

Sumber: `NavigationService::getMenu($role)`. Setiap item menu menunjuk rute
yang juga dijaga `role:` dengan peran pemiliknya — ditegakkan oleh test
`NavigasiWewenangPeranTest`, `NavigasiSuperAdminMenuTest`,
`NavigasiAdminMenuTest`.

| Peran | Metode menu | Dashboard tujuan (login) | Cakupan menu |
|---|---|---|---|
| `teller` | `menuTeller()` | `dashboard.teller` | Kas Loket, Data CIF, Transaksi Loket, Pelaporan & Closing (**Pusat Konfirmasi Auto-Debit TIDAK ditawarkan** — SoD: fungsi back-office) |
| `ao` | `menuAO()` | `dashboard.ao` | Data CIF, Pembiayaan & Survei, Monitoring Nasabah, Tools |
| `manajer` | `menuManajer()` | `dashboard.manajer` | Verifikasi & Approval, Monitoring & Portofolio, Pusat Konfirmasi Auto-Debit |
| `pengurus` | `menuPengurus()` | `dashboard.pengurus` | Tata kelola eksekutif, 3 grup: **A. Strategis & Approval** (Dashboard Strategis, Approval Pembiayaan, Pengesahan Bagi Hasil & SHU, Approval Transaksi/Kebijakan), **B. Keuangan & Monitoring Eksekutif** (Laporan Keuangan Eksekutif, Kas & Rekening, Data CIF/Anggota & Simpanan, AML/CKPN, Opini DPS, Dana ZIS), **C. Kebijakan & Produk** (Profil & Kebijakan Koperasi) |
| `dps` | `menuDPS()` | `dashboard.dps` | Pengawasan: Audit Akad, Review Produk, Dana Kebajikan, Jurnal (read-only), Beban (read-only), Zakat, Opini & Catatan, Laporan |
| `super_admin` | `menuSuperAdmin()` | `dashboard.super-admin` | Real-time & Kinerja Unit, Matriks Hak Akses, Master Data & Produk, Kelola Pengguna, Kebijakan & Pengaturan (rincian laporan keuangan & audit laporan **tidak** ditawarkan — pemisahan tugas) |
| `admin` | `menuAdmin()` | `admin.settings.index` | Administrasi & konfigurasi sistem |
| `anggota` / `nasabah` | `menuNasabah()` | `dashboard.nasabah` | Swalayan: simpanan, rekening, mutasi, deposito, pembiayaan, pengajuan, profil |

> **Kebijakan khusus:** menu Manajer **tidak** memuat section
> `LAPORAN & LIKUIDITAS` maupun `REKAM JEJAK & AUDIT`. Halaman terkait juga
> ditolak bila diakses langsung (lihat `ManajerController::FITUR_DISEMBUNYIKAN`).
>
> **Pemisahan tugas Super Admin:** sidebar `super_admin` **tidak** menawarkan
> section `LAPORAN KEUANGAN SYARIAH` (PSAK 101/Neraca/Buku Besar) maupun item
> `Audit Laporan Keuangan`. Super Admin adalah peran administratif **sistem**,
> bukan penyusun/penyaji laporan keuangan, dan pemeriksa tidak boleh menjadi
> pihak yang diperiksa. Audit internal tetap dijalankan peran pengawas (DPS).
> Rute tetap tersedia; yang dihilangkan hanya akses dari sisi **menu**.
>
> **Pemisahan tugas Pengurus (Segregation of Duties):** sesuai Good
> Co-operative Governance, `pengurus` **hanya** berperan sebagai penentu
> kebijakan strategis dan pengambil keputusan approval. Sidebar `pengurus`
> **tidak** menawarkan fungsi operasional harian (kasir/teller), administratif
> teknis IT, maupun audit internal. Menu berikut **dihapus** dari sidebar
> `pengurus` dan **guardenya dialihkan**:
>
> | Menu dihapus dari Pengurus | Rute | Guard baru | Alasan |
> |---|---|---|---|
> | Setoran Angsuran | `pengurus.angsuran.index` | `role:teller,super_admin` | Transaksi operasional teller |
> | Pusat Konfirmasi Auto-Debit | `autodebit.*` | `role:manajer,super_admin,admin` | Fungsi back-office/finance (SoD: **bukan** tugas kasir/teller) |
> | Audit Laporan Keuangan | `audit.laporan.*` | `role:dps` | Auditor internal independen (SPI) |
> | Manajemen User & Hak Akses | `pengurus.users.*` | `role:super_admin` | Administrator teknis IT |
> | Riwayat Pengujian | `pengurus.riwayat-pengujian.*` | `role:super_admin` | Administrator teknis IT |
> | Mapping COA & Parameter Teknis | `pengurus.coa.*`, `pengurus.jenis-simpanan.*`, `pengurus.produk.*` | `role:super_admin` | Konfigurasi sistem |
>
> Akses URL langsung setiap rute di atas oleh `pengurus` → **403 Forbidden**
> (ditegakkan `CheckRole`), bukan sekadar disembunyikan dari menu.

---

## 5. Matriks Izin Akses per Modul

Legenda: **✓** = diizinkan · **—** = ditolak (403) · **R** = hanya baca

| Modul / Prefix rute | `dps` | `pengurus` | `manajer` | `ao` | `teller` | `super_admin` | `admin` | `anggota` / `nasabah` |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Dashboard peran sendiri | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dashboard peran lain | — | — | — | — | — | — | — | — |
| `teller/*` (kas, setor, tarik, angsuran, pencairan) | — | ✓ | ✓ | — | ✓ | — | — | — |
| `ao/*` (CIF, pengajuan, survei, tracking, restrukturisasi) | — | ✓ (baca) | ✓ | ✓ | — | — | — | — |
| `manajer/*` (approval pembiayaan/transaksi/restrukturisasi/beban/closing) | — | ✓ | ✓ | — | — | — | — | — |
| `pengurus/*` — **eksekutif & approval** (dashboard-strategis, approval, shu, pengesahan-shu, laporan, kas, anggota, simpanan, cif, laporan-syariah, aml, cadangan, kebijakan) | — | ✓ | — | — | — | — | — | — |
| `pengurus/*` — **konfigurasi sistem** (coa, produk, users, riwayat-pengujian, jenis-simpanan) | — | — | — | — | — | ✓ | — | — |
| `pengeluaran-beban/*` | — | ✓ | ✓ | ✓ | ✓ | — | — | — |
| `pengelola/*` (approval berjenjang) | — | ✓ | ✓ | — | — | — | — | — |
| `member-terminations/*` | — | ✓ | ✓ | — | ✓ | — | — | — |
| `pengajuan-pembiayaan/*` (verifikasi) | — | ✓ | ✓ | ✓ | — | — | — | — |
| `pengajuan-pembiayaan/*` (review/tolak) | — | ✓ | ✓ | — | — | — | — | — |
| `pengajuan-pembiayaan/*` (approve/tolak pengurus) | — | ✓ | — | — | — | — | — | — |
| `laporan.neraca`, `laporan.buku-besar`, `laporan.psak101` | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| `laporan.ckpn` | ✓ | ✓ | ✓ | — | — | — | — | — |
| `laporan.ckpn.posting` (POST) | — | ✓ | — | — | — | — | — | — |
| `manajer.laporan.index` | — | — | ✓ | — | — | — | — | — |
| `audit.laporan.*` (matriks, index) | ✓ | ✓ | ✓ | — | — | ✓ | ✓ | — |
| `dps/*` (audit-akad, review-produk, dana-kebajikan, jurnal-umum, opini-catatan, zakat, pengesahan-akad) | ✓ | — | — | — | — | — | — | — |
| `admin/*` (settings, clear-cache) | — | ✓ | — | — | — | ✓ | ✓ | — |
| `super-admin/*` (dashboard, kinerja-unit) | — | — | — | — | — | ✓ | — | — |
| `nasabah/*` (simpanan, mutasi, deposito, pengajuan) | — | — | — | — | — | — | — | ✓ |

### 5.1 Modul laporan keuangan (`laporan.akses:`)

Dijaga `LaporanKeuanganPermission` (per modul × hak). Hak tulis **tidak**
menempel pada modul laporan (prinsip maker-checker); hanya `pengurus` yang
memegang `penyesuaian_jurnal`.

| Modul | `dps` | `pengurus` | `manajer` | `super_admin` | `admin` |
|---|:--:|:--:|:--:|:--:|:--:|
| `neraca` | ✓ | ✓ | ✓ | ✓ | — |
| `buku_besar` | ✓ | ✓ | ✓ | ✓ | — |
| `laporan_psak101` | ✓ | ✓ | ✓ | ✓ | — |
| `laporan_manajer` | — | — | ✓ | — | — |
| `audit_laporan` | ✓ (lihat+ekspor) | — | — | — | — |
| `penyesuaian_jurnal` (tulis) | — | ✓ | — | — | — |

### 5.2 API (`routes/api.php`)

| Grup | Guard | Peran |
|---|---|---|
| Endpoint data internal | `role:admin,manajer,pengurus,teller,ao,dps` | 6 peran pengelola/sistem (produk pembiayaan: publik, tanpa auth) |
| Distribusi bagi hasil | `role:admin,manajer,pengurus` | 3 peran |
| Transaksi setor/tarik & approval | `auth:sanctum` | token perangkat |

---

## 6. Batas Operasional per Peran

Sumber: `config/role_hierarki.php` (dapat ditimpa dari tabel
`limit_transaksi_role` melalui UI tanpa deploy). `null` = tanpa batas,
`0` = dilarang.

| Peran | Limit per transaksi (setor / tarik) | Maks transaksi/hari | Maks baris ekspor |
|---|---|:--:|:--:|
| `teller` | Rp50.000.000 / Rp5.000.000 | 500 | — |
| `ao` | Rp100.000.000 / Rp20.000.000 | 300 | — |
| `manajer` | tanpa batas / tanpa batas | 200 | 20.000 |
| `pengurus` | tanpa batas / tanpa batas | 200 | 50.000 |
| `dps` | — | — | 50.000 |
| `super_admin` / `admin` | — | — | tanpa batas (peran sistem) |

Transaksi di atas limit `butuh_approval` wajib disetujui peran berikutnya di
rantai approval (`aproval_role`).

---

## 7. Cara Menambah Peran Baru

Peran baru cukup ditambahkan **tanpa mengubah kode otorisasi**:

1. **ENUM** — buat migration `ALTER TABLE users MODIFY COLUMN role ENUM(...)`
   yang menyertakan nilai baru (ikuti pola
   `2026_10_14_000100_add_super_admin_to_users_role_enum.php`).
2. **Config** — tambah satu entri di `config/role_hierarki.php` pada
   `peran` (+ `rantai`/`batas`/`frekuensi`/`akses_data` bila perlu). Sertakan
   `label`, `kategori`, dan `dashboard_route` pada entri tersebut. Method
   `HierarkiRole::peran()`/`level()`/`dikenal()`/`label()`/`dashboardRoute()`
   otomatis mengenalinya.
3. **Menu (frontend)** — tambah `menuPeranBaru()` di `NavigationService` dan
   daftarkan pada `match` di `getMenu()`.
4. **Redirect** — **tidak perlu mengubah kode**: `dashboard_route` pada entri
   `peran` (langkah 2) sudah dibaca otomatis oleh
   `WebAuthController::redirectByRole()`.
5. **Guard rute** — tambahkan nama peran pada argumen middleware `role:` rute
   yang relevan.
6. **Test** — tambahkan peran pada konstanta `PERAN` di
   `NavigasiWewenangPeranTest` dan `DemonstrasiPerPeranTest`, lalu jalankan
   suite.
7. **Dokumen** — perbarui tabel pada dokumen ini.

### 7.1 Sumber Pusat Peran (Peran Terpusat)

Seluruh pertanyaan "peran apa saja yang setara kategori/kelompok X" dan
"berkas ini boleh diunduh peran apa" dijawab dari **satu tempat** —
`config/role_hierarki.php` — melalui pintu masuk tunggal
`App\Support\HierarkiRole`. Tidak ada lagi `in_array($role, ['…'])` literal
yang tersebar di model/controller/view.

| Accessor | Sumber config | Dipakai oleh (contoh) |
|---|---|---|
| `HierarkiRole::label($role)` | `peran.<role>.label` | `layouts/app.blade.php`, `layouts/sidebar.blade.php`, `errors/403.blade.php`, `User::labelPeran()` |
| `HierarkiRole::dashboardRoute($role)` | `peran.<role>.dashboard_route` | `WebAuthController::redirectByRole()`, tombol Dashboard di `layouts/app.blade.php`, `errors/403.blade.php` |
| `HierarkiRole::jabatan($role)` | `peran.<role>.jabatan` (fallback: `label`) | `config/pdf.php` (`jabatan`), dokumen resmi seperti bilyet deposito |
| `HierarkiRole::kelompok($nama)` | `kelompok.<nama>` (fallback: `peran.*.kategori`) | penerima notifikasi, daftar peran satu kelompok |
| `HierarkiRole::dalamKelompok($role, $nama)` | idem | `User::isAdminSistem/isPengelola/hasOperationalAccess`, `Anggota::peranBolehUbahStatus()`, `CifController::index()`, guard `PengeluaranBebanController`, `Api\DepositoController`, `TransaksiMobileController`, `ManajerController`, view `pengurus/kebijakan.blade.php`, `partials/mutasi-terakhir.blade.php`, `partials/deposito-bilyet-table.blade.php`, `member-terminations/show.blade.php` |
| `HierarkiRole::izinDokumen($prefiks)` / `bolehUnduhDokumen($role, $prefiks)` | `izin_dokumen.<prefiks>` | `DokumenController::unduh()` (fail-CLOSED: prefiks tak terpetakan → 404) |

**Kelompok terdaftar** (`kelompok` di config): `sistem`, `pengawas`,
`pengelola`, `operasional`, `notifikasi_usulan_autodebit`,
`notifikasi_simpanan_wajib`, `notifikasi_shu_ditahan`,
`pengubah_status_anggota`, `pengelola_beban`, `wali_data_rekening`,
`pencairan_dini`, `pencetak_bilyet`, `lihat_mutasi`, `eksekusi_terminasi`,
`lihat_detil_cif`, `kinerja_ao`, `kinerja_teller`.

**Cara menambah peran ke sebuah kelompok:** cukup tambahkan peran ke daftar
`kelompok.<nama>` (atau set `kategori` yang sesuai bila nama kelompok = nama
kategori). Seluruh konsumen di atas ikut berubah otomatis tanpa menyentuh
kode otorisasi.

> **Prinsip penamaan label:** label `admin` diselaraskan menjadi
> **"Administrator"** pada config agar identik dengan tampilan UI sebelumnya
> (tidak ada regresi teks). `HierarkiRole::label()` adalah satu-satunya sumber
> teks label peran pada antarmuka.

---

## 8. Dokumentasi & Pengujian Terkait

| Berkas | Isi |
|---|---|
| `docs/TAHAP13-RBAC-HIERARKI-ROLE.md` | Fondasi RBAC: hierarki, batas operasional, approval berjenjang |
| `docs/AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md` | Audit kepatuhan peran terhadap syariah & PSAK |
| `docs/LAPORAN-AUDIT-ROLE-DASBOR.md` | Audit akses dashboard per peran |
| `docs/LAPORAN-DEMONSTRASI-PER-PERAN.md` | Demonstrasi fungsi per peran |
| `tests/Feature/NavigasiWewenangPeranTest.php` | Invariant: rute menu peran X wajib memuat X di guard `role:` |
| `tests/Feature/NavigasiSuperAdminMenuTest.php` | Anti-403 untuk menu Super Admin |
| `tests/Feature/NavigasiAdminMenuTest.php` | Anti-403 untuk menu Admin |
| `tests/Feature/DemonstrasiPerPeranTest.php` | E2E per peran: login, halaman 200, dashboard peran lain 403 |
| `tests/Feature/MatriksHakAksesAuditTest.php` | Matriks hak akses audit laporan |
| `tests/Feature/PetaAksesPeranMenuTest.php` | Regresi peta akses: ENUM, kelengkapan menu/redirect, 403 URL terlarang |
| `tests/Feature/LaporanKeuanganRbacTest.php` | RBAC modul laporan keuangan |

**Cara menjalankan uji terkait peran:**

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter "Navigasi"
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter "DemonstrasiPerPeranTest"
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter "PetaAksesPeranMenuTest"
```

---

## 9. Ringkasan Audit (2026-10-14)

Hasil audit menyeluruh rute & menu terhadap peran:

| Item diperiksa | Status | Bukti |
|---|---|---|
| Setiap rute menu peran X memuat X pada guard `role:` | ✅ konsisten | `NavigasiWewenangPeranTest` (hijau) |
| Menu Super Admin tidak menaut rute eksklusif peran lain | ✅ konsisten | `NavigasiSuperAdminMenuTest` (hijau) |
| Menu Admin tidak menaut rute eksklusif peran lain | ✅ konsisten | `NavigasiAdminMenuTest` (hijau) |
| Akses URL dashboard peran lain → 403 | ✅ ditegakkan | `DemonstrasiPerPeranTest` (hijau) |
| Modul `nasabah/*` konsisten dengan `menuNasabah()` | ✅ konsisten | guard `role:anggota`, alias `nasabah` dipetakan |
| Seluruh peran ENUM punya handler menu & redirect | ✅ lengkap | `PetaAksesPeranMenuTest` (hijau) |
| Menambah peran baru tanpa ubah kode | ✅ terpusat di config | `config/role_hierarki.php` |

### 9.1 Perbaikan Lapis Menu (sesuai standar koperasi syariah)

Ruang lingkup perbaikan **dibatasi pada lapis menu** (`NavigationService`), tanpa
mengubah sistem, guard, maupun arsitektur RBAC — sesuai arahan pemilik sistem.

| Perbaikan | Sebelum | Sesudah | Alasan standar koperasi syariah |
|---|---|---|---|
| Tautan "CKPN Piutang Pembiayaan" pada `menuPengurus()` | Tertaut **ganda** di dua section: "KEUANGAN & MONITORING EKSEKUTIF" dan "AKUNTANSI & LAPORAN" | Ditautkan **sekali** di section "AKUNTANSI & LAPORAN" (digantikan komentar kebijakan) | CKPN (PSAK 71) adalah **laporan akuntansi**, bukan indikator monitoring likuiditas; duplikasi tautan membingungkan pengurus |
| Section "LAPORAN KEUANGAN SYARIAH" & item "Audit Laporan Keuangan" pada `menuSuperAdmin()` | Ditawarkan di sidebar Super Admin | **Dihilangkan** dari sidebar (digantikan komentar kebijakan) | **Pemisahan tugas (SoD)**: Super Admin = peran administratif sistem, bukan penyusun/penyaji laporan keuangan; pemeriksa (auditor) tidak boleh menjadi pihak yang diperiksa. Audit internal dijalankan peran pengawas (DPS) |
| Tombol "Super Admin" pada panel "Akses Cepat Demo Akun" (`login.blade.php`) | Belum ada | Ditambahkan `superadmin@koperasi.test` (peran baru ikut tersedia untuk demo) | Kelengkapan akses cepat demo untuk seluruh peran ENUM yang di-seed; `anggota` sengaja tidak disertakan |

Tidak ada perubahan pada `routes/*.php`, `CheckRole`, `LaporanKeuanganPermission`,
`HierarkiRole`, maupun `config/role_hierarki.php`. Invariant RBAC tetap hijau
(verifikasi terakhir: `Navigasi|PetaAkses|DemonstrasiPerPeran|MatriksHakAksesAudit|LaporanKeuanganRbac`
→ 89 passed, 2690 assertions).

### 9.2 Catatan Celah yang Tidak Diubah (di luar lapis menu)

| Temuan | Lokasi | Keputusan |
|---|---|---|
| Guard `role:` pada dua rute API tidak memuat `super_admin` | `routes/api.php` baris 47 & 107 (`['web','auth','role:admin,manajer,pengurus,teller,ao,dps']`) | **Dibiarkan** — berada di lapis *sistem* (guard/route), bukan lapis menu. Perubahan menyentuh sistem, di luar arahan "dari menunya saja". Dicatat sebagai rekomendasi terpisah. |

### 9.3 Verifikasi Peran Terpusat (2026-10-14)

Implementasi **peran terpusat** (satu sumber di `config/role_hierarki.php` +
pintu masuk `HierarkiRole`) telah diverifikasi memenuhi seluruh kriteria
keberhasilan:

| Kriteria keberhasilan | Status | Bukti |
|---|---|---|
| Tidak ada `in_array($role, ['…'])` literal di `app/` | ✅ | Pemindaian `in_array(` + literal `['` pada argumen peran → 0 temuan (selain `LaporanKeuanganPermission` yang memang matriks akses terpisah) |
| Tidak ada `in_array($role, ['…'])` literal di `resources/views/` | ✅ | Pemindaian → 0 temuan |
| Peran baru cukup via config + migration ENUM + method menu | ✅ | `dashboard_route` dibaca otomatis; guard, notifikasi, izin dokumen, label, rute dasbor semuanya membaca `HierarkiRole` |
| Seluruh accessor `HierarkiRole` berfungsi | ✅ | `label`, `dashboardRoute`, `kelompok`, `dalamKelompok`, `kategori`, `peranDenganKategori`, `izinDokumen`, `bolehUnduhDokumen` — teruji |
| Tanpa regresi | ✅ | Filter RBAC **48 passed (275 assertions)**; suite penuh **5 skipped, 1576 passed (8991 assertions)** — identik baseline |

Perbandingan literal peran yang **sengaja dipertahankan** (bukan pelanggaran
prinsip terpusat, melainkan perbandingan identitas peran tunggal atau konteks
non-kelompok):

| Lokasi | Bentuk | Alasan dipertahankan |
|---|---|---|
| `User::isTeller/isManajer/isPengurus/isAO/isDPS/isSuperAdmin` | `$this->role === 'x'` | Predikat identitas satu peran (bukan pertanyaan kelompok) |
| `views/laporan/neraca.blade.php` (`pengurus`, `super_admin`) | `role === 'x'` | Gating satu tombol/tautan eksklusif peran tunggal |
| `views/manajer/closing/index.blade.php`, `Dashboard/ManajerController`, `ZakatController`, `DanaCadanganController` | `role === 'x'` | Gating aksi eksklusif peran tunggal |
| `views/pengelola/pengajuan-pinjaman*.blade.php` | `$role === 'manajer'` / `'pengurus'` | Percabangan **langkah approval** (status alur), bukan klasifikasi kelompok peran |
| `routes/api.php` guard `role:` | argumen daftar middleware | Definisi guard rute; ditambal bila menambah peran (§7 langkah 5) |

**Kelompok yang baru dipusatkan pada verifikasi ini:** `lihat_mutasi`
(`partials/mutasi-terakhir`), `eksekusi_terminasi`
(`member-terminations/show`), `lihat_detil_cif` (`CifController::index`), serta
`pencairan_dini` disamakan antara `partials/deposito-bilyet-table` dan
`Api\DepositoController`.

### 9.4 Zero-Hardcode Konfigurasi (2026-10-14)

Menutup temuan K3 `docs/REKOMENDASI-ARSITEKTUR-FLEKSIBEL-TERUKUR.md` (§3)
dengan cakupan **aman bertahap** — tanpa mengubah perilaku uang/jurnal.

| Perubahan | Sebelum | Sesudah | Bukti |
|---|---|---|---|
| Peta jabatan penandatangan dokumen | Array literal 6 peran di `config/pdf.php` | Diturunkan otomatis dari `peran.*.jabatan` via IIFE `require __DIR__ . '/role_hierarki.php'` | `config('pdf.jabatan')` = 9 peran; `HierarkiRole::jabatan('dps')` = "Dewan Pengawas Syariah" |
| Kunci `jabatan` per peran | Tidak ada | Ditambahkan pada seluruh 9 entri `peran` (fallback ke `label` bila kosong) | `config/role_hierarki.php`; accessor `HierarkiRole::jabatan()` |
| Presisi penyimpanan uang (sen) | `round($x, 2)` tersebar 359× di 43 berkas | `Setting::bulatkanUang($x)` — **setara persis** `round($x, 2)`, definisi presisi di satu tempat | `app/Support/Setting.php` (`bulatkanUang`, `DESIMAL_UANG = 2`); grep `round(…, 2)` di `app/` (di luar `Setting.php`) → **0** temuan |
| Literal role pada laporan Kinerja Staf | `User::where('role', 'ao')` / `'teller'` di `ManajerController` | `User::whereIn('role', HierarkiRole::kelompok('kinerja_ao'/'kinerja_teller'))` | kelompok baru `kinerja_ao`, `kinerja_teller` di config |
| Literal `0.01` toleransi nisbah produk | `abs(nisbah_anggota + nisbah_koperasi - 100) < 0.01` di 3 berkas (`DepositoProduct`, `MasterProdukPembiayaan`, `DynamicLoanController`) | `Setting::toleransiPersen()` (kunci `toleransi_persen`, default 0,01) | accessor `Setting::toleransiPersen()`; grep literal `0.01` pada ketiga berkas → **0** |
| Literal `0.01` toleransi jurnal pada command | `havingRaw('ABS(SUM(d.kredit) - b.amount) >= 0.01')` di `ValidasiKepatuhanSimpananWajib` | Parameter terikat `>= ?` dengan `Setting::toleransiSelisihJurnal()` | ambang jurnal kini satu sumber, sekaligus aman injeksi SQL |

> **Penting — jangan tertukar:** `Setting::pembulatanNominal()` mengikuti
> kebijakan `pembulatan_desimal` (default **0** = rupiah penuh) dan **bukan**
> pengganti `round($x, 2)`. Menggantinya secara massal akan mengubah satuan sen
> → rupiah penuh dan memecahkan `PsakParameterTest`. Pengganti yang benar untuk
> idiom presisi uang adalah `Setting::bulatkanUang()` (selalu 2 desimal).
>
> **Dua toleransi berbeda:** `toleransi_selisih_jurnal` (rupiah, untuk
> keseimbangan debet-kredit) ≠ `toleransi_persen` (persen, untuk total nisbah
> produk simpanan/pembiayaan). Keduanya bernilai default 0,01 tetapi satuan dan
> maknanya berbeda — jangan disatukan.

#### 9.4.1 Sumber Tunggal "Nama Peran" (2026-10-15)

Setelah peran terpusat (§7.1) selesai, sisa terakhir adalah **literal nama peran
yang masih ditulis langsung** di controller/model. Semuanya kini ditutup:

| Perubahan | Sebelum | Sesudah | Bukti |
|---|---|---|---|
| Pengalihan peran yang ditolak | `if ($role === 'ao') { redirect()->route('dashboard.ao') … }` literal di `PengeluaranBebanController::__construct()` | `HierarkiRole::penolakanKelompok($role, 'pengelola_beban')` — peta hidup di `role_hierarki.kelompok_tolak` | `tests/Feature/WewenangBebanZeroHardcodeTest.php` (8 test, anti-regresi perilaku redirect AO) |
| Pembanding peran tunggal di model | `$this->role === 'teller'` (7×) tersebar di `User` | Semua `is*()` memanggil `User::berperan(string $peran)` — satu titik | `app/Models/User.php` |
| Pembanding peran DPS | `Auth::user()?->role === 'dps'` di `ZakatController`; `$user->role === 'dps'` di `DanaCadanganController` | `->isDPS()` / `$user->isDPS()` | grep `->role ===` pada kedua controller → **0** |

**Menambah pengalihan ramah baru** (mis. peran lain dialihkan ke dasbornya alih-alih
403) kini cukup menambah satu entri di `role_hierarki.kelompok_tolak`, **tanpa
mengedit controller**. Kelompok tanpa entri tetap `abort(403)` — perilaku aman bawaan.

**Verifikasi:** `php -l` bersih pada seluruh berkas tersentuh; filter beban
**30 passed (134 assertions)**; filter zakat/cadangan/policy/user/beban
**119 passed (444 assertions)**; suite penuh **5 skipped, 1576 passed
(8991 assertions)** — identik baseline (ditambah 8 test baru).

#### 9.4.2 Sumber Tunggal "Nama Peran" — lanjutan & Kontrak (2026-10-15)

**a. Fitur Manajer tersembunyi → konfigurasi.** `ManajerController::__construct()`
dulu menulis `if (Auth::user()?->role === 'manajer') { redirect('dashboard.manajer') … }`.
Kini memakai `HierarkiRole::penolakanKelompok($role, 'manajer_fitur_tersembunyi')`
dengan entri baru di `role_hierarki.kelompok_tolak`. Lima rute (`kinerja-staf`,
`kas`, `bagi-hasil`, `audit`, `laporan`) **sengaja tetap terdaftar** di grup
`role:manajer` agar percobaan akses langsung via address bar tetap tercatat —
guard ini yang mengalihkannya dengan pesan ramah. Hasil: **literal role
otorisasi di `app/` = 0**.

**b. Kontrak & Dependency Inversion (Rekomendasi 3 audit).** Ternyata sudah
terpasang dan kini diuji:

| Kontrak (`app/Contracts`) | Implementasi bawaan |
|---|---|
| `JournalPosterInterface` | `App\Services\AutoJournalingService` |
| `PolicySourceInterface` | `App\Services\SettingPolicySource` (adaptor → `App\Support\Setting`) |
| `PeriodResolverInterface` | `App\Services\TahunBukuService` |
| `CoaResolverInterface` | `App\Services\CoaResolver` |
| `ApprovalGuardInterface` | `App\Services\PemisahanTugasGuard` |

Seluruh binding **aditif** di `AppServiceProvider::register()`; pemanggil statis
lama (`Setting::…()`) tetap bekerja. Test baru
`tests/Feature/KontrakArsitekturTest.php` (8 test) membuktikan: resolve dari
container, tiap implementasi benar `implements`, kontrak kompak (≤ 10 method),
**swap** `PolicySourceInterface` dengan fake, dan **mock** `JournalPosterInterface`
tanpa mengubah konsumen.

**Metrik audit §4.6:** interface `0 → 5` ✅; binding `0 → 5` ✅;
`new \App\Services\…` `3 → 0` ✅; `use App\Services\…` 78 pemakaian → migrasi
konsumen bertahap **belum** dilakukan (opsional, lapisan aditif sudah aman).

**Verifikasi:** filter beban+RBAC+manajer **69 passed (368 assertions)**;
filter kontrak **8 passed (48 assertions)**; suite penuh **5 skipped, 1592 passed
(9060 assertions)** — 0 kegagalan.

**Verifikasi:** `php -l` bersih pada seluruh berkas termigrasi; filter RBAC +
PSAK **86 passed (811 assertions)**; filter nisbah/deposito/PSAK/kepatuhan
**159 passed (759 assertions)**; suite penuh **5 skipped, 1576 passed
(8991 assertions)** — identik baseline.
