# Panduan Deploy Produksi — Sistem Informasi Koperasi Syariah

**Status sistem:** 1120 test / 5139 assertions hijau · audit kepatuhan PSAK LULUS · siap produksi
**Kebutuhan minimum:** PHP ≥ 8.2 (diuji 8.3.33), MySQL 8, Composer 2, web server (Nginx/Apache)

> **Catatan penting:** `deploy_to_remote` di lingkungan ini hanya mendukung
> **Vercel**, yang **tidak kompatibel** dengan Laravel 12 + MySQL (Vercel
> *serverless* tanpa runtime PHP/MySQL persisten). Karena itu deploy produksi
> dilakukan **manual** ke VPS/cPanel/hosting PHP+MySQL mengikuti langkah di bawah.

**Riwayat pembaruan dokumen:**

| Tanggal | Perubahan |
|---|---|
| — | Versi awal (955 test / 4629 assertions) |
| 2026-09-30 | Status regresi diperbarui ke **1120 test / 5139 assertions**; ditambahkan **§0 Prasyarat Go-Live & Gerbang Rilis**, variabel keamanan pada `.env`, gerbang kepatuhan PSAK pada §7, dan lembar tanda tangan pada §10 |

---

## 0. Prasyarat Go-Live & Gerbang Rilis

> Bagian ini **wajib dibaca sebelum** menjalankan instalasi di §4. Tujuannya agar
> rilis produksi tidak membawa serta dua item terbuka yang telah dilaporkan pada
> Tahap 12 (dokumen `docs/TAHAP12-R6-DOKUMENTASI-KEPATUHAN.md` §4).

### 0.1 Item terbuka yang perlu keputusan Pengurus/DPS

| # | Item | Dampak bila dibiarkan | Keputusan yang diminta |
|---|---|---|---|
| 1 | **Reklasifikasi saldo legacy akun `2-1002`** (Simpanan Wajib disajikan sebagai *Kewajiban*) sebesar **Rp 500.000,00** ke akun kanonik `3-1002` (*Modal Anggota*, PSAK 101 ¶31) | Neraca menyajikan sebagian Simpanan Wajib pada pos Kewajiban, bukan Modal Anggota | Setujui jurnal reklasifikasi `DEBET 2-1002 500.000 → KREDIT 3-1002 500.000` **sebelum** basis data lama dipindahkan ke produksi. Rincian: `docs/TAHAP12-R2-PENYATUAN-AKUN-COA.md` §2.3 |
| 2 | **Status formal alur penarikan Simpanan Wajib** — sejak audit kepatuhan Gelombang 3, alur penarikan rutin **DIKUNCI PERMANEN** (PSAK 101 ¶31: Simpanan Wajib = Modal Anggota). Saklar `simpanan_wajib_boleh_ditarik` **tidak lagi dapat membuka** penarikan; rute penarikan selalu ditolak + audit log `PENARIKAN_MODAL_DIBLOKIR` | Tidak ada — perilaku aman sudah ditegakkan permanen oleh kode, bukan oleh konfigurasi | **Tidak ada keputusan yang diperlukan.** Satu-satunya jalur sah pengembalian Modal Anggota adalah modul `member_terminations` (`ExitKeanggotaanService`). Bila kebijakan berubah di masa depan, diperlukan perubahan kode + persetujuan DPS/Pengurus. Rincian: `docs/TAHAP12-R3-SIKLUS-PENARIKAN.md` (catatan SUPERSEDED) + `tests/Feature/SimpananWajibPenarikanTest.php` |

> **Untuk pemasangan baru (basis data kosong):** item 1 **tidak berlaku** — saldo
> legacy hanya ada pada basis data yang sudah berisi data lama. **Satu-satunya item
> terbuka yang tersisa adalah reklasifikasi `2-1002`.** Penguncian penarikan
> Simpanan Wajib sudah permanen di level kode, bukan bergantung pada konfigurasi.

### 0.2 Empat Gerbang Rilis (G1–G4)

Setiap rilis ke produksi **wajib melewati keempat gerbang** berikut secara berurutan.

