# LAPORAN AUDIT — PENATAAN TAMPILAN DASHBOARD SEMUA ROLE

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal:** 2026-10-14
**Lingkup:** Keenam dashboard peran (pengurus, manajer, AO, teller, DPS, nasabah)
**Permintaan:** "Rapikan semua dashboard dari semua role agar enak dilihat dan mudah digunakan."
**Acuan CSS tunggal:** `resources/views/layouts/app.blade.php`

---

## 1. Ringkasan Eksekutif

Keenam dashboard dibangun secara terpisah pada waktu berbeda, sehingga tiap peran
menulis gaya sendiri-sendiri: definisi ulang `.stat-card`, `inline style` grid dengan
`minmax()` yang menimpa media query global, ikon tanpa kelas varian, dan banner
sapaan yang berbeda-beda. Akibatnya tampilan antar-peran tidak konsisten dan pada
layar sempit terjadi **overflow horizontal** (kolom kanan terpotong) serta kartu
statistik yang tetap memaksa 2 kolom.

Audit menemukan **6 jenis masalah (T1–T6)** dan **1 akar masalah tambahan** pada
perilaku grid CSS. Seluruh perbaikan dipusatkan pada **satu sumber kebenaran**
(`layouts/app.blade.php`) ditambah **satu partial banner baru**
(`dashboard.partials.sapaan`), lalu diterapkan ke semua view dashboard.

Hasil akhir: tata letak dashboard seragam, kartu statistik konsisten, banner sapaan
identik polanya di semua peran, dan **nol overflow horizontal** pada 360px maupun
1440px.

---

## 2. Modul yang Diidentifikasi (Langkah 1 — Audit)

| # | Dashboard | View | Partial statistik |
|---|---|---|---|
| 1 | Pengurus | `resources/views/dashboard/pengurus.blade.php` | `dashboard/partials/pengurus-stats.blade.php` |
| 2 | Manajer | `resources/views/dashboard/manajer.blade.php` | `dashboard/partials/manajer-stats.blade.php` |
| 3 | Account Officer | `resources/views/dashboard/ao.blade.php` | `dashboard/partials/ao-stats.blade.php` |
| 4 | Teller | `resources/views/dashboard/teller.blade.php` | `dashboard/partials/teller-stats.blade.php` |
| 5 | Dewan Pengawas Syariah | `resources/views/dashboard/dps.blade.php` | `dashboard/partials/dps-stats.blade.php` |
| 6 | Nasabah / Anggota | `resources/views/nasabah/dashboard.blade.php` | `nasabah/partials/dashboard-metrik.blade.php` |

URL resmi tiap dashboard: `/pengurus/dashboard`, `/manajer/dashboard`, `/ao/dashboard`,
`/teller/dashboard`, `/dps/dashboard`, `/nasabah/dashboard`.

---

## 3. Jenis Masalah yang Ditemukan (Langkah 2 — Analisis Akar Penyebab)

### T1 — Tabel lebar terpotong permanen di layar sempit
Tabel di dalam `.card`/`.data-card` melebar melebihi layar, tetapi `body { overflow-x: hidden }`
membuat kolom kanan **terpotong tanpa bisa digulir**. Tidak ada pembungkus `overflow-x: auto`
yang konsisten di seluruh dashboard.
**Akar:** tidak ada aturan tabel-agnostik pada layout bersama.

### T2 — `inline style` grid mengalahkan media query global
Sejumlah view menulis `style="display:grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); ..."`
langsung pada elemen. Karena `inline style` menang atas stylesheet, media query
`@media (max-width: 420px)` yang mengecilkan kartu **tidak pernah berlaku**, sehingga
di ponsel kartu tetap 2 kolom sempit.
**Akar:** definisi grid diduplikasi di view, bukan memakai kelas `.stats-grid` global.

