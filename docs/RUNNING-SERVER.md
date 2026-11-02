# Menjalankan Server - Sistem Informasi Koperasi Syariah

Dokumen ini menjelaskan seluruh langkah untuk menyalakan kembali server
pengembangan maupun produksi, termasuk prasyarat, perintah, penanganan galat,
dan cara menghentikan server dengan benar.

---

## 1. Prasyarat

| Komponen | Versi yang dipakai / disyaratkan | Cara verifikasi |
|---|---|---|
| PHP CLI | 8.3.33 (syarat `composer.json`: `^8.2`) | `php -v` |
| Composer | 2.10.3 | `composer -V` |
| MySQL | 8.4.3 (Laragon) | `mysql --version` |
| PowerShell | 5.1 (Windows) | `$PSVersionTable.PSVersion` |
| Node.js | **TIDAK diperlukan** (proyek tidak memakai `@vite`) | lihat bagian 8 |

> **Pengujian:** jalankan `php artisan optimize:clear` **sebelum** `php artisan test`.
> Jalur resmi yang menegakkannya otomatis: `scripts\run-tests.ps1` (bagian 7).

Ekstensi PHP yang wajib aktif:

```
pdo_mysql, openssl, mbstring, tokenizer, xml, ctype, json, fileinfo, bcmath
```

Verifikasi cepat seluruh ekstensi sekaligus:

```powershell
php -r "echo implode(',', get_loaded_extensions());"
```

Lokasi instalasi standar di mesin ini:

- PHP  : `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`
- MySQL: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`
- php.ini: `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.ini`

> PHP CLI boleh tidak ada di `PATH`; skrip runner otomatis mencari instalasi
> Laragon dan hanya memberi PERINGATAN bila memakai jalur fallback itu.

---

## 2. Konfigurasi Variabel Lingkungan

Berkas `.env` di root proyek. Bila belum ada:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

Nilai penting untuk lingkungan pengembangan:

| Kunci | Nilai pengembangan | Catatan |
|---|---|---|
| `APP_ENV` | `local` | `production` + `APP_DEBUG=true` memicu PERINGATAN |
| `APP_DEBUG` | `true` | Matikan di server publik |
| `APP_KEY` | terisi | **Wajib**. Kosong = ERROR (sesi/cookie/enkripsi rusak) |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | `127.0.0.1` | |
| `DB_PORT` | `3306` | Harus dapat dihubungi, jika tidak = ERROR |
| `DB_DATABASE` | `koperasi_syariah` | |
| `DB_USERNAME` | `root` | |
| `DB_PASSWORD` | *(kosong)* | Default Laragon |
| `APP_URL` | `http://localhost` | |

Bersihkan cache konfigurasi setelah mengubah `.env`:

```powershell
php artisan optimize:clear   # mereset config, cache, compiled, events, routes, views
# (padanan terarah: php artisan config:clear ; php artisan view:clear)
```

Port server **tidak** diambil dari `.env`; port ditentukan oleh argumen
`-Port` pada skrip runner (default `8000`).

---

## 3. Memulai Server

### 3a. Cara cepat (tanpa skrip preflight)

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

### 3b. Cara dianjurkan - memakai runner dengan preflight

```powershell
# dari root proyek
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-server.ps1
```

Parameter yang tersedia:

| Parameter | Default | Fungsi |
|---|---|---|
| `-HostAddress` | `127.0.0.1` | Alamat bind. Pakai `0.0.0.0` untuk akses LAN |
| `-Port` | `8000` | Port TCP |
| `-SkipDbCheck` | *(switch)* | Lewati verifikasi koneksi MySQL |
| `-Force` | *(switch)* | Tetap jalan walau ada PERINGATAN (ERROR tetap memblokir) |

Contoh:

```powershell
# dapat diakses dari perangkat lain di LAN (mesin ini: 10.10.10.11)
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-server.ps1 -HostAddress 0.0.0.0 -Port 8080

# bekerja offline tanpa MySQL
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-server.ps1 -SkipDbCheck
```

### Yang dilakukan runner sebelum menyalakan server

1. Mencari PHP CLI (PATH, lalu fallback `C:\laragon\bin\php`) dan memeriksa versi `^8.2`.
2. Memeriksa seluruh ekstensi wajib.
3. Memeriksa `vendor/autoload.php` (dependensi Composer).
4. Memeriksa keberadaan `.env` dan `APP_KEY`.
5. Memeriksa kebutuhan Node/Vite (hanya bila view memakai `@vite`).
6. Membaca `.env`, lalu menguji TCP ke `DB_HOST:DB_PORT` (timeout 3 detik).
7. Memvalidasi rentang port (1-65535) dan memastikan port belum dipakai.
8. Menulis seluruh hasil ke log, lalu menjalankan `php artisan serve` di foreground.

