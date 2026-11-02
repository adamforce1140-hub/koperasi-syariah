# TAHAP 10 — KEAMANAN DATA (KEPATUHAN UU PDP No. 27/2022)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Lingkup:** Perlindungan data pribadi (PII) anggota & pengajuan rekening, keamanan transportasi HTTP, keamanan sesi, kebijakan password.
**Dasar hukum:** UU No. 27/2022 tentang Pelindungan Data Pribadi (Pasal 16, 35, 39, 40) — pelengkap kewajiban syariah (PSAK 101–111) dan kerahasiaan data nasabah koperasi syariah.

---

## 1. Ringkasan untuk Pemangku Kepentingan

Sistem koperasi syariah menyimpan data pribadi anggota: NIK, nama lengkap, tempat/tanggal lahir, alamat, nomor telepon, email, pekerjaan, nama ibu kandung, foto KTP, foto selfie KTP. Sebelum Tahap 10, data-data tersebut tersimpan **apa adanya (plaintext)** di basis data, sehingga satu kebocoran basis data langsung berarti kebocoran identitas seluruh anggota.

Tahap 10 mengubah hal ini secara menyeluruh:

| Aspek | Sebelum | Sesudah |
|---|---|---|
| NIK di basis data | Teks biasa, mudah dibaca | Terenkripsi (AES-256-GCM via `APP_KEY`) |
| Pencarian / deteksi NIK ganda | `WHERE nik = ?` langsung | Blind index HMAC-SHA256 (`nik_hash`) |
| Jejak perubahan data pribadi | Tidak ada | Tabel `audit_perubahan_pii` (append-only + hash chain) |
| Header keamanan HTTP | Tidak ada | `nosniff`, CSP, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, HSTS |
| Endpoint publik | Tanpa pembatas laju | Rate limiting `throttle` |
| Kebijakan password | Bebas | Minimum panjang + komposisi, dapat disetel via env |

Seluruh perubahan **dapat dikonfigurasi lewat variabel lingkungan** dan **tidak memerlukan perubahan kode** untuk menyalakan/mematikan (lihat §6).

---

## 2. Pemenuhan Kriteria Kepatuhan

Kolom **Bukti** menunjuk ke berkas kode atau pengujian otomatis yang membuktikan pemenuhan.

| Kode | Kriteria (UU PDP) | Implementasi | Bukti |
|---|---|---|---|
| K1 | Pasal 35 — Langkah teknis yang layak untuk melindungi data pribadi | Cast `encrypted` pada kolom PII `Anggota` | `app/Models/Anggota.php`, migrasi `2026_09_30_001000_encrypt_pii_nik_blind_index.php` |
| K2 | Pasal 35 — Data pribadi tidak dapat dibaca tanpa kunci | Enkripsi memakai `APP_KEY`; rotasi kunci memutus akses data lama | `config/app.php` (`key`), `PiiHasher` |
| K3 | Pasal 16 — Minimalisasi data | Tabel audit hanya menyimpan **nama kolom**, tidak pernah nilai PII | `app/Models/AuditPerubahanPii.php`, `AuditPerubahanPiiTest::test_nilai_pii_tidak_bocor_ke_tabel_audit` |
| K4 | Pasal 16 — Akurasi & integritas data | Hash chain pada audit: penyuntingan/penghapusan langsung di DB terdeteksi | `AuditPerubahanPii::verifyChain()`, 2 pengujian khusus |
| K5 | Pasal 39 — Akuntabilitas (jejak audit) | Observer mencatat siapa, kapan, kolom apa, dari IP mana | `app/Observers/ObserverAuditPii.php` |
| K6 | Pasal 40 — Keamanan pemrosesan | Header keamanan respons, CSP, HSTS, `Permissions-Policy` | `app/Http/Middleware/KeamananHeader.php`, `KeamananHeaderTest` |
| K7 | Pasal 40 — Perlindungan sesi | Cookie `HttpOnly` + `SameSite`, opsi enkripsi payload sesi | `config/session.php`, `KeamananHeaderTest` |
| K8 | Pasal 40 — Pencegahan akses tidak sah | Rate limiting endpoint publik & login; RBAC sudah berjalan | `routes/web.php`, `routes/api.php` |
| K9 | Pasal 39 — Mutu kredensial akses | Aturan kekuatan password terpusat | `app/Rules/PasswordKuat.php`, `PasswordKuatTest` |
| K10 | Prinsip *security by default* | Seluruh saklar keamanan punya nilai aman di `config/keamanan.php` | `config/keamanan.php` |

