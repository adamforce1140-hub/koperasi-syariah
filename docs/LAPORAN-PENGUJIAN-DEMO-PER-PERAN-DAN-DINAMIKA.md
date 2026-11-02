# LAPORAN PENGUJIAN DATA DEMO SELURUH PERAN & VERIFIKASI DINAMIKA SISTEM

**Tanggal pengujian:** 2026-10-12
**Lingkungan:** Laragon — PHP 8.3.33 · Laravel 12.69.1 · MySQL 8.4.3 · Windows / PowerShell 5.1
**Server uji:** `php artisan serve --port=8123` (HTTP nyata, bukan simulasi)
**Suite pengujian:** PHPUnit 11.5.56 (serial)

> **Pengecualian tanggal (sesuai instruksi):** Seluruh elemen tanggal — `created_at`, `updated_at`,
> `tanggal`, `jatuh_tempo`, periode laporan, tanggal transaksi/jurnal — **TIDAK disentuh, tidak diubah,
> dan tidak dimutasi** dalam pengujian ini. Semua nilai tanggal diperlakukan sebagai *data asli yang
> tidak dapat dimodifikasi dalam kondisi apa pun*. Pengujian hanya memakai kolom/atribut **non-tanggal**.

---

## 0. RINGKASAN EKSEKUTIF

| Aspek | Hasil | Status |
|-------|-------|--------|
| Peran fungsional diuji | 5/5 (`dps`, `pengurus`, `manajer`, `ao`, `teller`) | ✅ LULUS |
| Peran phantom (tanpa user) | 3 (`admin`, `anggota`, `nasabah`) | ⚠️ by design |
| Pengujian E2E per peran | 12 passed / 263 assertions | ✅ LULUS |
| Pengujian RBAC & navigasi | 44 passed / 233 assertions | ✅ LULUS |
| Runtime HTTP nyata (login + halaman) | 5/5 login 302 · 18/18 halaman 200 | ✅ LULUS |
| Runtime RBAC negatif | 20/20 → 403 (isolasi antar-peran) | ✅ LULUS |
| CRUD/aksi tulis sesuai izin | Tersimpan (read-only ditolak 403) | ✅ LULUS |
| **Dinamika denyut (real-time)** | Tanda **BERUBAH** saat data operasional berubah | ✅ TERBUKTI DINAMIS |
| Regresi mesin denyut | 44 passed / 166 assertions | ✅ LULUS |
| Suite lengkap (fungsional + integrasi) | **1474 passed / 6597 assertions** | ✅ LULUS |
| Konsistensi jurnal (debit=kredit) | Selisih **Rp 0,00** | ✅ SEIMBANG |
| Elemen tanggal disentuh? | **Tidak ada** | ✅ PATUH |

**Kesimpulan:** Sistem berjalan **dinamis** sesuai spesifikasi fungsional. Seluruh alur kerja beroperasi
normal tanpa gangguan; tidak ada tanggal yang diubah.

---

## 1. INVENTARIS PERAN & AKUN DEMO

### 1.1 Peran (role) dalam sistem

Basis data mendefinisikan **8 nilai ENUM** pada kolom `users.role`; **5 peran memiliki user fungsional**
dan **3 peran bersifat phantom** (tidak ada user).

| # | Role | Tipe | Jumlah user | Dashboard/Menu | Keterangan |
|---|------|------|-------------|----------------|------------|
| 1 | `dps` | Fungsional | 1 | ✅ | Pengawas syariah — read-only |
| 2 | `pengurus` | Fungsional | 1 | ✅ | Manajemen — hak tulis luas |
| 3 | `manajer` | Fungsional | 1 | ✅ | Approval & pengawasan operasional |
| 4 | `ao` | Fungsional | 1 | ✅ | Account Officer — pengajuan & survei |
| 5 | `teller` | Fungsional | 1 | ✅ | Operasional kas & CIF |
| 6 | `admin` | **Phantom** | **0** | ✅ (route ada) | Dirujuk route/middleware, tanpa user |
| 7 | `anggota` | **Phantom** | **0** | ⚠️ (area `/nasabah`) | Tidak lagi punya akses (lihat `CheckRole`) |
| 8 | `nasabah` | **Phantom** | **0** | ❌ (0 route) | Hanya target redirect |

