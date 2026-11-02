# Laporan Pemeliharaan & Pengelolaan Sistem Koperasi Syariah

**Tanggal:** 20 Oktober 2026
**Lingkup:** arsitektur, modul fungsional, basis data, server hosting, keamanan, pemantauan, pencadangan, dan pengujian
**Status akhir:** sistem operasional normal; 1 temuan kritis (penjadwal Windows tidak aktif) **sudah diperbaiki dan diverifikasi**

---

## Ringkasan Eksekutif

| Aspek | Kondisi | Bukti |
|---|---|---|
| Ketersediaan aplikasi | Normal | `/health` 200, `/readyz` 200 |
| Integritas akuntansi | Seimbang | 0 jurnal tidak balance; debet = kredit = Rp124.747.500,00 |
| Pengujian fungsional | Lulus | 1.763 passed, 5 skipped, 8.358 assertion |
| Pencadangan otomatis | Aktif | `db:cadangkan` setiap 01:00, retensi dari kebijakan `backup_retensi_hari` (default 14 hari) |
| Pemulihan data | Terbukti | 56/56 tabel, 0 selisih baris, saldo identik |
| Pemantauan berkala | Aktif | `denyut:pemeriksaan` setiap jam |
| Penjadwal Windows | Aktif | Task `KoperasiSyariah-Scheduler`, Last Result `0` |
| Kebocoran berkas sensitif | Tertutup | `.env` → 403, `composer.json` → 403, `storage/logs/*` → 403 |

---

## Langkah 1 — Audit Arsitektur, Modul, Basis Data, dan Server

### 1.1 Tumpukan teknologi

| Komponen | Versi |
|---|---|
| Laravel | 12.69.1 |
| PHP | 8.3.33 |
| MySQL | 8.4.3 (Laragon) |
| Apache | 2.4.68 |
| PHPUnit | 11.5.56 |

Virtual host `koperasi_syariah.test` sudah benar menunjuk `public/`. Akses kanonik sistem adalah **`http://koperasi_syariah.test`**.

### 1.2 Basis data

- **56 tabel**, ukuran total ±4,0 MB; migrasi pending **0**.
- Tabel terbesar: `pembiayaan` (58 baris), `pengajuan_pembiayaan` (8), `jurnal_detail` (188), `transaksi` (94), `jurnal` (91).
- Volume data masih sangat kecil → **tidak ada masalah performa kueri** saat ini.

### 1.3 Integritas data

| Pemeriksaan | Hasil |
|---|---|
| Jurnal tidak balance (`debet ≠ kredit`) | **0** |
| Total debet = total kredit | Rp124.747.500,00 |
| Detail jurnal tanpa induk | **0** |
| Total saldo seluruh `rekening` | Rp120.450.000,00 |

Catatan: pemeriksaan awal melaporkan "152 detail tanpa akun". Ini **false positive** — kolom `jurnal_detail.akun_id` memang NULL seluruhnya; sistem memakai `coa_id`. Tidak ada data yatim.

### 1.4 Anomali kinerja & struktur (belum kritis)

| Temuan | Dampak | Rekomendasi |
|---|---|---|
| ~~39 kolom berakhiran `_id` tanpa indeks~~ **SELESAI** — 18 indeks ditambahkan lewat migrasi `2026_10_20_160000_add_indexes_for_relation_lookups`; sisa 21 kolom sengaja tidak diindeks (tabel 1 baris, kolom kedua indeks polimorfik, kolom rantai hash) | — | Selesai 20/10/2026 |
| ~~OPcache tidak aktif~~ **SELESAI** — `zend_extension=opcache` + `opcache.enable=1` di `php.ini`, Apache di-restart | Waktu respons `/` turun dari ±613 ms menjadi ±45 ms; `/health` dari ±454 ms menjadi ±44 ms | Selesai 20/10/2026 |
| `config:cache` & `route:cache` belum dibuat | Boot sedikit lebih lambat | Jalankan pada saat deploy produksi |

---

## Langkah 2 — Protokol Keamanan

### 2.1 Yang sudah diterapkan

1. **Penutupan kebocoran berkas sensitif.** Akar masalah: `00-default.conf` Laragon memetakan `C:/laragon/www` pada `_default_:80`, sehingga `http://localhost/koperasi_syariah/` menampilkan *directory listing* berisi `.env`, `composer.json`, `artisan`, dan `storage/logs/laravel.log` (71 MB).
   Mitigasi terpasang: `.htaccess` di root proyek yang menolak `.env`, `.git`, direktori internal (`app`, `config`, `database`, `storage`, `vendor`, dst.), serta berkas build. Seluruh direktif dibungkus `<IfModule>` agar tidak memicu HTTP 500 bila modul Apache tidak tersedia.

   **Hasil uji:** `.env` → **403**, `composer.json` → **403**, `storage/logs/laravel.log` → **403**. Akses vhost kanonik `/.env` → **404**.

