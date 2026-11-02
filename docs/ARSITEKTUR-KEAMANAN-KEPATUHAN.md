# Arsitektur, Keamanan Data, dan Kepatuhan — Langkah 2

| Atribut | Keterangan |
|---|---|
| Sifat | Analisis dan rencana (read-only). Belum ada kode produksi yang diubah. |
| Acuan | `docs/SPESIFIKASI-TEKNIS-MODUL.md` (langkah 1), `docs/AUDIT-ROLE-KEPATUHAN-SYARIAH-PSAK.md`, `docs/REKOMENDASI-ARSITEKTUR-FLEKSIBEL-TERUKUR.md`, `docs/TAHAP10-KEAMANAN-DATA-UU-PDP.md` |
| Stack | Laravel 12, PHP 8.3, MySQL 8.4 |

---

## 1. Ringkasan

Arsitektur modular (Controller → Action → Service → Repository → Model), kontrak di `app/Contracts/`, konfigurasi berbasis data (`config/alur_pembiayaan.php`, `config/role_hierarki.php`, `config/keamanan.php`), dan lapisan keamanan data (enkripsi PII, blind index, audit PII berantai hash) sudah ada.

Verifikasi ulang temuan audit lama terhadap kode saat ini menghasilkan status berikut:

| Kode audit | Temuan | Status saat ini (terverifikasi di kode) |
|---|---|---|
| R-02 | Jurnal manual tanpa maker–checker | **Sudah tertutup.** `JurnalController` memakai `PemisahanTugas` pada `update` (baris 159), `post` (210), dan `void` (300). |
| R-04 | `is_active` tidak ditegakkan saat login | **Sudah tertutup.** `WebAuthController` memeriksa `Auth::user()->is_active` (baris 39). |
| R-08 | Peran hantu `keuangan` di beban operasional | **Sudah tertutup.** Tidak ditemukan lagi literal `keuangan` di `PengeluaranBebanController`. |
| R-03 | Self-approval AO pada `ao.pengajuan.approve` | **Masih terbuka.** `AOController::pengajuanApprove()` (baris 501) tidak memanggil `PemisahanTugas`. Dikonfirmasi. |
| R-01 | Fatwa/opini DPS tidak tersimpan | **Sebagian.** Persetujuan DPS atas akad sudah ada (`DpsAuditController`). Fatwa tertaut produk dan gating produk baru belum ada. |
| R-05 | Pengecekan peran hardcoded | **Sudah tertutup.** `CheckRole` memvalidasi nama peran lewat `HierarkiRole::dikenal()` lalu membandingkan `role` pengguna; `User::isPengelola()` memakai `HierarkiRole::dalamKelompok()`; `TransactionLimitService` memakai `peranUntukStatus()`. Tidak ada lagi daftar peran literal di ketiga titik itu. |
| Pemisahan pencairan | `approvePengurus()` menyetujui sekaligus mencairkan | **Sudah tertutup.** `approvePengurus()` hanya menetapkan `STATUS_DISETUJUI`; pencairan dilakukan `cairkan()` dan ditolak bila `disetujui_oleh` sama dengan pencair (`PemisahanTugas::dilanggar()`). |

Catatan: dokumen audit berasal dari 2 Oktober 2026, sedangkan sebagian kode sudah diperbaiki sesudahnya. Dokumen itu tidak boleh dijadikan dasar tanpa verifikasi ulang.

---

## 2. Arsitektur Target

### 2.1 Lapisan
```
HTTP (routes, middleware, Form Request)
  └─ Controller        : validasi HTTP, orkestrasi, respons
      └─ Action        : use-case dan DB::transaction
          └─ Service   : aturan domain (jurnal, bagi hasil, zakat)
              └─ Repository : query kompleks (dibalik via kontrak)
                  └─ Model  : Eloquent, cast, observer
```

### 2.2 Status komponen (verifikasi kode)
| Komponen | Status | Bukti |
|---|---|---|
| Kontrak / DI | Ada, 7+ binding | `app/Contracts/`, `AppServiceProvider` |
| Journaler terpecah | Ada | `app/Services/Jurnal/` |
| Action teller | Ada | `app/Actions/Teller/` |
| Repository | Ada (3) | `app/Repositories/` |
| Job asinkron | Ada (4), default sinkron | `app/Jobs/`, `FEATURE_QUEUE_ASYNC=false` |
| Event dan listener | Ada (5 event, 2 listener) | `app/Events/`, `app/Listeners/` |
| Konfigurasi alur dan RBAC | Ada | `config/alur_pembiayaan.php`, `config/role_hierarki.php` |