### Kode keluar (exit code)

| Exit code | Arti |
|---|---|
| `0` | Server berhenti normal |
| `1` | Ada ERROR (server tidak dinyalakan, atau server mati karena galat) |
| `2` | Hanya ada PERINGATAN; jalankan ulang dengan `-Force` untuk tetap menyalakan |

---

## 4. Penanganan Galat

| Kasus | Perilaku runner | Cara memperbaiki |
|---|---|---|
| PHP CLI tidak ditemukan | ERROR, exit 1 | Tambahkan PHP ke `PATH`, atau jalankan Laragon > Menu > PHP > Version |
| Versi PHP < 8.2 | ERROR, exit 1 | Aktifkan PHP 8.2+ di Laragon |
| Ekstensi wajib tidak aktif | ERROR (menyebut nama ekstensi), exit 1 | Aktifkan `extension=` di `php.ini`, lalu restart |
| `vendor/` tidak lengkap | ERROR, exit 1 | `composer install` |
| `.env` tidak ada | ERROR, exit 1 | `Copy-Item .env.example .env; php artisan key:generate` |
| `APP_KEY` kosong | ERROR, exit 1 | `php artisan key:generate` |
| MySQL tidak dapat dihubungi | ERROR (menyebut host:port), exit 1 | Nyalakan service MySQL di Laragon |
| **Port sedang dipakai** | ERROR menyebut nama proses + PID pemiliknya, exit 1 | Pilih port lain (`-Port 8001`) atau hentikan proses pemilik |
| `@vite` dipakai tapi `public/build` tidak ada | PERINGATAN, exit 2 | `npm install; npm run build`, atau jalankan dengan `-Force` |
| `APP_ENV=production` + `APP_DEBUG=true` | PERINGATAN, exit 2 | Set `APP_DEBUG=false`, atau `-Force` |
| Bind `0.0.0.0` | PERINGATAN (membuka ke seluruh jaringan), exit 2 | Pakai `-Force` bila memang disengaja |

Mencari proses yang menahan port:

```powershell
Get-NetTCPConnection -LocalPort 8000 -State Listen |
    Select-Object LocalAddress, LocalPort, OwningProcess
Get-Process -Id <OwningProcess>
```

---

## 5. Logging

| Berkas | Isi |
|---|---|
| `storage/logs/server-runner.log` | Log runner: hasil preflight, alamat akses, health endpoint, PERINGATAN/ERROR, durasi hidup, status pelepasan port |
| `storage/logs/serve.log` | Log server: banner `Server running on [...]` dan daftar request yang dilayani |
| `storage/logs/laravel.log` | Log aplikasi (galat runtime, exception) |

Memantau log secara langsung:

```powershell
Get-Content storage\logs\server-runner.log -Tail 20 -Wait
```

Contoh isi `server-runner.log` saat start berhasil:

```
[2026-09-23 10:16:51] [INFO] === Koperasi Syariah - Server Runner ===
[2026-09-23 10:16:51] [INFO] PHP: 8.3.33 (C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe)
[2026-09-23 10:16:51] [INFO] Semua ekstensi wajib aktif: pdo_mysql, openssl, ...
[2026-09-23 10:16:51] [INFO] APP_KEY terisi.
[2026-09-23 10:16:51] [INFO] MySQL merespons di 127.0.0.1:3306.
[2026-09-23 10:16:52] [INFO] Semua pemeriksaan lolos. Menyalakan server...
[2026-09-23 10:16:52] [INFO] Alamat akses     : http://127.0.0.1:8000
[2026-09-23 10:16:52] [INFO] Health endpoint  : http://127.0.0.1:8000/up
```

---

## 6. Pengujian setelah Server Menyala

Health endpoint bawaan Laravel:

```powershell
Invoke-WebRequest -Uri http://127.0.0.1:8000/up -UseBasicParsing | Select-Object StatusCode
```

Uji endpoint utama:

```powershell
foreach ($u in @(
    'http://127.0.0.1:8000/up',
    'http://127.0.0.1:8000/',
    'http://127.0.0.1:8000/login',
    'http://127.0.0.1:8000/buka-rekening',
    'http://127.0.0.1:8000/api',
    'http://127.0.0.1:8000/api/deposito/products'
)) {
    try {
        $r = Invoke-WebRequest -Uri $u -UseBasicParsing -TimeoutSec 15
        "[{0}] {1} ({2} bytes)" -f $r.StatusCode, $u, $r.RawContentLength
    } catch { "[GAGAL] $u -> $($_.Exception.Message)" }
}
```