2. **Header keamanan** dikelola middleware `KeamananHeader` (bukan `.htaccess`), sehingga konsisten untuk semua respons.
3. **RBAC API terverifikasi** (`routes/api.php`):
   - Baca master data & buku besar: `admin, manajer, pengurus, teller, ao, dps` (`ao`/`dps` read-only — pemisahan tugas).
   - Tulis jurnal/transaksi: `admin, manajer, pengurus, teller`.
   - Tulis kontrak pembiayaan: `admin, manajer, pengurus` (teller dilarang).
   - Pencairan deposito dipercepat: **hanya `teller`**.
   - Distribusi bagi hasil: **hanya `admin, manajer, pengurus`**.
   - Mobile: `auth:sanctum`.
4. **Prinsip syariah pada data**: tidak ada bunga/riba dalam skema bagi hasil; pemisahan wewenang AO/DPS dari pencatatan jurnal mencegah manipulasi satu orang.

### 2.2 Risiko yang masih terbuka (wajib ditutup sebelum produksi)

| No | Temuan | Risiko | Tindakan |
|---|---|---|---|
| K-1 | `APP_DEBUG=true`, `APP_ENV=local` | Stack trace + kredensial DB tampil ke pengguna saat error | Set `APP_ENV=production`, `APP_DEBUG=false` |
| K-2 | `DB_USERNAME=root`, `DB_PASSWORD` kosong | Siapa pun di host dapat mengakses seluruh data | Buat user MySQL khusus dengan hak terbatas + kata sandi kuat |
| K-3 | Cadangan tidak dienkripsi, masih di host yang sama | Pencurian media / ransomware menghapus DB **dan** cadangan | Enkripsi (age/GPG) + salinan off-site |
| K-4 | `DEMO_LOGIN_ENABLED=true` | Pintu masuk tanpa kredensial nyata | Matikan di produksi |
| K-5 | `csp_strict` belum dapat diaktifkan (59 blok `<script>` inline, hanya 2 ber-`csp_nonce`) | Risiko XSS | Migrasikan script inline ke bundel sebelum mengaktifkan CSP ketat |

---

## Langkah 3 — Proses Bisnis Inti

| Proses | Status | Keterangan |
|---|---|---|
| Pendaftaran anggota | Normal | Teruji pada suite otomatis |
| Pengelolaan tabungan (pokok, wajib, sukarela) | Normal | Saldo rekening Rp120.450.000, konsisten dengan jurnal |
| Pembiayaan syariah (akad, angsuran, autodebet) | Normal | `angsuran:run-autodebet` terjadwal 07:00 |
| Perhitungan bagi hasil | Normal | `deposito:bagi-hasil` terjadwal tanggal 1 pukul 00:30 |
| Laporan keuangan | Normal | Jurnal seimbang; neraca dapat direkonstruksi |
| Pengajuan pembiayaan | Normal | RBAC pembatasan kontrak berlapis |

**Temuan akuntansi yang masih perlu diselesaikan (belum diubah karena menyentuh nilai uang):**

| No | Temuan | Nilai | Prioritas |
|---|---|---|---|
| A-1 | Subledger simpanan sukarela vs GL 2-1001 | selisih **Rp21.500.000** | P1 |
| A-2 | "Deposito hantu": `depositos` 0 baris, GL 2-1003 Rp15.000.000 | **Rp15.000.000** | P1 |
| A-3 | Total klaim 4 jenis simpanan vs Kas Teller | Rp120.450.000 vs Rp49.297.500 (defisit Rp71.152.500) | P1 (likuiditas) |
| A-4 | Rekening berstatus `Jatuh Tempo` tidak ditutup saat anggota keluar | — | P2 |
| A-5 | GL piutang `1-2002/1-2003/1-2004` tanpa `anggota_id` | — | P2 |

---

## Langkah 4 — Pemantauan Real-Time, Pencadangan, dan Pemulihan

### 4.1 Pemantauan

**Command baru `denyut:pemeriksaan`** memeriksa tiga indikator ketersediaan dalam satu eksekusi:

1. Koneksi basis data (`SELECT 1`).
2. Integritas jurnal (`ABS(SUM(debet) − SUM(kredit)) > 0.005`).
3. Kesegaran berkas cadangan (ambang `--toleransi-cadangan-jam`, default 26 jam).

Keluar dengan **exit code 1** bila ada indikator gagal → dapat dikonsumsi Uptime Kuma / Zabbix / Task Scheduler. Kegagalan dicatat ke kanal `denyut` dengan pembatas 1 log/jam/indikator agar tidak membanjiri log.