### 2.3 Kekurangan yang perlu ditutup (skalabilitas)
| # | Masalah | Dampak | Usulan |
|---|---|---|---|
| A-1 | `QUEUE_CONNECTION=sync` di `.env` lokal | Operasi berat memblokir request | Produksi memakai `database`/`redis` dengan worker supervisor. Feature flag tetap default `false` sampai lulus uji. |
| A-2 | Cache dan session berbasis file | Tidak bisa scale-out | Siapkan `CACHE_STORE=redis` dan `SESSION_DRIVER=redis` sebagai opsi env. Tidak diubah default-nya. |
| A-3 | Tidak ada endpoint health | Orchestrator tidak bisa memeriksa | Sudah ada `HealthCheckTest`. Pastikan `HEALTH_ENDPOINT_ENABLED` dan rate limit sesuai produksi. |
| A-4 | `AutoJournalingService` masih besar (±2000 baris) | Sulit diuji dan diubah | Pemecahan per domain menjadi pekerjaan lanjutan. Tidak masuk langkah 2. |

---

## 3. Keamanan Data

### 3.1 Konfigurasi lingkungan
| Item | Lokal (`.env` saat ini) | Produksi yang disyaratkan |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `SESSION_ENCRYPT` | default `true` | `true` |
| `SESSION_SECURE_COOKIE` | default `true` di produksi | `true` (HTTPS) |
| `KEAMANAN_HSTS_AKTIF` | `false` | `true` setelah HTTPS terpasang |
| `KEAMANAN_CSP_ENFORCE` | default `true` di non-local | `true` (lihat R4 di bawah) |
| `KEAMANAN_HEALTH_ENDPOINT` | sesuai kebutuhan | diuji dengan rate limit |

Risiko: `.env` ini bersifat lokal. Konfigurasi produksi harus dipastikan terpisah dan tidak memakai `APP_DEBUG=true`.

### 3.2 Kontrol yang sudah ada
- Enkripsi PII lewat cast `encrypted`, blind index `nik_hash` dengan HMAC-SHA256 (`PiiHasher`).
- Audit PII append-only berantai hash (`AuditPerubahanPii`, `ObserverAuditPii`).
- Header keamanan (`KeamananHeader`): nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy, CSP.
- Rate limit: login `throttle:5,1`, buka rekening 30/menit GET dan 10/menit POST.
- Kebijakan password (`config/keamanan.php`): minimal 12 karakter di produksi, huruf besar, angka, simbol. `cek_bocor` default `false`.
- Retensi log: `log_hari` 1095, `pii_hari` 365, command `log:purge-kedaluwarsa`.
- Cadangan: `php artisan db:cadangkan` menulis `.sql.gz.enc` — gzip lalu **XChaCha20-Poly1305 secretstream** (libsodium), streaming per potongan 1 MiB sehingga plaintext tidak pernah menyentuh disk; retensi membersihkan `.sql.gz` lama maupun `.sql.gz.enc`. Pemulihan: `php artisan db:buka-cadangan <berkas>`. Kunci dari `BACKUP_ENCRYPTION_KEY` (base64 32 byte) atau diturunkan HKDF-SHA256 dari `APP_KEY`.

### 3.3 Risiko sisa keamanan (dari TAHAP10 dan verifikasi ulang)
| Kode | Risiko | Tingkat | Usulan |
|---|---|---|---|
| R4 | CSP masih Report-Only di lingkungan tertentu | Sedang | Aktifkan enforce di produksi, uji dulu di staging. Mode strict dengan nonce tersedia. |
| ~~R6~~ | ~~Cadangan database tidak dienkripsi~~ | ~~Tinggi~~ | **Tertutup.** `CadanganTerenkripsi` (XChaCha20-Poly1305 secretstream, framing panjang eksplisit), `config/cadangan.php`, `db:buka-cadangan`; dikunci `CadanganEnkripsiTest` (6 test). Kunci terpisah dari `APP_KEY` lewat `BACKUP_ENCRYPTION_KEY`. |
| R7 | Foto KTP belum ditinjau retensinya | Sedang | Tetapkan kebijakan retensi dan akses. Tinjau dengan pengurus. |
| K-1 | 2FA belum ada | Sedang | Pertimbangkan TOTP untuk peran pengurus, manajer, dan admin. Di luar langkah 2 untuk implementasi. |
| K-2 | Konfigurasi produksi belum diverifikasi di server | Tinggi | Checklist deploy (`docs/DEPLOY-PRODUKSI.md`) dijalankan sebelum rilis. |
| ~~K-3~~ | ~~Angka baseline uji berbeda antar dokumen (1089, 1156, 1886, 2000)~~ | ~~Rendah~~ | **Tertutup.** Angka resmi: **2032 passed (9481 assertions)** di `koperasi_syariah_test`. |