### T3 — Teks kartu tidak dibungkus pembungkus `.stat-info`
Kartu statistik menaruh judul/nilai langsung tanpa `.stat-info`, sehingga pada kartu
sempit teks panjang (mis. nominal rupiah) tidak menyusut dengan benar dan mendorong
lebar kartu.
**Akar:** markup kartu berbeda antar-view; layout sudah menyediakan `.stat-info { min-width:0; flex:1 1 auto; overflow-wrap:break-word; }` tetapi tidak dipakai.

### T4 — Pengurus mendefinisikan ulang kelas global
`dashboard/pengurus.blade.php` menulis ulang `.stat-card`, `.stat-icon`, `.stat-value`,
`.stat-label`, `.stat-sub`, `.stat-icon.teal` di blok `<style>` halaman, plus override
`@media (max-width:768px)` (`.stat-card { padding:1rem 1.1rem }`, `.stat-value { font-size:1.35rem }`).
Akibatnya kartu dashboard pengurus **berbeda ukuran** dari peran lain (padding/ikon/nilai
tidak selaras).
**Akar:** duplikasi definisi; seharusnya cukup memakai definisi global.

### T5 — Ikon tanpa kelas varian warna
Sebagian kartu memakai `inline style` latar ikon (mis. `background:#ede9fe`) alih-alih
kelas varian `.stat-icon.purple`, sehingga warna tidak mengikuti token global.
**Akar:** tidak konsisten memakai varian `.stat-icon.green/blue/amber/purple/red`.

### T6 — Banner sapaan berbeda-beda di tiap peran
Setiap dashboard menulis banner sapaan sendiri dengan `inline style` penuh, sehingga
warna, radius, jarak, dan struktur berbeda-beda (ada yang tanpa badge, ada yang
memakai emoji berbeda). Tidak ada komponen bersama.
**Akar:** tidak ada partial banner yang dipakai ulang.

### Akar masalah tambahan — anak grid `min-width: auto`
Anak langsung sebuah grid CSS memiliki `min-width: auto` secara default, artinya
**min-content** anak menjadi lebar minimum track. Bila anak memuat tabel lebar
(walau sudah dibungkus `overflow-x:auto`), `min-content` tabel men-stretch track grid →
grid melebar melewati container → kolom kanan terpotong. Ini ditemukan pada
`#pengurus-transaksi` (anak `.chart-row-2`): `docScrollW 519 > clientW 487`.
**Akar:** tidak ada `min-width: 0` pada anak grid.

---

## 4. Perubahan yang Dilakukan (Langkah 3 — Implementasi Konsisten)

### 4.1 `resources/views/layouts/app.blade.php` (sumber kebenaran CSS)
- **T1** — menambah aturan tabel-agnostik: `.card > table` dan `.data-card > table`
  memakai `display:block; width:100%; overflow-x:auto` agar tabel lebar dapat digulir
  (sebelumnya hanya `.table-wrapper` yang mendukung).
- **Akar grid** — menambah token:
  `.stats-grid > *, .chart-row > *, .chart-row-2 > * { min-width: 0; }`
  sehingga anak grid boleh menyusut dan tidak men-stretch track.
- **T6** — menambah token komponen banner: `.sapaan`, `.sapaan--emas`, `.sapaan--ungu`,
  `.sapaan__identitas`, `.sapaan__avatar`, `.sapaan__teks`, `.sapaan__salam`,
  `.sapaan__nama`, `.sapaan__meta`, `.sapaan__badge`, `.sapaan__badge-judul`,
  `.sapaan__badge-keterangan`, beserta varian `@media (max-width: 420px)`.
- Memperbaiki **komentar CSS rusak** (mojibake `â€"` → `—`) pada blok token grid
  dan blok banner sapaan.

### 4.2 `resources/views/dashboard/partials/sapaan.blade.php` (BARU — T6)
Partial banner sapaan seragam untuk keenam dashboard. Parameter opsional:
`$peran` (wajib), `$tema` (`hijau`|`emas`|`ungu`), `$nama`, `$salam`, `$badge`,
`$badgeKetika`.