**Jadwal aktif (12 perintah):**

| Waktu | Perintah |
|---|---|
| Setiap jam | `denyut:pemeriksaan` |
| 00:01 | `deposito:process-maturity`, `mandatory-savings:process-daily` |
| 01:00 | **`db:cadangkan --simpan-hari={backup_retensi_hari}`** |
| 02:30 | `log:purge-kedaluwarsa --force` |
| 03:00 | `jurnal:rekonsiliasi` |
| 03:10 | `jurnal:deteksi-orphan --contoh=20` |
| 03:20 | `denyut:kesehatan` |
| 07:00 | `angsuran:run-autodebet` |
| 07:30 | `ckpn:hitung-penyesuaian` |
| Tgl 1, 00:01 | `wajib-dues:process` |
| Tgl 1, 00:30 | `deposito:bagi-hasil` |

**Temuan kritis yang diperbaiki:** tidak ada Windows Scheduled Task yang menjalankan `schedule:run`, sehingga seluruh 12 jadwal di atas **tidak akan pernah berjalan otomatis**. Sudah didaftarkan:

```
schtasks /create /tn "KoperasiSyariah-Scheduler" ^
  /tr "\"C:\Windows\System32\wscript.exe\" //B //Nologo \"C:\laragon\www\koperasi_syariah\scripts\scheduler-hidden.vbs\" \"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe\"" ^
  /sc minute /mo 1 /f
```

**Kenapa `wscript.exe` + `scripts/scheduler-hidden.vbs`, bukan `php.exe` langsung:**

1. `php.exe` dijalankan langsung oleh Task Scheduler (`LogonType: Interactive`) → Windows membuka jendela konsol hitam setiap kali task berjalan (tiap menit).
2. Mengganti ke `php-win.exe` saja **tidak cukup**. `php-win.exe` adalah build GUI tanpa konsol, sehingga setiap proses anak yang console-based memicu alokasi konsol **baru** (jendela muncul-hilang). Ada dua pemicu nyata:
   - **Tiap menit** — Symfony Console (`vendor/symfony/console/Terminal.php::hasSttyAvailable`) memanggil `shell_exec('stty 2> NUL')` untuk mengukur lebar terminal; `shell_exec` dieksekusi lewat `cmd.exe`.
   - **Setiap 01:00** — command `db:cadangkan` menjalankan `mysqldump` lewat Symfony Process → `cmd.exe /V:ON /E:ON /D /C mysqldump ...` + `conhost.exe`. Inilah jendela yang muncul "jarang sekali".
3. Solusi di `scheduler-hidden.vbs`:
   - Menjalankan **`php.exe` (build konsol) dengan window style 0 (SW_HIDE)**. Windows membuat satu konsol untuk `php.exe` dalam keadaan tersembunyi; seluruh anak-cucunya (`cmd.exe`, `stty.exe`, `mysqldump.exe`) **mewarisi** konsol tersembunyi itu sehingga tidak pernah membuat jendela baru.
   - Menyetel `COLUMNS`/`LINES` agar `Terminal::getWidth()`/`getHeight()` tidak memanggil `stty` sama sekali.

Bukti perbaikan (perekaman WMI `__InstanceCreationEvent`):

```
sebelum (php-win.exe): php-win.exe -> cmd.exe /s /c "stty 2> NUL" -> conhost.exe        (jendela, tiap menit)
                       php-win.exe -> cmd.exe /D /C mysqldump ... -> conhost.exe         (jendela, 01:00)
sesudah (php.exe SW_HIDE): wscript -> php.exe -> conhost.exe (tersembunyi)
                           cmd.exe /D /C mysqldump ... (tanpa conhost, mewarisi konsol tersembunyi)
```

**Verifikasi:** `Status: Ready`, `Last Run Time: 20/10/2026 22:25:01`, **`Last Result: 0`**, `NumberOfMissedRuns: 0`, 12 jadwal utuh (`php artisan schedule:list`).

### 4.2 Pencadangan

- Perintah: `php artisan db:cadangkan --simpan-hari={backup_retensi_hari}`
- Retensi: dibaca dari parameter kebijakan `backup_retensi_hari` (menu **Kebijakan Koperasi → Pemeliharaan & Cadangan Basis Data**), default 14 hari, dibatasi 1–365 hari. Scheduler membaca nilai ini setiap `schedule:run`, sehingga perubahan langsung berlaku tanpa mengubah kode. Pemanggilan manual tetap dapat memakai `--simpan-hari=N`.
- Mekanisme: `mysqldump --single-transaction --quick --routines` dialirkan langsung ke gzip (hemat memori), kredensial lewat `MYSQL_PWD`, timeout 1800 detik, berkas dihapus bila gagal, kegagalan dicatat `CRITICAL`.
- Lokasi: `storage/app/backups/*.sql.gz`, izin `0600`, retensi mengikuti kebijakan `backup_retensi_hari`.
- Berkas terakhir: `koperasi_syariah-20261020-150717.sql.gz` (43,6 KB).