---

## 3. Enkripsi PII & Blind Index

### 3.1 Masalah non-determinisme cast `encrypted`

Cast `encrypted` Laravel menghasilkan ciphertext **berbeda pada setiap penyimpanan** (IV acak + MAC). Konsekuensinya kolom terenkripsi **tidak dapat** dipakai untuk:

- constraint `unique()` — dua baris dengan NIK sama tetap lolos, dan
- pencarian `WHERE nik = ?` / `LIKE ?`.

### 3.2 Solusi: blind index

`App\Support\PiiHasher` menghitung **HMAC-SHA256 deterministik** dari NIK yang telah dinormalisasi:

1. **Normalisasi** — semua karakter non-alfanumerik dibuang, lalu di-`uppercase`. Dengan begitu `"3273 0101 0101 0001"` dan `"3273010101010001"` menghasilkan indeks yang sama.
2. **HMAC-SHA256** dengan kunci `config('app.key')` (`APP_KEY`), menghasilkan hex 64 karakter.
3. **Satu arah** — indeks tidak dapat dibalik menjadi NIK.
4. **Tahan brute-force** — ruang nilai NIK hanya 16 digit; hash polos dapat dipecahkan. Dengan kunci rahasia `APP_KEY`, penyerang yang hanya memiliki dump basis data tidak dapat menghitung ulang blind index.

Kolom `anggota.nik_hash` memakai unique index, sehingga **duplikasi NIK tetap ditolak pada tingkat basis data** meskipun kolom `nik` terenkripsi. Hook `saving` pada model `Anggota` menjaga `nik_hash` selalu sinkron dengan `nik`.

### 3.3 Rotasi `APP_KEY` — PROSEDUR WAJIB

> ⚠️ **PERINGATAN.** Mengganti `APP_KEY` pada aplikasi yang sudah berisi data akan:
> 1. membuat **seluruh data terenkripsi lama tidak terbaca** (NIK, dan kolom PII bercast `encrypted` lainnya), dan
> 2. membuat **seluruh blind index tidak lagi cocok** (`nik_hash` hasil hitung ulang akan berbeda).
>
> Rotasi hanya boleh dilakukan dengan rencana migrasi data: dekripsi memakai kunci lama → simpan sementara → ganti kunci → enkripsi ulang → **hitung ulang seluruh blind index** dengan `PiiHasher::hash()`. Tanpa langkah hitung ulang, deteksi NIK ganda akan gagal dan validasi `NikUnik` menjadi tidak dapat diandalkan.

---

## 4. Audit Perubahan Data Pribadi

### 4.1 Prinsip

Tabel `audit_perubahan_pii` bersifat **append-only** (model `AuditPerubahanPii` menetapkan `public const UPDATED_AT = null` dan tidak ada jalur pembaruan). Setiap perubahan PII pada `Anggota` dan `PengajuanRekening` dicatat oleh `ObserverAuditPii` melalui hook `created`, `updated`, `deleted`, dan `restored`.

### 4.2 Minimalisasi data (Pasal 16)

Tabel audit **HANYA menyimpan NAMA kolom yang berubah**, **tidak pernah nilainya**. Kolom `nik_hash` dilaporkan sebagai satu kolom logis `nik` agar tetap terbaca manusia tanpa membocorkan isi.

Pengujian `test_nilai_pii_tidak_bocor_ke_tabel_audit` membuktikan hal ini secara langsung: seluruh baris tabel audit di-`json_encode` dan dipastikan **tidak memuat** NIK maupun alamat asli.

### 4.3 Hash chain (integritas)

Setiap baris menyimpan `hash_sebelumnya_id`, `hash_sebelumnya`, dan `hash` hasil SHA-256 atas seluruh field barisnya (kolom diurutkan agar kanonik). `AuditPerubahanPii::verifyChain()` mengembalikan:

```php
['utuh' => bool, 'diperiksa' => int, 'rusak_di' => ?int, 'alasan' => ?string]
```

Dua jenis manipulasi terdeteksi:

- **Penyuntingan langsung** di DB → `hash != hitungHash()` → baris ditandai `rusak_di`.
- **Penghapusan baris di tengah rantai** → rujukan `hash_sebelumnya_id`/`hash_sebelumnya` tidak lagi cocok → rantai terputus.

### 4.4 Kegagalan audit tidak menggagalkan bisnis

Penulisan audit dibungkus `try/catch (\Throwable)`. Bila gagal, kejadiannya dicatat ke log **tanpa nilai PII** dan transaksi bisnis tetap berjalan. Prinsipnya: audit adalah kewajiban pelaporan, bukan penghambat operasional.

---

## 5. Hardening Transportasi & Sesi

### 5.1 Header keamanan (`KeamananHeader`)

Terpasang global pada grup middleware `web`:

| Header | Nilai bawaan | Catatan |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Mencegah MIME sniffing |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Membatasi kebocoran URL |
| `X-Frame-Options` | `SAMEORIGIN` | Mencegah clickjacking |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` | Mematikan API perangkat sensitif |
| `Content-Security-Policy-Report-Only` | `default-src 'self'; …` | **Bawaan: mode laporan** agar aman diaktifkan bertahap |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | **Hanya dikirim pada koneksi HTTPS** |

> **Catatan desain:** CSP dikirim sebagai *Report-Only* secara bawaan. Aktifkan penuh (`KEAMANAN_CSP_ENFORCE=true`) hanya setelah laporan pelanggaran bersih. **HSTS sengaja tidak dikirim pada HTTP** agar lingkungan pengembangan lokal (Laragon, `http://localhost`) tidak terkunci oleh browser.

### 5.2 Sesi & proxy

- `SESSION_ENCRYPT` — dapat dinyalakan via env untuk mengenkripsi payload sesi.
- `SESSION_SECURE_COOKIE` — cookie hanya dikirim di HTTPS di produksi.
- Cookie sesi `HttpOnly` + `SameSite` (diuji otomatis).
- `TrustProxies` — diperlukan agar deteksi HTTPS benar saat aplikasi berada di belakang load balancer.

### 5.3 Rate limiting

| Endpoint | Batas | Alasan |
|---|---|---|
| `GET /buka-rekening` | 30 / menit | Formulir publik rawan scraping |
| `POST /buka-rekening` | 10 / menit | Mencegah pengiriman massal |
| Login | 5 / menit | Mencegah brute-force (sudah ada sejak Tahap 5) |

---

## 6. Saklar Konfigurasi (Environment Variable)

Seluruh saklar dibaca melalui `config/keamanan.php` — **bukan** `env()` langsung di middleware/route/test. Alasannya: ketika `php artisan config:cache` aktif (kondisi produksi), `env()` di luar berkas config bernilai `null` dan saklar keamanan akan mati tanpa disadari. Pengujian `test_konfigurasi_keamanan_dibaca_lewat_file_config` memverifikasi middleware **tidak memuat pemanggilan `env(`** sama sekali.

| Variabel | Bawaan | Fungsi |
|---|---|---|
| `KEAMANAN_AUDIT_PII` | `true` | Menyalakan observer audit perubahan PII |
| `KEAMANAN_CSP_AKTIF` | `true` | Mengirim header CSP |
| `KEAMANAN_CSP_ENFORCE` | `false` | `true` = CSP berlaku penuh (bukan Report-Only) |
| `KEAMANAN_HSTS_AKTIF` | `false` | Mengirim HSTS pada koneksi HTTPS |
| `KEAMANAN_HSTS_MAX_AGE` | `31536000` | Umur HSTS (detik) |
| `KEAMANAN_PASSWORD_MIN` | 8 lokal / 12 produksi | Panjang minimum password |
| `KEAMANAN_PASSWORD_HURUF_BESAR` | non-lokal | Wajib minimal satu huruf besar |
| `KEAMANAN_PASSWORD_ANGKA` | `true` | Wajib minimal satu angka |
| `KEAMANAN_PASSWORD_SIMBOL` | non-lokal | Wajib minimal satu simbol |
| `KEAMANAN_PASSWORD_CEK_BOCOR` | `false` | Cek daftar password bocor (belum diaktifkan) |

Kebijakan password di `local`/`testing` sengaja dilonggarkan agar akun demo & seeder tetap sah.