### 4.3 Partial statistik (T2/T3/T5)
Semua partial disamakan memakai kelas global `.stats-grid` / `.stat-card` /
`.stat-icon <warna>` / `.stat-info` / `.stat-value` / `.stat-label`:
- `dashboard/partials/pengurus-stats.blade.php` (sudah bersih; 4 kartu `.stat-info`)
- `dashboard/partials/manajer-stats.blade.php` (grid inline dihapus; 4 kartu `.stat-info`)
- `dashboard/partials/ao-stats.blade.php` (grid inline dihapus; ikon `#fee2e2` → `.stat-icon.red`)
- `dashboard/partials/teller-stats.blade.php` (4 kartu `.stat-info`)
- `dashboard/partials/dps-stats.blade.php` (grid inline dihapus; ikon `#ede9fe` → `.stat-icon.purple`)
- `nasabah/partials/dashboard-metrik.blade.php` (seluruh `inline style` grid & kartu
  dihapus; kini memakai `.stats-grid`/`.stat-card`/`.stat-icon`/`.stat-info`)

### 4.4 View dashboard (T4/T6)
- `dashboard/pengurus.blade.php` — definisi ulang kelas statistik + override media
  query 768px **dihapus**; banner → `@include('dashboard.partials.sapaan', ['peran' => 'Pengurus', 'tema' => 'emas'])`.
- `dashboard/teller.blade.php` — `inline style` grid dihapus; banner → partial (tema hijau).
- `dashboard/ao.blade.php` — banner → partial (`peran` = "Account Officer").
- `dashboard/manajer.blade.php` — banner → partial (`peran` = "Manajer").
- `dashboard/dps.blade.php` — banner → partial (tema ungu + badge "READ-ONLY"/"Akses Audit").
- `nasabah/dashboard.blade.php` — banner inline penuh → partial
  (`peran` = "Anggota", `salam` = "Selamat datang kembali,", `nama` = `$anggota->nama_lengkap`).

---

## 5. Pengujian Fungsional (Langkah 4)

Pengujian dilakukan langsung pada browser (Chrome DevTools) terhadap 6 dashboard
pada dua viewport: **1440px (desktop)** dan **360px (ponsel)**.

| Verifikasi | Metode | Hasil |
|---|---|---|
| T1 — semua `<table>` punya ancestor `overflow-x:auto` | inspeksi DOM | ✅ semua tabel dapat digulir |
| T2 — kartu benar-benar 1 kolom di 360px | `gridTemplateColumns` | ✅ `328px` (1 kolom, 4 baris) |
| T3 — jumlah `.stat-info` == jumlah `.stat-card` | hitung elemen | ✅ cocok di semua dashboard |
| T4 — ukuran kartu pengurus = global | `getComputedStyle` | ✅ padding `24px`, ikon `52px`, nilai `24px` |
| T5 — ikon memakai kelas varian | daftar `className` | ✅ AO: `amber/green/red/green`; DPS: `green/green/purple` |
| T6 — banner seragam | `className` + teks | ✅ pengurus `sapaan--emas`; manajer/ao/teller/nasabah `sapaan--hijau`; dps `sapaan--ungu` + badge |
| Overflow horizontal | `documentElement.scrollWidth` vs `clientWidth` | ✅ `scrollWidth == clientWidth`, tanpa overflow di semua dashboard |

Verifikasi khusus dashboard nasabah (role `anggota`) dilakukan dengan membuat
**akun uji sementara** (karena seeder tidak menyediakan akun peran `anggota`),
lalu akun tersebut **dihapus kembali** setelah pengujian selesai — data anggota
dikembalikan ke keadaan semula (`user_id = null`).

**Screenshot bukti:** `dashboard-nasabah-360.png` & `dashboard-nasabah-1440.png`
(diambil pada sesi pengujian; lihat riwayat pengujian di aplikasi).

---

## 6. Verifikasi Tanpa Regresi (Langkah 5)

Seluruh perbaikan bersifat **penambahan token** pada layout bersama dan **penyeragaman
markup** pada view dashboard; tidak ada perubahan logika controller, route, atau model.