Hasil yang diharapkan (terverifikasi di mesin ini):

| URL | Status | Keterangan |
|---|---|---|
| `/up` | 200 | Health check Laravel |
| `/` | 200 | Halaman welcome |
| `/login` | 200 | Form login |
| `/buka-rekening` | 200 | Pendaftaran anggota publik |
| `/api` | 200 | JSON daftar modul |
| `/api/deposito/products` | 200 | Membuktikan komunikasi nyata dengan MySQL |
| `/rute-tidak-ada-xyz` | 404 | Penanganan 404 benar |
| `POST /login` tanpa CSRF | 419 | Proteksi CSRF aktif |

Verifikasi basis data dari sisi server:

```powershell
php artisan migrate:status
php artisan config:show app
```

---

## 7. Prosedur Pengujian (WAJIB: `optimize:clear` sebelum `test`)

> **Aturan mutlak.** Pada **setiap sesi pengujian**, `php artisan optimize:clear`
> **harus** dijalankan **sebelum** `php artisan test`. Tidak ada pengecualian.

### 7a. Cara resmi — satu perintah

```powershell
# dari root proyek
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

Meneruskan filter atau berkas uji tertentu:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Filter DenyutSinkronisasiRealtimeTest
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1 -Tests tests\Feature\DenyutSinkronisasiRealtimeTest.php
```

Skrip menjalankan tiga langkah berurutan dan **membatalkan** pengujian bila ada
langkah gerbang yang gagal:

| Langkah | Tindakan | Bila gagal |
|---|---|---|
| 1/3 | `php artisan optimize:clear` | Log ERROR, `exit 1`, pengujian **tidak** dijalankan |
| 2/3 | Verifikasi bukti: `bootstrap/cache/` bebas `config.php`, `routes-*.php`, `events.php` | Log ERROR, `exit 1` |
| 3/3 | `php artisan test` (+ `--filter=` / daftar berkas) | Meneruskan exit code pengujian |

Log lengkap tersimpan di `storage/logs/test-runner.log`.

### 7b. Mengapa ini wajib

`php artisan config:cache` merender seluruh konfigurasi ke
`bootstrap/cache/config.php`. Berkas itu **membekukan isi `.env`**, termasuk
`APP_KEY`. Ketika suite berjalan di atas cache semacam itu:

- `APP_KEY` yang terpakai sudah kedaluwarsa,
- token CSRF seluruh form tidak lagi cocok,
- **ratusan test gagal serentak dengan galat 419 CSRF.**

Kegagalan itu **bukan bug produk**, melainkan artefak cache yang tidak
teratur. Karena itu cache wajib direset pada setiap sesi pengujian.

### 7c. Validasi otomatis (dua lapis)

Prosedur di atas ditegakkan otomatis, bukan sekadar diimbau:

1. **`scripts/run-tests.ps1`** — gerbang 3 langkah di atas. Menjalankan
   pengujian tanpa pembersihan menjadi tidak mungkin lewat jalur resmi.
   Switch `-TanpaBersih` disediakan hanya untuk iterasi cepat di luar jalur
   resmi (tanpa perubahan `.env`/config).
2. **`tests/CreatesApplication.php`** — penjaga tingkat-suite yang berjalan
   sekali per proses. Ia memeriksa `bootstrap/cache/` dan `configurationIsCached()`
   sebelum suite dimulai, lalu melempar `RuntimeException` yang menyebut
   perintah perbaikannya. Penjaga ini menutup celah **jalur langsung**
   (`php artisan test` tanpa skrip).

Pesan galat yang muncul bila cache kotor:

```
Cache belum direset sebelum pengujian. Berkas tertinggal: config.php. Jalankan
'php artisan optimize:clear' lebih dahulu (lihat docs/RUNNING-SERVER.md,
bagian "Prosedur Pengujian").
```

> **Catatan penting.** `bootstrap/cache/packages.php` dan `services.php`
> **bukan** cache kotor. Keduanya adalah manifest paket hasil `package:discover`
> yang **sengaja dibiarkan** oleh `optimize:clear` dan dibutuhkan Laravel untuk
> booting. Kedua berkas ini dikecualikan eksplisit dari kedua lapis validasi.

### 7d. Prosedur manual (bila skrip belum memadai)

```powershell
php artisan optimize:clear   # WAJIB lebih dahulu
php artisan test
```