| Gerbang | Nama | Perintah / Bukti | Kriteria Lulus |
|---|---|---|---|
| **G1** | Cache bersih sebelum uji | `powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1` | Skrip menjalankan `optimize:clear` lebih dahulu; bila cache tidak bersih, skrip membatalkan pengujian (mencegah kegagalan massal 419 CSRF) |
| **G2** | Backup + baseline saldo | `mysqldump` + catat saldo akun `2-1002` dan `3-1002` | Backup terverifikasi dapat dipulihkan; baseline saldo tercatat sebagai pembanding pasca-rilis |
| **G3** | Regresi + kepatuhan PSAK | `php artisan test` (via G1) **dan** `php artisan psak:validasi-simpanan-wajib` | Regresi **hijau tanpa kegagalan**; audit kepatuhan berakhir `✔ LULUS` dengan **exit code 0** |
| **G4** | Larangan mengubah jurnal historis | Pemeriksaan prosedur | Tidak ada `UPDATE`/`DELETE` pada tabel `jurnal`, `jurnal_detail`, dan `audit_pii` selama proses rilis |

> **Mengapa G3 memakai `psak:validasi-simpanan-wajib`:** perintah ini adalah
> gerbang kepatuhan otomatis (7 kategori pelanggaran) yang memeriksa penyajian
> Simpanan Wajib sebagai Modal Anggota, keseimbangan jurnal, jejak audit
> tagihan↔transaksi↔jurnal, serta saldo modal negatif. Ia bersifat **read-only**
> sehingga aman dijalankan pada basis data produksi.

---

## 1. Ringkasan Alur

```
[1] Siapkan server (PHP, MySQL, web server)
[2] Unggah kode & install dependensi (composer install --no-dev)
[3] Konfigurasi .env produksi  (lihat bagian 3)
[4] Generate key, migrate, seed data master
[5] Cache config/route/view
[6] Set hak akses (storage/, bootstrap/cache/)
[7] Daftarkan cron scheduler
[8] Uji pasca-deploy (bagian 7)
```

---

## 2. Persiapan Server

### 2.1 Paket wajib
- PHP 8.2/8.3 dengan ekstensi: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
  `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd`/`imagick` (PDF bilyet/laporan).
- MySQL 8 (atau MariaDB 10.6+).
- Composer 2.
- Nginx atau Apache (dengan `mod_rewrite`).

### 2.2 Document root
Arahkan **document root ke folder `public/`**, bukan ke root proyek. Ini mencegah
`.env`, `storage/`, dan kode sumber dapat diakses langsung dari web.

---

## 3. Konfigurasi `.env` Produksi

Buat `.env` produksi dari `.env.example`, lalu ubah nilai-nilai berikut:

```dotenv
APP_NAME="Koperasi Syariah"
APP_ENV=production          # WAJIB produksi
APP_KEY=                    # isi lewat: php artisan key:generate
APP_DEBUG=false             # WAJIB false (jangan buka stack trace)
APP_URL=https://koperasi.example.id

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=koperasi_syariah
DB_USERNAME=koperasi_user   # JANGAN root
DB_PASSWORD=<password kuat>

CACHE_DRIVER=file           # atau redis bila tersedia
SESSION_DRIVER=file         # atau redis
QUEUE_CONNECTION=sync

# WAJIB: kosongkan kredensial demo di produksi
DEMO_LOGIN_ENABLED=
DEMO_USER_PASSWORD=

MAIL_MAILER=smtp            # isi kredensial SMTP nyata untuk notifikasi
```

**Variabel keamanan (UU PDP No. 27/2022) — wajib disetel eksplisit di produksi.**
Ketentuan lengkap: `docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md`.

```dotenv
# Sesi hanya dikirim melalui HTTPS + payload sesi terenkripsi.
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

# Header keamanan: CSP ditegakkan (bukan report-only), HSTS aktif.
KEAMANAN_CSP_AKTIF=true
KEAMANAN_CSP_ENFORCE=true
KEAMANAN_HSTS_AKTIF=true
KEAMANAN_HSTS_MAX_AGE=31536000

# Kebijakan password produksi (12 karakter + huruf besar + angka + simbol).
KEAMANAN_PASSWORD_MIN=12
KEAMANAN_PASSWORD_HURUF_BESAR=true
KEAMANAN_PASSWORD_ANGKA=true
KEAMANAN_PASSWORD_SIMBOL=true

# Jejak audit perubahan data pribadi (append-only + rantai hash) — biarkan aktif.
KEAMANAN_AUDIT_PII=true

# Bila aplikasi berada di belakang proxy/load balancer terkelola.
# Kosongkan bila diakses langsung.
TRUSTED_PROXIES=
```

