# LAPORAN AUDIT — KERUSAKAN RENDER CSS AKIBAT DIREKTIF BLADE DI DALAM BLOK `<style>`

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal:** 2026-10-14
**Lingkup:** Seluruh basis kode tampilan (`resources/views/**/*.blade.php`)
**Gejala yang dilaporkan:** Pada halaman `/manajer/approval-restrukturisasi`, seluruh
isi CSS tampil sebagai **teks mentah** di badan halaman (bukan diterapkan sebagai gaya),
sehingga tata letak halaman hancur total.

---

## 1. Ringkasan Eksekutif

Satu direktif Blade (`@yield('styles')`) tertulis sebagai **teks di dalam komentar CSS**
yang berada di dalam blok `<style>` pada layout induk. Laravel/Blade **tidak mengenali
komentar CSS**, sehingga direktif tersebut tetap dikompilasi menjadi kode PHP yang
menyuntikkan konten section anak **tepat di tengah blok `<style>` layout**. Karena
section anak (`@section('styles')`) membawa tag `<style>` dan `</style>` sendiri, tag
`</style>` milik anak **menutup blok `<style>` layout lebih awal**; sisa CSS layout
"tumpah" keluar dari elemen `<style>` dan dirender sebagai teks biasa.

Perbaikan dilakukan dengan **menghapus nama direktif dari komentar CSS**. Seluruh
halaman yang meng-extend `layouts.app` dan mendefinisikan `@section('styles')`
(19 view) dipulihkan sekaligus oleh satu perbaikan di layout bersama.

---

## 2. Audit Basis Kode (Langkah 1)

Pencarian pola berbahaya: **direktif Blade yang muncul di dalam blok `<style>`/`<script>`**.

| Pola | Lokasi ditemukan | Status |
|---|---|---|
| `@yield('styles')` di dalam komentar CSS (`/* ... */`) pada blok `<style>` | `resources/views/layouts/app.blade.php:503` | ❌ **BERMASALAH** — penyebab utama |
| `@include(...)` & `@yield('sidebar')` di dalam komentar | `resources/views/dashboard/manajer.blade.php:8` | ✅ AMAN — berada di dalam komentar Blade `{{-- --}}` (di-strip saat kompilasi) |
| `@extends` di dalam blok `@php ... @endphp` (`/** ... */`) | `resources/views/laporan/psak101-excel.blade.php:3` | ✅ AMAN — isi `@php` adalah PHP mentah, bukan direktif |
| Layout lain (`layouts/publik.blade.php`, `layouts/sidebar.blade.php`) | — | ✅ AMAN — tidak ada direktif di dalam `<style>`/`<script>` |

**Kesimpulan:** hanya **satu** titik akar masalah, yaitu `layouts/app.blade.php:503`.

### 2.1 Modul/view yang terdampak (meng-extend `layouts.app` + punya `@section('styles')`)

Bug ini bersifat **global pada layout bersama**, sehingga **seluruh** 19 view berikut
terdampak (meski hanya sebagian yang tampak jelas rusak tergantung isi section-nya):

1. `dashboard/pengurus.blade.php`
2. `dashboard/manajer.blade.php` (via `layouts.app`)
3. `pengurus/shu/index.blade.php`
4. `teller/riwayat.blade.php`
5. `manajer/approval-restrukturisasi.blade.php` ← **halaman yang dilaporkan**
6. `ao/restrukturisasi/index.blade.php`
7. `nasabah/pengajuan-pinjaman-baru.blade.php`
8. `nasabah/profil.blade.php`
9. `teller/angsuran.blade.php`
10. `laporan/neraca.blade.php`
11. `laporan/psak101.blade.php`
12. `teller/tarik.blade.php`
13. `teller/setor.blade.php`
14. `laporan/audit.blade.php`
15. `dps/audit-akad.blade.php`
16. `pengurus/laporan/dana-kebajikan.blade.php`
17. `ao/tunggakan/index.blade.php`
18. `nasabah/status-pengajuan.blade.php`
19. `ao/pengajuan/create.blade.php`

Seluruh view di atas meng-extend `layouts.app`, sehingga semuanya pulih dengan
perbaikan tunggal pada layout.

---

## 3. Analisis Akar Penyebab (Langkah 2)

**Berkas:** `resources/views/layouts/app.blade.php`

Blok `<style>` layout dibuka di **baris 16**. Di dalam blok tersebut terdapat komentar
CSS penjelas pada baris 494–504 yang **menyebut nama direktif Blade sebagai contoh teks**:

```css
/* ... blok ini hanya mengisinya; setiap view tetap bebas menimpa
   lewat @yield('styles') yang dimuat setelah blok ini. */
```

Blade memindai direktif berdasarkan awalan `@` **tanpa peduli konteks CSS**. Karena itu
`@yield('styles')` pada baris 503 dikompilasi menjadi:

```php
<?php echo $__env->yieldContent('styles'); ?>
```

**Rantai kegagalan:**

1. Saat Blade merender layout, ia **mengeksekusi** `yieldContent('styles')` di baris 503
   (masih di dalam elemen `<style>`).