---

## 7. Bukti Pengujian

| Berkas uji | Fokus | Hasil |
|---|---|---|
| `tests/Feature/KeamananPiiNikTest.php` | Enkripsi NIK + blind index + `NikUnik` | **17 / 17 lulus** |
| `tests/Feature/KeamananHeaderTest.php` | Header keamanan, CSP/HSTS per lingkungan, throttle publik, cookie sesi | **13 / 13 lulus** |
| `tests/Feature/AuditPerubahanPiiTest.php` | Audit PII, minimalisasi data, hash chain | **15 / 15 lulus** |
| `tests/Feature/PasswordKuatTest.php` | Kebijakan kekuatan password | **9 / 9 lulus** |
| **Suite penuh** | Tanpa regresi | **1089 lulus / 5056 assertion** |

Perintah uji wajib (lihat `docs/PANDUAN-PENGUJIAN.md`):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\run-tests.ps1
```

---

## 8. Risiko Sisa & Batasan

| No | Risiko | Dampak | Mitigasi saat ini | Rencana lanjutan |
|---|---|---|---|---|
| R1 | Data terenkripsi terikat pada `APP_KEY` | Rotasi kunci tanpa prosedur = data tidak terbaca | Prosedur rotasi terdokumentasi (§3.3) | Simpan `APP_KEY` di secret manager; rotasi terjadwal dengan skrip migrasi |
| R2 | Kolom PII selain `nik` **belum** ber-blind-index | Nama/alamat tidak dapat dicari lewat query langsung | Pencarian tetap lewat relasi & ID | Tambah blind index bila muncul kebutuhan pencarian nama persis |
| R3 | `KEAMANAN_PASSWORD_CEK_BOCOR` belum aktif | Password lemah yang sudah bocor di internet tetap diterima | Syarat komposisi + panjang | Sambungkan ke API *Have I Been Pwned* (k-anonymity) |
| R4 | CSP bawaan `Report-Only` | Perlindungan XSS belum ditegakkan | Header lain sudah aktif penuh | Aktifkan `KEAMANAN_CSP_ENFORCE=true` setelah laporan bersih |
| R5 | Audit PII mencatat IP & User-Agent (data pribadi petugas) | Menambah cakupan data pribadi yang tersimpan | Hanya nama kolom, tidak ada nilai PII nasabah | Kebijakan retensi & pembersihan berkala |
| R6 | Backup basis data tidak dienkripsi oleh aplikasi ini | Dump berisi ciphertext; kunci tetap di aplikasi | Enkripsi tingkat kolom | Enkripsi berkas backup + uji restore berkala |
| R7 | Foto KTP/selfie belum ditinjau jalur penyimpanannya | Risiko paparan berkas mentah | Akses berkas dibatasi RBAC | Pindahkan ke penyimpanan objek privat + URL bertanda tangan kedaluwarsa |

---

## 9. Peran & Tanggung Jawab

| Peran | Tanggung jawab |
|---|---|
| Pengurus / Pemangku kepentingan | Menyetujui kebijakan perlindungan data pribadi & prosedur rotasi kunci |
| Manajer Operasional | Memastikan variabel lingkungan produksi diisi sesuai §6 |
| Petugas TI / DevOps | Menjalankan `optimize:clear` sebelum pengujian; mengelola `APP_KEY`; memantau log audit |
| DPS (Dewan Pengawas Syariah) | Memastikan perlindungan data nasabah sejalan dengan prinsip kerahasiaan syariah |
| Seluruh petugas | Hanya memproses data pribadi sesuai kewenangan perannya |

---

## 10. Hubungan dengan Tahap Lain

- `docs/TAHAP5-HARDENING.md` — rate limiting login, jejak audit login, gerbang cut-off.
- `docs/TAHAP7-SKALABILITAS-JALUR-BACA.md` — indeks jalur baca (kinerja query).
- `docs/TAHAP9-AKSESIBILITAS-RESPONSIF.md` — WCAG 2.1 Level AA (aksesibilitas).
- `docs/RISIKO-SSE-DAN-JALUR-LANJUTAN.md` — risiko SSE & jalur WebSocket/Pusher.
- `docs/PANDUAN-PENGUJIAN.md` — kewajiban `php artisan optimize:clear` sebelum `php artisan test`.