> **Urutan pemasangan header keamanan:** aktifkan `KEAMANAN_CSP_ENFORCE=true`
> **setelah** seluruh aset statis dipastikan sesuai kebijakan. Bila ingin
> aktivasi bertahap, mulai dari `false` (mode *report-only*), pantau laporan
> pelanggaran di log, baru ubah ke `true`.

**Keamanan:** jangan commit `.env`; pastikan permission `600` dan pemilik = user web server.

---

## 4. Langkah Instalasi

```bash
# [2] Unggah kode (git clone atau rsync) lalu masuk ke direktori proyek
composer install --no-dev --optimize-autoloader

# [4] Kunci aplikasi & siapkan basis data
php artisan key:generate
php artisan migrate --force

# Seed data master (CoA, jenis simpanan, produk, akun, kebijakan awal).
# Jalankan HANYA pada pemasangan baru; jangan pada basis data berisi data nyata.
# PENTING: `db:seed` tanpa --class menjalankan DatabaseSeeder yang MEMUAT DATA
# DEMO. Di produksi, panggil seeder master satu per satu (lihat daftar di bawah).
php artisan db:seed --force --class=AkunSeeder
php artisan db:seed --force --class=CoaSeeder
php artisan db:seed --force --class=DepositoProductSeeder
php artisan db:seed --force --class=JenisSimpananSeeder
php artisan db:seed --force --class=HariLiburSeeder
php artisan db:seed --force --class=LimitTransaksiSeeder

# [5] Cache produksi
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Mengapa tidak `php artisan db:seed` saja?** `DatabaseSeeder` memanggil
> `AnggotaSeeder`, `RekeningSeeder`, `DemoDataSeeder`, `AdamRenataSeeder`, dan
> `ModulSimpananSeeder` — semuanya berisi **data contoh** (anggota fiktif seperti
> "Budi Santoso", transaksi simulasi). Menjalankannya di produksi akan mengotori
> buku besar dengan data palsu. Karena itu panggil seeder master secara eksplisit
> dengan `--class` seperti di atas.

**Seeder master (aman untuk produksi):**

| Seeder | Isi |
|---|---|
| `AkunSeeder` | Bagan akun internal (`akuns`) |
| `CoaSeeder` | Bagan Akun standar PSAK 101–111 beserta parameter nisbah/margin |
| `DepositoProductSeeder` | Produk deposito Mudharabah (tenor, nisbah) |
| `JenisSimpananSeeder` | Master jenis simpanan (Pokok/Wajib/Sukarela/Berjangka) + pemetaan akun |
| `HariLiburSeeder` | Kalender hari libur nasional untuk penjadwalan angsuran |
| `LimitTransaksiSeeder` | Limit transaksi per peran (teller/ao/manajer/pengurus) |

**Seeder demo (JANGAN dijalankan di produksi):**
`UserSeeder` (membuat akun demo `@koperasi.test`; otomatis dilewati bila
`APP_ENV=production`), `AnggotaSeeder`, `RekeningSeeder`, `DemoDataSeeder`,
`AdamRenataSeeder`, `ModulSimpananSeeder`.


---

## 5. Hak Akses (Permission)

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 6. Scheduler (WAJIB untuk keuangan otomatis)

Daftarkan **satu cron** yang memanggil Laravel scheduler setiap menit:

```cron
* * * * * cd /path/ke/proyek && php artisan schedule:run >> /dev/null 2>&1
```

Proses yang otomatis berjalan (verifikasi: `php artisan schedule:list`):

| Jam (WIB) | Command | Fungsi |
|---|---|---|
| 00:01 harian | `deposito:process-maturity` | Deposito jatuh tempo (ARO Dinamis) |
| 00:01 harian | `mandatory-savings:process-daily` | Penagihan Simpanan Wajib bulanan |
| 00:01 harian | `ckpn:hitung-penyesuaian` | CKPN PSAK 71 |
| 07:00 harian | `angsuran:run-autodebet` | Autodebet angsuran pembiayaan |
| 00:30 tgl-1 | `deposito:bagi-hasil` | Bagi hasil deposito dari laba riil |

> Tanpa cron ini, penagihan simpanan wajib, bagi hasil, dan CKPN **tidak berjalan
> otomatis**.

---

## 7. Verifikasi Pasca-Deploy

### 7.1 Pemeriksaan teknis

```bash
php artisan about                 # cek Environment=production, Debug=OFF
php artisan migrate:status        # tidak boleh ada "Pending"
php artisan schedule:list         # keenam proses harus tampil
php artisan route:list --path=laporan   # sanity rute laporan
```

### 7.2 Gerbang kepatuhan PSAK (G3 — WAJIB)

```bash
php artisan psak:validasi-simpanan-wajib
```

Perintah ini bersifat **read-only** dan memeriksa 7 kategori pelanggaran
(penyajian Simpanan Wajib sebagai Modal Anggota, tagihan lunas tanpa jurnal,
keseimbangan jurnal, kesesuaian nominal tagihan↔jurnal, duplikasi periode,
saldo modal negatif). **Rilis dinyatakan lulus hanya bila keluaran berakhir
`✔ LULUS` dengan exit code `0`.** Bila ada pelanggaran, perintah keluar dengan
exit code `1`; tangani temuan terlebih dahulu sebelum membuka akses pengguna.

Versi singkat keluaran yang diharapkan:

```
  Akun kanonik (titik resolusi) : 3-1002
  Akun modal anggota terdaftar  : 4
  Toleransi selisih jurnal      : 0.01
  [7 kategori pelanggaran] ...... 0