- Cache tampilan dibersihkan (`php artisan view:clear; php artisan view:cache`) setelah setiap edit Blade.
- **Tes penuh dijalankan** (`scripts/run-tests.ps1`): **`5 skipped, 1548 passed (8715 assertions), 0 failed`** — durasi ±224 detik.
- Halaman lain yang memakai `layouts.app` (mis. laporan, teller, ao) ikut memakai
  token baru tetapi tidak berubah perilakunya karena token bersifat menambah
  (`.sapaan*`, `min-width:0`, aturan tabel) dan tidak menimpa kelas lama.

### 6.1 Catatan penelusuran dua tes yang sempat merah

Pada satu run sebelumnya, dua tes **logika** (bukan tampilan) sempat gagal dan
telah ditelusuri sampai akar penyebab:

| Tes | Gejala | Akar penyebab |
|---|---|---|
| `PenguncianEkuitasPenarikanTest::test_http_penarikan_wajib_ditolak_dan_tercatat_audit` | `AuditLog` `PENARIKAN_MODAL_DIBLOKIR` tidak bertambah (0 > 0 gagal) | Permintaan Rp 10.000 tertahan **validasi `min:`** (`minimum_transaksi_teller`) lebih dulu, sehingga lapisan GUARD tidak pernah dijalankan dan audit tidak tertulis. |
| `TellerAnggotaAktivasiEndpointTest::test_setoran_awal_kedua_kali_mengaktifkan_anggota...` | Status anggota tetap `PENDING` (bukan `ACTIVE`) | Setoran awal Rp 20.000/30.000 tertahan validasi `min:` yang sama, sehingga kedua setoran awal tidak lengkap. |

**Penyebab tunggal:** nilai kebijakan `minimum_transaksi_teller` pada basis data dev
saat itu = **Rp 50.000**, lebih besar dari nominal uji (Rp 10.000/20.000/30.000).
Kedua tes memang dirancang berjalan di atas basis data dev nyata, tetapi **belum
mengunci** parameter kebijakan yang mereka andalkan.

**Perbaikan (tanpa mengubah kode produk):** kedua tes kini menetapkan
`minimum_transaksi_teller` secara eksplisit (Rp 1.000) pada set-up-nya dan
memulihkannya kembali — mengikuti pola yang sudah dipakai
`TellerAnggotaAktivasiEndpointTest` untuk `mandatory_*_savings`. Dengan begitu tes
**tidak lagi bergantung pada nilai ambien** basis data dan benar-benar menguji
lapisan guard/aktivasi. Nilai kebijakan pada basis data dev **tidak diubah**
(tetap Rp 50.000 sesuai konfigurasi Pengurus).

Diverifikasi ulang: dengan `minimum_transaksi_teller` = Rp 50.000 pun, kedua tes
lulus (`13 passed`), membuktikan perbaikan bersifat independen dari konfigurasi.

### 6.2 Penyesuaian menu sidebar peran Manajer

Permintaan lanjutan: *"hilangkan menu Laporan & Likuiditas, serta Rekam Jejak &
Audit dari menu Manajer karena menu/dashboard ini tidak seharusnya ada di role
Manajer."*

- **`app/Services/NavigationService.php`** — method `menuManajer()` kini hanya
  menyisakan **3 section**: *Dashboard Manajer*, *Verifikasi & Approval*,
  *Monitoring & Portofolio*. Dua section yang dihapus:
  *Laporan & Likuiditas* (Perhitungan Bagi Hasil/SHU, Laporan Neraca, Buku Besar,
  PSAK 101, CKPN, Zakat/Infak/Sedekah) dan *Rekam Jejak & Audit* (Audit Log,
  Audit Laporan Keuangan).
- **`app/Http/Controllers/Dashboard/ManajerController.php`** — konstanta
  `FITUR_DISEMBUNYIKAN` diperluas menjadi
  `['kinerjaStaf', 'laporan', 'kas', 'audit', 'bagiHasil']`, sehingga halaman
  khusus Manajer yang dulu hanya dapat dijangkau dari kedua section itu
  (`/manajer/audit`, `/manajer/bagi-hasil`, `/manajer/laporan`, `/manajer/kas`)
  ditolak bila dibuka **langsung lewat URL** — diarahkan ulang ke dashboard
  Manajer disertai pesan kesalahan, bukan sekadar 403.