2. Section `styles` dari view anak (mis. `manajer/approval-restrukturisasi`) berisi
   **tag `<style>` + aturan CSS + tag `</style>`** (contoh di `teller/setor.blade.php:25-28`).
3. HTML hasil akhir menjadi: `<style> ...layout css... <style> ...css anak... </style> ...sisa layout css... </style>`.
   Tag `</style>` milik anak menutup `<style>` lebih awal.
4. Sisa CSS layout setelah titik itu keluar dari elemen `<style>` → browser merendernya
   sebagai **teks biasa**. Itulah yang terlihat di tangkapan layar.

**Distribusi:** masalah yang sama **tidak** muncul pada view tanpa `@section('styles')`
(mis. dashboard teller) — mengonfirmasi bahwa pemicunya adalah injeksi section ke dalam `<style>`.

---

## 4. Implementasi Perbaikan (Langkah 3)

Perbaikan minimal & konsisten: **hapus nama direktif dari komentar CSS** (ganti dengan
frasa deskriptif tanpa `@`), sekaligus menambah peringatan agar tidak terulang.

`resources/views/layouts/app.blade.php`:

```diff
-           Blok ini hanya mengisinya; setiap view tetap bebas menimpa
-           lewat @yield('styles') yang dimuat setelah blok ini.
+           Blok ini hanya mengisinya; setiap view tetap bebas menimpa
+           lewat bagian "styles" yang dimuat setelah blok ini.
+           (Jangan menulis nama direktif Blade seperti yield di dalam
+           komentar CSS ini — Blade akan tetap mengeksekusinya.)
```

Tidak ada perubahan pada view anak (struktur `<style>` di dalam `@section('styles')`
sudah benar untuk injeksi di `@yield('styles')` pasca-`</style>` layout).

**Verifikasi kompilasi:** setelah `php artisan view:clear; php artisan view:cache`,
`yieldContent('styles')` pada berkas terkompilasi hanya muncul **sekali**, yaitu di
**baris 758** (setelah `</style>` layout) — tidak lagi di baris 503.

---

## 5. Pengujian Fungsional (Langkah 4)

Diuji langsung pada aplikasi berjalan (`http://koperasi_syariah.test`) dengan skrip
pemeriksa `document.body.innerText` mencari jejak CSS mentah
(`.container-fluid`, `--- Wadah`, `.d-flex`, `@media`, dst.) dan memastikan elemen
khas modul ter-render.

| Halaman | Peran | CSS mentah sebagai teks | Elemen khas ter-render | Hasil |
|---|---|---|---|---|
| `/manajer/approval-restrukturisasi` | Manajer | **Tidak ada** | `.stat-strip`, `.stat-box`, tabel | ✅ LULUS |
| `/laporan/neraca` | Manajer | **Tidak ada** | tabel laporan | ✅ LULUS |
| `/laporan/keuangan-syariah` (PSAK 101) | Manajer | **Tidak ada** | tabel laporan | ✅ LULUS |
| `/pengurus/dashboard` | Pengurus | **Tidak ada** | kartu statistik | ✅ LULUS |
| `/teller/setor` | Teller | **Tidak ada** | `.ts-wrapper` (tom-select) aktif | ✅ LULUS |

---

## 6. Verifikasi Regresi (Langkah 5)

Suite penuh dijalankan via jalur resmi (`scripts\run-tests.ps1`) setelah
`php artisan optimize:clear`:

```
Tests:    5 skipped, 1538 passed (8688 assertions)
Duration: 266.50s
[OK] Pengujian LULUS.
```

**Tidak ada regresi** — jumlah lulus identik dengan baseline (1538 passed / 5 skipped / 0 failed).

---

## 7. Dokumentasi & Pelacakan (Langkah 6)

| Item | Keterangan |
|---|---|
| **Modul teridentifikasi** | `layouts/app.blade.php` (bersama) + 19 view pengguna `@section('styles')` |
| **Jenis masalah** | Direktif Blade (`@yield('styles')`) dieksekusi di dalam blok `<style>` → tag `</style>` dini menutup style → CSS tumpah menjadi teks |
| **Perubahan** | Hapus nama direktif dari komentar CSS + tambah peringatan pencegah kambuh (1 berkas: `layouts/app.blade.php`) |
| **Bukti uji** | 5 halaman lintas-peran bersih; 1538 passed / 0 failed |
| **Pencegahan** | Jangan pernah menulis awalan `@` yang membentuk nama direktif Blade di dalam `<style>`/`<script>`/komentar CSS/JS. Untuk contoh di dokumentasi kode, gunakan frasa lain (mis. "bagian styles") atau bungkus dengan `@verbatim` |

### 7.1 Pelajaran untuk pengembangan selanjutnya

- Direktif Blade dipindai **tanpa memperhatikan konteks** (CSS/JS/komentar). Selalu
  hindari `@kata` di dalam blok gaya/skrip kecuali memang ingin dieksekusi.
- Bila perlu menampilkan contoh direktif sebagai teks di HTML, gunakan `@verbatim`
  atau tulis tanpa awalan `@`.
- Bug pada layout bersama berdampak **global**; perbaikan tunggal memulihkan banyak modul.
