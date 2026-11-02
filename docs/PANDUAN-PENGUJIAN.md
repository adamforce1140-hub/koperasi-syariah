# PANDUAN PENGUJIAN PROYEK

**Status:** ✅ BERLAKU
**Lingkup:** Seluruh sesi pengujian otomatis (PHPUnit / `php artisan test`) pada proyek Koperasi Syariah.

> **ATURAN MUTLAK.** Pada **setiap sesi pengujian**, `php artisan optimize:clear`
> **harus** dijalankan **sebelum** `php artisan test`. Tidak ada pengecualian.
> Prosedur ini ditegakkan otomatis oleh dua lapis penjaga (§3) — bukan sekadar
> imbauan.

---

## 1. Perintah Resmi

Selalu gunakan jalur resmi berikut. Skrip ini menjalankan pembersihan cache
terlebih dahulu, memverifikasinya, baru menjalankan pengujian.

```powershell
# dari root proyek
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

Meneruskan filter atau berkas uji tertentu:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter PasswordKuatTest
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Tests tests\Feature\AuditPerubahanPiiTest.php
```

| Parameter | Fungsi |
|---|---|
| `-Filter <pola>` | Meneruskan `--filter=` ke PHPUnit |
| `-Tests <berkas...>` | Membatasi pengujian ke berkas tertentu |
| `-TanpaBersih` | **Hanya untuk iterasi cepat di luar jalur resmi** (melewati pembersihan). Jangan dipakai bila `.env`/config baru berubah. |

Log lengkap tersimpan di `storage/logs/test-runner.log`.

---

## 2. Tiga Langkah Gerbang

Skrip menjalankan tiga langkah berurutan dan **membatalkan** pengujian bila ada
langkah gerbang yang gagal:

| Langkah | Tindakan | Bila gagal |
|---|---|---|
| 1/3 | `php artisan optimize:clear` | Log ERROR, `exit 1`, pengujian **tidak** dijalankan |
| 2/3 | Verifikasi `bootstrap/cache/` bebas `config.php`, `routes-*.php`, `events.php` | Log ERROR, `exit 1` |
| 3/3 | `php artisan test` (+ `--filter=` / daftar berkas) | Meneruskan exit code pengujian |

`optimize:clear` mereset **config, cache, compiled, events, routes, dan views**
sekaligus.

---

## 3. Mengapa `optimize:clear` Wajib (dan Bagaimana Ditegakkan)

### 3.1 Akar masalah

`php artisan config:cache` merender seluruh konfigurasi ke
`bootstrap/cache/config.php`. Berkas itu **membekukan isi `.env`**, termasuk
`APP_KEY`. Ketika suite berjalan di atas cache semacam itu:

- `APP_KEY` yang terpakai sudah kedaluwarsa,
- token CSRF seluruh form tidak lagi cocok,
- **ratusan test gagal serentak dengan galat 419 CSRF.**

Kegagalan itu **bukan bug produk**, melainkan artefak cache yang tidak teratur.
Karena itu cache wajib direset pada setiap sesi pengujian.

### 3.2 Dua lapis validasi otomatis

1. **`scripts/run-tests.ps1`** — gerbang 3 langkah di §2. Menjalankan pengujian
   tanpa pembersihan menjadi tidak mungkin lewat jalur resmi.
2. **`tests/CreatesApplication.php`** — penjaga tingkat-suite yang berjalan
   sekali per proses. Ia memeriksa `bootstrap/cache/` dan
   `configurationIsCached()` sebelum suite dimulai, lalu melempar
   `RuntimeException` yang menyebut perintah perbaikannya. Penjaga ini menutup
   celah **jalur langsung** (`php artisan test` tanpa skrip).

Pesan galat yang muncul bila cache kotor:

```
Cache belum direset sebelum pengujian. Berkas tertinggal: config.php. Jalankan
'php artisan optimize:clear' lebih dahulu (lihat docs/PANDUAN-PENGUJIAN.md).
```

> **Catatan penting.** `bootstrap/cache/packages.php` dan `services.php`
> **bukan** cache kotor. Keduanya adalah manifest paket hasil `package:discover`
> yang **sengaja dibiarkan** oleh `optimize:clear` dan dibutuhkan Laravel untuk
> booting. Kedua berkas ini dikecualikan eksplisit dari kedua lapis validasi.

---

## 4. Prosedur Manual (bila skrip belum memadai)

```powershell
php artisan optimize:clear   # WAJIB lebih dahulu
php artisan test
```

Mengabaikan langkah pertama akan langsung ditolak oleh penjaga di
`tests/CreatesApplication.php` — inilah perilaku yang diharapkan.

---