### 3.4 Pemeriksaan keamanan yang sudah ada sebagai test
`AbilityRoleGateTest`, `AuditLogAppendOnlyTest`, `AuditPerubahanPiiTest`, `HealthCheckTest`, `KeamananHeaderTest`, `KeamananPiiNikTest`, `PasswordKuatTest`, `PemisahanTugasGcgTest`, `PemisahanTugasPembiayaanTest`, `PurgeLogKedaluwarsaTest`, `RbacHierarkiRoleTest`.

Catatan: `DebugAuthTest.php` tampak sebagai test sementara. Perlu ditinjau sebelum dipertahankan.

---

## 4. Kepatuhan Regulasi dan Syariah

### 4.1 Pemisahan tugas (maker ≠ checker)
| Aksi | Status | Tindakan |
|---|---|---|
| Jurnal update, post, void | Sudah | Pertahankan dan pastikan ada regression test. |
| Approval limit, beban, ta'zir, restrukturisasi, AML | Sudah | Pertahankan. |
| AO approve pengajuan (R-03) | **Sudah** | `PengajuanPembiayaanController::approve()` memakai `PemisahanTugas::dilanggarSalahSatu()`; pengaju tidak boleh menyetujui pengajuannya sendiri. Dikunci `DisbursementPencairanPembiayaanTest`. |
| Pencairan dipisah dari persetujuan | **Sudah** | `approve()` hanya mengubah status menjadi disetujui; pencairan dilakukan peran lain lewat `TellerPencairanController` → `ProsesPencairanAction`/`DisbursementService`, yang juga menegakkan maker ≠ checker. |

### 4.2 Kepatuhan syariah
- Bebas riba: denda diperlakukan sebagai ta'zir dan dipetakan ke akun tersendiri (`akun_tazir_id`).
- **Ta'zir deposito:** penalti pencairan dini dipetakan ke Dana Kebajikan `2-3001` (Fatwa DSN-MUI 17/2000). Migrasi `2026_09_30_000600` memindahkan `deposito_products.penalty_coa_id` dari `4-2001` ke `2-3001`; `DepositoProduct::akunPenaltiDiizinkan()` menolak akun pendapatan (`4-`). Tidak ada lagi rujukan `4-2001` di `app/`.
- **Estimasi bagi hasil deposito:** `DepositoService::calculateSimulation()` menghitung imbalan sebagai `nisbah × pendapatan` yang diprorata **hari efektif / hari dalam tahun** per bulan kalender — bukan pola bunga tetap (`pokok × rate × tenor/12`). Rincian per bulan tersedia di `pendapatan_bulanan` dan Σ baris = total (tanpa drift pembulatan). Sesuai PSAK 105/Fatwa DSN-MUI 115.
- **Nisbah & jatuh tempo dari akad:** jalur teller mengambil nisbah, tenor, dan tanggal jatuh tempo dari master produk deposito; input `nisbah_nasabah`/`tanggal_jatuh_tempo` dari pengguna diabaikan.
- Gharar: akad wajib dipetakan ke akun. Akad IMBT, Istishna, dan Salam sudah tersedia (`Pembiayaan` konstanta).
- DPS: persetujuan akad pembiayaan sudah ada (`DpsAuditController`). Modul fatwa tertaut produk belum ada (R-01, risiko tinggi). Ini butuh keputusan organisasi, bukan hanya kode.

### 4.3 Regulasi yang dirujuk
UU No. 25/1992, UU No. 17/2012, UU No. 27/2022 (PDP), PSAK 101–109, PSAK 71, Fatwa DSN-MUI No. 17/DSN-MUI/IX/2000.

Peringatan: dokumen ini bukan nasihat hukum. Kepatuhan final perlu dikonfirmasi dengan konsultan syariah dan regulator yang berlaku.

---

## 5. Langkah 3 — status pelaksanaan

Setiap perubahan kecil, dengan regression test, dan gate `php artisan test` harus hijau. Uji di `koperasi_syariah_test`. Sebelum menyentuh produksi, jalankan `php artisan db:cadangkan`.