✔ LULUS — tidak ditemukan pelanggaran kepatuhan PSAK 101/108.
```

> **Catatan basis data lama:** bila produksi memakai basis data berisi data
> historis, jalankan pemeriksaan **sebelum** pemindahan (baseline) dan **sesudah**
> pemindahan (verifikasi). Selisih temuan antara kedua waktu inilah bukti bahwa
> proses pemindahan tidak merusak jejak akuntansi. Perhatikan pula item terbuka
> §0.1 mengenai saldo legacy akun `2-1002`.

### 7.3 Uji fungsional minimal (manual)

1. Login sebagai tiap peran (admin, teller, manajer, pengurus, ao, dps, anggota).
2. Portal anggota (`/nasabah/*`) harus dapat dibuka — ini pernah menjadi bug
   produksi (`role:nasabah` tidak valid) dan telah diperbaiki.
3. Buat satu transaksi teller → pastikan jurnal terbentuk dan neraca seimbang.
4. Jalankan `php artisan mandatory-savings:process-daily --dry-run` → periksa
   rencana tagihan tanpa mengubah data.
5. Buka laporan Neraca/Laba-Rugi dan pastikan angka konsisten.
6. Ubah satu parameter kebijakan Simpanan Wajib (mis. tanggal jatuh tempo) dari
   menu Pengurus → pastikan perubahan berlaku **tanpa** menyentuh kode.

### 7.4 Pemeriksaan berkala (harian/mingguan)

Jalankan gerbang kepatuhan `php artisan psak:validasi-simpanan-wajib` sebagai
bagian dari pemeriksaan rutin (mis. cron harian setelah
`mandatory-savings:process-daily`), dan catat hasilnya ke log operasional.
Ini menjadikan kepatuhan PSAK terpantau berkelanjutan, bukan hanya saat rilis.

---

## 7A. Membuat Akun Pengguna Produksi (PENTING)

Karena `UserSeeder` sengaja **tidak** dijalankan di produksi, basis data produksi
**tidak memiliki akun login sama sekali**. Buat akun nyata (Manajer/Pengurus/DPS)
segera setelah `migrate` selesai:

```bash
php artisan tinker --execute="
\App\Models\User::updateOrCreate(
  ['email' => 'manajer@koperasi-anda.id'],
  [
    'name'              => 'Manajer Koperasi',
    'password'          => \Illuminate\Support\Facades\Hash::make('PASSWORD-KUAT-DI-SINI'),
    'role'              => 'manajer',
    'is_active'         => true,
    'email_verified_at' => now(),
  ]
);"
```

Peran yang tersedia (hierarki): `dps`, `pengurus`, `manajer`, `ao`, `teller`.
Buat minimal satu akun `manajer` dan satu `pengurus` agar operasional dapat
dimulai. **Jangan** membuat akun `anggota` — anggota adalah data, bukan pengguna
login. Ganti password setelah login pertama dan aktifkan MFA bila tersedia.

---

## 8. Pemeliharaan Rutin


| Frekuensi | Tindakan |
|---|---|
| Harian | Periksa `storage/logs/` & log scheduler (`deposito-jatuh-tempo.log`, `bagi-hasil-deposito.log`) |
| Harian | Backup basis data (mysqldump) ke lokasi terpisah |
| Mingguan | Tinjau audit trail (login, penolakan akses laporan, approval) |
| Bulanan | Rekonsiliasi buku besar vs rekening koran; verifikasi bagi hasil |
| Tahunan | Tutup buku (periode akuntansi), hitung & alokasi SHU, lepas penahanan SHU anggota yang lunas |

**Setiap kali mengubah `.env` atau config:** jalankan ulang
`php artisan config:cache`.  
**Setiap deploy kode baru:** jalankan `php artisan migrate --force`,
`php artisan config:cache route:cache view:cache`, lalu `queue:restart` bila memakai queue.

---

## 9. Rollback

1. Simpan rilis sebelumnya (mis. `releases/YYYYMMDD-HHMM`) agar dapat ditukar cepat.
2. Migrasi bersifat maju; untuk rollback gunakan `php artisan migrate:rollback --step=1`
   **hanya bila** migrasi terbaru belum menulis data produksi penting.
3. Setelah rollback kode: `php artisan optimize:clear` lalu cache ulang.

---

## 10. Daftar Periksa Keamanan & Go-Live Produksi

**Keamanan (UU PDP No. 27/2022):**
- [ ] `APP_ENV=production` dan `APP_DEBUG=false`
- [ ] `APP_KEY` terisi (unik per instalasi)
- [ ] `DEMO_LOGIN_ENABLED` dan `DEMO_USER_PASSWORD` **kosong**
- [ ] Kredensial DB bukan `root`, password kuat
- [ ] Document root mengarah ke `public/`
- [ ] `.env` tidak dapat diakses dari web (`600`, di luar web root)
- [ ] HTTPS aktif (sertifikat TLS)
- [ ] `SESSION_ENCRYPT=true` dan `SESSION_SECURE_COOKIE=true`
- [ ] `KEAMANAN_CSP_ENFORCE=true` dan `KEAMANAN_HSTS_AKTIF=true`
- [ ] `KEAMANAN_PASSWORD_MIN=12` + syarat huruf besar/angka/simbol
- [ ] `KEAMANAN_AUDIT_PII=true` (jejak audit data pribadi)
- [ ] Folder `storage/` & `bootstrap/cache/` writable, sisanya read-only
- [ ] Backup otomatis terjadwal & teruji restore
- [ ] Cron `schedule:run` terpasang

**Kepatuhan & operasional (Tahap 12):**
- [ ] Minimal satu akun `manajer` dan satu `pengurus` nyata telah dibuat (§7A)
- [ ] Seeder **master** dijalankan satu per satu; seeder demo **tidak** dijalankan
- [ ] Gerbang **G3** lulus: `php artisan psak:validasi-simpanan-wajib` → `✔ LULUS` (exit code 0)
- [ ] Item terbuka §0.1 telah diputuskan Pengurus/DPS (reklasifikasi `2-1002`; status penarikan sudah permanen di kode)

### 10.1 Lembar Tanda Tangan Go-Live

| Peran | Nama | Tanggal | Tanda Tangan |
|---|---|---|---|
| Manajer (penanggung jawab teknis) | | | |
| Pengurus (penanggung jawab operasional) | | | |
| Dewan Pengawas Syariah (kepatuhan PSAK/syariah) | | | |

> Lembar ini adalah bukti formal bahwa sistem telah diverifikasi memenuhi standar
> koperasi syariah (PSAK 101–111) sebelum melayani transaksi anggota secara nyata.