**Hierarki fungsional:** DPS > Pengurus > Manajer > AO > Teller.

### 1.2 Bukti distribusi user (kueri basis data)

```json
{ "dps": 1, "pengurus": 1, "manajer": 1, "ao": 1, "teller": 1 }
```

Sesuai `database/seeders/UserSeeder.php` — hanya 5 akun fungsional yang di-seed, password dibaca dari
`config('app.demo.password')` (tanpa hardcode); seeder dilewati bila `APP_ENV=production` atau password kosong.

### 1.3 Akun & kredensial pengujian

| Role | Email | Password |
|------|-------|----------|
| DPS | `dps@koperasi.test` | dari `config('app.demo.password')` |
| Pengurus | `pengurus@koperasi.test` | idem |
| Manajer | `manajer@koperasi.test` | idem |
| AO | `ao@koperasi.test` | idem |
| Teller | `teller@koperasi.test` | idem |

### 1.4 Data demo dalam basis data (snapshot saat pengujian)

| Tabel | Baris | Keterangan |
|-------|-------|------------|
| `anggota` | 13 | Data anggota/keanggotaan |
| `rekening` | 25 | Rekening simpanan |
| `transaksi` | 57 | Mutasi simpanan/operasional |
| `jurnal` | 69 | Jurnal umum (PSAK 101) |
| `jurnal_detail` | 147 | Baris debit/kredit jurnal |
| `pembiayaan` | 5 | Akad pembiayaan aktif |
| `pengajuan_pembiayaan` | 5 | Pengajuan (workflow approval) |
| `jenis_simpanans` | 4 | Parameter jenis simpanan |
| `kas_harian` | 1 | Saldo kas harian |
| `kebijakan_koperasis` | 1 | Parameter kebijakan (dinamis) |

Total **53 tabel** pada basis data. `APP_ENV=local`; ketersediaan password demo = **ya**.

---

## 2. HASIL PENGUJIAN PER PERAN

Setiap peran diuji dengan **4 dimensi**: (1) otentikasi & pengalihan, (2) akses menu/halaman utama,
(3) RBAC negatif (lintas-peran), (4) aksi tulis sesuai izin.

### 2.1 Ringkasan matriks per peran

| Role | Login → redirect | Halaman utama | RBAC negatif (lintas peran) | Aksi tulis | Status |
|------|------------------|---------------|------------------------------|------------|--------|
| `teller` | 302 → `/teller/dashboard` | 200 (semua halaman teller) | 403 (4 dashboard lain) | Tulis operasional kas/CIF diizinkan | ✅ LULUS |
| `ao` | 302 → `/ao/dashboard` | 200 | 403 | Pengajuan/survei diizinkan | ✅ LULUS |
| `manajer` | 302 → `/manajer/dashboard` | 200 | 403 | Approval diizinkan | ✅ LULUS |
| `pengurus` | 302 → `/pengurus/dashboard` | 200 | 403 | Tulis luas diizinkan (CRUD tersimpan) | ✅ LULUS |
| `dps` | 302 → `/dps/dashboard` | 200 | 403 | **Read-only** (tulis → 403) | ✅ LULUS |

### 2.2 Pengujian E2E otomatis (`DemonstrasiPerPeranTest`)

```
Tests:  12 passed (263 assertions)   Duration: 2.91s
```

Menguji per-role: halaman wajib (200) per peran, larangan dashboard lintas-peran (403), dan
CRUD/aksi tulis sesuai izin pada entitas parameter (tanpa kolom tanggal).

### 2.3 Pengujian RBAC & navigasi (4 suite)