| Item | Status | Bukti |
|---|---|---|
| R-03: pemisahan tugas pada persetujuan pengajuan | Selesai | `PengajuanPembiayaanController::approve()` + `PemisahanTugas::dilanggarSalahSatu()`; `DisbursementPencairanPembiayaanTest`. |
| Pemisahan pencairan dari persetujuan | Selesai | `approve()` hanya menyetujui; pencairan via `TellerPencairanController`/`ProsesPencairanAction`. |
| Denda deposito ke Dana Kebajikan | Selesai (false positive) | Migrasi `2026_09_30_000600`; `DepositoProduct::akunPenaltiDiizinkan()`; tidak ada `4-2001` di `app/`. |
| Estimasi bagi hasil berpola bunga | Selesai | `DepositoService::calculateSimulation()` berbasis hari efektif/hari dalam tahun + `pendapatan_bulanan`; `DepositoSyariahTest`. |
| Celah nisbah jalur teller | Selesai | `TellerController::depositoForm/Store`, `BukaDepositoAction`, `teller/deposito.blade.php`; 3 regression test. |
| Nisbah pembiayaan B6 | Selesai | `margin_nisbah` dibatasi 0–100% di 3 jalur tulis; akad bagi hasil menolak nisbah ≤ 0%; `nisbah_pengelola`/`nisbah_pemilik` diisi berjumlah 100%; `PembiayaanController::pastikanNisbahUtuh()`; `NisbahPembiayaanPengajuanTest` (7 test). |
| Rute dana kebajikan A2 | Selesai (false positive) | Nama rute sidebar Dana Kebajikan sudah memakai `*.index` yang terdaftar; `AUDIT-MODUL-SISTEM.md`. |
| Rute approval Pengurus A4 | Selesai | `routes/web.php` mendaftarkan `pengurus.approval.index` di grup `izin:pembiayaan.persetujuan`. |
| R-05: `CheckRole`/`User::isPengelola()` → `HierarkiRole` | Selesai | `CheckRole` memvalidasi peran lewat `HierarkiRole::dikenal()`; `User::isPengelola()` memakai `dalamKelompok()`; `TransactionLimitService` memakai `peranUntukStatus()`; tanpa literal peran. |
| Pint massal (`app`, `routes`, `database`) | Selesai | 447 file PASS; full suite tetap 2019 test hijau. |
| R6: enkripsi cadangan | Selesai | `app/Support/CadanganTerenkripsi.php` (XChaCha20-Poly1305 secretstream, potongan 1 MiB, framing `[4 byte panjang][ciphertext+tag]`), `config/cadangan.php`, `db:cadangkan` → `.sql.gz.enc`, `db:buka-cadangan` untuk pemulihan, `denyut:pemeriksaan` mengenali kedua ekstensi; `CadanganEnkripsiTest` (6 test) + `PemeliharaanSistemTest`. Smoke test: 51.7 KB terenkripsi → dekripsi 51.5 KB berisi `-- MySQL dump`. |

Baseline uji terakhir: **2032 passed (9481 assertions)** — pasca-R6.

---

## 6. Keputusan Terbuka untuk Pengguna

1. ~~**Pemisahan pencairan:**~~ **Diputuskan: opsi (a)** — persetujuan dan pencairan menjadi aksi terpisah, pencairan dilakukan peran lain. Sudah diimplementasikan.
2. **Modul fatwa DPS (R-01):** apakah DPS perlu menyimpan fatwa tertaut produk di sistem, atau cukup persetujuan akad yang sudah ada?
3. ~~**Baseline uji resmi:**~~ **Sudah dijalankan:** 2032 passed (9481 assertions).
4. **`DebugAuthTest.php`:** dihapus atau dipertahankan?
5. **Konfigurasi produksi:** apakah ada akses ke server produksi untuk memverifikasi `APP_ENV`, `APP_DEBUG`, dan driver cache/queue?
6. ~~**Enkripsi cadangan (R6):**~~ **Diputuskan: dikerjakan di langkah 3.** Sudah diimplementasikan dengan `CadanganTerenkripsi`; `BACKUP_ENCRYPTION_KEY` sebaiknya diisi di produksi dan disimpan terpisah dari server.

---

## 7. Status

- Langkah 2: rencana selesai dan didokumentasikan di berkas ini.
- Langkah 3: berjalan. Item "perlu verifikasi" (denda deposito, estimasi bagi hasil, celah nisbah jalur teller) selesai; pemisahan tugas & pencairan selesai; B6, A2, A4, R-05, dan R6 selesai.
- Pint massal `app`, `routes`, `database`: selesai (447 file PASS), tanpa regresi.
- Baseline uji: 2032 passed (9481 assertions) — pasca-R6, gate penuh hijau.
- Sisa pekerjaan langkah 3: modul fatwa DPS (R-01, butuh keputusan organisasi).