### 4.3 Pemulihan (terbukti end-to-end)

Skrip `scripts/restore_verify.php` memulihkan cadangan ke database sementara lalu membandingkan jumlah baris tiap tabel dengan sumber.

Hasil uji terakhir:

```
Tabel sumber = 56, tabel pulih = 56, tabel berbeda = 0
Jurnal tidak balance pada hasil pulih = 0
Total saldo rekening pulih = 120450000.00
EXIT=0
```

Database uji `koperasi_syariah_uji_pulih` sudah dihapus setelah verifikasi.

### 4.4 Proyeksi 99,5% uptime

99,5% per bulan = maksimum **±3 jam 39 menit** henti. Dengan pemantauan tiap jam, gangguan terdeteksi maksimal 60 menit setelah terjadi; dengan cadangan harian, **RPO ≤ 24 jam**. Target ini hanya dapat **dijamin** bila:

- host memiliki SLA (bukan Laragon di workstation),
- pemantauan eksternal (Uptime Kuma) memantau `/health` dan `/readyz` dari luar jaringan,
- notifikasi kegagalan diteruskan ke kanal yang dibaca operator (email/WhatsApp/Telegram).

---

## Langkah 5 — Pengujian Fungsional & Keandalan

### 5.1 Suite penuh

```
Tests:    5 skipped, 1763 passed (8358 assertions)
Duration: 320.85s
```

Seluruh modul lulus. Peringatan yang muncul hanya deprecation metadata doc-comment PHPUnit 12 pada tiga berkas uji — bukan kegagalan.

### 5.2 Suite pemeliharaan (baru)

`tests/Feature/PemeliharaanSistemTest.php` — **5 passed (14 assertions)**:

1. Command cadangan terdaftar dengan opsi retensi.
2. Cadangan menghasilkan tepat satu berkas `.sql.gz` yang dapat dibaca dan berisi `CREATE TABLE`.
3. Cadangan menghapus berkas lebih tua dari batas retensi.
4. Pemantauan melaporkan seluruh indikator sehat.
5. Pemantauan gagal (exit 1) bila cadangan kedaluwarsa.

Catatan teknis: opsi command pada test harus dikirim sebagai **array** (`['--tujuan' => $path]`), bukan string gabungan, karena path Windows mengandung backslash yang tidak terurai dengan benar.

---

## Perubahan Berkas

| Berkas | Jenis | Keterangan |
|---|---|---|
| `.htaccess` (root proyek) | Baru | Menolak akses `.env`, `.git`, direktori internal, berkas build |
| `app/Console/Commands/PeriksaDenyutOperasional.php` | Baru | Command `denyut:pemeriksaan` (3 indikator) |
| `routes/console.php` | Diubah | Jadwal `denyut:pemeriksaan` tiap jam |
| `tests/Feature/PemeliharaanSistemTest.php` | Baru | 5 test pemeliharaan |
| `scripts/restore_verify.php` | Baru | Verifikasi pemulihan end-to-end |
| Windows Task `KoperasiSyariah-Scheduler` | Baru | Menjalankan `schedule:run` tiap menit |

---

## Rekomendasi Berurutan

**P1 — sebelum produksi**
1. `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_LOGIN_ENABLED=false`.
2. User MySQL khusus (bukan `root`) dengan kata sandi kuat.
3. Enkripsi cadangan + salinan off-site.
4. Rekonsiliasi A-1 (Rp21.500.000) dan A-2 (Rp15.000.000); tambahkan *reconciliation gate* `rekening` vs GL.
5. Pasang pemantauan eksternal `/health` & `/readyz` + notifikasi.

**P2 — dalam 30 hari**
6. ~~Indeks untuk 36 kolom `_id`.~~ **Selesai 20/10/2026** — 18 indeks baru, 21 kolom sisanya sengaja dilewati.
7. ~~Aktifkan OPcache;~~ **Selesai 20/10/2026.** Sisa: `config:cache` + `route:cache` saat deploy.
8. Tutup rekening `Jatuh Tempo` pada proses exit; isi `anggota_id` pada GL piutang.
9. Uji pemulihan terjadwal (bulanan) memakai `scripts/restore_verify.php`.

**P3 — perbaikan berkelanjutan**
10. Migrasikan script inline ke bundel, lalu aktifkan CSP ketat.
11. Audit log akses data pribadi anggota (UU PDP).