Mengabaikan langkah pertama akan langsung ditolak oleh penjaga di
`tests/CreatesApplication.php` — inilah perilaku yang diharapkan.

---

## 8. Catatan tentang Node.js / Vite

Proyek ini **tidak memakai `@vite`** (tidak ada `resources/js`, `resources/css`,
maupun `vite.config.js`; `public/` hanya berisi `.htaccess`, `index.php`,
`robots.txt`). Karena itu **Node.js tidak diperlukan untuk menjalankan server**.

Perintah di `package.json` (`npm run dev`, `npm run build`) hanya relevan bila
kelak aset frontend di-bundle dengan Vite. Jika itu terjadi, runner akan
memberi PERINGATAN dan Anda perlu menjalankan:

```powershell
npm install
npm run build
```

---

## 9. Menghentikan Server (Graceful Shutdown)

**Cara yang benar:** tekan `Ctrl+C` pada jendela terminal tempat server berjalan.

Runner menjalankan `php artisan serve` di **foreground** (satu proses dengan
runner), sehingga sinyal `SIGINT` dari `Ctrl+C` diteruskan langsung ke proses
PHP. Blok `finally` memastikan langkah berikut selalu dijalankan:

1. Menulis log penutup (`Server berhenti. Exit code: ... Durasi: ...`).
2. Menunggu sampai port benar-benar dilepas (maksimum ~3 detik).
3. Menulis status `Port <n> sudah dilepas - tidak ada proses anak yang tertinggal.`

Contoh log penutup:

```
[2026-09-23 10:17:04] [INFO] Server berhenti. Exit code: 0. Durasi: 00:00:12.
[2026-09-23 10:17:05] [INFO] Port 8000 sudah dilepas - tidak ada proses anak yang tertinggal.
```

### Bila server perlu dihentikan paksa dari terminal lain

```powershell
$c = Get-NetTCPConnection -LocalPort 8000 -State Listen
Stop-Process -Id $c[0].OwningProcess -Force
```

Hentikan juga proses `php artisan serve` yang mungkin menjadi induknya:

```powershell
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like '*artisan serve*' -or $_.CommandLine -like '*server.php*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```

> Peringatan: menghentikan proses secara paksa (`Stop-Process`) **tidak** memberi
> kesempatan runner menulis log penutup. Untuk penghentian bersih, selalu pakai
> `Ctrl+C` pada jendela runner.

---

## 10. Ringkasan Perintah Harian

```powershell
# 1. Nyalakan MySQL lewat Laragon (Start All), lalu dari root proyek:

# 2. Pasang/segarkan dependensi (sekali saja atau setelah pull)
composer install

# 3. Jalankan migrasi bila ada perubahan skema
php artisan migrate

# 4. Nyalakan server dengan preflight lengkap
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\start-server.ps1

# 5. Verifikasi
Invoke-WebRequest -Uri http://127.0.0.1:8000/up -UseBasicParsing

# 6. Jalankan pengujian (SATU jalur resmi: optimize:clear + test)
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1

# 7. Hentikan dengan Ctrl+C pada jendela tersebut
```

---

## 11. Pemecahan Masalah Cepat

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| `Port 8000 sudah digunakan oleh ...` | Server lama masih hidup | Pakai `-Port 8001`, atau hentikan PID yang disebut |
| `Tidak dapat terhubung ke MySQL ...` | Service MySQL mati | Laragon > Start All, atau `-SkipDbCheck` bila offline |
| `Folder vendor tidak lengkap` | Belum `composer install` | `composer install` |
| `APP_KEY kosong` | `.env` baru disalin | `php artisan key:generate` |
| Halaman tampil polos tanpa gaya | Aset Vite belum di-build (bila `@vite` dipakai) | `npm install; npm run build` |
| Perubahan `.env` tidak berefek | Config ter-cache | `php artisan config:clear` |
| Perubahan Blade tidak muncul | View ter-cache | `php artisan view:clear` |
| Test gagal massal dengan **419 CSRF** | Suite berjalan di atas `bootstrap/cache/config.php` (`config:cache` membekukan `APP_KEY`) | `php artisan optimize:clear`, lalu ulangi via `scripts\run-tests.ps1` (lihat bagian 7) |
| `Cache belum direset sebelum pengujian. Berkas tertinggal: ...` | `php artisan test` dijalankan langsung tanpa pembersihan | `php artisan optimize:clear` lebih dahulu, atau pakai `scripts\run-tests.ps1` |
| Peringatan cache `packages.php` / `services.php` | Manifest paket bawaan (`package:discover`) | **Bukan masalah** — keduanya memang tidak dihapus oleh `optimize:clear` |