## 5. Pemecahan Masalah

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Test gagal massal dengan **419 CSRF** | Suite berjalan di atas `bootstrap/cache/config.php` | `php artisan optimize:clear`, lalu ulangi via `scripts\run-tests.ps1` |
| `Cache belum direset sebelum pengujian. Berkas tertinggal: ...` | `php artisan test` dijalankan langsung tanpa pembersihan | `php artisan optimize:clear` lebih dahulu, atau pakai `scripts\run-tests.ps1` |
| Perubahan `.env` tidak berefek | Config ter-cache | `php artisan config:clear` |
| Perubahan Blade tidak muncul | View ter-cache | `php artisan view:clear` |
| Peringatan cache `packages.php` / `services.php` | Manifest paket bawaan (`package:discover`) | **Bukan masalah** — memang tidak dihapus oleh `optimize:clear` |

---

## 6. Riwayat Pengujian dari UI (Dashboard Pengurus)

Selain jalur CLI di §1, Pengurus dapat menjalankan &amp; melihat hasil pengujian
**langsung dari dalam aplikasi** melalui menu **Riwayat Pengujian** pada dashboard
pengurus. Fitur ini memverifikasi kualitas sistem tanpa perlu keluar dari aplikasi.

**Cara pakai:**

1. Masuk sebagai **Pengurus** (mis. `pengurus@koperasi.test`), buka menu
   **Riwayat Pengujian** (`/pengurus/riwayat-pengujian`).
2. Isi **tanggal pengujian**, pilih **modul** yang ingin diuji (mis. *Simpanan Wajib*),
   lalu klik **Run Test**. Kolom **target uji** bersifat opsional (membatasi ke satu berkas
   di dalam `tests/`).
3. Hasil muncul sebagai **baris tabel**: status (LULUS/GAGAL/ERROR), jumlah total/lulus/gagal/
   dilewati, assertions, durasi, pelaksana, dan cuplikan keluaran bila tidak lulus.

**Modul yang tersedia:** `simpanan_wajib`, `simpanan_umum`, `pembiayaan`, `zakat`,
`keuangan`, `rbac_keamanan`, dan `semua` (seluruh suite). Daftar berkas uji per modul
didefinisikan di `app/Services/TestRunnerService.php` (`MODUL`).

> **Penting — hubungan dengan `optimize:clear`.** Runner UI menjalankan
> `php artisan optimize:clear` **secara otomatis** sebelum `php artisan test`, sehingga
> artefak cache (§3) tidak muncul. Bila Anda menjalankan pengujian manual via CLI, aturan
> §1 tetap berlaku mutlak.

**Catatan teknis (mengapa runner UI dapat berjalan di dalam web)**

Menjalankan PHPUnit dari dalam proses web (Apache/mod_php) tidak sama seperti dari CLI.
Dua kendala khusus Windows/Laragon telah diselesaikan dan perlu dicatat agar tidak muncul
kembali saat refaktor:

1. **Direktori temp subproses.** `Symfony\Component\Process` (lewat `WindowsPipes`) memakai
   `sys_get_temp_dir()` yang, di lingkungan ini, ter-resolve ke `C:\Windows` yang **tidak
   writable** → `RuntimeException: A temporary file could not be opened ... sf_proc_00.out.lock`.
   `ini_set('sys_temp_dir', ...)` tidak menolong (PHP_INI_SYSTEM), dan `putenv('TMP=...')`
   tidak andal karena nilai `sys_get_temp_dir()` ter-cache per-proses. **Solusi:** runner tidak
   memakai `Symfony\Process`, melainkan `proc_open()` native dengan berkas output di
   `storage/app/tmp` (lihat `jalankanProses()` + `direktoriTemp()` di `TestRunnerService`).

2. **`APP_ENV` yang diwarisi.** Saat melayani request HTTP, Laravel mengekspor
   `APP_ENV=local` ke environment proses. Bila variabel ini diwariskan ke subproses PHPUnit,
   ia **menang** atas `<env name="APP_ENV" value="testing"/>` di `phpunit.xml` (default
   `force="false"`), sehingga suite berjalan sebagai `local` → middleware CSRF aktif → semua
   request POST uji gagal **HTTP 419**. **Solusi:** `environmentSubproses()` memaksa
   `APP_ENV=testing` pada environment subproses.

**Berkas terkait fitur ini:**

- `app/Services/TestRunnerService.php` — definisi modul, penyusunan perintah, ringkasan hasil.
- `app/Http/Controllers/Dashboard/RiwayatPengujianController.php` — controller (index/jalankan/hapus).
- `app/Models/TestRun.php` + `database/migrations/2026_10_13_000200_create_test_runs_table.php` — riwayat tersimpan.
- `resources/views/pengurus/riwayat-pengujian.blade.php` — tampilan.
- `tests/Feature/RiwayatPengujianTest.php` — cakupan pengujian fitur ini.

---

## 7. Berkas Terkait

- `scripts/run-tests.ps1` — runner resmi (gerbang 3 langkah).
- `tests/CreatesApplication.php` — penjaga tingkat-suite.
- `docs/RUNNING-SERVER.md` §7 — prosedur pengujian &amp; pemecahan masalah server.