**Catatan kebijakan:** rute laporan **bersama** (`laporan.neraca`,
`laporan.buku-besar`, `laporan.psak101`, `laporan.ckpn`, `zakat.*`,
`audit.laporan.index`) **tidak diubah**, karena aksesnya diatur oleh matriks RBAC
Laporan Keuangan dan dikunci `LaporanKeuanganRbacTest` (Manajer memiliki hak
"lihat" atas laporan tersebut). Yang dihilangkan murni **tampilan section pada
sidebar Manajer** ditambah penolakan halaman khusus Manajer yang bersangkutan.

**Verifikasi:** `NavigasiWewenangPeranTest`, `ManajerClosingApprovalMenuTest`,
`LaporanKeuanganRbacTest`, `DemonstrasiPerPeranTest` → **`71 passed (2429 assertions)`**;
tes penuh → **`5 skipped, 1548 passed, 0 failed`**.


---

## 7. Daftar Berkas yang Berubah

| Berkas | Perubahan |
|---|---|
| `resources/views/layouts/app.blade.php` | Token `.sapaan*`, `min-width:0` anak grid, aturan tabel `.card`/`.data-card`, perbaikan komentar CSS |
| `resources/views/dashboard/partials/sapaan.blade.php` | **BARU** — partial banner sapaan seragam |
| `resources/views/dashboard/partials/ao-stats.blade.php` | Grid inline → `.stats-grid`; ikon → `.stat-icon.red`; kartu → `.stat-info` |
| `resources/views/dashboard/partials/manajer-stats.blade.php` | Grid inline → `.stats-grid`; kartu → `.stat-info` |
| `resources/views/dashboard/partials/dps-stats.blade.php` | Grid inline → `.stats-grid`; ikon → `.stat-icon.purple`; kartu → `.stat-info` |
| `resources/views/dashboard/partials/teller-stats.blade.php` | Kartu → `.stat-info` |
| `resources/views/dashboard/partials/pengurus-stats.blade.php` | Kartu → `.stat-info` (sudah bersih) |
| `resources/views/dashboard/pengurus.blade.php` | Definisi ulang kelas statistik + override 768px dihapus; banner → partial |
| `resources/views/dashboard/teller.blade.php` | Grid inline dihapus; banner → partial |
| `resources/views/dashboard/ao.blade.php` | Banner → partial |
| `resources/views/dashboard/manajer.blade.php` | Banner → partial |
| `resources/views/dashboard/dps.blade.php` | Banner → partial (tema ungu + badge) |
| `resources/views/nasabah/dashboard.blade.php` | Banner → partial |
| `resources/views/nasabah/partials/dashboard-metrik.blade.php` | Inline style grid & kartu → kelas global |
| `tests/Feature/PenguncianEkuitasPenarikanTest.php` | Set-up mengunci `minimum_transaksi_teller` (independen dari konfigurasi basis data) |
| `tests/Feature/TellerAnggotaAktivasiEndpointTest.php` | Payload kebijakan ikut mengunci & memulihkan `minimum_transaksi_teller` |
| `app/Services/NavigationService.php` | `menuManajer()` — section *Laporan & Likuiditas* dan *Rekam Jejak & Audit* dihapus |
| `app/Http/Controllers/Dashboard/ManajerController.php` | `FITUR_DISEMBUNYIKAN` diperluas (`audit`, `bagiHasil`) agar halaman khusus Manajer ditolak bila diakses lewat URL |

---

## 8. Kesimpulan

Keenam dashboard kini memakai **satu bahasa desain**: grid statistik yang sama,
kartu yang sama, ikon berwarna dengan token yang sama, dan banner sapaan yang sama.
Perbaikan terpusat pada layout bersama sehingga perubahan di masa depan cukup
dilakukan sekali. Pengujian di dua viewport membuktikan tata letak bersih tanpa
overflow, dan tes penuh lulus tanpa regresi.
