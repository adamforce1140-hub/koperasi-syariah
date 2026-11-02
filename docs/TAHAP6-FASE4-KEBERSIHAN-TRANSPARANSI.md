# TAHAP 6 — FASE 4: KEBERSIHAN KODE & TRANSPARANSI (R15–R19)

**Status:** ✅ SELESAI & TERVERIFIKASI
**Tanggal penyelesaian:** 2026-09-29
**Lingkup temuan:** Fase 4 dari `docs/AUDIT-MODUL-SISTEM.md` — R15, R16, R17, R18, R19
**Dasar standar:** PSAK 101 (penyajian laporan keuangan koperasi syariah),
Prinsip *maker-checker* / pemisahan tugas (POJK tentang tata kelola), Fatwa
DSN-MUI No. 17/DSN-MUI/IX/2000 (ta'zir → Dana Kebajikan, bukan pendapatan),
serta prinsip transparansi nisbah bagi hasil kepada anggota.
**Tahap sebelumnya:** `docs/TAHAP5-HARDENING.md` (900 passed / 4244 assertions)

**Dokumen Pendahulu (rantai deliverable Tahap 2–6):**

| Tahap | Dokumen | Hasil suite saat penutupan |
|---|---|---|
| 2 | `docs/TAHAP2-PSAK-INTI.md` | 826 passed / 4015 assertions |
| 3 | `docs/TAHAP3-PELAPORAN.md` | 854 passed / 4114 assertions |
| 4 | `docs/TAHAP4-GCG-OJK.md` | 872 passed / 4160 assertions |
| 5 | `docs/TAHAP5-HARDENING.md` | 900 passed / 4244 assertions |
| **6** | **`docs/TAHAP6-FASE4-KEBERSIHAN-TRANSPARANSI.md` (dokumen ini)** | **955 passed / 4629 assertions** |

---

## 1. Ringkasan Eksekutif

Fase 4 adalah fase penutup dari rencana implementasi `docs/AUDIT-MODUL-SISTEM.md`.
Berbeda dengan Fase 1–3 yang menyasar kerugian/error tampil, integritas data, dan
penyempurnaan syariah, Fase 4 menyasar **kebersihan kode** dan **transparansi
hubungan koperasi–anggota**:

| Kode | Rekomendasi | Ringkasan implementasi |
|---|---|---|
| **R15** | Bersihkan kode mati | Hapus view orphan `pengelola/*` palsu, bereskan `RequirePassword`, `errors/404` |
| **R16** | Rapikan penamaan rute API | Tambah `->name()` pada rute void teller; hilangkan hardcode path di `teller/riwayat.blade.php` |
| **R17** | Transparansi nisbah & bagi hasil ke anggota | Halaman rincian bagi hasil deposito + tampilkan nisbah per akad |
| **R18** | Jalur keringanan penalti deposito | Banding berjenjang Teller → AO → Manajer (adopsi pola ta'zir pembiayaan) |
| **R19** | Maker-checker peran pengurus | Pisahkan hak EDIT/HAPUS dari LIHAT/UNDUH/EKSPOR laporan |

Penulisan **test regresi Fase 4** (`tests/Feature/Fase4RegresiTest.php`, 15 test,
96 assertions) ternyata **menemukan 3 bug produksi nyata** yang selama ini tidak
terdeteksi — bukan sekadar memverifikasi kode yang sudah benar. Ketiga bug itu
diperbaiki dalam Fase 4 ini (detail pada §3).

---

## 2. Sasaran & Kriteria Keberhasilan

Kriteria keberhasilan akhir `docs/AUDIT-MODUL-SISTEM.md` §5.4 dan statusnya
setelah Fase 4:

| # | Kriteria | Status |
|---|---|---|
| 1 | Seluruh rute/tautan yang dipakai terdaftar dan dapat diakses | ✅ Terverifikasi via `php artisan route:list` + test regresi R16 |
| 2 | Tidak ada transaksi bisnis tersimpan tanpa jurnal | ✅ Dijaga jalur `DB::transaction` + pemeriksaan nilai balik `post()` |
| 3 | Seluruh penulisan jurnal dalam `DB::transaction` dengan cek nilai balik `post()` | ✅ Terkunci pada test regresi Tahap 5 & 6 |
| 4 | Sertifikasi produk DPS tersimpan dan dapat diaudit | ✅ Terverifikasi (test audit matriks) |
| 5 | Ta'zir/penalti tidak pernah masuk akun pendapatan | ✅ Penalti → CoA Dana Kebajikan (2-3001), bukan pendapatan |
| 6 | Bagi hasil dihitung dari laba riil, bukan estimasi berbunga | ✅ `PendapatanService::pendapatanInvestasiBulanan()` |
| 7 | Seluruh proses terjadwal aktif, terverifikasi via `schedule:list` | ✅ Enam penjadwalan aktif (lihat §6) |

---

## 3. Tiga Bug Produksi Nyata yang Ditemukan & Diperbaiki

Test regresi Fase 4 menyingkap tiga kelas masalah yang tidak terlihat dari
pembacaan kode semata. Ini adalah bukti nilai tertinggi dari tahap ini.

### 3.1 Namespace `PemisahanTugas` salah → seluruh alur keringanan penalti deposito 500

`app/Http/Controllers/DepositoPenaltyReliefController.php` mengimpor
`App\Services\PemisahanTugas` — kelas itu **tidak ada**. Kelas sebenarnya berada
di `App\Support\PemisahanTugas` (dipakai 30+ tempat lain). Akibatnya ketiga aksi
keringanan penalti (`rekomendasi`, `approve`, `reject`) memicu
`Class "App\Services\PemisahanTugas" not found` — fitur R18 mustahil dipakai
sebelum diperbaiki.

```php
// SEBELUM (salah — kelas tidak ada):
use App\Services\PemisahanTugas;
// SESUDAH (benar):
use App\Support\PemisahanTugas;
```

### 3.2 `role:nasabah` tidak valid pada ENUM `users.role` → seluruh portal anggota 403

`routes/web.php` mendaftarkan portal anggota dengan middleware `role:nasabah`.
Namun kolom `users.role` adalah **ENUM MySQL** dengan nilai sah
`admin, teller, manajer, pengurus, anggota, ao, dps` — **tidak ada `nasabah`**.
`CheckRole` mem-`abort(403)` bila peran tidak cocok, sehingga **seluruh rute
`/nasabah/*` tak dapat diakses siapa pun**. Perbaikan: `role:nasabah` →
`role:anggota`.

### 3.3 Empat titik kode lain masih bercabang pada string peran `'nasabah'`

Kesalahan ENUM yang sama menjalar ke empat lokasi:

| Berkas | Dampak | Perbaikan |
|---|---|---|
| `app\Http\Controllers\WebAuthController.php` (`redirectByRole`) | Anggota gagal diarahkan setelah login | `'nasabah'` → `'anggota'` → `route('dashboard.nasabah')` |
| `app\Http\Middleware\RedirectIfAuthenticated.php` | Pengguna login terlempar ke rute tak ada | `'nasabah'` → `'anggota'` |
| `app\Services\NavigationService.php` (`getMenu`) | Menu anggota tidak pernah dimuat | `'nasabah'` → `'anggota'` |
| `app\Console\Commands\ResetDataAnggota.php` | Kueri hapus user anggota selalu nol (data tidak pernah terhapus) | `where('role','nasabah')` → `where('role','anggota')` |

---

## 4. Implementasi per Rekomendasi

### R15 — Kebersihan kode mati
- Delapan view `resources/views/pengelola/*` diverifikasi **terpakai** oleh
  `ApprovalTransaksiController`, `DynamicLoanController`, `PengajuanPembiayaanController`,
  dan `PengajuanRekeningController` — bukan orphan, sehingga dipertahankan.
- `RequirePassword` sudah tidak ada di basis kode (bersih).
- `errors/404.blade.php` dipertahankan karena menangani 404 umum.

### R16 — Penamaan rute API
- Rute void teller kini bernama: `Route::post('/void/{transaksi}', ...)->name('teller.void');`
- `resources/views/teller/riwayat.blade.php` tidak lagi menuliskan path secara
  hardcode, melainkan dari `route()`:
  ```js
  const voidUrl = '{{ route("teller.void", ["transaksi" => "__ID__"]) }}';
  fetch(voidUrl.replace('__ID__', encodeURIComponent(voidTrxId)), ...)
  ```
  sehingga perubahan path di masa depan tidak mematahkan UI.

### R17 — Transparansi nisbah & bagi hasil
- Halaman rincian bagi hasil deposito untuk anggota (`nasabah.deposito.bagi-hasil`)
  menampilkan nisbah per akad (mis. `60% : 40%`) beserta blok "Rincian Bagi Hasil".
- Akses dibatasi kepemilikan: `NasabahController::depositoBagiHasil()` memakai
  `abort_unless((int) $deposito->member_id === (int) $anggota->id, 403, ...)` —
  anggota hanya dapat melihat rincian bilyet miliknya.

### R18 — Jalur keringanan penalti deposito
- Hierarki berjenjang: `TELLER (PENDING_AO) → AO (PENDING_MANAJER) → MANAJER (APPROVED/REJECTED)`.
- Penegakan maker-checker via `App\Support\PemisahanTugas::dilanggarSalahSatu()`:
  pemutus terakhir tidak boleh orang yang sama dengan perekomendasi.
- Penolakan menyisakan penalti penuh; persetujuan memangkas penalti namun
  **tidak boleh melebihi penalti awal** (dipangkas pada batas penalti awal).
- Penalti tetap masuk CoA Dana Kebajikan (2-3001) — konsisten dengan Fatwa
  DSN-MUI No. 17/DSN-MUI/IX/2000.

### R19 — Maker-checker peran pengurus
- Modul laporan (neraca, buku_besar, laporan_manajer, laporan_psak101) menjadi
  **hanya-baca untuk SEMUA peran**.
- Wewenang menulis dipindahkan ke modul baru
  `LaporanKeuanganPermission::MODUL_PENYESUAIAN = 'penyesuaian_jurnal'`, yang
  hanya dipegang pengurus dengan hak `[LIHAT, EDIT, HAPUS]`.
- Modul `penyesuaian_jurnal` inilah yang dipakai oleh alokasi SHU
  (`DanaCadanganController`) dan penyesuaian CKPN — bukan modul laporan.
- Route laporan didaftarkan **hanya GET**, sehingga metode tulis berhenti di
  router (405) — tidak ada jalur menulis laporan sama sekali.

---

## 5. Test Regresi Fase 4

**Berkas:** `tests/Feature/Fase4RegresiTest.php` — 15 test, 96 assertions, **semua hijau**.

Cakupan: R16 (1 test), R17 (2 test), R18 (5 test), R19 (4 test), ditambah test
integrasi alur (deposito anggota, pengajuan ganda). Seluruh test memakai
`DatabaseTransactions` dan prefix unik agar deterministik pada basis data dev.

Test R19 secara eksplisit mengunci bahwa:
- pengurus dapat **membaca** laporan (`GET /laporan/neraca` → 200);
- seluruh metode tulis (`POST/PUT/PATCH/DELETE`) berhenti di router (405);
- **tidak ada log "HAPUS berhasil"** untuk modul laporan;
- `peranYangBoleh(MODUL_NERACA, HAPUS) === []`.

Test R18 mengunci jenjang keringanan sampai disetujui, larangan pemutus = perekomendasi,
sisa penalti penuh saat ditolak, pemangkasan penalti pada batas awal, dan
pembatasan peran (dps/anggota → 403).

---

## 6. Verifikasi Akhir & Kesiapan Produksi

### 6.1 Hasil suite
```
php artisan test  →  955 passed (4629 assertions), 129.45s
```
Naik dari baseline Tahap 5 (900/4244) **tanpa regresi**.

### 6.2 Proses terjadwal aktif (`php artisan schedule:list`)
```
 0  * * * *  php artisan inspire
 1  0 * * *  php artisan deposito:process-maturity
 1  0 * * *  php artisan mandatory-savings:process-daily
 1  0 * * *  php artisan ckpn:hitung-penyesuaian
 0  7 * * *  php artisan angsuran:run-autodebet
30  0 1 * *  php artisan deposito:bagi-hasil
```
Semua jalur keuangan kritikal (jatuh tempo deposito, penagihan simpanan wajib,
CKPN PSAK 71, autodebet angsuran, bagi hasil dari laba riil) terjadwal dan
terverifikasi.

### 6.3 Kesiapan cache produksi
`config:cache`, `route:cache`, dan `view:cache` berhasil tanpa galat — aplikasi
siap dijalankan pada mode produksi. **Catatan operasional:** setelah
`config:cache`, jalankan `php artisan optimize:clear` sebelum menjalankan test
(memakai `.env` testing), agar konfigurasi test tidak tertimpa konfigurasi produksi.

### 6.4 Frontend
Seluruh UI bersifat *self-contained* (tanpa `@vite`), sehingga **tidak diperlukan
build Node** untuk produksi.

### 6.5 Status migrasi
`php artisan migrate:status` — **tidak ada migrasi pending**.

---

## 7. Risiko Sisa & Catatan

1. **Deploy otomatis ke Vercel tidak kompatibel** dengan Laravel 12 + MySQL
   (Vercel *serverless* tanpa runtime PHP/MySQL persisten). Deploy produksi harus
   dilakukan manual ke VPS/cPanel/hosting PHP 8.2+ & MySQL 8.
2. **Kredensial demo** (`DEMO_LOGIN_ENABLED`, `DEMO_USER_PASSWORD`) wajib dikosongkan
   di produksi agar panel demo tersembunyi dan akun demo tidak dibuat.
3. **`APP_DEBUG` wajib `false`** di produksi agar stack trace tidak terbuka.

---

## 8. Kesimpulan

Fase 4 menutup R15–R19 dan sekaligus membuktikan nilai *test-driven verification*:
penulisan test regresi menemukan **tiga bug produksi nyata** (namespace salah,
ENUM peran tidak valid, dan penjalaran kesalahan peran ke empat titik) yang
dampaknya signifikan — terutama **portal anggota yang sebelumnya 403 sepenuhnya**.
Dengan 955 test / 4629 assertions hijau, keenam rekomendasi Fase 4 tuntas, dan
seluruh kriteria keberhasilan akhir audit terpenuhi, sistem siap untuk deploy
produksi.