```
RbacHierarkiRoleTest · NavigasiWewenangPeranTest · MatriksHakAksesAuditTest · NavigasiAdminMenuTest
Tests:  44 passed (233 assertions)   Duration: 5.97s
```

### 2.4 Pengujian runtime HTTP nyata (`storage/app/demo-runtime.ps1`)

Ringkasan hasil server `127.0.0.1:8123`:

- **Login:** 5/5 peran → HTTP **302** (dialihkan ke dashboard masing-masing).
- **Halaman utama:** **18/18** halaman per peran → HTTP **200**.
- **Latensi render:** 287–484 ms (anggaran 5.000 ms) → **sangat cepat**.

### 2.5 Pengujian RBAC runtime (`storage/app/demo-rbac-crud.ps1`)

- **RBAC negatif:** **20/20** percobaan lintas-peran → HTTP **403**. Isolasi antar-peran **sempurna**.
- **Baca (READ):** 200 (peran berwenang dapat membaca).
- **Tulis (CREATE):** tersimpan di basis data (terverifikasi baris = 1) sebagai `pengurus`.
- **DPS tulis:** → HTTP **403** (read-only benar, sesuai kewenangan pengawas syariah).

> Catatan operasional: login dibatasi `throttle:5,1` (5 percobaan/menit/IP). Skrip demo memberi jeda
> untuk menghormati batas ini sehingga **tidak ada gangguan** (tanpa 429) selama pengujian berlangsung.

### 2.6 Peran phantom (tanpa user)

| Role | Hasil | Keterangan |
|------|-------|------------|
| `admin` | Route ada, **0 user** → tidak dapat diuji fungsional | Kandidat peninjauan (route aktif tanpa user) |
| `anggota` | **0 user**; akses ditutup oleh `CheckRole` | Peran dieliminasi dari aplikasi (keputusan desain) |
| `nasabah` | **0 route**, hanya target redirect | Tidak ada permukaan uji |

Peran phantom **bukan cacat fungsional**: sistem dirancang hanya untuk 5 peran operasional koperasi.

---

## 3. VERIFIKASI DINAMIKA SISTEM

Sistem dinyatakan **dinamis** bila tanda perubahan (denyut) **berubah** ketika data operasional berubah —
bukan statis/tetap. Pengujian dilakukan **tanpa menyentuh elemen tanggal**.

### 3.1 Mesin denyut (real-time)

| Domain denyut (8) | Keterangan |
|-------------------|------------|
| `TRANSAKSI`, `REKENING`, `APPROVAL`, `PEMBIAYAAN`, `ANGGOTA`, `JURNAL`, `SHU`, `DEPOSITO` | Data operasional yang memicu tanda berubah |

Endpoint `/denyut` menyediakan 3 transportasi: **JSON snapshot**, **SSE (`?stream=1`)**, **long-polling (`?tunggu=1`)**.
Snapshot **tidak membuka database** (dilayani dari cache) → respons sangat cepat.

### 3.2 Bukti runtime — tanda BERUBAH saat data operasional berubah

Hasil `storage/app/demo-dinamis.ps1`:

```
======== 1. ENDPOINT DENYUT (SIDIK JARI REAL-TIME) PER PERAN ========
  OK [teller   ] denyut HTTP 200 tanda=2f69f3399545d42f1fff774416dc2684
  OK [ao       ] denyut HTTP 200 tanda=2f69f3399545d42f1fff774416dc2684
  OK [manajer  ] denyut HTTP 200 tanda=2f69f3399545d42f1fff774416dc2684
  OK [pengurus ] denyut HTTP 200 tanda=2f69f3399545d42f1fff774416dc2684
  OK [dps      ] denyut HTTP 200 tanda=2f69f3399545d42f1fff774416dc2684

======== 2. DINAMIS: TANDA BERUBAH SAAT DATA OPERASIONAL BERUBAH ========
  Tanda SEBELUM = 2f69f3399545d42f1fff774416dc2684
  Aksi: bump domain 'shu' (tanpa menyentuh elemen tanggal).
  Tanda SESUDAH = ba4fc993370124c71023bd95540c5316
  => DENYUT BERUBAH (dinamis terbukti)

======== 3. NEGATIF: DOMAIN DI LUAR PETA TIDAK MENGUBAH TANDA ========
  Tanda sebelum = ba4fc993370124c71023bd95540c5316
  Tanda sesudah = ba4fc993370124c71023bd95540c5316  (harus SAMA: entitas master di luar peta)
  => BENAR: tanda tidak berubah (by design).
```

**Interpretasi:**
1. Semua 5 peran melihat tanda denyut yang **konsisten** (satu sumber kebenaran versi).
2. Mutasi domain operasional (`shu`) → tanda **BERUBAH** ⇒ **sistem dinamis**.
3. Entitas **master/parameter** (di luar 8 domain, mis. `JenisSimpanan`) → tanda **TIDAK berubah** ⇒
   sistem benar memisahkan *data operasional* (memicu sinkronisasi) dari *data master* (tidak memicu).
   Ini **by design**, bukan cacat.

### 3.3 Bukti regresi mesin denyut (`DenyutSinkronisasiRealtimeTest`)

```
Tests:  44 passed (166 assertions)   Duration: 4.89s
```

Test kunci yang lulus:
- tanda berubah ketika domain dinaikkan (JURNAL/SHU),
- simpan Transaksi menaikkan domain TRANSAKSI,
- potongan HTML dashboard **berubah** setelah data mutasi,
- alur integrasi observer → sidik jari klien,
- endpoint snapshot melayani **tanpa** membuka database,
- model di luar peta (mis. `User`) **tidak** mengubah tanda,
- degradasi anggun saat cache bermasalah (exception ditelan, transaksi tetap jalan).

### 3.4 Konsistensi akuntansi (integritas data dinamis)

| Pemeriksaan | Perintah | Hasil |
|-------------|----------|-------|
| Rekonsiliasi jurnal | `jurnal:rekonsiliasi` | **SEIMBANG** — debet = kredit = Rp 125.747.500,00 · selisih **Rp 0,00** |
| Deteksi jurnal orphan | `jurnal:deteksi-orphan --contoh=5` | **0 orphan** (7 kategori) |
| Kesehatan denyut | `denyut:kesehatan` | **SEHAT** — latensi 4.256 ms, tanda `2f69f3…` |
| Validasi simpanan wajib (PSAK 108) | `psak:validasi-simpanan-wajib` | **LULUS** — 23 tagihan (21 PAID, 1 tunggakan), 0 pelanggaran |

Neraca uji: Aset 107.339.167 · Ekuitas 11.850.000 · Kewajiban 97.050.000 · Pendapatan 439.167 · Beban 2.000.000.

---

## 4. PENGUJIAN FUNGSIONAL & INTEGRASI LINTAS PERAN (SUITE PENUH)

```
php artisan test
Tests:  1474 passed (6597 assertions)   Duration: 195.10s   Exit code: 0
```

Seluruh 1474 pengujian (fungsional, integrasi, RBAC, keamanan, PSAK/OJK, real-time) **LULUS** tanpa
kegagalan — membuktikan alur kerja sistem beroperasi **normal tanpa gangguan**.

---

## 5. STATUS DINAMIKA SISTEM — TERIDENTIFIKASI

| # | Kriteria dinamika | Bukti | Status |
|---|-------------------|-------|--------|
| 1 | Tanda real-time tersedia per peran | `/denyut` → 200 untuk 5/5 peran | ✅ |
| 2 | Tanda berubah saat data operasional berubah | `2f69f3…` → `ba4fc9…` setelah mutasi domain | ✅ |
| 3 | Data master tidak memicu perubahan (pemisahan yang benar) | tanda tetap setelah mutasi `JenisSimpanan` | ✅ |
| 4 | Parameter kebijakan dinamis (dari DB, bukan hardcode) | kebijakan dibaca dari `kebijakan_koperasis` | ✅ |
| 5 | Integritas akuntansi terjaga (debit=kredit) | selisih Rp 0,00 | ✅ |
| 6 | Snapshot denyut tanpa beban database | test "snapshot tanpa database" lulus | ✅ |
| 7 | Degradasi anggun saat cache gagal | `bump()` menelan exception, transaksi tetap jalan | ✅ |
| 8 | RBAC dinamis per peran (bukan statis) | 20/20 negatif → 403; izin tulis sesuai peran | ✅ |

---

## 6. TEMUAN & CATATAN

| Kode | Kategori | Uraian | Dampak |
|------|----------|--------|--------|
| D-01 | Observasi | 3 peran phantom (`admin`, `anggota`, `nasabah`) tanpa user | Rendah — route `admin` aktif tanpa akun; kandidat peninjauan |
| D-02 | Desain | `JenisSimpanan` sengaja di luar peta 8 domain denyut | Tidak ada — pemisahan master vs operasional yang benar |
| D-03 | Operasional | Login `throttle:5,1` membatasi kecepatan skrip demo | Rendah — perlu jeda antar-skenario (bukan gangguan sistem) |
| D-04 | Catatan teknis | `php artisan test --parallel` butuh `brianium/paratest` (tidak terpasang) | Rendah — suite dijalankan serial (195 s) |

**Tidak ditemukan bug fungsional** pada seluruh alur peran selama pengujian.

---

## 7. REKOMENDASI

| Kode | Rekomendasi | Prioritas |
|------|-------------|-----------|
| R-01 | Tinjau ulang route `role:admin` (56 route) — jadikan nonaktif atau dokumentasikan sebagai cadangan | Sedang |
| R-02 | Pertahankan skrip demo (`demo-runtime`, `demo-rbac-crud`, `demo-dinamis`) dalam regresi berkala | Rendah |
| R-03 | Dokumentasikan peta domain denyut agar jelas entitas mana yang memicu perubahan | Rendah |
| R-04 | Tambahkan mekanisme pembersihan baris probe uji otomatis (sudah tersedia di `demo-dinamis.ps1`) | Rendah |

---

## 8. PERNYATAAN KEPATUHAN

1. **Seluruh peran (5 fungsional)** telah diuji dengan data demo — **LULUS**.
2. **Identifikasi menyeluruh** membuktikan sistem berjalan **dinamis** sesuai spesifikasi fungsional — **TERBUKTI**.
3. **Seluruh elemen tanggal dikecualikan penuh**: tidak ada kolom/atribut tanggal yang diubah, dimutasi,
   atau dimodifikasi dalam bentuk apa pun selama pengujian.
4. Seluruh alur kerja sistem **beroperasi normal tanpa gangguan** (suite 1474 pengujian lulus; exit code 0).
5. Laporan ini mencakup **hasil pengujian setiap role** (Bagian 2) dan **status dinamika sistem**
   (Bagian 3 & 5).

---

## 9. LAMPIRAN — PERINTAH & SKRIP YANG DIGUNAKAN

| Jenis | Perintah / Berkas |
|-------|-------------------|
| Suite penuh | `php artisan test` |
| E2E per peran | `php artisan test tests/Feature/DemonstrasiPerPeranTest.php` |
| Regresi denyut | `php artisan test tests/Feature/DenyutSinkronisasiRealtimeTest.php` |
| Runtime HTTP | `storage/app/demo-runtime.ps1` |
| Runtime RBAC/CRUD | `storage/app/demo-rbac-crud.ps1` |
| Verifikasi dinamika | `storage/app/demo-dinamis.ps1` |

**Berkas laporan terkait:** `docs/LAPORAN-DEMONSTRASI-PER-PERAN.md` (demonstrasi 2026-10-05).
